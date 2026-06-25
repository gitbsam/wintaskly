<?php
/**
 * Wintaskly — footer.php (V8 modernisé)
 *
 * Pied de page principal avec :
 *   - Zone 4 colonnes (brand + 3 catégories de navigation)
 *   - Bandeau "Méthodes de paiement acceptées"
 *   - Bottom bar : copyright + bouton lang/thème + version
 *
 * Préserve le bridge WT_BASE + WT_I18N pour le cookie banner JS.
 *
 * Compat : on garde l'utilisateur sur la même page lors d'un changement
 * de langue/thème en passant via le query string (?lang=… / ?theme=…)
 * qui est géré dans includes/init.php (lignes 91-117).
 */
$_base  = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
$_lang  = $GLOBALS['WT_LANG_CODE'] ?? 'fr';
$_theme = $GLOBALS['WT_THEME']     ?? 'dark';

/* Construit l'URL courante avec un paramètre query mis à jour, pour
 * conserver la page actuelle lors d'un toggle lang/theme. */
$_currentUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$_makeToggleUrl = static function (string $key, string $value) use ($_currentUri): string {
    $parts = explode('?', $_currentUri, 2);
    $path  = $parts[0];
    $query = [];
    if (isset($parts[1])) parse_str($parts[1], $query);
    $query[$key] = $value;
    // Réinitialise la pagination ou param qui n'ont pas de sens après bascule
    unset($query['p']);
    return $path . (count($query) > 0 ? '?' . http_build_query($query) : '');
};

/* ====================================================================
 * Année dynamique pour le copyright
 *
 * Logique :
 *   - On lit `launch_year` depuis la table config (l'installeur l'a posé
 *     à l'année d'installation, ex: 2026)
 *   - Si on est encore dans la même année que le lancement → "© 2026"
 *   - Si on est plus tard → "© 2026 — 2030" (range automatique)
 *
 * L'admin peut modifier `launch_year` via /admin/settings.php si besoin
 * de corriger (ex: re-déployer une instance sur un domaine plus ancien).
 * ==================================================================== */
$_currentYear = (int) date('Y');
$_launchYear  = (int) cfg('launch_year', $_currentYear);
// Sécurité : si la valeur en config est aberrante (< 2020 ou > futur),
// on retombe sur l'année courante.
if ($_launchYear < 2020 || $_launchYear > $_currentYear) {
    $_launchYear = $_currentYear;
}
$_yearDisplay = ($_launchYear === $_currentYear)
    ? (string) $_currentYear
    : $_launchYear . ' — ' . $_currentYear;
?>

