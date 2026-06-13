<?php
/**
 * Wintaskly — Header partagé (V8).
 *
 * Refonte cohérente avec le footer V8 :
 *   - Switchers segmentés langue/thème (mêmes contrôles qu'en bas)
 *   - Active-state des liens de nav (page courante highlighted)
 *   - Skip link d'accessibilité
 *   - Backdrop-blur sticky avec shadow apparaissant au scroll
 *   - Solde Coins en pill gradient (desktop)
 *   - Drawer mobile avec sections groupées
 *   - Meta description dynamique via $pageDescription
 *
 * ⚠️  Compat préservée — TOUS les hooks JS sont identiques :
 *     - #wt-preloader / .wt-preloader__bar / [data-pct]
 *     - #app-wrapper (caché initialement)
 *     - [data-action="toggle-theme"] / [data-action="switch-lang"]
 *     - [data-drawer-toggle] / [data-drawer-close] / [data-drawer-backdrop]
 *     - [data-profile-toggle] / [data-profile-dropdown]
 *     - [data-notif-bell] / [data-msg-envelope]
 *     - Meta : csrf-token, wt-base, wt-theme, wt-authed
 *     - Anti-FOUC inline script
 */

$_u     = current_user();
$_theme = $GLOBALS['WT_THEME'] ?? 'dark';
$_lang  = $GLOBALS['WT_LANG_CODE'] ?? 'fr';

/* --------------------------------------------------------------------
 * $_base : URL de base du site, normalisée pour ÉVITER LES DOUBLES SLASHES.
 *
 * Source : $GLOBALS['WT_CONFIG']['base_url'] (typiquement "https://wintaskly.com"
 * ou "https://wintaskly.com/" selon comment l'admin l'a saisi à l'install).
 *
 * Normalisation en 2 passes :
 *   1) rtrim '/'  → enlève TOUS les slashes finaux ("https://x.com//" → "https://x.com")
 *   2) regex     → écrase les "//" dans le path si présents (mais préserve "https://")
 *
 * Résultat garanti : $_base ne finit JAMAIS par '/', et le code peut toujours
 * concatener "<?= $_base ?>/path" en toute sécurité.
 * ----------------------------------------------------------------- */
$_base = (string) ($GLOBALS['WT_CONFIG']['base_url'] ?? '');
$_base = rtrim($_base, '/');
// Sécurité défensive : écrase tout double-slash sauf après le scheme (https://)
$_base = preg_replace('#(?<!:)//+#', '/', $_base);

$_csrf  = csrf_token();

/* Compteurs unread — uniquement si connecté */
$_unreadMsgs   = $_u ? wt_messages_unread_count((int) $_u['id'])      : 0;
$_unreadNotifs = $_u ? wt_notifications_unread_count((int) $_u['id']) : 0;

/* Path courant pour le highlight des liens de nav (sans query string) */
$_currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

/* Si le site est installé dans un sous-dossier (ex: /wintaskly sur LWS),
 * on retire le préfixe basePath de l'URI pour la comparaison de section. */
$_navPath = $_currentPath;
if ($_base !== '' && $_base !== '/' && str_starts_with($_navPath, $_base)) {
    $_navPath = substr($_navPath, strlen($_base)) ?: '/';
}
if ($_navPath === '' || $_navPath[0] !== '/') {
    $_navPath = '/' . $_navPath;
}

/* ----------------------------------------------------------------------
 * Détection de la "section active" (longest match wins).
 *
 * Stratégie : on classe les sections par longueur de préfixe DÉCROISSANTE
 * et on prend la première qui matche. Ça garantit que :
 *   - Sur /tasks/faucet/ → match "/tasks/faucet" (pas "/tasks" ni "/")
 *   - Sur /tasks/        → match "/tasks"        (pas "/")
 *   - Sur /              → match "/"             (exact match uniquement)
 *
 * Tout path commence par "/", donc on traite "/" séparément avec un match
 * EXACT pour éviter qu'il soit actif sur toutes les pages.
 * ---------------------------------------------------------------------- */

// Sous-sections du dashboard / sous-sections de tasks : enregistrées en premier
// (plus longues = priorité plus haute)
$_navSections = [
    '/admin',
    '/dashboard/messages',
    '/dashboard/notifications',
    '/dashboard/referrals',
    '/dashboard/withdraw',
    '/dashboard/account',
    '/dashboard',
    '/tasks/faucet',
    '/tasks/shortlinks',
    '/tasks/ptc',
    '/tasks/offerwalls',
    '/tasks',
    '/leaderboard',
    '/testimonials',
    '/help',
    '/legal',
    '/auth',
];

// Détermine la section courante : la plus longue qui matche en préfixe
$_activeSection = null;
foreach ($_navSections as $section) {
    // Match : exact, ou suivi d'un séparateur (/, .php, ?)
    // Ainsi "/dashboard/withdraw" matche "/dashboard/withdraw.php" mais pas "/dashboardX"
    if ($_navPath === $section
        || str_starts_with($_navPath, $section . '/')
        || str_starts_with($_navPath, $section . '.')) {
        $_activeSection = $section;
        break;  // Le plus long match gagne (l'ordre du tableau les classe ainsi)
    }
}

// Si rien n'a matché ET qu'on est sur "/", c'est l'accueil
if ($_activeSection === null && $_navPath === '/') {
    $_activeSection = '/';
}

