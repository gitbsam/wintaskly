<?php
/**
 * Wintaskly — Admin · Paramètres globaux du site (V8).
 *
 * Permet de configurer tout ce qui peut être ajusté après l'install :
 *   - SEO : meta description, OG, Twitter, robots
 *   - Tracking : Google Analytics, AdSense, Facebook Pixel, Matomo
 *   - Économie : récompenses faucet, cooldowns, taux référral, XP par niveau
 *   - Classement : récompenses XP rang 1-2-3, activation des bonus
 *   - Email & SMTP : adresses, optionnellement serveur SMTP custom
 *
 * Stockage : table `config` (clés/valeurs) via cfg_set().
 * UI : onglets natifs (anchors #seo, #tracking, …) avec JS minimal pour
 * la persistance via localStorage.
 *
 * Sécurité : require_admin + CSRF + validation par champ.
 */

require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.settings');
$adminActive = 'settings';
$db          = db();
$notice      = null;
$noticeKind  = null;  // 'success' | 'error'
$errors      = [];
$activeTab   = $_POST['_tab'] ?? $_GET['tab'] ?? 'seo';

/* ----------------------------------------------------------------------
 * Définition des paramètres par onglet — single source of truth.
 *
 * Chaque entrée :
 *   - 'key'      → nom dans la table config
 *   - 'label'    → label i18n
 *   - 'type'     → text|textarea|url|email|number|checkbox|password
 *   - 'hint'     → aide i18n (optionnel)
 *   - 'maxlen'   → longueur max (text/textarea)
 *   - 'validate' → callable($val): ?string (returns error message or null)
 *   - 'sensitive'→ true = champ password (masqué à l'affichage)
 * ---------------------------------------------------------------------- */
