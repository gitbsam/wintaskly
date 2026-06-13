# Wintaskly — Frontend Build Chain (Tailwind v4)

Ce dossier contient la **chaîne de compilation Tailwind CSS v4** pour Wintaskly.

## 🎯 Rôle

- **Source** : `src/input.css` (Tailwind v4 — `@import "tailwindcss"`)
- **Output** : `../media/tailwind/css/tailwind.css` (consommé par `header.php`)

## ⚡ Pourquoi Tailwind 4 ?

- **5x plus rapide** que Tailwind 3 (build complet en ~300ms)
- **Plus de `tailwind.config.js`** : toute la config vit dans `src/input.css`
- **Sources auto-détectées** via la directive `@source "../../**/*.php"`
- **27 packages npm** au total (vs 73 en v3)
- **Theme via `@theme`** dans le CSS (au lieu de `extend.colors` en JS)

## ⚠️ Prérequis navigateurs

Tailwind 4 cible les navigateurs **modernes uniquement** :
- Chrome 111+ (mars 2023)
- Safari 16.4+ (mars 2023)
- Firefox 128+ (juillet 2024)
- Edge 111+ (mars 2023)

> Compatibilité globale ≈ 96% des utilisateurs (juin 2026). Les vieux IE/Safari < 16.4 ne sont **plus supportés**.

## 🚀 Setup initial

```bash
cd frontend
npm install
```

## 🛠️ Commandes

```bash
# Build one-shot (minifié, à faire avant chaque déploiement)
npm run build:css

# Watch mode (rebuild automatique pendant le dev)
npm run watch:css
```

## 📂 Arborescence

```
frontend/
├── src/
│   └── input.css           ← Source Tailwind v4 (à éditer)
├── node_modules/           ← Dépendances npm (gitignored)
├── package.json            ← Scripts npm + deps (tailwindcss ^4.3.0)
├── package-lock.json       ← Lock versions
└── README.md               ← ce fichier
```

**Note** : pas de `tailwind.config.js` en v4 — la config vit dans `src/input.css`.

## 🎨 Architecture du CSS — Wintaskly

Le CSS de Wintaskly est **hybride** :

### 1. `media/tailwind/css/tailwind.css` (généré ici, 19 KB)

- Reset CSS de base (preflight)
- Compilation des `@apply` dans `@layer components` (toutes les classes `wt-*`)
- 3 utilities maison : `.wt-text-accent`, `.wt-bg-accent`, `.wt-border-accent`
- Quelques utilities Tailwind utilisées dans le PHP (`.flex`, `.grid`, etc.)

### 2. `media/wintaskly/css/wintaskly.css` (édité à la main, ~150 KB)

- Design system complet (variables CSS, couleurs, ombres, animations)
- Composants spécifiques (header, footer, admin, dashboard, etc.)
- **Chargé APRÈS Tailwind** dans `header.php` → peut overrider si besoin

## ⚠️ Workflow de déploiement

**LWS et autres hébergeurs mutualisés n'ont PAS Node.js.**

**Workflow correct** :
1. Modifie `src/input.css` ou tes fichiers PHP en local sur ton PC
2. Lance `npm run build:css` **EN LOCAL** sur ton PC
3. Le fichier `media/tailwind/css/tailwind.css` est régénéré
4. Upload sur LWS via FTP/cPanel (le CSS compilé est servi directement)

## 🔄 Migration v3 → v4 (déjà faite)

Si tu reviens un jour à un ancien checkpoint :

| Tailwind 3 (ancien) | Tailwind 4 (nouveau) |
|---|---|
| `@tailwind base; @tailwind components; @tailwind utilities;` | `@import "tailwindcss";` |
| `content: [...]` dans `tailwind.config.js` | `@source "../../**/*.php";` dans CSS |
| `theme.extend.colors` en JS | `@theme { --color-* }` en CSS |
| `darkMode: 'class'` en JS | `@custom-variant dark (...)` en CSS |
| `tailwindcss` (CLI) | `tailwindcss` (CLI v4) |
