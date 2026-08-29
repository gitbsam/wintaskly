<?php
/**
 * Wintaskly — Admin · Audit des emplacements publicitaires
 * ----------------------------------------------------------------------
 * POURQUOI CETTE PAGE EXISTE
 *
 * Quand une publicité ne s'affiche pas, rien ne le dit. Le navigateur
 * bloque un script interdit par la politique de sécurité sans message,
 * une zone laissée sur son texte d'exemple ne produit rien, un visiteur
 * qui a refusé les cookies ne verra jamais le code d'une régie — et dans
 * les trois cas, l'écran est identique : un emplacement vide.
 *
 * Diagnostiquer cela à l'œil demande de croiser quatre tables et l'état
 * du navigateur. Cette page fait le croisement et donne, emplacement par
 * emplacement, la raison exacte de ce qui est servi.
 *
 * Elle ne modifie rien : c'est un constat, pas un réglage. Les
 * corrections se font dans /admin/ads.php (codes), /admin/ad-networks.php
 * (régies) et /admin/banners.php (bannières maison).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.adscheck.title');
$adminActive = 'adscheck';
$db          = db();

/* ----------------------------------------------------------------------
 * Etat global : ce qui vaut pour toutes les zones à la fois.
 * -------------------------------------------------------------------- */

// La table des régies conditionne TOUT : sans elle, init.php n'autorise
// aucun domaine dans la CSP et le navigateur bloque chaque script de
// régie, silencieusement.
$hasNetworksTable = false;
$networks         = [];
if ($res = $db->query("SHOW TABLES LIKE 'ad_networks'")) {
    $hasNetworksTable = $res->num_rows > 0;
    $res->free();
}
if ($hasNetworksTable) {
    if ($res = $db->query("SELECT k, name, active, script_domains FROM ad_networks ORDER BY sort_order, k")) {
        $networks = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
}
$activeNetworks = array_values(array_filter($networks, static fn($n) => (int) $n['active'] === 1));

// Consentement du navigateur qui consulte cette page. C'est la cause la
// plus fréquente d'un « je ne vois rien » : l'administrateur teste son
// site sans avoir accepté les cookies publicitaires, et ne voit donc
// jamais les codes des régies — exactement comme un visiteur qui refuse.
$consentRaw   = (string) ($_COOKIE['wt_consent'] ?? '');
$consentAds   = wt_consent_allows('ads');

// Bannières maison disponibles, par format : ce sont elles qui prennent
// le relais quand aucun code de régie ne peut être servi.
$bannersBySize = [];
if ($res = $db->query("SELECT size_key, COUNT(*) AS n FROM ad_banners WHERE active = 1 GROUP BY size_key")) {
    while ($r = $res->fetch_assoc()) {
        $bannersBySize[(string) $r['size_key']] = (int) $r['n'];
    }
    $res->free();
}

/* ----------------------------------------------------------------------
 * Analyse zone par zone.
 * -------------------------------------------------------------------- */
$zones = [];
if ($res = $db->query("SELECT k, label, code, banner_id, size_key, active FROM ad_zones ORDER BY k ASC")) {
    $zones = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

$rows    = [];
$counts  = ['regie' => 0, 'maison' => 0, 'defaut' => 0, 'vide' => 0];

foreach ($zones as $z) {
    $code        = (string) $z['code'];
    // Une zone « remplie » d'un simple commentaire d'exemple est vide.
    $realCode    = trim(preg_replace('/<!--.*?-->/s', '', $code));
    $hasCode     = $realCode !== '';
    $isActive    = (int) $z['active'] === 1;
    $sizeKey     = $z['size_key'] !== null ? (string) $z['size_key'] : null;
    $blocked     = $hasCode ? wt_ad_code_blocked_hosts($code) : [];
    $hasBanner   = $z['banner_id'] !== null;
    $poolSize    = $sizeKey !== null ? ($bannersBySize[$sizeKey] ?? 0) : 0;

    // Verdict : ce que wt_ad_zone() servira réellement, et pourquoi.
    // L'ordre suit exactement celui de la fonction, sans quoi le
    // diagnostic décrirait un comportement qui n'est pas celui du site.
    if (!$isActive) {
        $verdict = 'vide';
        $why     = t('admin.adscheck.why_inactive');
    } elseif ($hasCode && !$consentAds) {
        $verdict = $hasBanner ? 'maison' : ($poolSize > 0 ? 'maison' : ($sizeKey !== null ? 'defaut' : 'vide'));
        $why     = t('admin.adscheck.why_no_consent');
    } elseif ($hasCode && $blocked !== []) {
        // Le code sera bien écrit dans la page, mais le navigateur
        // refusera de charger son script : à l'écran, c'est un trou.
        $verdict = 'regie';
        $why     = sprintf((string) t('admin.adscheck.why_csp'), implode(', ', $blocked));
    } elseif ($hasCode) {
        $verdict = 'regie';
        $why     = t('admin.adscheck.why_ok');
    } elseif ($hasBanner) {
        $verdict = 'maison';
        $why     = t('admin.adscheck.why_banner');
    } elseif ($poolSize > 0) {
        $verdict = 'maison';
        $why     = sprintf((string) t('admin.adscheck.why_rotation'), $poolSize, (string) $sizeKey);
    } elseif ($sizeKey !== null) {
        $verdict = 'defaut';
        $why     = t('admin.adscheck.why_default');
    } else {
        $verdict = 'vide';
        $why     = t('admin.adscheck.why_nosize');
    }

    $counts[$verdict]++;
    $rows[] = [
        'k'       => (string) $z['k'],
        'label'   => (string) $z['label'],
        'size'    => $sizeKey ?? '—',
        'verdict' => $verdict,
        'why'     => $why,
        'blocked' => $blocked,
    ];
}

$badge = [
    'regie'  => ['#22c55e', t('admin.adscheck.v_regie')],
    'maison' => ['#3b82f6', t('admin.adscheck.v_maison')],
    'defaut' => ['#f59e0b', t('admin.adscheck.v_defaut')],
    'vide'   => ['#ef4444', t('admin.adscheck.v_vide')],
];

include __DIR__ . '/../header.php';
?>
<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
  <div class="wt-admin-v2__wrap">

    <header class="wt-admin-v2__page-header">
      <div>
        <h1 class="wt-admin-v2__title">🔎 <?= e(t('admin.adscheck.title')) ?></h1>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.adscheck.lead')) ?></p>
      </div>
    </header>

    <!-- ============ ÉTAT GLOBAL ============ -->
    <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
      <h2 style="margin-top:0"><?= e(t('admin.adscheck.global_title')) ?></h2>

      <?php if (!$hasNetworksTable): ?>
        <div class="wt-alert wt-alert--error">
          ⛔ <?= e(t('admin.adscheck.no_table')) ?>
        </div>
      <?php elseif ($activeNetworks === []): ?>
        <div class="wt-alert wt-alert--warn">
          ⚠️ <?= e(t('admin.adscheck.no_active_network')) ?>
          <a href="<?= e(wt_url('/admin/ad-networks.php')) ?>"><?= e(t('admin.adscheck.go_networks')) ?></a>
        </div>
      <?php else: ?>
        <div class="wt-alert wt-alert--success">
          ✅ <?= e(sprintf(
                (string) t('admin.adscheck.networks_active'),
                implode(', ', array_map(static fn($n) => (string) $n['name'], $activeNetworks))
              )) ?>
        </div>
      <?php endif; ?>

      <?php if ($consentRaw === ''): ?>
        <div class="wt-alert wt-alert--warn">
          🍪 <?= e(t('admin.adscheck.consent_none')) ?>
        </div>
      <?php elseif (!$consentAds): ?>
        <div class="wt-alert wt-alert--warn">
          🍪 <?= e(t('admin.adscheck.consent_refused')) ?>
        </div>
      <?php else: ?>
        <div class="wt-alert wt-alert--success">
          🍪 <?= e(t('admin.adscheck.consent_ok')) ?>
        </div>
      <?php endif; ?>

      <p class="wt-muted" style="font-size:.85rem;margin-bottom:0">
        <?= e(sprintf(
              (string) t('admin.adscheck.summary'),
              $counts['regie'], $counts['maison'], $counts['defaut'], $counts['vide']
            )) ?>
      </p>
    </section>

    <!-- ============ DÉTAIL PAR EMPLACEMENT ============ -->
    <section class="wt-card wt-card--padded">
      <h2 style="margin-top:0"><?= e(t('admin.adscheck.zones_title')) ?></h2>
      <div style="overflow-x:auto">
        <table class="wt-table">
          <thead>
            <tr>
              <th><?= e(t('admin.adscheck.col_zone')) ?></th>
              <th><?= e(t('admin.adscheck.col_size')) ?></th>
              <th><?= e(t('admin.adscheck.col_served')) ?></th>
              <th><?= e(t('admin.adscheck.col_why')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              [$color, $text] = $badge[$r['verdict']];
            ?>
              <tr>
                <td>
                  <strong><?= e($r['label']) ?></strong><br>
                  <code style="font-size:.75rem;opacity:.6"><?= e($r['k']) ?></code>
                </td>
                <td><?= e($r['size']) ?></td>
                <td>
                  <span style="font-size:.72rem;background:<?= e($color) ?>;color:#fff;padding:.2rem .5rem;border-radius:6px;white-space:nowrap">
                    <?= e((string) $text) ?>
                  </span>
                </td>
                <td style="font-size:.85rem"><?= e($r['why']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </div>
  </section>
  </div>
</main>
<?php include __DIR__ . '/../footer.php'; ?>
