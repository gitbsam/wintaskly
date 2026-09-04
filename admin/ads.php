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

/* Balises <meta>/<script> injectées dans le <head>, page par page.
   Lecture, écriture atomique et correspondance des motifs : voir
   includes/ad_tags.php. */
$rules = wt_ad_tags_load();

/* Pages proposées à la sélection, groupées par section. La liste vit
   dans includes/ad_tags.php, au plus près de la fonction qui compare les
   motifs : une liste qui dérive du comparateur produirait des motifs qui
   ne correspondent à aucune page, sans que rien ne le signale. */
$pageGroups = wt_ad_tags_available_pages();

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
        // Popunder (head) + Social Bar (body). Les trois champs de
        // « bannière auto-responsive » (ads.banner_728/468/300) ont été
        // retirés en 9.28.0 : aucune page n'appelait wt_ad_banner_auto(),
        // les codes saisis là ne s'affichaient donc nulle part. Les
        // bannières se gèrent zone par zone, plus bas dans cette page.
        wt_config_set('ads.head_enabled', !empty($_POST['head_enabled']) ? '1' : '0');
        wt_config_set('ads.head_code', (string)($_POST['head_code'] ?? ''));
        wt_config_set('ads.body_enabled', !empty($_POST['body_enabled']) ? '1' : '0');
        wt_config_set('ads.body_code', (string)($_POST['body_code'] ?? ''));
        $notice = t('admin.ads.saved');
    } elseif ($action === 'save_adsterra_api') {
        // Sauvegarde du token API + domain ID pour le dashboard de revenus.
        // Le token est un secret → chiffré en base (cohérent avec les autres
        // credentials). Préservation : si le champ est vide, on garde l'existant.
        $tokenInput = trim((string)($_POST['adsterra_api_token'] ?? ''));
        if ($tokenInput !== '') {
            $tokenStore = function_exists('wt_encrypt') ? wt_encrypt($tokenInput) : $tokenInput;
            wt_config_set('ads.adsterra_api_token', $tokenStore);
        }
        // (si vide : on ne touche pas au token existant)
        wt_config_set('ads.adsterra_domain_id', trim((string)($_POST['adsterra_domain_id'] ?? '')));
        $notice = t('admin.ads.saved');
    } elseif ($action === 'save_zones') {
        // La détection d'action est séparée de la validation des données :
        // si zones est absent/vide, on le signale au lieu d'ignorer en silence.
        $zonesPost = $_POST['zones'] ?? null;
        if (!is_array($zonesPost) || $zonesPost === []) {
            $error = t('admin.ads.zones_empty');
        } else {
            $stmt = $db->prepare("UPDATE ad_zones SET code = ?, active = ? WHERE k = ?");
            $updated = 0;
            foreach ($zonesPost as $k => $z) {
                $code   = (string)($z['code'] ?? '');
                $active = !empty($z['active']) ? 1 : 0;
                $key    = (string) $k;
                $stmt->bind_param('sis', $code, $active, $key);
                $stmt->execute();
                $updated += $stmt->affected_rows > 0 ? 1 : 0;
            }
            $stmt->close();
            $notice = t('admin.ads.zones_saved');
        }
    } elseif ($action === 'save_rule') {
        $id            = (string) ($_POST['rule_id'] ?? '');
        $selectedPages = is_array($_POST['pages'] ?? null) ? array_map('strval', $_POST['pages']) : [];
        $tagContent    = trim((string) ($_POST['tag_content'] ?? ''));
        $label         = trim((string) ($_POST['label'] ?? ''));
        $isActive      = !empty($_POST['is_active']);
        $needsConsent  = !empty($_POST['needs_consent']);

        // Le champ écrit directement dans le <head> : on refuse tout ce
        // qui n'est ni <meta> ni <script>.
        $invalid = wt_ad_tags_validate($tagContent);

        if ($selectedPages === []) {
            $error = t('admin.adtags.err_nopage');
        } elseif ($invalid !== '') {
            $error = t($invalid);
        } else {
            if ($id !== '') {
                foreach ($rules as $i => $r) {
                    if ($r['id'] === $id) {
                        $rules[$i]['label']         = $label;
                        $rules[$i]['pages']         = $selectedPages;
                        $rules[$i]['tag_content']   = $tagContent;
                        $rules[$i]['active']        = $isActive;
                        $rules[$i]['needs_consent'] = $needsConsent;
                        break;
                    }
                }
            } else {
                $rules[] = [
                    'id'            => uniqid('tag_'),
                    'label'         => $label,
                    'pages'         => $selectedPages,
                    'tag_content'   => $tagContent,
                    'active'        => $isActive,
                    'needs_consent' => $needsConsent,
                ];
            }

            // Une écriture qui échoue doit se voir : sur hébergement
            // mutualisé, un dossier en lecture seule est un cas réel, et
            // l'administrateur croirait avoir enregistré.
            if (wt_ad_tags_save($rules)) {
                wt_admin_log('ad_tags.save', ['id' => $id !== '' ? $id : 'new']);
                $notice = t('admin.ads.saved');
            } else {
                $error = t('admin.adtags.err_write');
            }
        }
    } elseif ($action === 'delete_rule') {
        $id    = (string) ($_POST['rule_id'] ?? '');
        $rules = array_values(array_filter($rules, static fn($r) => $r['id'] !== $id));
        if (wt_ad_tags_save($rules)) {
            wt_admin_log('ad_tags.delete', ['id' => $id]);
            $notice = t('admin.adtags.deleted');
        } else {
            $error = t('admin.adtags.err_write');
        }
    } elseif ($action === 'toggle_rule') {
        $id = (string) ($_POST['rule_id'] ?? '');
        foreach ($rules as $i => $r) {
            if ($r['id'] === $id) {
                $rules[$i]['active'] = !$r['active'];
                break;
            }
        }
        if (wt_ad_tags_save($rules)) {
            wt_admin_log('ad_tags.toggle', ['id' => $id]);
            $notice = t('admin.ads.saved');
        } else {
            $error = t('admin.adtags.err_write');
        }
    } else {
        // Action POST non reconnue : on le signale au lieu d'ignorer en silence
        $error = t('admin.ads.unknown_action');
    }
}