// =====================================================================
// $_hasSidebar : true si la page courante affiche une sidebar latérale.
// Seules /admin/* et /dashboard/* en ont (gérées par admin/_nav.php et
// dashboard/_nav.php). Utilisé pour afficher le bouton de toggle sidebar
// dans le header uniquement sur ces pages.
//
// IMPORTANT : on teste sur le path lui-même, pas sur $_activeSection,
// car les sous-sections (/dashboard/messages, /dashboard/withdraw, etc.)
// donnent un $_activeSection = '/dashboard/messages' (pas '/dashboard').
// =====================================================================
$_hasSidebar = str_starts_with($_navPath, '/admin')
            || str_starts_with($_navPath, '/dashboard');


/**
 * Retourne 'is-active' si $section correspond à la section courante
 * OU si $section est un parent (préfixe) de la section courante.
 *
 * Exemple : sur /tasks/faucet/ :
 *   - $_activeSection = '/tasks/faucet'
 *   - _navActive('/tasks/faucet') → 'is-active' ✓ (match exact)
 *   - _navActive('/tasks')        → 'is-active' ✓ (parent du courant)
 *   - _navActive('/')             → ''         ✓ (PAS un parent)
 *   - _navActive('/leaderboard')  → ''         ✓ (autre section)
 *
 * Le cas '/' est traité à part : il n'est actif QUE sur la home exacte,
 * jamais comme parent (sinon il serait toujours actif partout).
 */
$_navActive = static function (string $section) use (&$_activeSection): string {
    if ($_activeSection === null) {
        return '';
    }
    // Cas spécial '/' : match exact uniquement
    if ($section === '/') {
        return $_activeSection === '/' ? 'is-active' : '';
    }
    // Sinon : match exact OU section est un préfixe parent de la section active
    if ($section === $_activeSection) {
        return 'is-active';
    }
    if (str_starts_with($_activeSection, $section . '/')) {
        return 'is-active';
    }
    return '';
};

/* Helper : URL avec param query mis à jour, pour les togglers lang/theme */
$_makeToggleUrl = static function (string $key, string $value) use ($_currentPath): string {
    $uri   = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $parts = explode('?', $uri, 2);
    $query = [];
    if (isset($parts[1])) parse_str($parts[1], $query);
    $query[$key] = $value;
    unset($query['p']);
    return $parts[0] . (count($query) > 0 ? '?' . http_build_query($query) : '');
};

/* Meta description : utilise $pageDescription si défini, fallback admin settings ou générique */
$_adminMetaDesc = (string) cfg('seo.meta_description', '');
$_pageDesc = $pageDescription
             ?? ($_adminMetaDesc !== '' ? $_adminMetaDesc : (string) t('site.tagline'));

/* Récupération des paramètres SEO/tracking configurés en admin
 * Tout est centralisé ici pour minimiser les appels cfg() dans le HTML.
 */
$_seoKeywords     = (string) cfg('seo.meta_keywords', '');
$_seoOgImage      = (string) cfg('seo.og_image_url', '');
$_seoTwitter      = (string) cfg('seo.twitter_handle', '');
$_seoRobotsIndex  = cfg('seo.robots_index', '1') === '1';

$_gaId            = (string) cfg('tracking.google_analytics_id', '');
$_adsenseClient   = (string) cfg('tracking.google_adsense_client', '');
$_adsenseAutoAds  = cfg('tracking.adsense_auto_ads', '0') === '1';
$_fbPixelId       = (string) cfg('tracking.facebook_pixel_id', '');
$_matomoUrl       = (string) cfg('tracking.matomo_url', '');
$_matomoSiteId    = (string) cfg('tracking.matomo_site_id', '');
?><!doctype html>
<html lang="<?= e($_lang) ?>" class="<?= e($_theme) ?>" data-theme="<?= e($_theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#0a0f1e" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<title><?= e($pageTitle ?? t('site_name')) ?> — <?= e(t('site_name')) ?></title>
<meta name="description" content="<?= e($_pageDesc) ?>">
<?php if ($_seoKeywords !== ''): ?>
<meta name="keywords" content="<?= e($_seoKeywords) ?>">
<?php endif; ?>
<?php if (!$_seoRobotsIndex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>

<!-- Favicons : ICO multi-res pour vieux navigateurs, PNG modernes ensuite -->
<link rel="icon"          type="image/x-icon" href="<?= $_base ?>/media/wintaskly/img/favicon.ico">
<link rel="icon"          type="image/png" sizes="32x32"   href="<?= $_base ?>/media/wintaskly/img/logo-light-32.png">
<link rel="icon"          type="image/png" sizes="192x192" href="<?= $_base ?>/media/wintaskly/img/logo-light-192.png">
<link rel="apple-touch-icon" sizes="180x180"               href="<?= $_base ?>/media/wintaskly/img/apple-touch-icon.png">
<link rel="mask-icon" color="#3b82f6" href="<?= $_base ?>/media/wintaskly/img/favicon.ico">
<link rel="manifest" href="<?= $_base ?>/manifest.webmanifest">

<?php
/* Image OG : configurée en admin ou fallback assets locaux */
$_ogImage = $_seoOgImage !== '' ? $_seoOgImage : $_base . '/media/wintaskly/img/og-image.png';
?>
<!-- Open Graph + Twitter Card pour les partages -->
<meta property="og:title"       content="<?= e($pageTitle ?? t('site_name')) ?> — <?= e(t('site_name')) ?>">
<meta property="og:description" content="<?= e($_pageDesc) ?>">
<meta property="og:image"       content="<?= e($_ogImage) ?>">
<meta property="og:type"        content="website">
<meta property="og:url"         content="<?= $_base ?><?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
<meta name="twitter:card"       content="summary_large_image">
<meta name="twitter:image"      content="<?= e($_ogImage) ?>">
<?php if ($_seoTwitter !== ''): ?>
<meta name="twitter:site"       content="<?= e($_seoTwitter) ?>">
<meta name="twitter:creator"    content="<?= e($_seoTwitter) ?>">
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;500;600;700;800&family=Manrope:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="<?= $_base ?>/media/tailwind/css/tailwind.css">
<link rel="stylesheet" href="<?= $_base ?>/media/wintaskly/css/wintaskly.css">
<link rel="stylesheet" href="<?= $_base ?>/media/wintaskly/css/wintaskly-animations.css">

<meta name="csrf-token" content="<?= e($_csrf) ?>">
<meta name="wt-base"    content="<?= e($_base) ?>">
<meta name="wt-theme"   content="<?= e($_theme) ?>">
<meta name="wt-authed"  content="<?= $_u ? '1' : '0' ?>">

<script>
  // Anti-FOUC : applique la classe de thème immédiatement
  (function(){
    try {
      var t = document.documentElement.getAttribute('data-theme') || 'dark';
      document.documentElement.classList.remove('light','dark');
      document.documentElement.classList.add(t);
    } catch(e){}
  })();
</script>

<?php /* ============ SCRIPTS DE TRACKING — injectés depuis admin/settings.php ============ */ ?>

<?php if ($_gaId !== ''): ?>
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($_gaId) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?= e($_gaId) ?>');
</script>
<?php endif; ?>

<?php if ($_adsenseClient !== ''): ?>
<!-- Google AdSense -->
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?= e($_adsenseClient) ?>"
        crossorigin="anonymous"></script>
<?php if ($_adsenseAutoAds): ?>
<script>
  (adsbygoogle = window.adsbygoogle || []).push({
    google_ad_client: "<?= e($_adsenseClient) ?>",
    enable_page_level_ads: true
  });
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($_fbPixelId !== ''): ?>
<!-- Facebook Pixel -->
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window, document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '<?= e($_fbPixelId) ?>');
  fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=<?= e($_fbPixelId) ?>&ev=PageView&noscript=1"/></noscript>
