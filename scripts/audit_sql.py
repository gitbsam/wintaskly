#!/usr/bin/env python3
"""
Wintaskly — Audit des requêtes SQL contre le vrai schéma.

Parse `sql/schema.sql` pour extraire les tables et colonnes,
puis scanne tous les fichiers .php pour repérer les requêtes
qui référencent des colonnes inexistantes.

Heuristique : pour chaque table mentionnée dans `FROM`, `JOIN`,
`UPDATE`, `INSERT INTO`, on liste les colonnes utilisées (préfixées
ou non par un alias) et on vérifie qu'elles existent dans le schéma.

Faux positifs possibles :
  - Aliases SQL (`SELECT COUNT(*) c`) → ignorés
  - Fonctions SQL (`NOW()`, `UTC_DATE()`) → ignorées
  - Strings dans des littéraux PHP → on tronque aux backticks/lignes
  - Colonnes calculées via `SELECT ... AS` → ignorées
  - Tables réutilisées avec alias différent → on prend le dernier
"""
import re, sys, glob
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCHEMA_FILE = ROOT / "sql" / "schema.sql"

# ===== 1) Parse schema =====
schema_sql = SCHEMA_FILE.read_text()
TABLES: dict[str, set[str]] = {}

for m in re.finditer(
    r"CREATE TABLE IF NOT EXISTS\s+`(\w+)`\s*\((.*?)\)\s*ENGINE",
    schema_sql, re.DOTALL,
):
    table = m.group(1)
    body  = m.group(2)
    cols = set()
    for cm in re.finditer(r"^\s+`(\w+)`\s+", body, re.MULTILINE):
        cols.add(cm.group(1))
    TABLES[table] = cols

# Ajout des colonnes ajoutées via hotfix `ALTER TABLE ADD COLUMN`
for m in re.finditer(
    r"ALTER TABLE\s+`(\w+)`\s+ADD COLUMN\s+`(\w+)`",
    schema_sql,
):
    TABLES.setdefault(m.group(1), set()).add(m.group(2))

print(f"Schéma chargé : {len(TABLES)} tables")

# ===== 2) Scan PHP files =====
issues = []
for php in sorted(glob.glob(str(ROOT) + "/**/*.php", recursive=True)):
    if "node_modules" in php or ".bak" in php:
        continue
    src = Path(php).read_text(errors="ignore")
    rel = Path(php).relative_to(ROOT)

    # On cherche tous les blocs SQL en heredoc ou string
    # Heuristique : tout texte entre " ou ' qui contient SELECT / INSERT / UPDATE / DELETE
    for sm in re.finditer(
        r'"([^"]*?(?:SELECT|INSERT INTO|UPDATE|DELETE FROM)[^"]*?)"',
        src, re.IGNORECASE | re.DOTALL,
    ):
        sql = sm.group(1)
        # Trouve toutes les tables référencées avec leur alias
        # Patterns : FROM table [alias] | JOIN table [alias] | UPDATE table | INSERT INTO table
        aliases: dict[str, str] = {}  # alias -> table

        for tm in re.finditer(
            r"(?:FROM|JOIN|UPDATE|INSERT INTO)\s+`?(\w+)`?(?:\s+(?:AS\s+)?(\w+))?",
            sql, re.IGNORECASE,
        ):
            table = tm.group(1).lower()
            alias = (tm.group(2) or table).lower()
            # Filtre les mots-clés SQL communs qui matchent
            if table in {"select", "where", "on", "and", "or"}:
                continue
            if table not in TABLES:
                continue  # table inconnue, skip (probablement un mot-clé)
            aliases[alias] = table

        # Si aucune table reconnue, skip
        if not aliases:
            continue

        # Cherche les `alias.column` ou `table.column`
        for cm in re.finditer(r"\b(\w+)\.(\w+)\b", sql):
            ref_alias = cm.group(1).lower()
            col       = cm.group(2)
            # Skip if 'column' looks like a SQL keyword/function
            if ref_alias not in aliases:
                continue
            table = aliases[ref_alias]
            if col not in TABLES[table]:
                issues.append((str(rel), table, col, sql[:80] + "..."))

# ===== 3) Dedupe + report =====
issues = list(dict.fromkeys(issues))

if not issues:
    print("✓ Aucune référence de colonne fantôme détectée.")
    sys.exit(0)

print(f"\n❌ {len(issues)} référence(s) à des colonnes inexistantes :\n")
for rel, table, col, snippet in issues:
    print(f"  {rel}")
    print(f"    table=`{table}`  col=`{col}`  ← inconnue")
    print(f"    sql: {snippet}")
    valid = sorted(TABLES[table])
    print(f"    colonnes valides : {', '.join(valid[:8])}{'…' if len(valid) > 8 else ''}")
    print()

sys.exit(1)
