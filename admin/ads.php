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
    } elseif ($action === 'save_adsterra') {
        // Popunder (head) + Social Bar (body) + bannières auto-responsive
        wt_config_set('ads.head_enabled', !empty($_POST['head_enabled']) ? '1' : '0');
        wt_config_set('ads.head_code', (string)($_POST['head_code'] ?? ''));
        wt_config_set('ads.body_enabled', !empty($_POST['body_enabled']) ? '1' : '0');
        wt_config_set('ads.body_code', (string)($_POST['body_code'] ?? ''));
        wt_config_set('ads.banner_728', (string)($_POST['banner_728'] ?? ''));
        wt_config_set('ads.banner_468', (string)($_POST['banner_468'] ?? ''));
        wt_config_set('ads.banner_300', (string)($_POST['banner_300'] ?? ''));
        $notice = t('admin.ads.saved');
    } elseif ($action === 'save_adsterra_api') {
        // Sauvegarde du token API + domain ID pour le dashboard de revenus
        wt_config_set('ads.adsterra_api_token', trim((string)($_POST['adsterra_api_token'] ?? '')));
        wt_config_set('ads.adsterra_domain_id', trim((string)($_POST['adsterra_domain_id'] ?? '')));
        $notice = t('admin.ads.saved');
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

// Adsterra : popunder (head), social bar (body), bannières auto-responsive
$headEnabled = (string) cfg('ads.head_enabled', '0') === '1';
$headCode    = (string) cfg('ads.head_code', '');
$bodyEnabled = (string) cfg('ads.body_enabled', '0') === '1';
$bodyCode    = (string) cfg('ads.body_code', '');
$banner728   = (string) cfg('ads.banner_728', '');
$banner468   = (string) cfg('ads.banner_468', '');
$banner300   = (string) cfg('ads.banner_300', '');

// API Publisher Adsterra (dashboard revenus)
$adsterraToken    = (string) cfg('ads.adsterra_api_token', '');
$adsterraDomainId = (string) cfg('ads.adsterra_domain_id', '');

// Récupération des stats si demandé (bouton "Actualiser les stats")
$statsData    = null;
$statsError   = null;
$statsStart   = date('Y-m-d', strtotime('-30 days'));
$statsFinish  = date('Y-m-d');
if (($_GET['stats'] ?? '') === '1' && $adsterraToken !== '') {
    // Permettre de personnaliser la période via GET (sinon 30 derniers jours)
    if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])) {
        $statsStart = (string) $_GET['from'];
    }
    if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])) {
        $statsFinish = (string) $_GET['to'];
    }
    $res = wt_adsterra_fetch_stats($statsStart, $statsFinish, 'date');
    if ($res['ok']) {
        $statsData = $res['items'];
    } else {
        $statsError = wt_adsterra_error_msg($res['error']);
    }
}

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

      <!-- ============ ADSTERRA (popunder, social bar, bannières auto) ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">📣 <?= e(t('admin.ads.adsterra_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.ads.adsterra_lead')) ?></p>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_adsterra">

          <!-- Popunder (head) -->
          <div style="margin-bottom:1.5rem">
            <label class="wt-check" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
              <input type="checkbox" name="head_enabled" value="1" <?= $headEnabled ? 'checked' : '' ?>>
              <strong><?= e(t('admin.ads.head_label')) ?></strong>
            </label>
            <small class="wt-muted" style="display:block;margin-bottom:.5rem"><?= e(t('admin.ads.head_hint')) ?></small>
            <textarea class="wt-input wt-mono" name="head_code" rows="3"
                      placeholder="&lt;script src=&quot;...&quot;&gt;&lt;/script&gt;"><?= e($headCode) ?></textarea>
          </div>

          <!-- Social Bar (body) -->
          <div style="margin-bottom:1.5rem">
            <label class="wt-check" style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
              <input type="checkbox" name="body_enabled" value="1" <?= $bodyEnabled ? 'checked' : '' ?>>
              <strong><?= e(t('admin.ads.body_label')) ?></strong>
            </label>
            <small class="wt-muted" style="display:block;margin-bottom:.5rem"><?= e(t('admin.ads.body_hint')) ?></small>
            <textarea class="wt-input wt-mono" name="body_code" rows="3"
                      placeholder="&lt;script src=&quot;...&quot;&gt;&lt;/script&gt;"><?= e($bodyCode) ?></textarea>
          </div>

          <!-- Bannières auto-responsive -->
          <h3 style="margin:1rem 0 .5rem"><?= e(t('admin.ads.banners_title')) ?></h3>
          <p class="wt-muted" style="font-size:.85rem;margin-bottom:1rem"><?= e(t('admin.ads.banners_hint')) ?></p>

          <div style="margin-bottom:1rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.ads.banner_728_label')) ?></strong></label>
            <textarea class="wt-input wt-mono" name="banner_728" rows="2"
                      placeholder="728x90"><?= e($banner728) ?></textarea>
          </div>
          <div style="margin-bottom:1rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.ads.banner_468_label')) ?></strong></label>
            <textarea class="wt-input wt-mono" name="banner_468" rows="2"
                      placeholder="468x60"><?= e($banner468) ?></textarea>
          </div>
          <div style="margin-bottom:1rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.ads.banner_300_label')) ?></strong></label>
            <textarea class="wt-input wt-mono" name="banner_300" rows="2"
                      placeholder="300x250"><?= e($banner300) ?></textarea>
          </div>

          <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
        </form>

        <div class="wt-alert wt-alert--info" style="margin-top:1rem;font-size:.85rem">
          💡 <?= e(t('admin.ads.adsterra_usage')) ?>
        </div>
      </section>

      <!-- ============ DASHBOARD REVENUS ADSTERRA (API) ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">📊 <?= e(t('admin.ads.stats_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.ads.stats_lead')) ?></p>

        <form method="post" style="margin-bottom:1rem">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_adsterra_api">
          <div style="margin-bottom:.8rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.ads.stats_token_label')) ?></strong></label>
            <input class="wt-input wt-mono" type="text" name="adsterra_api_token"
                   value="<?= e($adsterraToken) ?>" placeholder="X-API-Key">
            <small class="wt-muted"><?= e(t('admin.ads.stats_token_hint')) ?></small>
          </div>
          <div style="margin-bottom:.8rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.ads.stats_domain_label')) ?></strong></label>
            <input class="wt-input wt-mono" type="text" name="adsterra_domain_id"
                   value="<?= e($adsterraDomainId) ?>" placeholder="5873394">
            <small class="wt-muted"><?= e(t('admin.ads.stats_domain_hint')) ?></small>
          </div>
          <button class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
        </form>

        <?php if ($adsterraToken !== ''): ?>
          <a class="wt-btn wt-btn--ghost" href="?stats=1">🔄 <?= e(t('admin.ads.stats_refresh')) ?></a>

          <?php if ($statsError !== null): ?>
            <div class="wt-alert wt-alert--error" style="margin-top:1rem"><?= e($statsError) ?></div>
          <?php endif; ?>

          <?php if ($statsData !== null): ?>
            <?php
              // Calcul des totaux
              $totImpr = 0; $totClicks = 0; $totRev = 0.0;
              foreach ($statsData as $row) {
                  $totImpr   += (int)   ($row['impression'] ?? $row['impressions'] ?? 0);
                  $totClicks += (int)   ($row['clicks'] ?? 0);
                  $totRev    += (float) ($row['revenue'] ?? 0);
              }
              $avgCpm = $totImpr > 0 ? ($totRev / $totImpr * 1000) : 0;
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:1rem;margin-top:1.5rem">
              <div class="wt-card wt-card--padded" style="text-align:center">
                <small class="wt-muted"><?= e(t('admin.ads.stats_revenue')) ?></small><br>
                <strong style="font-size:1.4rem;color:#22c55e">$<?= e(number_format($totRev, 2)) ?></strong>
              </div>
              <div class="wt-card wt-card--padded" style="text-align:center">
                <small class="wt-muted"><?= e(t('admin.ads.stats_impressions')) ?></small><br>
                <strong style="font-size:1.4rem"><?= e(number_format($totImpr, 0, '.', ' ')) ?></strong>
              </div>
              <div class="wt-card wt-card--padded" style="text-align:center">
                <small class="wt-muted"><?= e(t('admin.ads.stats_clicks')) ?></small><br>
                <strong style="font-size:1.4rem"><?= e(number_format($totClicks, 0, '.', ' ')) ?></strong>
              </div>
              <div class="wt-card wt-card--padded" style="text-align:center">
                <small class="wt-muted"><?= e(t('admin.ads.stats_avg_cpm')) ?></small><br>
                <strong style="font-size:1.4rem">$<?= e(number_format($avgCpm, 3)) ?></strong>
              </div>
            </div>

            <p class="wt-muted" style="font-size:.82rem;margin-top:1rem">
              <?= e(sprintf((string) t('admin.ads.stats_period'), $statsStart, $statsFinish)) ?>
            </p>

            <?php if (!empty($statsData)): ?>
              <div style="overflow-x:auto;margin-top:1rem">
                <table class="wt-table" style="width:100%;font-size:.85rem">
                  <thead>
                    <tr>
                      <th><?= e(t('admin.ads.stats_date')) ?></th>
                      <th><?= e(t('admin.ads.stats_impressions')) ?></th>
                      <th><?= e(t('admin.ads.stats_clicks')) ?></th>
                      <th>CTR</th>
                      <th>CPM</th>
                      <th><?= e(t('admin.ads.stats_revenue')) ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($statsData as $row): ?>
                      <tr>
                        <td><?= e((string)($row['date'] ?? '—')) ?></td>
                        <td><?= e(number_format((int)($row['impression'] ?? $row['impressions'] ?? 0), 0, '.', ' ')) ?></td>
                        <td><?= e(number_format((int)($row['clicks'] ?? 0), 0, '.', ' ')) ?></td>
                        <td><?= e(number_format((float)($row['ctr'] ?? 0), 2)) ?>%</td>
                        <td>$<?= e(number_format((float)($row['cpm'] ?? 0), 3)) ?></td>
                        <td>$<?= e(number_format((float)($row['revenue'] ?? 0), 2)) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="wt-muted" style="margin-top:1rem"><?= e(t('admin.ads.stats_empty')) ?></p>
            <?php endif; ?>
          <?php endif; ?>
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