<?php endif; ?>

<?php if ($_matomoUrl !== '' && $_matomoSiteId !== ''): ?>
<!-- Matomo Analytics -->
<script>
  var _paq = window._paq = window._paq || [];
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u = "<?= e(rtrim($_matomoUrl, '/')) ?>/";
    _paq.push(['setTrackerUrl', u + 'matomo.php']);
    _paq.push(['setSiteId', '<?= e($_matomoSiteId) ?>']);
    var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
    g.async = true; g.src = u + 'matomo.js';
    s.parentNode.insertBefore(g, s);
  })();
</script>
<?php endif; ?>

</head>
<body class="wt-body">

<!-- ===================================================================
     CSS INLINE pour le preloader + app-wrapper.
     CRITICAL : ce CSS DOIT être présent AVANT que le navigateur dessine
     le DOM. Si on attend que wintaskly.css se télécharge (12000 lignes),
     l'utilisateur voit un gros cercle SVG pleine largeur en blanc.
     Avec ce CSS inline : preloader correctement sizé dès le 1er paint.
     =================================================================== -->
<style>
  #wt-preloader{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;
    justify-content:center;background:#0a0e1a;transition:opacity .35s ease,visibility .35s ease}
  #wt-preloader.is-hidden{opacity:0;visibility:hidden;pointer-events:none}
  #wt-preloader .wt-preloader__inner{position:relative;width:96px;height:96px;
    display:flex;align-items:center;justify-content:center}
  #wt-preloader .wt-preloader__ring{position:absolute;inset:0;width:96px;height:96px;
    transform:rotate(-90deg)}
  #wt-preloader .wt-preloader__track{fill:none;stroke:rgba(255,255,255,.08);stroke-width:3}
  #wt-preloader .wt-preloader__bar{fill:none;stroke:#ff9933;stroke-width:3;stroke-linecap:round;
    transition:stroke-dashoffset .2s ease}
  #wt-preloader .wt-preloader__logo{position:relative;z-index:1;display:flex;
    align-items:center;justify-content:center;width:48px;height:48px;line-height:0}
  #wt-preloader .wt-preloader__logo img{width:48px;height:48px;display:block;border-radius:8px;
    object-fit:contain}
  #wt-preloader .wt-preloader__pct{position:absolute;bottom:-1.75rem;left:50%;
    transform:translateX(-50%);font-family:ui-monospace,'SF Mono',Menlo,monospace;
    font-size:.72rem;color:#a4abc4;letter-spacing:.05em}
  .wt-app-wrapper{visibility:hidden}
  .wt-app-wrapper.is-ready{visibility:visible}
  /* Skip link caché par défaut (évite flash au chargement) */
  .wt-skip-link{position:absolute;top:-100px;left:50%;transform:translateX(-50%)}

  /* =================================================================
     FILET DE SÉCURITÉ CSS : après 8 secondes, on force la visibilité
     de l'app et on masque le préloader, même si le JS a échoué.
     Évite que les visiteurs Google (référencés sur la home) restent
     bloqués indéfiniment sur "0%" si une ressource JS ne charge pas.

     L'animation `wt-force-reveal` ne fait que poser visibility:visible
     à 100% du keyframe (= après 8s), ce qui ne déclenche aucun reflow
     prématuré et n'interfère pas avec le JS si tout va bien.
     ================================================================= */
  @keyframes wt-force-reveal-app {
    0%, 99% { visibility: hidden; }
    100%    { visibility: visible; }
  }
  @keyframes wt-force-hide-preloader {
    0%, 99% { opacity: 1; visibility: visible; }
    100%    { opacity: 0; visibility: hidden; }
  }
  .wt-app-wrapper:not(.is-ready) {
    animation: wt-force-reveal-app 8s forwards;
  }
  #wt-preloader:not(.is-hidden) {
    animation: wt-force-hide-preloader 8s forwards;
  }