$schema = [
    'seo' => [
        ['key' => 'seo.meta_description',  'label' => 'admin.set.seo.meta_description',  'type' => 'textarea', 'hint' => 'admin.set.seo.meta_description_hint',  'maxlen' => 160],
        ['key' => 'seo.meta_keywords',     'label' => 'admin.set.seo.meta_keywords',     'type' => 'textarea', 'hint' => 'admin.set.seo.meta_keywords_hint',     'maxlen' => 255],
        ['key' => 'seo.og_image_url',      'label' => 'admin.set.seo.og_image_url',      'type' => 'url',      'hint' => 'admin.set.seo.og_image_url_hint'],
        ['key' => 'seo.twitter_handle',    'label' => 'admin.set.seo.twitter_handle',    'type' => 'text',     'hint' => 'admin.set.seo.twitter_handle_hint',    'maxlen' => 32],
        ['key' => 'seo.robots_index',      'label' => 'admin.set.seo.robots_index',      'type' => 'checkbox', 'hint' => 'admin.set.seo.robots_index_hint'],
        ['key' => 'launch_year',           'label' => 'admin.set.seo.launch_year',       'type' => 'number',   'hint' => 'admin.set.seo.launch_year_hint',
         'min' => 2020, 'max' => 2100],
    ],
    'tracking' => [
        ['key' => 'tracking.google_analytics_id',  'label' => 'admin.set.tracking.ga_id',         'type' => 'text', 'hint' => 'admin.set.tracking.ga_id_hint',         'maxlen' => 32,
         'validate' => fn($v) => $v === '' || preg_match('/^(G|UA|GTM)-[A-Z0-9-]+$/i', $v) ? null : 'Format attendu : G-XXXXXXXXXX, UA-… ou GTM-…'],
        ['key' => 'tracking.google_adsense_client','label' => 'admin.set.tracking.adsense_client','type' => 'text', 'hint' => 'admin.set.tracking.adsense_client_hint','maxlen' => 32,
         'validate' => fn($v) => $v === '' || preg_match('/^ca-pub-[0-9]+$/', $v) ? null : 'Format attendu : ca-pub-XXXXXXXXXXXXXXXX'],
        ['key' => 'tracking.adsense_auto_ads',     'label' => 'admin.set.tracking.adsense_auto', 'type' => 'checkbox','hint'=> 'admin.set.tracking.adsense_auto_hint'],
        ['key' => 'tracking.facebook_pixel_id',    'label' => 'admin.set.tracking.fb_pixel',     'type' => 'text', 'hint' => 'admin.set.tracking.fb_pixel_hint',      'maxlen' => 32],
        ['key' => 'tracking.matomo_url',           'label' => 'admin.set.tracking.matomo_url',   'type' => 'url',  'hint' => 'admin.set.tracking.matomo_url_hint'],
        ['key' => 'tracking.matomo_site_id',       'label' => 'admin.set.tracking.matomo_site',  'type' => 'number','hint'=> 'admin.set.tracking.matomo_site_hint'],
    ],
    'economy' => [
        ['key' => 'faucet_reward_coins',      'label' => 'admin.set.eco.faucet_coins',     'type' => 'number', 'hint' => 'admin.set.eco.faucet_coins_hint',  'step' => '0.01', 'min' => 0],
        ['key' => 'faucet_reward_xp',         'label' => 'admin.set.eco.faucet_xp',        'type' => 'number', 'hint' => 'admin.set.eco.faucet_xp_hint',     'min' => 0],
        ['key' => 'faucet_cooldown_seconds',  'label' => 'admin.set.eco.faucet_cooldown',  'type' => 'number', 'hint' => 'admin.set.eco.faucet_cooldown_hint', 'min' => 1],
        ['key' => 'referral_commission_pct',  'label' => 'admin.set.eco.referral_pct',     'type' => 'number', 'hint' => 'admin.set.eco.referral_pct_hint',  'min' => 0, 'max' => 100],
        ['key' => 'xp_per_level',             'label' => 'admin.set.eco.xp_per_level',     'type' => 'number', 'hint' => 'admin.set.eco.xp_per_level_hint',  'min' => 1],
        ['key' => 'withdrawal_min_default',   'label' => 'admin.set.eco.wd_min_default',   'type' => 'number', 'hint' => 'admin.set.eco.wd_min_default_hint','min' => 0],
    ],
    'leaderboard' => [
        ['key' => 'leaderboard.rewards_enabled','label'=>'admin.set.lb.rewards_enabled','type'=>'checkbox','hint'=>'admin.set.lb.rewards_enabled_hint'],
        ['key' => 'leaderboard.mask_usernames', 'label'=>'admin.set.lb.mask_usernames', 'type'=>'checkbox','hint'=>'admin.set.lb.mask_usernames_hint'],
        ['key' => 'leaderboard.reward_xp_1',    'label'=>'admin.set.lb.reward_xp_1',    'type'=>'number',  'hint'=>'admin.set.lb.reward_xp_1_hint',   'min'=>0],
        ['key' => 'leaderboard.reward_xp_2',    'label'=>'admin.set.lb.reward_xp_2',    'type'=>'number',  'hint'=>'admin.set.lb.reward_xp_2_hint',   'min'=>0],
        ['key' => 'leaderboard.reward_xp_3',    'label'=>'admin.set.lb.reward_xp_3',    'type'=>'number',  'hint'=>'admin.set.lb.reward_xp_3_hint',   'min'=>0],
        ['key' => 'leaderboard.reward_coins_1', 'label'=>'admin.set.lb.reward_coins_1', 'type'=>'number',  'hint'=>'admin.set.lb.reward_coins_1_hint','step'=>'0.01','min'=>0],
        ['key' => 'leaderboard.reward_coins_2', 'label'=>'admin.set.lb.reward_coins_2', 'type'=>'number',  'hint'=>'admin.set.lb.reward_coins_2_hint','step'=>'0.01','min'=>0],
        ['key' => 'leaderboard.reward_coins_3', 'label'=>'admin.set.lb.reward_coins_3', 'type'=>'number',  'hint'=>'admin.set.lb.reward_coins_3_hint','step'=>'0.01','min'=>0],
    ],
    'email' => [
        ['key' => 'email.from_address',  'label' => 'admin.set.email.from_address',  'type' => 'email', 'hint' => 'admin.set.email.from_address_hint'],
        ['key' => 'email.from_name',     'label' => 'admin.set.email.from_name',     'type' => 'text',  'hint' => 'admin.set.email.from_name_hint',  'maxlen' => 80],
        ['key' => 'email.contact_to',    'label' => 'admin.set.email.contact_to',    'type' => 'email', 'hint' => 'admin.set.email.contact_to_hint'],
        ['key' => 'email.smtp_enabled',  'label' => 'admin.set.email.smtp_enabled',  'type' => 'checkbox', 'hint' => 'admin.set.email.smtp_enabled_hint'],
        ['key' => 'email.smtp_host',     'label' => 'admin.set.email.smtp_host',     'type' => 'text',  'hint' => 'admin.set.email.smtp_host_hint',  'maxlen' => 120],
        ['key' => 'email.smtp_port',     'label' => 'admin.set.email.smtp_port',     'type' => 'number','hint' => 'admin.set.email.smtp_port_hint',  'min' => 1, 'max' => 65535],
        ['key' => 'email.smtp_user',     'label' => 'admin.set.email.smtp_user',     'type' => 'text',  'hint' => 'admin.set.email.smtp_user_hint',  'maxlen' => 120],
        ['key' => 'email.smtp_pass',     'label' => 'admin.set.email.smtp_pass',     'type' => 'password','hint'=>'admin.set.email.smtp_pass_hint',  'sensitive' => true],
        ['key' => 'email.smtp_encryption','label'=> 'admin.set.email.smtp_encryption','type'=> 'select','hint' => 'admin.set.email.smtp_encryption_hint',
         'options' => ['' => '— Aucune —', 'tls' => 'TLS (port 587)', 'ssl' => 'SSL (port 465)']],
    ],
    'social' => [
        ['key' => 'social.facebook', 'label' => 'admin.set.social.facebook', 'type' => 'url', 'hint' => 'admin.set.social.facebook_hint'],
        ['key' => 'social.twitter',  'label' => 'admin.set.social.twitter',  'type' => 'url', 'hint' => 'admin.set.social.twitter_hint'],
        ['key' => 'social.telegram', 'label' => 'admin.set.social.telegram', 'type' => 'url', 'hint' => 'admin.set.social.telegram_hint'],
        ['key' => 'social.discord',  'label' => 'admin.set.social.discord',  'type' => 'url', 'hint' => 'admin.set.social.discord_hint'],
        ['key' => 'social.youtube',  'label' => 'admin.set.social.youtube',  'type' => 'url', 'hint' => 'admin.set.social.youtube_hint'],
        ['key' => 'social.instagram','label' => 'admin.set.social.instagram','type' => 'url', 'hint' => 'admin.set.social.instagram_hint'],
        ['key' => 'social.tiktok',   'label' => 'admin.set.social.tiktok',   'type' => 'url', 'hint' => 'admin.set.social.tiktok_hint'],
    ],
    'legal' => [
        ['key' => 'legal.editor_name',    'label' => 'admin.set.legal.editor_name',    'type' => 'text',     'hint' => 'admin.set.legal.editor_name_hint',    'maxlen' => 150],
        ['key' => 'legal.editor_status',  'label' => 'admin.set.legal.editor_status',  'type' => 'text',     'hint' => 'admin.set.legal.editor_status_hint',  'maxlen' => 150],
        ['key' => 'legal.editor_address', 'label' => 'admin.set.legal.editor_address', 'type' => 'textarea', 'hint' => 'admin.set.legal.editor_address_hint', 'maxlen' => 300],
        ['key' => 'legal.editor_email',   'label' => 'admin.set.legal.editor_email',   'type' => 'email',    'hint' => 'admin.set.legal.editor_email_hint'],
        ['key' => 'legal.editor_siret',   'label' => 'admin.set.legal.editor_siret',   'type' => 'text',     'hint' => 'admin.set.legal.editor_siret_hint',   'maxlen' => 60],
        ['key' => 'legal.publication_director', 'label' => 'admin.set.legal.publication_director', 'type' => 'text', 'hint' => 'admin.set.legal.publication_director_hint', 'maxlen' => 150],
        ['key' => 'legal.host_name',      'label' => 'admin.set.legal.host_name',      'type' => 'text',     'hint' => 'admin.set.legal.host_name_hint',      'maxlen' => 150],
        ['key' => 'legal.host_address',   'label' => 'admin.set.legal.host_address',   'type' => 'textarea', 'hint' => 'admin.set.legal.host_address_hint',   'maxlen' => 300],
        ['key' => 'legal.host_contact',   'label' => 'admin.set.legal.host_contact',   'type' => 'text',     'hint' => 'admin.set.legal.host_contact_hint',   'maxlen' => 150],
    ],
];