<footer class="wt-footer-v2">
  <div class="wt-footer-v2__wrap">

    <!-- ====== 4 COLONNES ====== -->
    <div class="wt-footer-v2__grid">

      <!-- COL 1 : Brand + tagline + socials -->
      <div class="wt-footer-v2__brand-col">
        <a class="wt-footer-v2__brand" href="<?= $_base ?>/"
           aria-label="<?= e(t('footer.brand_home_label')) ?>">
          <span class="wt-brand__mark">W</span>
          <span class="wt-brand__name">intaskly</span>
        </a>
        <p class="wt-footer-v2__tagline">
          <?= e(t('footer.tagline_v2')) ?>
        </p>

        <!-- Réseaux sociaux (icônes seulement, désactivés si pas d'URL configurée) -->
        <?php
          $_socials = [
              'facebook' => ['icon' => 'facebook', 'url' => cfg('social.facebook', '')],
              'twitter'  => ['icon' => 'twitter',  'url' => cfg('social.twitter',  '')],
              'instagram'=> ['icon' => 'instagram','url' => cfg('social.instagram','')],
              'tiktok'   => ['icon' => 'tiktok',   'url' => cfg('social.tiktok',   '')],
              'discord'  => ['icon' => 'discord',  'url' => cfg('social.discord',  '')],
              'telegram' => ['icon' => 'telegram', 'url' => cfg('social.telegram', '')],
              'youtube'  => ['icon' => 'youtube',  'url' => cfg('social.youtube',  '')],
          ];
          $_anySocial = array_filter($_socials, static fn ($s) => !empty($s['url']));
        ?>
        <?php if ($_anySocial): ?>
          <div class="wt-footer-v2__socials">
            <?php foreach ($_anySocial as $key => $s): ?>
              <a class="wt-footer-v2__social"
                 href="<?= e($s['url']) ?>"
                 target="_blank" rel="noopener noreferrer"
                 aria-label="<?= e(ucfirst($key)) ?>">
                <?php switch ($key):
                  case 'facebook': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
                    </svg>
                  <?php break; case 'twitter': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                    </svg>
                  <?php break; case 'instagram': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.43.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.43.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9c-.42-.42-.68-.82-.9-1.38-.16-.43-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.43-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.12 1.38C1.36 2.67.94 3.34.63 4.14.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.79.72 1.46 1.38 2.12.66.66 1.33 1.08 2.12 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56.79-.3 1.46-.72 2.12-1.38.66-.66 1.08-1.33 1.38-2.12.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91-.3-.79-.72-1.46-1.38-2.12C21.33 1.36 20.66.94 19.86.63 19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.41-10.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z"/>
                    </svg>
                  <?php break; case 'tiktok': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.3 0 .59.04.86.13V9.4a6.33 6.33 0 0 0-5.42 10.45 6.33 6.33 0 0 0 10.86-4.43V8.69a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-.96-.12z"/>
                    </svg>
                  <?php break; case 'discord': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M20.317 4.37a19.79 19.79 0 0 0-4.885-1.515.07.07 0 0 0-.073.035c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.65 12.65 0 0 0-.617-1.25.07.07 0 0 0-.073-.035 19.74 19.74 0 0 0-4.885 1.515.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.08.08 0 0 0 .031.055 19.9 19.9 0 0 0 5.993 3.03.07.07 0 0 0 .076-.027c.461-.63.873-1.295 1.226-1.994a.07.07 0 0 0-.038-.097 13.1 13.1 0 0 1-1.872-.892.07.07 0 0 1-.007-.117c.126-.094.252-.192.372-.291a.07.07 0 0 1 .071-.01c3.927 1.793 8.18 1.793 12.061 0a.07.07 0 0 1 .072.009c.12.099.246.198.373.292a.07.07 0 0 1-.006.117c-.598.349-1.22.645-1.873.891a.07.07 0 0 0-.038.098c.36.698.772 1.362 1.225 1.994a.07.07 0 0 0 .076.027 19.83 19.83 0 0 0 6.002-3.03.07.07 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.06.06 0 0 0-.031-.028zM8.02 15.331c-1.183 0-2.157-1.087-2.157-2.42 0-1.335.956-2.421 2.157-2.421 1.21 0 2.176 1.095 2.157 2.42 0 1.334-.956 2.421-2.157 2.421zm7.974 0c-1.183 0-2.157-1.087-2.157-2.42 0-1.335.955-2.421 2.157-2.421 1.21 0 2.176 1.095 2.157 2.42 0 1.334-.946 2.421-2.157 2.421z"/>
                    </svg>
                  <?php break; case 'telegram': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.05-.2-.06-.06-.16-.04-.23-.02-.1.02-1.69 1.07-4.77 3.16-.45.31-.86.46-1.23.45-.41-.01-1.18-.23-1.76-.41-.71-.23-1.27-.36-1.23-.76.03-.21.32-.42.88-.65 3.43-1.49 5.71-2.48 6.85-2.96 3.26-1.36 3.93-1.59 4.37-1.59.1 0 .31.02.45.12.12.08.15.2.16.28-.01.08.01.21 0 .31z"/>
                    </svg>
                  <?php break; case 'youtube': ?>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                      <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
                    </svg>
                  <?php break; endswitch; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- COL 2 : Produit -->
      <nav class="wt-footer-v2__col" aria-label="<?= e(t('footer.cat_product')) ?>">
        <h3 class="wt-footer-v2__col-title"><?= e(t('footer.cat_product')) ?></h3>
        <ul>
          <li><a href="<?= $_base ?>/tasks/"><?= e(t('nav.tasks')) ?></a></li>
          <li><a href="<?= $_base ?>/tasks/faucet/"><?= e(t('nav.faucet')) ?></a></li>
          <li><a href="<?= $_base ?>/tasks/shortlinks/"><?= e(t('nav.shortlinks')) ?></a></li>
          <li><a href="<?= $_base ?>/leaderboard/"><?= e(t('nav.leaderboard')) ?></a></li>
          <li><a href="<?= $_base ?>/testimonials/"><?= e(t('nav.testimonials')) ?></a></li>
        </ul>
      </nav>

      <!-- COL 3 : Support -->
      <nav class="wt-footer-v2__col" aria-label="<?= e(t('footer.cat_support')) ?>">
        <h3 class="wt-footer-v2__col-title"><?= e(t('footer.cat_support')) ?></h3>
        <ul>
          <li><a href="<?= $_base ?>/help/"><?= e(t('footer.help_center')) ?></a></li>
          <li><a href="<?= $_base ?>/help/faq.php"><?= e(t('faq.title')) ?></a></li>
          <li><a href="<?= $_base ?>/help/contact.php"><?= e(t('contact.title')) ?></a></li>
          <?php if (function_exists('wt_blog_enabled') && wt_blog_enabled()): ?>
            <li><a href="<?= $_base ?>/blog"><?= e(t('nav.blog')) ?></a></li>
          <?php endif; ?>
          <?php if (current_user()): ?>
            <li><a href="<?= $_base ?>/dashboard/messages.php"><?= e(t('footer.my_tickets')) ?></a></li>
          <?php endif; ?>
        </ul>
      </nav>

      <!-- COL 4 : Légal -->
      <nav class="wt-footer-v2__col" aria-label="<?= e(t('footer.cat_legal')) ?>">
        <h3 class="wt-footer-v2__col-title"><?= e(t('footer.cat_legal')) ?></h3>
        <ul>
          <li><a href="<?= $_base ?>/legal/cgu.php"><?= e(t('legal.cgu')) ?></a></li>
          <li><a href="<?= $_base ?>/legal/privacy.php"><?= e(t('legal.privacy')) ?></a></li>
          <li><a href="<?= $_base ?>/legal/cookies.php"><?= e(t('legal.cookies_title')) ?></a></li>
          <li><button type="button" class="wt-footer-v2__link-btn" data-cookie-reopen>
            <?= e(t('footer.manage_cookies')) ?>
          </button></li>
        </ul>
      </nav>
    </div>

    <!-- ====== MÉTHODES DE PAIEMENT ====== -->
    <div class="wt-footer-v2__payments" aria-label="<?= e(t('footer.payments_label')) ?>">
      <small class="wt-footer-v2__payments-title">
        💳 <?= e(t('footer.payments_title')) ?>
      </small>
      <div class="wt-footer-v2__payments-list">
        <span class="wt-footer-v2__payment">PayPal</span>
        <span class="wt-footer-v2__payment">Bitcoin</span>
        <span class="wt-footer-v2__payment">USDT</span>
        <span class="wt-footer-v2__payment">Mobile Money</span>
        <span class="wt-footer-v2__payment">Orange Money</span>
      </div>
    </div>

    <!-- ====== BOTTOM BAR ====== -->
    <div class="wt-footer-v2__bottom">
      <small class="wt-footer-v2__copyright">
        © <?= $_yearDisplay ?> Wintaskly · <?= e(t('footer.tagline')) ?>
        <span class="wt-footer-v2__made">
          · <?= e(t('footer.made_with')) ?> ❤️ <?= e(t('footer.from_mayotte')) ?>
        </span>
        <?php
        /* Version Wintaskly — affichée uniquement aux admins connectés,
           pour vérifier en un coup d'œil quelle version tourne en prod
           après une mise à jour. Invisible pour les utilisateurs normaux. */
        if (!empty($_SESSION['uid']) && ($_SESSION['role'] ?? '') === 'admin' && defined('WT_VERSION')):
        ?>
          <span class="wt-footer-v2__version" style="opacity:.5;font-family:var(--wt-font-mono);font-size:.75rem">
            · v<?= e(WT_VERSION) ?>
          </span>
        <?php endif; ?>
      </small>

      <div class="wt-footer-v2__controls">
        <!-- Langue (FR / EN) -->
        <div class="wt-footer-v2__switch" role="group" aria-label="<?= e(t('footer.lang_label')) ?>">
          <a class="wt-footer-v2__switch-btn <?= $_lang === 'fr' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('lang', 'fr')) ?>"
             title="Français" rel="nofollow">
            FR
          </a>
          <a class="wt-footer-v2__switch-btn <?= $_lang === 'en' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('lang', 'en')) ?>"
             title="English" rel="nofollow">
            EN
          </a>
        </div>

        <!-- Thème (dark / light) -->
        <div class="wt-footer-v2__switch" role="group" aria-label="<?= e(t('footer.theme_label')) ?>">
          <a class="wt-footer-v2__switch-btn <?= $_theme === 'light' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('theme', 'light')) ?>"
             title="<?= e(t('footer.theme_light')) ?>" rel="nofollow"
             aria-label="<?= e(t('footer.theme_light')) ?>">
            ☀️
          </a>
          <a class="wt-footer-v2__switch-btn <?= $_theme === 'dark' ? 'is-active' : '' ?>"
             href="<?= e($_makeToggleUrl('theme', 'dark')) ?>"
             title="<?= e(t('footer.theme_dark')) ?>" rel="nofollow"
             aria-label="<?= e(t('footer.theme_dark')) ?>">
            🌙
          </a>
        </div>
      </div>
    </div>

  </div>
</footer>

</div><!-- /#app-wrapper -->

<script>
/* Bridge i18n + base url pour les modules JS (cookie banner, etc.) */
window.WT_BASE = <?= json_encode($_base, JSON_UNESCAPED_SLASHES) ?>;
window.WT_I18N = {
  cookie_title:           <?= json_encode(t('cookies.banner_title'),       JSON_UNESCAPED_UNICODE) ?>,
  cookie_desc:            <?= json_encode(t('cookies.banner_desc'),        JSON_UNESCAPED_UNICODE) ?>,
  cookie_learn:           <?= json_encode(t('cookies.learn_more'),         JSON_UNESCAPED_UNICODE) ?>,
  cookie_privacy:         <?= json_encode(t('legal.privacy_short'),        JSON_UNESCAPED_UNICODE) ?>,
  cookie_accept:          <?= json_encode(t('cookies.accept_all'),         JSON_UNESCAPED_UNICODE) ?>,
  cookie_refuse:          <?= json_encode(t('cookies.refuse'),             JSON_UNESCAPED_UNICODE) ?>,
  cookie_prefs:           <?= json_encode(t('cookies.preferences'),        JSON_UNESCAPED_UNICODE) ?>,
  cookie_prefs_title:     <?= json_encode(t('cookies.prefs_title'),        JSON_UNESCAPED_UNICODE) ?>,
  cookie_save:            <?= json_encode(t('cookies.save'),               JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_essential:   <?= json_encode(t('cookies.cat_essential'),      JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_essential_d: <?= json_encode(t('cookies.cat_essential_d'),    JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_analytics:   <?= json_encode(t('cookies.cat_analytics'),      JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_analytics_d: <?= json_encode(t('cookies.cat_analytics_d'),    JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_ads:         <?= json_encode(t('cookies.cat_ads'),            JSON_UNESCAPED_UNICODE) ?>,
  cookie_cat_ads_d:       <?= json_encode(t('cookies.cat_ads_d'),          JSON_UNESCAPED_UNICODE) ?>,
  common_cancel:          <?= json_encode(t('common.cancel'),              JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= $_base ?>/media/wintaskly/js/wintaskly-ui.js" defer></script>
<script src="<?= $_base ?>/media/wintaskly/js/wintaskly.js" defer></script>
<script src="<?= $_base ?>/media/wintaskly/js/wt-ads-responsive.js" defer></script>

<!-- =====================================================================
     PWA — Service Worker + Bannière d'installation
     =====================================================================
     - Enregistre /sw.js dès le chargement
     - Affiche une bannière discrète "Installer Wintaskly" si le navigateur
       est compatible (Android Chrome, Edge, Samsung Internet, desktop Chrome).
     - iOS Safari : pas d'événement beforeinstallprompt, on affiche une
       note "Partager → Sur l'écran d'accueil" à la place sur iPhone/iPad.
     - Choix mémorisé en localStorage pour ne pas re-spammer.
===================================================================== -->
<script>
(function () {
  'use strict';

  /* ---- 1) Service Worker registration ---- */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      // On passe la version Wintaskly en query string. Quand WT_VERSION
      // change (nouveau déploiement), l'URL du SW change → le navigateur
      // détecte un "nouveau" SW et le réinstalle, purgeant les vieux
      // caches. Plus besoin de bumper manuellement CACHE_VERSION dans
      // sw.js : la version PHP pilote tout.
      navigator.serviceWorker.register('<?= $_base ?>/sw.js?v=<?= e(defined('WT_VERSION') ? WT_VERSION : '0') ?>', { scope: '<?= $_base ?>/' })
        .then(function (reg) {
          // Détection de nouvelle version dispo
          reg.addEventListener('updatefound', function () {
            const newSW = reg.installing;
            if (!newSW) return;
            newSW.addEventListener('statechange', function () {
              if (newSW.state === 'installed' && navigator.serviceWorker.controller) {
                // Nouvelle version dispo → on l'active sans déranger l'utilisateur
                newSW.postMessage({ type: 'SKIP_WAITING' });
              }
            });
          });
        })
        .catch(function (err) {
          // Pas de bruit en console, juste un log silencieux
          console.warn('[PWA] SW registration failed:', err);
        });
    });
  }

  /* ---- 2) Bannière d'installation (Android / Chrome desktop / Edge) ---- */
  const STORAGE_KEY = 'wt_install_banner_dismissed_at';
  const COOLDOWN_DAYS = 14;  // si refus, on n'affiche plus pendant 14 jours

  function shouldShowBanner() {
    // Déjà installé ? on n'affiche rien
    if (window.matchMedia('(display-mode: standalone)').matches) return false;
    if (window.navigator.standalone === true) return false;  // iOS Safari standalone
    // Cooldown post-refus
    const dismissed = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
    if (dismissed && (Date.now() - dismissed) < COOLDOWN_DAYS * 24 * 60 * 60 * 1000) return false;
    return true;
  }

  function dismissBanner(banner) {
    localStorage.setItem(STORAGE_KEY, String(Date.now()));
    banner.classList.add('wt-pwa-banner--hidden');
    setTimeout(function () { banner.remove(); }, 400);
  }

  function makeBanner(html) {
    const div = document.createElement('div');
    div.className = 'wt-pwa-banner';
    div.innerHTML = html;
    document.body.appendChild(div);
    // Animation d'entrée après un court délai
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { div.classList.add('wt-pwa-banner--shown'); });
    });
    return div;
  }

  // Chrome / Edge / Samsung Internet : événement natif
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (!shouldShowBanner()) return;

    const banner = makeBanner(
      '<div class="wt-pwa-banner__icon">⚡</div>' +
      '<div class="wt-pwa-banner__text">' +
        '<strong>Installer Wintaskly</strong>' +
        '<small>Accède au faucet en un tap depuis ton écran d\'accueil</small>' +
      '</div>' +
      '<button class="wt-pwa-banner__install" type="button">Installer</button>' +
      '<button class="wt-pwa-banner__close" type="button" aria-label="Fermer">✕</button>'
    );

    banner.querySelector('.wt-pwa-banner__install').addEventListener('click', async function () {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const choice = await deferredPrompt.userChoice;
      deferredPrompt = null;
      if (choice.outcome === 'accepted') {
        banner.remove();
      } else {
        dismissBanner(banner);
      }
    });
    banner.querySelector('.wt-pwa-banner__close').addEventListener('click', function () {
      dismissBanner(banner);
    });
  });

  // iOS Safari : pas de beforeinstallprompt → on affiche une instruction
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const isInStandalone = window.navigator.standalone === true;
  if (isIOS && !isInStandalone && shouldShowBanner()) {
    window.addEventListener('load', function () {
      // Attendre 8s pour ne pas spammer dès l'arrivée
      setTimeout(function () {
        const banner = makeBanner(
          '<div class="wt-pwa-banner__icon">📱</div>' +
          '<div class="wt-pwa-banner__text">' +
            '<strong>Ajouter à l\'écran d\'accueil</strong>' +
            '<small>Appuie sur <span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border:1px solid currentColor;border-radius:4px;font-size:.85em;">⬆</span> puis « Sur l\'écran d\'accueil »</small>' +
          '</div>' +
          '<button class="wt-pwa-banner__close" type="button" aria-label="Fermer">✕</button>'
        );
        banner.querySelector('.wt-pwa-banner__close').addEventListener('click', function () {
          dismissBanner(banner);
        });
      }, 8000);
    });
  }

  /* ---- 3) Track des installations effectives ---- */
  window.addEventListener('appinstalled', function () {
    localStorage.removeItem(STORAGE_KEY);
    if (typeof gtag === 'function') {
      gtag('event', 'pwa_install', { event_category: 'engagement' });
    }
  });
})();
</script>