</style>

<!-- ===================================================================
     SKIP LINK — accessibilité clavier (caché jusqu'au focus)
     =================================================================== -->
<a href="#main-content" class="wt-skip-link"><?= e(t('header.skip_to_content')) ?></a>

<!-- ===================================================================
     PRELOADER PLEIN ÉCRAN
     =================================================================== -->
<div id="wt-preloader" class="wt-preloader" aria-hidden="false" role="status">
  <div class="wt-preloader__inner">
    <svg class="wt-preloader__ring" viewBox="0 0 96 96">
      <circle class="wt-preloader__track" cx="48" cy="48" r="42" />
      <circle class="wt-preloader__bar"   cx="48" cy="48" r="42"
              stroke-dasharray="263.9" stroke-dashoffset="263.9"/>
    </svg>
    <picture class="wt-preloader__logo">
      <source srcset="<?= $_base ?>/media/wintaskly/img/logo-dark-192.png"  media="(prefers-color-scheme: dark)">
      <img    src="<?= $_base ?>/media/wintaskly/img/logo-light-192.png"
              alt="Wintaskly" width="48" height="48" decoding="async">
    </picture>
    <div class="wt-preloader__pct" data-pct>0%</div>
  </div>
</div>

<!-- ===================================================================
     PRELOADER JS — INLINE (sans defer/async)
     ===================================================================
     IMPORTANT : ce JS est inline dans le <body> juste après le HTML du
     préloader. Il s'exécute IMMÉDIATEMENT, sans attendre que
     wintaskly-ui.js (qui est en defer) ne se charge.

     Pourquoi : sur connexion lente (4G), wintaskly-ui.js peut mettre
     5-15 secondes à se télécharger + s'exécuter (defer = après tout le
     parsing HTML). Pendant ce temps, le préloader était bloqué à 0%
     → impression de site cassé pour le visiteur Google.

     Ce script autonome anime le préloader dès maintenant, et est conçu
     pour ne pas conflicter avec wintaskly-ui.js (il pose un flag
     window.__wtPreloaderHandled pour signaler qu'il a pris le contrôle).
     =================================================================== */
<script>
(function () {
  'use strict';
  var preloader = document.getElementById('wt-preloader');
  var appWrap   = document.getElementById('app-wrapper');
  if (!preloader) return;

  var pctEl = preloader.querySelector('[data-pct]');
  var barEl = preloader.querySelector('.wt-preloader__bar');
  var CIRC  = 263.9;  // 2π * 42

  // Flag pour que wintaskly-ui.js sache qu'on a déjà géré le préloader
  window.__wtPreloaderHandled = true;

  var current = 0;
  var target  = 5;
  var rafId;

  function setPct(p) {
    var clamped = Math.max(0, Math.min(100, p));
    if (pctEl) pctEl.textContent = Math.round(clamped) + '%';
    if (barEl) barEl.setAttribute('stroke-dashoffset', String(CIRC - (CIRC * clamped / 100)));
  }

  function loop() {
    current += (target - current) * 0.08;
    if (target - current < 0.4) current = target;
    setPct(current);
    if (current < 100) {
      rafId = requestAnimationFrame(loop);
    } else {
      cancelAnimationFrame(rafId);
      revealApp();
    }
  }

  function bumpTo(val) {
    target = Math.max(target, Math.min(100, val));
  }

  function revealApp() {
    if (!appWrap) return;
    setTimeout(function () {
      preloader.classList.add('is-hidden');
      appWrap.classList.add('is-ready');
      // Stagger des reveals
      var els = document.querySelectorAll('[data-reveal]');
      for (var i = 0; i < els.length; i++) {
        els[i].style.setProperty('--wt-reveal-delay', (i * 60) + 'ms');
      }
    }, 100);
  }

  // Lance immédiatement la boucle d'animation
  setPct(0);
  rafId = requestAnimationFrame(loop);
  bumpTo(40);

  // Phase B : 70% quand DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bumpTo(95); });
  } else {
    bumpTo(95);
  }

  // Phase C : 100% quand window.load (toutes images + CSS chargés)
  if (document.readyState === 'complete') {
    bumpTo(100);
  } else {
    window.addEventListener('load', function () { bumpTo(100); });
  }

  // Garde-fou DUR : après 4 secondes max, on force le 100% et on révèle
  // l'app, peu importe l'état du chargement. Évite que l'utilisateur reste
  // bloqué si une ressource lente (image, ad, script tiers) prend trop
  // de temps.
  setTimeout(function () { bumpTo(100); }, 4000);

  // Garde-fou ULTIME : après 6 secondes, on hide brutalement le préloader
  // même si l'anim n'a pas fini, pour éviter un blocage indéfini.
  setTimeout(function () {
    if (!preloader.classList.contains('is-hidden')) {
      preloader.classList.add('is-hidden');
      if (appWrap) appWrap.classList.add('is-ready');
    }
  }, 6000);
})();
</script>

<!-- ===================================================================
     APP WRAPPER
     ===================================================================
     visibility: hidden permet au CSS de pré-calculer le layout (donc pas
     de FOUC quand le JS révèle le contenu), tout en cachant visuellement.
     Le JS ajoute la classe is-ready pour révéler. En fallback no-JS,
     <noscript> force la visibilité (sinon site invisible sans JS). -->
<div id="app-wrapper" class="wt-app-wrapper">
<noscript><style>.wt-app-wrapper{visibility:visible !important}#wt-preloader{display:none !important}</style></noscript>