/* ----------------------------------------------------------------------
 * Traitement POST : sauvegarde d'un onglet
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Diagnostic : message clair selon ce qui plante
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        // CSRF échoué : très probablement session expirée ou cache Varnish
        $notice = '⚠ ' . t('admin.csrf_invalid');
        $noticeKind = 'error';
        error_log('[Wintaskly admin/settings] CSRF check failed for user ' . ($_u['id'] ?? '?')
                . ' on tab ' . ($_POST['_tab'] ?? '?'));
    } else {
    $tab = (string)($_POST['_tab'] ?? 'seo');

    // IMPORTANT : on lit les valeurs depuis $_POST['s'][...] (wrapper)
    // pour contourner le bug PHP qui convertit les "." en "_" dans les
    // noms POST de premier niveau. Ex : "email.from_address" deviendrait
    // $_POST['email_from_address'] sans le wrapper.
    $postValues = $_POST['s'] ?? [];

    if (isset($schema[$tab])) {
        foreach ($schema[$tab] as $field) {
            $k    = $field['key'];
            $type = $field['type'];

            // Récupération valeur brute depuis le wrapper $postValues['email.from_address']
            if ($type === 'checkbox') {
                $value = !empty($postValues[$k]) ? '1' : '0';
            } else {
                $value = trim((string)($postValues[$k] ?? ''));
            }

            // Cas spécial mot de passe sensible : si vide en édition, on garde l'ancien
            if (!empty($field['sensitive']) && $value === '') {
                continue;
            }

            // Validation par champ (callable optionnel)
            if (isset($field['validate'])) {
                $err = ($field['validate'])($value);
                if ($err !== null) {
                    $errors[$k] = $err;
                    continue;
                }
            }

            // Validations standard par type
            if ($value !== '') {
                if ($type === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$k] = 'URL invalide';
                    continue;
                }
                if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$k] = 'Email invalide';
                    continue;
                }
                if ($type === 'number') {
                    if (!is_numeric($value)) {
                        $errors[$k] = 'Doit être un nombre';
                        continue;
                    }
                    if (isset($field['min']) && (float)$value < $field['min']) {
                        $errors[$k] = 'Valeur minimum : ' . $field['min'];
                        continue;
                    }
                    if (isset($field['max']) && (float)$value > $field['max']) {
                        $errors[$k] = 'Valeur maximum : ' . $field['max'];
                        continue;
                    }
                }
                if (isset($field['maxlen']) && strlen($value) > $field['maxlen']) {
                    $errors[$k] = 'Longueur maximale : ' . $field['maxlen'] . ' caractères';
                    continue;
                }
            }

            // OK → sauvegarde
            $saveOk = cfg_set($k, $value);
            if (!$saveOk) {
                $errors[$k] = 'Échec écriture BDD (voir error.log)';
            }
        }

        if (empty($errors)) {
            $notice     = '✓ ' . t('admin.saved');
            $noticeKind = 'success';
            $activeTab  = $tab;
        } else {
            $notice     = '⚠ ' . t('admin.has_errors');
            $noticeKind = 'error';
            $activeTab  = $tab;
        }
    } else {
        $notice     = '⚠ Onglet inconnu : ' . htmlspecialchars($tab);
        $noticeKind = 'error';
    }
    } // fin else CSRF OK
}

/* ----------------------------------------------------------------------
 * Récupération des valeurs courantes pour préremplir le form
 * ---------------------------------------------------------------------- */