<?php
/* ============ TOASTS DE DÉBLOCAGE DE SUCCÈS ============
   Si award_user() a débloqué des badges pendant cette requête, ils sont
   dans $GLOBALS['__wt_ach_just_unlocked']. On les affiche en notifications
   animées. Le buffer est consommé (une seule fois). */
if (!empty($GLOBALS['__wt_ach_just_unlocked']) && is_array($GLOBALS['__wt_ach_just_unlocked'])):
    $achToasts = $GLOBALS['__wt_ach_just_unlocked'];
    $GLOBALS['__wt_ach_just_unlocked'] = []; // consommé
?>
<div id="wtAchToasts" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:10000;display:flex;flex-direction:column;gap:.75rem"></div>
<script>
(function () {
  var unlocked = <?= json_encode(array_map(function ($a) {
      return [
          'icon'  => $a['icon'] ?: '🏆',
          'title' => $a['title'],
          'coins' => $a['coins'],
          'xp'    => $a['xp'],
          'tier'  => $a['tier'],
      ];
  }, $achToasts), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?>;

  var container = document.getElementById('wtAchToasts');
  if (!container || !unlocked.length) return;

  var tierColors = {
    bronze:'#cd7f32', silver:'#c0c0c0', gold:'#ffd700',
    platinum:'#7dd3fc', special:'#c084fc'
  };

  unlocked.forEach(function (a, i) {
    setTimeout(function () {
      var toast = document.createElement('div');
      var color = tierColors[a.tier] || '#ffd700';
      toast.style.cssText =
        'display:flex;align-items:center;gap:.85rem;padding:1rem 1.25rem;' +
        'background:linear-gradient(135deg,#1a2138,#131829);' +
        'border:1.5px solid ' + color + ';border-radius:14px;' +
        'box-shadow:0 8px 28px rgba(0,0,0,.45),0 0 20px ' + color + '33;' +
        'color:#e8eaf0;min-width:260px;max-width:340px;' +
        'animation:wtAchToastIn .5s cubic-bezier(.2,.9,.3,1.4) forwards;cursor:pointer';

      var reward = '';
      if (a.coins > 0) reward += '💰 +' + a.coins;
      if (a.xp > 0)    reward += (reward ? ' · ' : '') + '⭐ +' + a.xp + ' XP';

      toast.innerHTML =
        '<div style="font-size:2rem;line-height:1">' + a.icon + '</div>' +
        '<div style="flex:1;min-width:0">' +
          '<div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:' + color + ';font-weight:700">' +
            <?= json_encode((string) t('ach.toast_unlocked'), JSON_UNESCAPED_UNICODE) ?> + '</div>' +
          '<div style="font-weight:700;font-size:.98rem;margin:.1rem 0">' + a.title + '</div>' +
          (reward ? '<div style="font-size:.82rem;opacity:.85">' + reward + '</div>' : '') +
        '</div>';

      toast.addEventListener('click', function () { toast.remove(); });
      container.appendChild(toast);

      // Auto-disparition après 6s
      setTimeout(function () {
        toast.style.transition = 'opacity .5s, transform .5s';
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(function () { toast.remove(); }, 500);
      }, 6000);
    }, i * 600); // décalage entre plusieurs badges
  });
})();
</script>
<style>
@keyframes wtAchToastIn {
  from { opacity:0; transform:translateX(40px) scale(.9); }
  to   { opacity:1; transform:translateX(0) scale(1); }
}
</style>
<?php endif; ?>

<?php
// Scripts publicitaires globaux à charger une seule fois avant </body>
// (Social Bar Adsterra, etc.). Gérés via /admin/ads.php.
if (function_exists('wt_ads_body_scripts')) {
    echo wt_ads_body_scripts();
}
?>
</body>
</html>
