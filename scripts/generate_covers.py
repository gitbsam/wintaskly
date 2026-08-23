#!/usr/bin/env python3
"""
Wintaskly — Générateur de couvertures d'articles
=================================================
Produit, pour chaque article publié :

  • une couverture  1200 × 630 px  (format Open Graph, partage réseaux)
  • une miniature    600 × 400 px  (listes d'articles)

POURQUOI UN GÉNÉRATEUR PLUTÔT QUE DES IMAGES DESSINÉES UNE À UNE
----------------------------------------------------------------
Avec 58 articles et un rythme d'un article par jour ouvré, produire les
couvertures à la main est intenable — et surtout, l'uniformité se perd dès
qu'on s'en écarte une fois. Un générateur garantit que la couverture n° 200
aura exactement le même style que la n° 1.

PRINCIPES GRAPHIQUES
--------------------
  • Fond sombre repris du thème du site (#0F1523), pas un fond blanc neutre.
  • Une teinte d'accent PAR CATÉGORIE, prise dans la palette existante :
    l'ambre de la marque pour les guides, et des variantes pour les autres.
    Un lecteur régulier reconnaît la catégorie avant même de lire.
  • Un motif géométrique différent par catégorie — cercles, grille,
    diagonales, vagues. Pas de photographie de banque d'images : elles
    donnent exactement l'aspect impersonnel qu'on cherche à éviter.
  • Le logo en bas à gauche, discret, à opacité réduite.
  • Le titre en gros, coupé proprement sur plusieurs lignes.

Les polices système sont utilisées avec un repli : le rendu reste correct
même si DejaVu n'est pas installé.

USAGE
-----
    python3 scripts/generate_covers.py           # tous les articles
    python3 scripts/generate_covers.py --force   # régénère l'existant
"""
import os, re, sys, unicodedata
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT  = os.path.join(ROOT, 'media', 'wintaskly', 'img', 'blog')
LOGO = os.path.join(ROOT, 'media', 'wintaskly', 'img', 'logo-light-256.png')

COVER = (1200, 630)
THUMB = (600, 400)

BG       = (15, 21, 35)      # --wt-bg du thème sombre
TEXT     = (243, 244, 248)   # --wt-text
TEXT_DIM = (150, 158, 176)

# Une teinte et un motif par catégorie — la catégorie se reconnaît d'un coup d'œil
CATS = {
    'guides':  {'color': (245, 158, 11),  'motif': 'grid',      'label': 'GUIDE'},
    'finance': {'color': (52, 211, 153),  'motif': 'waves',     'label': 'FINANCE'},
    'crypto':  {'color': (139, 92, 246),  'motif': 'circles',   'label': 'CRYPTO'},
    'astuces': {'color': (59, 130, 246),  'motif': 'diagonals', 'label': 'ASTUCE'},
}
DEFAULT = {'color': (245, 158, 11), 'motif': 'grid', 'label': 'ARTICLE'}