$values = [];
foreach ($schema as $group => $fields) {
    foreach ($fields as $f) {
        $values[$f['key']] = cfg($f['key'], '');
    }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <!-- ====== HEADER ====== -->
      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">⚙️ <?= e(t('admin.eyebrow_settings')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.settings')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.set.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?>
        <?php
          // Sélection du style selon le type de notice
          $alertCls = ($noticeKind ?? 'success') === 'error' ? 'wt-alert--error' : 'wt-alert--success';
        ?>
        <div class="wt-alert <?= $alertCls ?>" data-reveal><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="wt-alert wt-alert--error" data-reveal>
          ⚠ <?= e(t('admin.set.errors_found')) ?> :
          <ul style="margin: .35rem 0 0; padding-left: 1.2rem;">
            <?php foreach ($errors as $k => $msg): ?>
              <li><code><?= e($k) ?></code> — <?= e($msg) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ====== ONGLETS ====== -->
      <article class="wt-admin-v2__card" data-reveal>
        <nav class="wt-tabs" role="tablist" data-tabs>
          <?php
          $tabIcons = [
              'seo'         => ['🔍', 'admin.set.tab.seo'],
              'tracking'    => ['📊', 'admin.set.tab.tracking'],
              'economy'     => ['💰', 'admin.set.tab.economy'],
              'leaderboard' => ['🏆', 'admin.set.tab.leaderboard'],
              'email'       => ['📧', 'admin.set.tab.email'],
              'social'      => ['🌐', 'admin.set.tab.social'],
              'legal'       => ['⚖️', 'admin.set.tab.legal'],
          ];
          foreach ($tabIcons as $tab => [$icon, $labelKey]):
            $isActive = $tab === $activeTab;
          ?>
            <button type="button"
                    class="wt-tabs__tab <?= $isActive ? 'is-active' : '' ?>"
                    role="tab"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                    data-tab="<?= e($tab) ?>">
              <span aria-hidden="true"><?= $icon ?></span>
              <?= e(t($labelKey)) ?>
            </button>
          <?php endforeach; ?>
        </nav>

        <?php foreach ($schema as $tab => $fields): ?>
          <section class="wt-tabs__panel <?= $tab === $activeTab ? 'is-active' : '' ?>"
                   data-panel="<?= e($tab) ?>" role="tabpanel">

            <form method="post" class="wt-admin-v2__form-body">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="_tab"  value="<?= e($tab) ?>">

              <?php foreach ($fields as $field):
                $k    = $field['key'];
                $type = $field['type'];
                $val  = $values[$k] ?? '';
                $hint = !empty($field['hint']) ? t($field['hint']) : null;
                $hasErr = isset($errors[$k]);
              ?>
                <div class="wt-field <?= $hasErr ? 'wt-field--error' : '' ?>">
                  <?php if ($type === 'checkbox'): ?>
                    <label class="wt-checkbox" style="cursor:pointer;">
                      <input type="checkbox" name="s[<?= e($k) ?>]" value="1"
                             <?= $val === '1' ? 'checked' : '' ?>>
                      <span>
                        <strong><?= e(t($field['label'])) ?></strong>
                        <?php if ($hint): ?> — <small class="wt-muted"><?= e($hint) ?></small><?php endif; ?>
                      </span>
                    </label>
                  <?php else: ?>
                    <label class="wt-field__label" for="f_<?= e(str_replace('.', '_', $k)) ?>">
                      <?= e(t($field['label'])) ?>
                    </label>

                    <?php if ($type === 'textarea'): ?>
                      <textarea class="wt-input" rows="3"
                                id="f_<?= e(str_replace('.', '_', $k)) ?>"
                                name="s[<?= e($k) ?>]"
                                <?= isset($field['maxlen']) ? 'maxlength="' . (int)$field['maxlen'] . '"' : '' ?>
                                ><?= e($val) ?></textarea>

                    <?php elseif ($type === 'select'): ?>
                      <select class="wt-input"
                              id="f_<?= e(str_replace('.', '_', $k)) ?>"
                              name="s[<?= e($k) ?>]">
                        <?php foreach (($field['options'] ?? []) as $optVal => $optLabel): ?>
                          <option value="<?= e($optVal) ?>" <?= $val === (string)$optVal ? 'selected' : '' ?>>
                            <?= e($optLabel) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>

                    <?php elseif ($type === 'password'): ?>
                      <input class="wt-input wt-mono" type="password"
                             id="f_<?= e(str_replace('.', '_', $k)) ?>"
                             name="s[<?= e($k) ?>]"
                             autocomplete="new-password"
                             placeholder="<?= $val !== '' ? '•••••••••• (mot de passe enregistré)' : '' ?>">

                    <?php else:
                      // text / url / email / number
                      $inputType = $type === 'url' ? 'url' : ($type === 'email' ? 'email' : ($type === 'number' ? 'number' : 'text'));
                    ?>
                      <input class="wt-input"
                             type="<?= $inputType ?>"
                             id="f_<?= e(str_replace('.', '_', $k)) ?>"
                             name="s[<?= e($k) ?>]"
                             value="<?= e($val) ?>"
                             <?= isset($field['maxlen']) ? 'maxlength="' . (int)$field['maxlen'] . '"' : '' ?>
                             <?= isset($field['min'])    ? 'min="'       . e((string)$field['min']) . '"'    : '' ?>
                             <?= isset($field['max'])    ? 'max="'       . e((string)$field['max']) . '"'    : '' ?>
                             <?= isset($field['step'])   ? 'step="'      . e((string)$field['step']) . '"'   : '' ?>
                             >
                    <?php endif; ?>

                    <?php if ($hint): ?>
                      <small class="wt-field__hint"><?= e($hint) ?></small>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($hasErr): ?>
                    <small class="wt-field__error">⚠ <?= e($errors[$k]) ?></small>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              <div class="wt-admin-v2__form-actions">
                <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg">
                  💾 <?= e(t('common.save')) ?>
                </button>

                <?php if ($tab === 'email'): ?>
                  <!-- =========================================================
                       BOUTON DIAGNOSTIC SMTP
                       =========================================================
                       Permet à l'admin de tester en 1 clic si la config mail
                       actuelle fonctionne. Appelle /api/admin_mail_test.php
                       qui envoie un mail test et retourne un rapport détaillé.

                       /!\ IMPORTANT : il faut SAUVEGARDER d'abord la config
                       avant de tester, sinon le test utilise les anciennes
                       valeurs en BDD. Hint affiché en dessous.
                       ========================================================= -->
                  <button type="button"
                          class="wt-btn wt-btn--ghost wt-btn--lg"
                          data-mail-test
                          data-endpoint="<?= e(wt_url('/api/admin_mail_test.php')) ?>"
                          data-csrf="<?= e(csrf_token()) ?>"
                          data-admin-email="<?= e($u['email']) ?>">
                    🔍 <?= e(t('admin.set.smtp_test_btn')) ?>
                  </button>
                <?php endif; ?>
              </div>

              <?php if ($tab === 'email'): ?>
                <!-- Zone d'affichage du résultat du test SMTP -->
                <div class="wt-alert wt-alert--info is-hidden" data-mail-test-result
                     style="margin-top:1rem"></div>
                <small class="wt-muted" style="display:block;margin-top:.5rem">
                  ⚠ <?= e(t('admin.set.smtp_test_hint')) ?>
                </small>
              <?php endif; ?>
            </form>
          </section>
        <?php endforeach; ?>
      </article>

      <!-- ====== Aide rapide ====== -->
      <details class="wt-admin-v2__doc" data-reveal>
        <summary>
          <span aria-hidden="true">💡</span>
          <strong><?= e(t('admin.set.help_title')) ?></strong>
          <small class="wt-muted"><?= e(t('admin.set.help_lead')) ?></small>
        </summary>
        <div class="wt-admin-v2__doc-body">
          <p><strong>🔍 SEO</strong> — La meta description s'affiche dans les résultats Google sous le titre.
          Idéalement entre 120 et 160 caractères. L'image OG est utilisée par Facebook, LinkedIn, Discord pour
          le preview quand votre site est partagé.</p>

          <p><strong>📊 Tracking</strong> — Google Analytics : créer une propriété sur
          <code>analytics.google.com</code>, récupérer le Measurement ID (commence par <code>G-</code>).
          AdSense : créer un compte sur <code>adsense.google.com</code>, récupérer le client ID
          (<code>ca-pub-XXXXXXXXXXXXXXXX</code>). L'option "Auto Ads" laisse Google placer les annonces
          automatiquement.</p>

          <p><strong>💰 Économie</strong> — Le coût d'un retrait minimum se calcule via
          <code>min_coins ÷ coins_per_unit</code> par méthode (configurable dans
          <code>/admin/payment_methods.php</code>). La récompense faucet est ajoutée à chaque réclamation
          réussie.</p>

          <p><strong>🏆 Classement</strong> — Les bonus de fin de mois sont distribués par le cron
          d'archivage (1er du mois à 00:05 UTC). Les bonus en coins sont marqués
          <code>meta='leaderboard:YYYY-MM:rankN'</code> et EXCLUS du calcul du classement courant
          (pour éviter une boucle auto-renforçante).</p>

          <p><strong>📧 Email & SMTP</strong> — Si SMTP désactivé, Wintaskly utilise la fonction
          <code>mail()</code> native de PHP (configurée par votre hébergeur). Sur LWS, vous pouvez
          activer SMTP avec les paramètres : hôte <code>mail.votredomaine.com</code>, port 587, TLS,
          user/pass de votre boîte mail cPanel.</p>
        </div>
      </details>

    </section>
  </div>
</main>

<script>
/**
 * Navigation par onglets (vanilla JS).
 * Toggle .is-active sur les boutons et panels.
 * Persiste la sélection en URL hash pour conserver l'état au refresh.
 */
(function () {
  const tabsContainer = document.querySelector('[data-tabs]');
  if (!tabsContainer) return;
  const tabs = tabsContainer.querySelectorAll('[data-tab]');
  const panels = document.querySelectorAll('[data-panel]');

  function activate(tabName) {
    tabs.forEach(t => {
      const active = t.dataset.tab === tabName;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.forEach(p => {
      p.classList.toggle('is-active', p.dataset.panel === tabName);
    });
    // Mise à jour URL hash (sans scroll)
    history.replaceState(null, '', '#' + tabName);
  }

  tabs.forEach(t => {
    t.addEventListener('click', () => activate(t.dataset.tab));
  });

  // Au chargement : si #tab dans URL, l'utiliser
  const hash = window.location.hash.replace('#', '');
  if (hash && tabsContainer.querySelector('[data-tab="' + hash + '"]')) {
    activate(hash);
  }
})();

/* ====================================================================
   DIAGNOSTIC SMTP : bouton "Tester l'envoi"
   ====================================================================
   Au clic, on appelle /api/admin_mail_test.php qui envoie un mail TEST
   à l'email admin et retourne un rapport complet (driver utilisé,
   config SMTP résolue, résultat exact, hints de diagnostic).

   Le rapport est affiché en visuel sous le bouton — pas besoin d'aller
   chercher dans les logs PHP.
   ==================================================================== */
(function () {
  const btn      = document.querySelector('[data-mail-test]');
  if (!btn) return;
  const resultEl = document.querySelector('[data-mail-test-result]');
  const url      = btn.getAttribute('data-endpoint');
  const csrf     = btn.getAttribute('data-csrf') || '';

  function showResult(html, type) {
    if (!resultEl) return;
    resultEl.className = 'wt-alert wt-alert--' + (type || 'info');
    resultEl.innerHTML = html;
    resultEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  btn.addEventListener('click', async function () {
    btn.disabled = true;
    const origLabel = btn.textContent;
    btn.textContent = '⏳ Test en cours…';
    showResult('⏳ Envoi du mail test, patiente quelques secondes…', 'info');

    try {
      const fd = new FormData();
      fd.append('_csrf', csrf);
      const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(function () { return { ok: false, error: 'Réponse invalide' }; });

      // Construit l'affichage du rapport
      let html = '';
      if (data.ok) {
        html += '<strong>✅ ' + escapeHtml(data.message || 'Email envoyé !') + '</strong>';
        if (data.report) {
          html += '<details style="margin-top:.75rem"><summary style="cursor:pointer">📋 Rapport technique</summary>';
          html += '<pre style="font-size:.8rem;margin-top:.5rem;white-space:pre-wrap">';
          html += escapeHtml(JSON.stringify(data.report, null, 2));
          html += '</pre></details>';
        }
        showResult(html, 'success');
      } else {
        html += '<strong>❌ Échec : ' + escapeHtml(data.error || 'inconnue') + '</strong>';
        if (data.detail) {
          html += '<p style="margin-top:.5rem">' + escapeHtml(data.detail) + '</p>';
        }
        if (data.hints && data.hints.length) {
          html += '<ul style="margin-top:.5rem;padding-left:1.5rem">';
          for (let i = 0; i < data.hints.length; i++) {
            html += '<li>' + escapeHtml(data.hints[i]) + '</li>';
          }
          html += '</ul>';
        }
        if (data.report) {
          html += '<details style="margin-top:.75rem"><summary style="cursor:pointer">📋 Rapport technique</summary>';
          html += '<pre style="font-size:.8rem;margin-top:.5rem;white-space:pre-wrap">';
          html += escapeHtml(JSON.stringify(data.report, null, 2));
          html += '</pre></details>';
        }
        showResult(html, 'error');
      }
    } catch (e) {
      showResult('❌ Erreur réseau : ' + escapeHtml(e.message || String(e)), 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = origLabel;
    }
  });
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