<?php
/* =====================================================================
   BANNIÈRE UTILISATEUR : annonce maintenance / update à venir.

   Configurée via /admin/updates.php. Affichée uniquement si :
     - update.user_banner_on = 1
     - le texte n'est pas vide
     - la date d'expiration (si définie) n'est pas dépassée

   La bannière apparaît en haut de TOUTES les pages publiques + user,
   AVANT le header sticky. Discret mais visible : utilisateurs avertis
   en avance.
   ===================================================================== */
if (function_exists('cfg')) {
    $_bannerOn    = (string) cfg('update.user_banner_on', '0') === '1';
    $_bannerMsg   = trim((string) cfg('update.user_banner_msg', ''));
    $_bannerUntil = trim((string) cfg('update.user_banner_until', ''));

    if ($_bannerOn && $_bannerMsg !== '') {
        $_showBanner = true;
        if ($_bannerUntil !== '') {
            // Format datetime-local (YYYY-MM-DDTHH:MM) ou DATETIME MySQL
            $_until = strtotime($_bannerUntil);
            if ($_until !== false && $_until < time()) {
                $_showBanner = false;
            }
        }
        if ($_showBanner):
?>
<div class="wt-maint-banner" role="status" aria-live="polite">
  <span class="wt-maint-banner__icon">🔔</span>
  <span class="wt-maint-banner__msg"><?= e($_bannerMsg) ?></span>
  <?php if ($_bannerUntil !== '' && isset($_until) && $_until > time()): ?>
    <small class="wt-maint-banner__until">
      <?= e(date('d/m/Y H:i', $_until)) ?>
    </small>
  <?php endif; ?>
</div>
<?php
        endif;
    }
}
?>