def font(size, bold=False):
    """Charge une police, avec repli sur la police par défaut de Pillow."""
    for p in (
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' if bold
        else '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf' if bold
        else '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ):
        if os.path.exists(p):
            try:
                return ImageFont.truetype(p, size)
            except Exception:
                pass
    return ImageFont.load_default()


def draw_motif(d, w, h, kind, color):
    """Motif géométrique de fond, à faible opacité — présent sans distraire."""
    c = color + (26,)
    if kind == 'grid':
        for x in range(0, w, 46):
            d.line([(x, 0), (x, h)], fill=c, width=1)
        for y in range(0, h, 46):
            d.line([(0, y), (w, y)], fill=c, width=1)
    elif kind == 'circles':
        for i, r in enumerate(range(90, max(w, h), 105)):
            d.ellipse([w - r, h - r, w + r, h + r], outline=color + (max(4, 30 - i * 2),), width=2)
    elif kind == 'diagonals':
        step = 54
        for x in range(-h, w + h, step):
            d.line([(x, 0), (x + h, h)], fill=c, width=1)
    elif kind == 'waves':
        for k in range(5):
            pts = []
            base = h * 0.62 + k * 26
            for x in range(0, w + 12, 12):
                import math
                pts.append((x, base + math.sin(x / 95.0 + k) * 17))
            d.line(pts, fill=color + (24,), width=2)


def wrap(text, fnt, max_w, draw, max_lines=4):
    """Découpe le titre en lignes qui tiennent dans la largeur donnée."""
    words, lines, cur = text.split(), [], ''
    for wd in words:
        trial = (cur + ' ' + wd).strip()
        if draw.textlength(trial, font=fnt) <= max_w:
            cur = trial
        else:
            if cur:
                lines.append(cur)
            cur = wd
            if len(lines) == max_lines:
                break
    if cur and len(lines) < max_lines:
        lines.append(cur)
    if len(lines) == max_lines and len(' '.join(lines)) < len(text) - 2:
        lines[-1] = lines[-1].rstrip('.,;: ') + '…'
    return lines


def build(title, cat_slug, size, with_logo=True):
    conf = CATS.get(cat_slug, DEFAULT)
    w, h = size
    img = Image.new('RGB', size, BG)
    layer = Image.new('RGBA', size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)

    draw_motif(d, w, h, conf['motif'], conf['color'])

    # Halo d'accent dans un angle — donne de la profondeur sans image
    for r in range(int(w * 0.55), 0, -14):
        a = max(0, int(16 * (1 - r / (w * 0.55))))
        d.ellipse([w - r, -r // 2, w + r, r], fill=conf['color'] + (a,))

    img = Image.alpha_composite(img.convert('RGBA'), layer).convert('RGB')
    d = ImageDraw.Draw(img)

    pad   = int(w * 0.065)
    big   = size == COVER
    f_cat = font(int(h * 0.033), bold=True)
    f_ttl = font(int(h * 0.082 if big else h * 0.072), bold=True)

    # Bandeau de catégorie
    d.rectangle([pad, pad, pad + 6, pad + int(h * 0.045)], fill=conf['color'])
    d.text((pad + 20, pad + int(h * 0.006)), conf['label'], font=f_cat, fill=conf['color'])

    # Titre
    lines = wrap(title, f_ttl, w - pad * 2, d, max_lines=4 if big else 3)
    lh = int(f_ttl.size * 1.28)
    y = pad + int(h * 0.13)
    for ln in lines:
        d.text((pad, y), ln, font=f_ttl, fill=TEXT)
        y += lh

    # Pied : nom du site + logo discret
    f_ft = font(int(h * 0.030), bold=False)
    fy = h - pad - int(h * 0.035)
    lx = pad
    if with_logo and os.path.exists(LOGO):
        try:
            lg = Image.open(LOGO).convert('RGBA')
            s = int(h * 0.062)
            lg = lg.resize((s, s), Image.LANCZOS)
            alpha = lg.split()[3].point(lambda p: int(p * 0.85))
            lg.putalpha(alpha)
            img.paste(lg, (lx, fy - int(h * 0.014)), lg)
            lx += s + 14
        except Exception:
            pass
    d.text((lx, fy), 'wintaskly.com', font=f_ft, fill=TEXT_DIM)

    return img


def slugify(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode()
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9]+', '-', s.lower())).strip('-')


def main():
    force = '--force' in sys.argv
    os.makedirs(OUT, exist_ok=True)

    # Articles lus depuis les migrations : pas besoin d'une base en marche
    items = []
    sqldir = os.path.join(ROOT, 'sql')
    for fn in sorted(os.listdir(sqldir)):
        if not fn.startswith('migration_blog_') or not fn.endswith('.sql'):
            continue
        txt = open(os.path.join(sqldir, fn), encoding='utf-8').read()
        m = re.search(r"VALUES \(\s*\n\s*'([a-z0-9-]+)',\s*\n\s*\(SELECT id FROM blog_categories WHERE slug='([a-z]+)'\),\s*\n\s*'((?:[^']|'')*)'", txt)
        if m:
            items.append((m.group(1), m.group(2), m.group(3).replace("''", "'")))

    if not items:
        print("Aucun article trouvé dans sql/migration_blog_*.sql")
        return 1

    made = skipped = 0
    for slug, cat, title in items:
        cp = os.path.join(OUT, f'{slug}-cover.png')
        tp = os.path.join(OUT, f'{slug}-thumb.png')
        if not force and os.path.exists(cp) and os.path.exists(tp):
            skipped += 1
            continue
        build(title, cat, COVER).save(cp, 'PNG', optimize=True)
        build(title, cat, THUMB, with_logo=False).save(tp, 'PNG', optimize=True)
        made += 1

    print(f"{made} couverture(s) générée(s), {skipped} déjà présente(s) — dossier : media/wintaskly/img/blog/")
    return 0


if __name__ == '__main__':
    sys.exit(main())
