<?php
/**
 * Wintaskly — Admin · Gestion des publicités
 *
 * Permet de gérer toutes les sources de revenus publicitaires :
 *   1. AdSense Auto Ads : un seul ID (ca-pub-XXXX) → Google place tout seul
 *   2. Zones manuelles : coller le code de chaque bloc pub (AdSense manuel,
 *      Media.net, A-ADS, ou n'importe quelle régie) zone par zone.
 *
 * Les zones sont stockées dans la table `ad_zones` et affichées dans les
 * pages via le helper wt_ad_zone('cle_de_zone').
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.ads');
$adminActive = 'ads';
$db          = db();
$notice      = null;
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_adsense') {
        $client = trim((string)($_POST['adsense_client'] ?? ''));
        // Validation format ca-pub-XXXXXXXXXXXXXXXX (ou vide pour désactiver)
        if ($client !== '' && !preg_match('/^ca-pub-\d{10,20}$/', $client)) {
            $error = t('admin.ads.client_invalid');
        } else {
            wt_config_set('ads.adsense_client', $client);
            wt_config_set('ads.adsense_auto', !empty($_POST['adsense_auto']) ? '1' : '0');
            $notice = t('admin.ads.saved');
        }
    } elseif ($action === 'save_zones' && !empty($_POST['zones']) && is_array($_POST['zones'])) {
        $stmt = $db->prepare("UPDATE ad_zones SET code = ?, active = ? WHERE k = ?");
        foreach ($_POST['zones'] as $k => $z) {
            $code   = (string)($z['code'] ?? '');
            $active = !empty($z['active']) ? 1 : 0;
            $key    = (string) $k;
            $stmt->bind_param('sis', $code, $active, $key);
            $stmt->execute();
        }
        $stmt->close();
        $notice = t('admin.ads.zones_saved');
    }
}

/* ====================== LECTURE ÉTAT ====================== */
$adsenseClient = (string) cfg('ads.adsense_client', '');
$adsenseAuto   = (string) cfg('ads.adsense_auto', '0') === '1';

// Toutes les zones, groupées par "page" via le préfixe de leur clé
$zones = [];
if ($res = $db->query("SELECT k, label, code, active FROM ad_zones ORDER BY k ASC")) {
    while ($r = $res->fetch_assoc()) {
        $zones[] = $r;
    }
    $res->free();
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">💰 <?= e(t('admin.ads.eyebrow')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.ads')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.ads.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

      <!-- ============ ADSENSE AUTO ADS ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">🤖 <?= e(t('admin.ads.auto_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.ads.auto_lead')) ?></p>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_adsense">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ads.client_id')) ?></span>
            <input class="wt-input wt-mono" type="text" name="adsense_client"
                   value="<?= e($adsenseClient) ?>"
                   placeholder="ca-pub-1234567890123456"
                   pattern="ca-pub-[0-9]{10,20}">
            <small class="wt-field__hint"><?= e(t('admin.ads.client_hint')) ?></small>
          </label>

          <label class="wt-checkbox" style="margin:1rem 0;display:flex;gap:.75rem;align-items:flex-start">
            <input type="checkbox" name="adsense_auto" value="1" <?= $adsenseAuto ? 'checked' : '' ?>
                   style="margin-top:.3rem;transform:scale(1.4)">
            <span>
              <strong><?= e(t('admin.ads.auto_enable')) ?></strong>
              <small class="wt-muted" style="display:block;margin-top:.3rem">
                <?= e(t('admin.ads.auto_enable_hint')) ?>
              </small>
            </span>
          </label>

          <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
        </form>

        <?php if ($adsenseClient !== '' && $adsenseAuto): ?>
          <div class="wt-alert wt-alert--success" style="margin-top:1rem">
            ✅ <?= e(sprintf((string) t('admin.ads.auto_active'), $adsenseClient)) ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- ============ ZONES MANUELLES ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">🎯 <?= e(t('admin.ads.zones_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.ads.zones_lead')) ?></p>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_zones">

          <?php foreach ($zones as $z):
            $k = $z['k'];
            // Détecte si la zone contient encore le placeholder de démo
            $isPlaceholder = (trim(preg_replace('/<!--.*?-->/s', '', (string)$z['code'])) === '');
          ?>
            <div style="border:1px solid var(--wt-border, #2a3252);border-radius:12px;padding:1rem;margin-bottom:1rem">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.5rem">
                <strong style="font-size:.95rem">
                  <?= e($z['label']) ?>
                  <code style="font-size:.75rem;opacity:.6;font-weight:400">(<?= e($k) ?>)</code>
                </strong>
                <div style="display:flex;gap:.75rem;align-items:center">
                  <?php if ($isPlaceholder): ?>
                    <span style="font-size:.7rem;background:var(--wt-bg-soft);padding:.2rem .5rem;border-radius:6px;opacity:.7">
                      <?= e(t('admin.ads.zone_empty')) ?>
                    </span>
                  <?php else: ?>
                    <span style="font-size:.7rem;background:#22c55e;color:#fff;padding:.2rem .5rem;border-radius:6px">
                      <?= e(t('admin.ads.zone_filled')) ?>
                    </span>
                  <?php endif; ?>
                  <label class="wt-checkbox" style="display:flex;gap:.4rem;align-items:center;font-size:.85rem">
                    <input type="checkbox" name="zones[<?= e($k) ?>][active]" value="1" <?= (int)$z['active'] === 1 ? 'checked' : '' ?>>
                    <?= e(t('admin.ads.zone_active')) ?>
                  </label>
                </div>
              </div>
              <textarea name="zones[<?= e($k) ?>][code]" rows="3"
                        class="wt-input wt-mono" style="font-size:.8rem"
                        placeholder="<?= e(t('admin.ads.zone_placeholder')) ?>"><?= e((string)$z['code']) ?></textarea>
            </div>
          <?php endforeach; ?>

          <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
        </form>
      </section>

      <!-- ============ AIDE ============ -->
      <details style="margin-top:1.5rem">
        <summary style="cursor:pointer;padding:.75rem 1rem;background:var(--wt-bg-soft);border-radius:8px">
          ❓ <?= e(t('admin.ads.help_title')) ?>
        </summary>
        <div class="wt-card wt-card--padded" style="margin-top:.75rem">
          <h3><?= e(t('admin.ads.help_auto_h')) ?></h3>
          <p style="line-height:1.7"><?= e(t('admin.ads.help_auto_p')) ?></p>
          <h3 style="margin-top:1rem"><?= e(t('admin.ads.help_manual_h')) ?></h3>
          <p style="line-height:1.7"><?= e(t('admin.ads.help_manual_p')) ?></p>
          <h3 style="margin-top:1rem"><?= e(t('admin.ads.help_other_h')) ?></h3>
          <p style="line-height:1.7"><?= e(t('admin.ads.help_other_p')) ?></p>
        </div>
      </details>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