<header class="wt-header-v2" data-header-sticky>
  <div class="wt-header-v2__inner">

    <?php if ($_hasSidebar): ?>
    <!-- ====== Bouton toggle sidebar (mobile/tablette uniquement) ======
         Visible seulement sur /admin/* et /dashboard/*, < 960px.
         Icône : 3 points horizontaux (style "more options"). -->
    <button type="button"
            class="wt-header-v2__sidebar-toggle"
            data-sidebar-toggle
            aria-controls="wt-sidebar-drawer"
            aria-expanded="false"
            aria-label="<?= e(t('header.sidebar_toggle')) ?>">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
        <circle cx="5"  cy="12" r="2"/>
        <circle cx="12" cy="12" r="2"/>
        <circle cx="19" cy="12" r="2"/>
      </svg>
    </button>
    <?php endif; ?>

    <!-- ====== Brand ====== -->
    <a href="<?= $_base ?>/" class="wt-header-v2__brand" aria-label="Wintaskly">
      <picture class="wt-header-v2__brand-mark">
        <source srcset="<?= $_base ?>/media/wintaskly/img/logo-dark-64.png"  media="(prefers-color-scheme: dark)">
        <img    src="<?= $_base ?>/media/wintaskly/img/logo-light-64.png"
                alt="Wintaskly" width="32" height="32" decoding="async">
      </picture>
      <span class="wt-header-v2__brand-name">intaskly</span>
    </a>

    <!-- ====== Navigation desktop ====== -->
    <nav class="wt-header-v2__nav" data-nav="desktop" aria-label="<?= e(t('header.nav_main')) ?>">
      <a class="<?= $_navActive('/') ?>" href="<?= $_base ?>/"><?= e(t('nav.home')) ?></a>
      <a class="<?= $_navActive('/tasks') ?>" href="<?= $_base ?>/tasks/"><?= e(t('nav.tasks')) ?></a>
      <?php if ($_u): ?>
        <a class="<?= $_navActive('/dashboard') ?>" href="<?= $_base ?>/dashboard/">
          <?= e(t('nav.dashboard')) ?>
        </a>
      <?php endif; ?>
      <a class="<?= $_navActive('/leaderboard') ?>" href="<?= $_base ?>/leaderboard/">
        <?= e(t('nav.leaderboard')) ?>
      </a>
      <a class="<?= $_navActive('/testimonials') ?>" href="<?= $_base ?>/testimonials/">
        <?= e(t('nav.testimonials')) ?>
      </a>
      <a class="<?= $_navActive('/help') ?>" href="<?= $_base ?>/help/">
        <?= e(t('nav.help')) ?>
      </a>
    </nav>

    <!-- ====== Actions à droite ====== -->
    <div class="wt-header-v2__actions">

      <!-- Toggle thème (icône qui tourne au clic) -->
      <button type="button"
              class="wt-header-v2__icon-btn wt-header-v2__theme-btn"
              data-action="toggle-theme"
              aria-label="<?= e(t('footer.theme_label')) ?>"
              title="<?= e(t('footer.theme_label')) ?>">
        <svg class="wt-icon-sun"  viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="4"/>
          <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
        </svg>
        <svg class="wt-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
      </button>

      <!-- Sélecteur de langue : segmented control desktop -->
      <div class="wt-header-v2__lang-switch" data-nav="desktop"
           role="group" aria-label="<?= e(t('footer.lang_label')) ?>">
        <a class="wt-header-v2__lang-btn <?= $_lang === 'fr' ? 'is-active' : '' ?>"
           href="<?= e($_makeToggleUrl('lang', 'fr')) ?>"
           rel="nofollow" title="Français">FR</a>
        <a class="wt-header-v2__lang-btn <?= $_lang === 'en' ? 'is-active' : '' ?>"
           href="<?= e($_makeToggleUrl('lang', 'en')) ?>"
           rel="nofollow" title="English">EN</a>
      </div>

      <!-- Sélecteur natif caché (compat JS existant qui écoute [data-action="switch-lang"]) -->
      <select class="wt-header-v2__lang-fallback" data-action="switch-lang" aria-hidden="true" tabindex="-1">
        <option value="fr" <?= $_lang === 'fr' ? 'selected' : '' ?>>FR</option>
        <option value="en" <?= $_lang === 'en' ? 'selected' : '' ?>>EN</option>
      </select>

      <?php if ($_u): ?>

        <!-- Solde Coins (desktop) -->
        <a class="wt-header-v2__balance" data-nav="desktop"
           href="<?= $_base ?>/dashboard/withdraw.php"
           title="<?= e(t('wd.title')) ?>"
           aria-label="<?= e(t('common.coins')) ?> : <?= number_format((float)$_u['coins'], 2, '.', ' ') ?>">
          <span class="wt-header-v2__balance-icon" aria-hidden="true">💰</span>
          <span class="wt-header-v2__balance-amt"><?= number_format((float)$_u['coins'], 2, '.', ' ') ?></span>
          <span class="wt-header-v2__balance-unit"><?= e(t('common.coins')) ?></span>
        </a>

        <!-- Cloche notifications -->
        <a class="wt-header-v2__icon-btn wt-header-v2__icon-btn--badge"
           href="<?= $_base ?>/dashboard/notifications.php"
           aria-label="<?= e(t('nav.notifications')) ?>"
           data-notif-bell
           title="<?= e(t('nav.notifications')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <?php if ($_unreadNotifs > 0): ?>
            <span class="wt-ping" aria-hidden="true"></span>
          <?php endif; ?>
        </a>

        <!-- Enveloppe messages (disparaît si 0) -->
        <?php if ($_unreadMsgs > 0): ?>
          <a class="wt-header-v2__icon-btn wt-header-v2__icon-btn--badge"
             href="<?= $_base ?>/dashboard/messages.php"
             aria-label="<?= e(t('nav.messages')) ?>"
             data-msg-envelope
             title="<?= e(t('nav.messages')) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
            </svg>
            <span class="wt-pill-badge"><?= e(wt_badge_count($_unreadMsgs)) ?></span>
          </a>
        <?php endif; ?>

        <!-- Avatar dropdown -->
        <div class="wt-header-v2__profile" data-profile-menu>
          <button type="button"
                  class="wt-avatar wt-header-v2__avatar"
                  data-profile-toggle
                  data-hash-color="<?= e($_u['username']) ?>"
                  aria-haspopup="menu"
                  aria-expanded="false"
                  aria-label="<?= e(t('nav.profile')) ?>">
            <?= wt_avatar_inner($_u) ?>
          </button>

          <div class="wt-dropdown wt-header-v2__dropdown" role="menu" data-profile-dropdown>
            <div class="wt-dropdown__head">
              <strong><?= e($_u['username']) ?></strong>
              <span class="wt-muted"><?= e($_u['email']) ?></span>
            </div>
            <?php if (($_u['role'] ?? 'user') === 'admin'): ?>
              <a class="wt-dropdown__item wt-dropdown__item--admin"
                 href="<?= $_base ?>/admin/">
                <span aria-hidden="true">🛡️</span> <?= e(t('nav.admin')) ?>
              </a>
              <hr class="wt-dropdown__sep">
            <?php endif; ?>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/">
              <span aria-hidden="true">🏠</span> <?= e(t('nav.dashboard')) ?>
            </a>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/messages.php">
              <span aria-hidden="true">✉️</span> <?= e(t('nav.messages')) ?>
              <?php if ($_unreadMsgs > 0): ?>
                <span class="wt-pill-badge wt-pill-badge--inline"><?= e(wt_badge_count($_unreadMsgs)) ?></span>
              <?php endif; ?>
            </a>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/notifications.php">
              <span aria-hidden="true">🔔</span> <?= e(t('nav.notifications')) ?>
              <?php if ($_unreadNotifs > 0): ?>
                <span class="wt-pill-badge wt-pill-badge--inline"><?= (int)$_unreadNotifs ?></span>
              <?php endif; ?>
            </a>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/referrals.php">
              <span aria-hidden="true">🤝</span> <?= e(t('ref.title')) ?>
            </a>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/withdraw.php">
              <span aria-hidden="true">💸</span> <?= e(t('wd.title')) ?>
            </a>
            <a class="wt-dropdown__item" href="<?= $_base ?>/dashboard/account.php">
              <span aria-hidden="true">⚙️</span> <?= e(t('nav.account')) ?>
            </a>
            <hr class="wt-dropdown__sep">
            <a class="wt-dropdown__item wt-dropdown__item--danger"
               href="<?= $_base ?>/auth/logout.php">
              <span aria-hidden="true">🚪</span> <?= e(t('nav.logout')) ?>
            </a>
          </div>
        </div>

      <?php else: ?>

        <!-- Boutons login/signup : visibles en desktop uniquement -->
        <a class="wt-btn wt-btn--ghost"   data-nav="desktop"
           href="<?= $_base ?>/auth/login.php"><?= e(t('nav.login')) ?></a>
        <a class="wt-btn wt-btn--primary" data-nav="desktop"
           href="<?= $_base ?>/auth/signup.php"><?= e(t('nav.register')) ?></a>

        <!-- Icône clé : mobile uniquement -->
        <a class="wt-header-v2__icon-btn" data-nav="mobile"
           href="<?= $_base ?>/auth/login.php"
           aria-label="<?= e(t('nav.login')) ?>"
           title="<?= e(t('nav.login')) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>
          </svg>
        </a>

      <?php endif; ?>

      <!-- Hamburger : mobile uniquement -->
      <button type="button" class="wt-header-v2__icon-btn wt-hamburger" data-nav="mobile"
              data-drawer-toggle
              aria-controls="wt-drawer"
              aria-expanded="false"
              aria-label="<?= e(t('header.open_menu')) ?>">
        <span class="wt-hamburger__bar"></span>
        <span class="wt-hamburger__bar"></span>
        <span class="wt-hamburger__bar"></span>
      </button>
    </div>
  </div>
</header>

<?php
/* =====================================================================
   BANNIÈRE ADMIN : mise à jour disponible
   =====================================================================
   Affichée uniquement aux admins connectés sur toutes les pages, juste
   sous le header sticky. Mise en avant si update "critique" (rouge),
   sinon ton accent (orange) classique. Cliquable → /admin/updates.php.
   ===================================================================== */