// Mode édition : récupérer la règle si on clique sur "Modifier"
$editRule = null;
if (isset($_GET['edit'])) {
    foreach ($rules as $r) {
        if ($r['id'] === $_GET['edit']) { $editRule = $r; break; }
    }
}
$editPages = $editRule['pages'] ?? [];

/* ====================== LECTURE ÉTAT ====================== */
$adsenseClient = (string) cfg('ads.adsense_client', '');
$adsenseAuto   = (string) cfg('ads.adsense_auto', '0') === '1';

// Adsterra : popunder (head) et social bar (body), scripts globaux
$headEnabled = (string) cfg('ads.head_enabled', '0') === '1';
$headCode    = (string) cfg('ads.head_code', '');
$bodyEnabled = (string) cfg('ads.body_enabled', '0') === '1';
$bodyCode    = (string) cfg('ads.body_code', '');

// API Publisher Adsterra (dashboard revenus)
$adsterraToken    = (string) cfg('ads.adsterra_api_token', '');
// On ne montre jamais le token (placeholder seulement). La fonction
// wt_adsterra_fetch_stats() le relit et le déchiffre elle-même au besoin.
$adsterraTokenSet = $adsterraToken !== '';
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
            <input class="wt-input wt-mono" type="password" name="adsterra_api_token"
                   autocomplete="new-password"
                   placeholder="<?= $adsterraTokenSet ? '••••••••••••••••• (token enregistré)' : 'X-API-Key' ?>">
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

      <!-- ============ BALISES <head> PAR PAGE ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:2rem">
        <h2 style="margin-top:0">🎯 <?= e(t('admin.adtags.title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.adtags.lead')) ?></p>

        <!-- Formulaire d'ajout / modification -->
        <form method="post" class="wt-form" style="margin-bottom:1.5rem;padding:1rem;border:1px solid var(--wt-border);border-radius:var(--wt-radius-md)">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_rule">
          <input type="hidden" name="rule_id" value="<?= e($editRule['id'] ?? '') ?>">

          <div style="margin-bottom:1rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.adtags.label_name')) ?></strong></label>
            <input type="text" class="wt-input" name="label" maxlength="80"
                   placeholder="<?= e(t('admin.adtags.label_name_ph')) ?>"
                   value="<?= e($editRule['label'] ?? '') ?>">
          </div>

          <!-- Pages ciblées -->
          <div style="margin-bottom:1rem">
            <label style="display:block;margin-bottom:.5rem"><strong><?= e(t('admin.adtags.pages')) ?></strong></label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:.7rem;padding:.8rem;border:1px solid var(--wt-border);border-radius:var(--wt-radius-md);background:var(--wt-bg-soft)">
              <?php foreach ($pageGroups as $groupName => $groupPages): ?>
                <fieldset style="border:1px solid var(--wt-border);border-radius:var(--wt-radius-md);padding:.6rem .8rem;margin:0">
                  <legend style="font-size:.8rem;font-weight:700;padding:0 .3rem"><?= e((string) $groupName) ?></legend>
                  <?php foreach ($groupPages as $routeKey => $pageName): ?>
                    <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.85rem;padding:.15rem 0">
                      <input type="checkbox" name="pages[]" value="<?= e($routeKey) ?>"
                             <?= in_array($routeKey, (array) $editPages, true) ? 'checked' : '' ?>
                             style="width:16px;height:16px;flex:none">
                      <span><?= e((string) $pageName) ?> <small class="wt-muted"><?= e($routeKey) ?></small></span>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
              <?php endforeach; ?>
            </div>
            <small class="wt-muted"><?= e(t('admin.adtags.pages_hint')) ?></small>
          </div>

          <!-- Balise -->
          <div style="margin-bottom:.8rem">
            <label style="display:block;margin-bottom:.3rem"><strong><?= e(t('admin.adtags.code')) ?></strong></label>
            <textarea class="wt-input wt-mono" name="tag_content" rows="3" required
                      placeholder="<?= e('<meta name="exemple-verification" content="…">') ?>"><?= e($editRule['tag_content'] ?? '') ?></textarea>
            <small class="wt-muted"><?= e(t('admin.adtags.code_hint')) ?></small>
          </div>

          <div style="margin-bottom:.6rem;display:flex;align-items:center;gap:.5rem">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                   <?= ($editRule['active'] ?? true) ? 'checked' : '' ?> style="width:18px;height:18px">
            <label for="is_active" style="cursor:pointer"><strong><?= e(t('admin.adtags.activate')) ?></strong></label>
          </div>

          <div style="margin-bottom:1rem;display:flex;align-items:flex-start;gap:.5rem">
            <input type="checkbox" id="needs_consent" name="needs_consent" value="1"
                   <?= !empty($editRule['needs_consent']) ? 'checked' : '' ?> style="width:18px;height:18px;margin-top:.15rem">
            <label for="needs_consent" style="cursor:pointer">
              <strong><?= e(t('admin.adtags.consent')) ?></strong><br>
              <small class="wt-muted"><?= e(t('admin.adtags.consent_hint')) ?></small>
            </label>
          </div>

          <div style="display:flex;gap:.5rem">
            <button class="wt-btn wt-btn--primary"><?= e($editRule ? t('admin.adtags.update') : t('admin.adtags.add')) ?></button>
            <?php if ($editRule): ?>
              <a href="?" class="wt-btn wt-btn--ghost"><?= e(t('common.cancel')) ?></a>
            <?php endif; ?>
          </div>
        </form>

        <!-- Liste -->
        <h3 style="font-size:1.1rem;margin-bottom:.8rem"><?= e(t('admin.adtags.configured')) ?></h3>
        <?php if ($rules !== []): ?>
          <div style="overflow-x:auto">
            <table class="wt-table" style="width:100%;font-size:.85rem">
              <thead>
                <tr>
                  <th style="width:60px"><?= e(t('admin.adtags.col_status')) ?></th>
                  <th><?= e(t('admin.adtags.col_pages')) ?></th>
                  <th><?= e(t('admin.adtags.col_code')) ?></th>
                  <th style="text-align:right;width:150px"><?= e(t('common.actions')) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rules as $r):
                  /* Une balise <script> dont le domaine n'est pas déclaré dans
                     ad_networks sera bloquée par la CSP, sans message : on le
                     signale ici plutôt que de laisser chercher. */
                  $tagBlocked = wt_ad_code_blocked_hosts((string) $r['tag_content']);
                ?>
                  <tr>
                    <td style="text-align:center">
                      <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_rule">
                        <input type="hidden" name="rule_id" value="<?= e($r['id']) ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:1.2rem"
                                title="<?= e(t('admin.adtags.toggle')) ?>"><?= $r['active'] ? '🟢' : '🔴' ?></button>
                      </form>
                    </td>
                    <td>
                      <?php if (($r['label'] ?? '') !== ''): ?>
                        <strong><?= e((string) $r['label']) ?></strong><br>
                      <?php endif; ?>
                      <code><?= e(implode(', ', $r['pages'])) ?></code>
                      <?php if (!empty($r['needs_consent'])): ?>
                        <br><small class="wt-muted">🍪 <?= e(t('admin.adtags.consent_badge')) ?></small>
                      <?php endif; ?>
                    </td>
                    <td class="wt-mono" style="max-width:300px">
                      <code style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e((string) $r['tag_content']) ?></code>
                      <?php if ($tagBlocked !== []): ?>
                        <small style="color:#f59e0b">⚠️ <?= e(sprintf((string) t('admin.adtags.csp_blocked'), implode(', ', $tagBlocked))) ?></small>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                      <a href="?edit=<?= e($r['id']) ?>" class="wt-btn wt-btn--ghost" style="padding:2px 8px;font-size:.8rem"><?= e(t('common.edit')) ?></a>
                      <form method="post" style="display:inline"
                            onsubmit="return confirm('<?= e(t('admin.adtags.confirm_delete')) ?>');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?= e($r['id']) ?>">
                        <button type="submit" class="wt-btn wt-btn--ghost" style="padding:2px 8px;font-size:.8rem;color:#ef4444"><?= e(t('common.delete')) ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="wt-muted"><?= e(t('admin.adtags.empty')) ?></p>
        <?php endif; ?>
      </section>

      <!-- ============ ZONES MANUELLES ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">🎯 <?= e(t('admin.ads.zones_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.ads.zones_lead')) ?></p>
        <div class="wt-alert wt-alert--info" style="font-size:.85rem;margin-bottom:1rem">
          🍪 <?= e(t('admin.ads.zones_consent_note')) ?>
        </div>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_zones">

          <?php foreach ($zones as $z):
            $k = $z['k'];
            // Détecte si la zone contient encore le placeholder de démo
            $isPlaceholder = (trim(preg_replace('/<!--.*?-->/s', '', (string)$z['code'])) === '');
            /* Domaines appelés par le code mais absents de la CSP : le
               navigateur les bloquera en silence (voir la fonction). */
            $blockedHosts = $isPlaceholder ? [] : wt_ad_code_blocked_hosts((string)$z['code']);
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
              <?php if ($blockedHosts !== []): ?>
                <div class="wt-alert wt-alert--warn" style="margin-top:.6rem;font-size:.82rem">
                  ⚠️ <?= e(sprintf((string) t('admin.ads.zone_csp_blocked'), implode(', ', $blockedHosts))) ?>
                </div>
              <?php endif; ?>
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