$_isAdminViewer = !empty($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'admin';
if ($_isAdminViewer
    && function_exists('wt_update_has_pending') && wt_update_has_pending()
    && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/admin/updates.php') === false) {
    $_isCrit = wt_update_is_critical();
    $_lat    = (string) cfg('update.latest_version', '');
?>
<a href="<?= e(rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/')) ?>/admin/updates.php"
   class="wt-admin-update-banner <?= $_isCrit ? 'is-critical' : '' ?>">
  <span class="wt-admin-update-banner__icon"><?= $_isCrit ? '🚨' : '🔄' ?></span>
  <span class="wt-admin-update-banner__msg">
    <strong>
      <?= e(sprintf((string) t('admin.upd.banner_admin'), $_lat)) ?>
    </strong>
    <small style="opacity:.85;margin-left:.5rem">
      <?= e(t('admin.upd.banner_admin_cta')) ?> →
    </small>
  </span>
</a>
<?php } ?>

<!-- ===================================================================
     DRAWER MOBILE — coulissant de la droite vers la gauche
     =================================================================== -->
<div class="wt-drawer-backdrop" data-drawer-backdrop aria-hidden="true"></div>

<aside class="wt-drawer wt-drawer-v2" id="wt-drawer" aria-hidden="true"
       role="dialog" aria-label="<?= e(t('header.mobile_menu')) ?>">

  <div class="wt-drawer-v2__head">
    <a href="<?= $_base ?>/" class="wt-header-v2__brand">
      <picture class="wt-header-v2__brand-mark">
        <source srcset="<?= $_base ?>/media/wintaskly/img/logo-dark-64.png"  media="(prefers-color-scheme: dark)">
        <img    src="<?= $_base ?>/media/wintaskly/img/logo-light-64.png"
                alt="Wintaskly" width="32" height="32" decoding="async">
      </picture>
      <span class="wt-header-v2__brand-name">intaskly</span>
    </a>
    <button type="button" class="wt-header-v2__icon-btn" data-drawer-close
            aria-label="<?= e(t('header.close_menu')) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </button>
  </div>

  <?php if ($_u): ?>
    <!-- Profile card mobile -->
    <div class="wt-drawer-v2__profile">
      <div class="wt-avatar wt-avatar--lg" data-hash-color="<?= e($_u['username']) ?>" aria-hidden="true">
        <?= wt_avatar_inner($_u) ?>
      </div>
      <div class="wt-drawer-v2__profile-info">
        <strong><?= e($_u['username']) ?></strong>
        <small><?= e($_u['email']) ?></small>
      </div>
    </div>

    <!-- Solde Coins en card -->
    <a class="wt-drawer-v2__balance" href="<?= $_base ?>/dashboard/withdraw.php">
      <span class="wt-drawer-v2__balance-icon" aria-hidden="true">💰</span>
      <div>
        <small><?= e(t('common.coins')) ?></small>
        <strong><?= number_format((float)$_u['coins'], 2, '.', ' ') ?></strong>
      </div>
      <span class="wt-drawer-v2__balance-arrow" aria-hidden="true">→</span>
    </a>
  <?php endif; ?>

  <!-- Navigation drawer en sections groupées -->
  <nav class="wt-drawer-v2__nav" aria-label="<?= e(t('header.nav_main')) ?>">

    <!-- Section : Explorer (toujours visible) -->
    <div class="wt-drawer-v2__section">
      <h3 class="wt-drawer-v2__section-title"><?= e(t('header.section_explore')) ?></h3>
      <a class="<?= $_navActive('/') ?>" href="<?= $_base ?>/"
         style="--idx:0">
        <span aria-hidden="true">🏠</span> <?= e(t('nav.home')) ?>
      </a>
      <a class="<?= $_navActive('/tasks') ?>" href="<?= $_base ?>/tasks/"
         style="--idx:1">
        <span aria-hidden="true">🎯</span> <?= e(t('nav.tasks')) ?>
      </a>
      <a class="<?= $_navActive('/leaderboard') ?>" href="<?= $_base ?>/leaderboard/"
         style="--idx:2">
        <span aria-hidden="true">🏆</span> <?= e(t('nav.leaderboard')) ?>
      </a>
      <a class="<?= $_navActive('/testimonials') ?>" href="<?= $_base ?>/testimonials/"
         style="--idx:3">
        <span aria-hidden="true">💬</span> <?= e(t('nav.testimonials')) ?>
      </a>
      <a class="<?= $_navActive('/help') ?>" href="<?= $_base ?>/help/"
         style="--idx:4">
        <span aria-hidden="true">🛟</span> <?= e(t('nav.help')) ?>
      </a>
    </div>

    <?php if ($_u): ?>
      <!-- Section : Mes tâches -->
      <div class="wt-drawer-v2__section">
        <h3 class="wt-drawer-v2__section-title"><?= e(t('header.section_tasks')) ?></h3>
        <a class="<?= $_navActive('/tasks/faucet') ?>" href="<?= $_base ?>/tasks/faucet/"
           style="--idx:0">
          <span aria-hidden="true">💧</span> <?= e(t('nav.faucet')) ?>
        </a>
        <a class="<?= $_navActive('/tasks/shortlinks') ?>" href="<?= $_base ?>/tasks/shortlinks/"
           style="--idx:1">
          <span aria-hidden="true">🔗</span> <?= e(t('nav.shortlinks')) ?>
        </a>
        <a class="<?= $_navActive('/tasks/ptc') ?>" href="<?= $_base ?>/tasks/ptc/"
           style="--idx:2">
          <span aria-hidden="true">📺</span> <?= e(t('nav.ptc')) ?>
        </a>
        <a class="<?= $_navActive('/tasks/offerwalls') ?>" href="<?= $_base ?>/tasks/offerwalls/"
           style="--idx:3">
          <span aria-hidden="true">🎁</span> <?= e(t('nav.offerwalls')) ?>
        </a>
      </div>

      <!-- Section : Mon compte -->
      <div class="wt-drawer-v2__section">
        <h3 class="wt-drawer-v2__section-title"><?= e(t('header.section_account')) ?></h3>
        <a class="<?= $_navActive('/dashboard') ?>" href="<?= $_base ?>/dashboard/"
           style="--idx:0">
          <span aria-hidden="true">📊</span> <?= e(t('nav.dashboard')) ?>
        </a>
        <a href="<?= $_base ?>/dashboard/messages.php"
           class="<?= $_navActive('/dashboard/messages') ?>" style="--idx:1">
          <span aria-hidden="true">✉️</span> <?= e(t('nav.messages')) ?>
          <?php if ($_unreadMsgs > 0): ?>
            <span class="wt-pill-badge wt-pill-badge--inline"><?= e(wt_badge_count($_unreadMsgs)) ?></span>
          <?php endif; ?>
        </a>
        <a href="<?= $_base ?>/dashboard/notifications.php"
           class="<?= $_navActive('/dashboard/notifications') ?>" style="--idx:2">
          <span aria-hidden="true">🔔</span> <?= e(t('nav.notifications')) ?>
          <?php if ($_unreadNotifs > 0): ?>
            <span class="wt-pill-badge wt-pill-badge--inline"><?= (int)$_unreadNotifs ?></span>
          <?php endif; ?>
        </a>
        <a href="<?= $_base ?>/dashboard/referrals.php"
           class="<?= $_navActive('/dashboard/referrals') ?>" style="--idx:3">
          <span aria-hidden="true">🤝</span> <?= e(t('ref.title')) ?>
        </a>
        <a href="<?= $_base ?>/dashboard/withdraw.php"
           class="<?= $_navActive('/dashboard/withdraw') ?>" style="--idx:4">
          <span aria-hidden="true">💸</span> <?= e(t('wd.title')) ?>
        </a>
        <a href="<?= $_base ?>/dashboard/account.php"
           class="<?= $_navActive('/dashboard/account') ?>" style="--idx:5">
          <span aria-hidden="true">⚙️</span> <?= e(t('nav.account')) ?>
        </a>
      </div>

      <?php if (($_u['role'] ?? 'user') === 'admin'): ?>
        <div class="wt-drawer-v2__section">
          <h3 class="wt-drawer-v2__section-title">🛡️ Admin</h3>
          <a class="<?= $_navActive('/admin') ?>" href="<?= $_base ?>/admin/" style="--idx:0">
            <span aria-hidden="true">🛡️</span> <?= e(t('nav.admin')) ?>
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </nav>

  <!-- Foot : préférences + logout/login -->
  <div class="wt-drawer-v2__foot">
    <!-- Switchers langue + thème (cohérent footer) -->
    <div class="wt-drawer-v2__prefs">
      <div class="wt-drawer-v2__pref-row">
        <small><?= e(t('footer.lang_label')) ?></small>
        <div class="wt-header-v2__lang-switch">
          <a class="wt-header-v2__lang-btn <?= $_lang === 'fr' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('lang', 'fr')) ?>" rel="nofollow">FR</a>
          <a class="wt-header-v2__lang-btn <?= $_lang === 'en' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('lang', 'en')) ?>" rel="nofollow">EN</a>
        </div>
      </div>
      <div class="wt-drawer-v2__pref-row">
        <small><?= e(t('footer.theme_label')) ?></small>
        <div class="wt-header-v2__lang-switch">
          <a class="wt-header-v2__lang-btn <?= $_theme === 'light' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('theme', 'light')) ?>" rel="nofollow"
             aria-label="<?= e(t('footer.theme_light')) ?>">☀️</a>
          <a class="wt-header-v2__lang-btn <?= $_theme === 'dark' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('theme', 'dark')) ?>" rel="nofollow"
             aria-label="<?= e(t('footer.theme_dark')) ?>">🌙</a>
        </div>
      </div>
    </div>

    <?php if ($_u): ?>
      <a class="wt-btn wt-btn--ghost wt-btn--block" href="<?= $_base ?>/auth/logout.php">
        🚪 <?= e(t('nav.logout')) ?>
      </a>
    <?php else: ?>
      <a class="wt-btn wt-btn--ghost   wt-btn--block" href="<?= $_base ?>/auth/login.php">
        <?= e(t('nav.login')) ?>
      </a>
      <a class="wt-btn wt-btn--primary wt-btn--block" href="<?= $_base ?>/auth/signup.php">
        <?= e(t('nav.register')) ?>
      </a>
    <?php endif; ?>
  </div>
</aside>


<!-- Anchor cible du skip link -->
<a id="main-content" tabindex="-1" class="wt-skip-anchor" aria-hidden="true"></a>
