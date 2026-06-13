<?php
/**
 * Wintaskly — Admin · CRUD des méthodes de retrait (V8).
 *
 * Permet à l'admin d'ajouter / modifier / activer-désactiver / supprimer
 * les passerelles de paiement utilisées par les utilisateurs pour
 * retirer leurs Coins :
 *   - FaucetPay (FP_USD / FP_BTC / FP_LTC / FP_DOGE...)
 *   - Payeer
 *   - Binance Pay
 *   - PayPal, etc.
 *
 * Chaque méthode définit :
 *   - `k`              : identifiant interne unique (slug)
 *   - `label`          : nom affiché à l'utilisateur (ex: "FaucetPay USD")
 *   - `currency`       : devise de destination (USD, EUR, BTC, etc.)
 *   - `coins_per_unit` : taux de conversion (10000 = 1 USD pour 10k coins)
 *   - `min_coins`      : seuil minimum de retrait
 *   - `max_coins`      : plafond optionnel (NULL = pas de plafond)
 *   - `address_label`  : libellé du champ adresse côté user
 *   - `address_placeholder` : exemple d'adresse (ex: alice@faucetpay.io)
 *   - `active`         : flag ON/OFF
 *   - `sort_order`     : ordre d'affichage (asc)
 *
 * Sécurité : require_admin + CSRF + prepared statements + ON DELETE
 * protégée par FK (les méthodes utilisées en historique sont indélébiles).
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.payment_methods');
$adminActive = 'payment_methods';
$db          = db();
$notice      = null;
$noticeError = null;
$editing     = null;

/* ---------- Traitements POST ---------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Vérifier qu'aucun retrait n'utilise cette méthode (FK violation
            // sinon → message clair plutôt qu'erreur SQL brute)
            $stmt = $db->prepare("SELECT COUNT(*) c FROM withdrawals WHERE method_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $used = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();

            if ($used > 0) {
                $noticeError = sprintf(
                    (string) t('admin.pm.cannot_delete'),
                    $used
                );
            } else {
                $stmt = $db->prepare("DELETE FROM withdrawal_methods WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.deleted');
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE withdrawal_methods SET active = 1 - active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        }
    } elseif ($action === 'save') {
        $id         = (int)   ($_POST['id'] ?? 0);
        $k          = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['k'] ?? '')));
        $label      = trim((string)($_POST['label'] ?? ''));
        $currency   = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
        $coinsUnit  = (float) ($_POST['coins_per_unit'] ?? 10000);
        $minCoins   = (float) ($_POST['min_coins'] ?? 0);
        $maxCoinsIn = trim((string)($_POST['max_coins'] ?? ''));
        $maxCoins   = $maxCoinsIn === '' ? null : (float) $maxCoinsIn;
        $addrLabel  = trim((string)($_POST['address_label'] ?? '')) ?: 'Adresse de retrait';
        $addrPlace  = trim((string)($_POST['address_placeholder'] ?? '')) ?: null;
        $sortOrder  = (int)   ($_POST['sort_order'] ?? 0);
        $active     = !empty($_POST['active']) ? 1 : 0;
        $autoPayout = !empty($_POST['auto_payout']) ? 1 : 0;

        // ---- Credentials API : merge avec l'existant ----
        // Politique de sécurité :
        //   - À l'édition, on n'expose JAMAIS la valeur actuelle dans le HTML.
        //   - L'admin laisse le champ vide pour conserver la clé existante,
        //     ou la remplit pour l'écraser.
        //   - Un bouton "Effacer la clé" envoie 'clear_api=1' pour la vider.
        $apiKey      = trim((string)($_POST['api_key']      ?? ''));
        $apiSecret   = trim((string)($_POST['api_secret']   ?? ''));
        $apiAccount  = trim((string)($_POST['api_account']  ?? ''));
        $clearApi    = !empty($_POST['clear_api']);

        // Charge les credentials existantes si update
        $existingCreds = [];
        if ($id > 0 && !$clearApi) {
            $stmt = $db->prepare("SELECT api_credentials FROM withdrawal_methods WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['api_credentials'])) {
                $decoded = json_decode((string)$row['api_credentials'], true);
                if (is_array($decoded)) $existingCreds = $decoded;
            }
        }

        // Fusion : on n'écrase un champ que si l'admin a saisi quelque chose
        $newCreds = $existingCreds;
        if ($apiKey     !== '') $newCreds['api_key']     = $apiKey;
        if ($apiSecret  !== '') $newCreds['api_secret']  = $apiSecret;
        if ($apiAccount !== '') $newCreds['api_account'] = $apiAccount;

        // Si clear_api : on vide tout
        if ($clearApi) $newCreds = [];

        // Si auto_payout=1 mais aucune clé : on désactive et avertit
        if ($autoPayout === 1 && empty($newCreds)) {
            $autoPayout = 0;
            $noticeError = (string) t('admin.pm.auto_needs_key');
        }

        $credsJson = $newCreds ? json_encode($newCreds, JSON_UNESCAPED_UNICODE) : null;

        // Validations métier
        $errors = [];
        if ($k === '')               $errors[] = (string) t('admin.pm.err_key');
        if ($label === '')           $errors[] = (string) t('admin.pm.err_label');
        if ($currency === '')        $errors[] = (string) t('admin.pm.err_currency');
        if ($coinsUnit <= 0)         $errors[] = (string) t('admin.pm.err_rate');
        if ($minCoins < 0)           $errors[] = (string) t('admin.pm.err_min');
        if ($maxCoins !== null && $maxCoins < $minCoins) {
            $errors[] = (string) t('admin.pm.err_max_lt_min');
        }

        if ($errors) {
            $noticeError = implode(' · ', $errors);
        } else {
            try {
                if ($id > 0) {
                    $stmt = $db->prepare(
                        "UPDATE withdrawal_methods SET
                            k = ?, label = ?, currency = ?,
                            coins_per_unit = ?, min_coins = ?, max_coins = ?,
                            address_label = ?, address_placeholder = ?,
                            api_credentials = ?, auto_payout = ?,
                            sort_order = ?, active = ?
                         WHERE id = ?"
                    );
                    $stmt->bind_param(
                        'sssddd' . 'sss' . 'iiii',
                        $k, $label, $currency,
                        $coinsUnit, $minCoins, $maxCoins,
                        $addrLabel, $addrPlace,
                        $credsJson, $autoPayout,
                        $sortOrder, $active, $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.saved');
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO withdrawal_methods
                           (k, label, currency, coins_per_unit, min_coins, max_coins,
                            address_label, address_placeholder,
                            api_credentials, auto_payout,
                            sort_order, active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        'sssddd' . 'sss' . 'iii',
                        $k, $label, $currency,
                        $coinsUnit, $minCoins, $maxCoins,
                        $addrLabel, $addrPlace,
                        $credsJson, $autoPayout,
                        $sortOrder, $active
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.created');
                }
            } catch (mysqli_sql_exception $e) {
                // Probable : violation UNIQUE sur (k)
                if ($e->getCode() === 1062) {
                    $noticeError = (string) t('admin.pm.err_key_taken');
                } else {
                    $noticeError = (string) t('common.error');
                    error_log('payment_methods save: ' . $e->getMessage());
                }
            }
        }
    }
}

/* ---------- Édition d'un item (mode formulaire prérempli) ----------- */
if (!empty($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $db->prepare(
        "SELECT id, k, label, currency, coins_per_unit, min_coins, max_coins,
                address_label, address_placeholder,
                api_credentials, auto_payout,
                sort_order, active
           FROM withdrawal_methods WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

/* ---------- Liste --------------------------------------------------- */
$rows = [];
$res = $db->query(
    "SELECT m.id, m.k, m.label, m.currency, m.coins_per_unit, m.min_coins, m.max_coins,
            m.sort_order, m.active,
            m.auto_payout,
            (m.api_credentials IS NOT NULL AND m.api_credentials != '') AS has_credentials,
            (SELECT COUNT(*) FROM withdrawals w WHERE w.method_id = m.id) AS usage_count
       FROM withdrawal_methods m
       ORDER BY m.sort_order ASC, m.id ASC"
);
if ($res) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* ---------- Stats hero --------------------------------------------- */
$nbTotal  = count($rows);
$nbActive = count(array_filter($rows, fn ($r) => (int)$r['active'] === 1));
$totalUsage = 0;
foreach ($rows as $r) $totalUsage += (int)$r['usage_count'];

/* ---------- Devises courantes proposées en datalist ----------------- */
$currencyOptions = ['USD', 'EUR', 'BTC', 'LTC', 'DOGE', 'BCH', 'TRX', 'ETH', 'USDT', 'XRP'];

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <!-- ====== HEADER ====== -->
      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">💳 <?= e(t('admin.eyebrow_payment_methods')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.payment_methods')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.pm.lead')) ?></p>
        </div>
        <?php if ($editing): ?>
          <a class="wt-btn wt-btn--ghost wt-btn--xs"
             href="<?= e(wt_url('/admin/payment_methods.php')) ?>">
            ← <?= e(t('admin.exit_edit_mode')) ?>
          </a>
        <?php endif; ?>
      </header>

      <?php if ($notice): ?>
        <div class="wt-alert wt-alert--success" data-reveal>✓ <?= e($notice) ?></div>
      <?php endif; ?>
      <?php if ($noticeError): ?>
        <div class="wt-alert wt-alert--error" data-reveal>⚠ <?= e($noticeError) ?></div>
      <?php endif; ?>

      <!-- ====== Stats hero ====== -->
      <section class="wt-admin-v2__stats" data-reveal>
        <article class="wt-admin-v2__stat" style="--idx:0">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">💳</span>
          <div>
            <small><?= e(t('admin.stat.total')) ?></small>
            <strong><?= (int)$nbTotal ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat wt-admin-v2__stat--ok" style="--idx:1">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">✅</span>
          <div>
            <small><?= e(t('admin.stat.active')) ?></small>
            <strong><?= (int)$nbActive ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat" style="--idx:2">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">📊</span>
          <div>
            <small><?= e(t('admin.pm.total_withdrawals')) ?></small>
            <strong><?= number_format($totalUsage, 0, '.', ' ') ?></strong>
          </div>
        </article>
      </section>

      <!-- ====== Form card ====== -->
      <article class="wt-admin-v2__card" data-reveal>
        <header class="wt-admin-v2__card-head">
          <span class="wt-admin-v2__card-icon" aria-hidden="true">
            <?= $editing ? '✏️' : '➕' ?>
          </span>
          <div>
            <h2>
              <?= $editing
                    ? e(sprintf((string)t('admin.edit_item'), '#' . (int)$editing['id']))
                    : e(t('admin.pm.new_method')) ?>
            </h2>
            <small class="wt-muted">
              <?= e($editing ? t('admin.edit_lead') : t('admin.pm.new_lead')) ?>
            </small>
          </div>
        </header>

        <form method="post" class="wt-admin-v2__form-body">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id"     value="<?= (int)($editing['id'] ?? 0) ?>">

          <!-- ====== Identité ====== -->
          <h3 class="wt-admin-v2__form-section">🪪 <?= e(t('admin.pm.section_identity')) ?></h3>
          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.pm.key')) ?></span>
              <input class="wt-input wt-mono" type="text" name="k"
                     required pattern="[a-z0-9_-]+" maxlength="40"
                     value="<?= e((string)($editing['k'] ?? '')) ?>"
                     placeholder="faucetpay-usd">
              <small class="wt-field__hint"><?= e(t('admin.pm.key_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.pm.label')) ?></span>
              <input class="wt-input" type="text" name="label" required maxlength="80"
                     value="<?= e((string)($editing['label'] ?? '')) ?>"
                     placeholder="FaucetPay (USD)">
              <small class="wt-field__hint"><?= e(t('admin.pm.label_hint')) ?></small>
            </label>
          </div>

          <!-- ====== Conversion ====== -->
          <h3 class="wt-admin-v2__form-section">💱 <?= e(t('admin.pm.section_conversion')) ?></h3>
          <div class="wt-admin-v2__grid-3">
            <label class="wt-field">
              <span class="wt-field__label">💵 <?= e(t('admin.pm.currency')) ?></span>
              <input class="wt-input wt-mono" type="text" name="currency"
                     required maxlength="20" list="wt-pm-currencies"
                     value="<?= e((string)($editing['currency'] ?? 'USD')) ?>"
                     placeholder="USD">
              <datalist id="wt-pm-currencies">
                <?php foreach ($currencyOptions as $c): ?>
                  <option value="<?= e($c) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⚖ <?= e(t('admin.pm.coins_per_unit')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0.0001"
                     name="coins_per_unit" required
                     value="<?= e((string)($editing['coins_per_unit'] ?? '10000')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.coins_per_unit_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">🔢 <?= e(t('admin.pm.sort_order')) ?></span>
              <input class="wt-input" type="number" name="sort_order"
                     value="<?= (int)($editing['sort_order'] ?? 0) ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.sort_order_hint')) ?></small>
            </label>
          </div>

          <!-- Preview du taux -->
          <?php
            $previewCoins = (float)($editing['coins_per_unit'] ?? 10000);
            $previewCur   = (string)($editing['currency'] ?? 'USD');
            $previewMin   = (float)($editing['min_coins'] ?? 10000);
          ?>
          <div class="wt-admin-v2__preview-box">
            💡 <strong><?= e(t('admin.pm.example')) ?></strong> :
            <?= e(rtrim(rtrim(number_format($previewCoins, 4, '.', ''), '0'), '.')) ?>
            Coins = 1 <?= e($previewCur) ?>
            <?php if ($previewMin > 0): ?>
              · <?= e(t('admin.pm.min_payout')) ?> :
              <?= e(rtrim(rtrim(number_format($previewMin / max($previewCoins, 0.0001), 6, '.', ''), '0'), '.')) ?>
              <?= e($previewCur) ?>
            <?php endif; ?>
          </div>

          <!-- ====== Limites ====== -->
          <h3 class="wt-admin-v2__form-section">📏 <?= e(t('admin.pm.section_limits')) ?></h3>
          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label">⬇ <?= e(t('admin.pm.min_coins')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0"
                     name="min_coins" required
                     value="<?= e((string)($editing['min_coins'] ?? '10000')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.min_coins_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⬆ <?= e(t('admin.pm.max_coins')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0"
                     name="max_coins"
                     value="<?= $editing && $editing['max_coins'] !== null
                                ? e((string)$editing['max_coins']) : '' ?>"
                     placeholder="<?= e(t('admin.pm.max_coins_placeholder')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.max_coins_hint')) ?></small>
            </label>
          </div>

          <!-- ====== UI adresse ====== -->
          <h3 class="wt-admin-v2__form-section">📝 <?= e(t('admin.pm.section_ui')) ?></h3>
          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.pm.address_label')) ?></span>
              <input class="wt-input" type="text" name="address_label" maxlength="80"
                     value="<?= e((string)($editing['address_label'] ?? 'Adresse de retrait')) ?>"
                     placeholder="Email FaucetPay">
              <small class="wt-field__hint"><?= e(t('admin.pm.address_label_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.pm.address_placeholder')) ?></span>
              <input class="wt-input wt-mono" type="text" name="address_placeholder" maxlength="160"
                     value="<?= e((string)($editing['address_placeholder'] ?? '')) ?>"
                     placeholder="alice@faucetpay.io">
              <small class="wt-field__hint"><?= e(t('admin.pm.address_placeholder_hint')) ?></small>
            </label>
          </div>

          <!-- ====== API & paiement automatique ====== -->
          <?php
            // Détection : la méthode actuelle a-t-elle des credentials stockées ?
            $hasExistingCreds = false;
            $existingCredKeys = [];
            if ($editing && !empty($editing['api_credentials'])) {
                $decoded = json_decode((string)$editing['api_credentials'], true);
                if (is_array($decoded) && $decoded) {
                    $hasExistingCreds = true;
                    $existingCredKeys = array_keys($decoded);
                }
            }
          ?>
          <h3 class="wt-admin-v2__form-section">🔐 <?= e(t('admin.pm.section_api')) ?></h3>

          <div class="wt-admin-v2__api-warn">
            <span aria-hidden="true">⚠️</span>
            <small><?= e(t('admin.pm.api_security_warn')) ?></small>
          </div>

          <?php if ($hasExistingCreds): ?>
            <div class="wt-admin-v2__preview-box" style="margin-top:.5rem">
              ✅ <strong><?= e(t('admin.pm.api_creds_set')) ?></strong> :
              <?= e(implode(', ', $existingCredKeys)) ?>
              · <?= e(t('admin.pm.api_leave_empty_to_keep')) ?>
            </div>
          <?php endif; ?>

          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label">🔑 <?= e(t('admin.pm.api_key')) ?></span>
              <input class="wt-input wt-mono" type="password"
                     name="api_key" autocomplete="new-password"
                     placeholder="<?= $hasExistingCreds && in_array('api_key', $existingCredKeys, true)
                                        ? '••••••••••••••••• (clé existante)'
                                        : 'Ex : 1a2b3c4d5e6f7g8h9i' ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.api_key_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">🔒 <?= e(t('admin.pm.api_secret')) ?></span>
              <input class="wt-input wt-mono" type="password"
                     name="api_secret" autocomplete="new-password"
                     placeholder="<?= $hasExistingCreds && in_array('api_secret', $existingCredKeys, true)
                                        ? '••••••••••••••••• (secret existant)'
                                        : 'Optionnel selon le provider' ?>">
              <small class="wt-field__hint"><?= e(t('admin.pm.api_secret_hint')) ?></small>
            </label>
          </div>

          <label class="wt-field">
            <span class="wt-field__label">👤 <?= e(t('admin.pm.api_account')) ?></span>
            <input class="wt-input wt-mono" type="text"
                   name="api_account" autocomplete="off"
                   placeholder="<?= $hasExistingCreds && in_array('api_account', $existingCredKeys, true)
                                      ? '•••••• (compte existant)'
                                      : 'Optionnel — Ex Payeer : P1234567' ?>">
            <small class="wt-field__hint"><?= e(t('admin.pm.api_account_hint')) ?></small>
          </label>

          <label class="wt-checkbox" style="margin-top:.5rem">
            <input type="checkbox" name="auto_payout" value="1"
                   <?= !empty($editing['auto_payout']) ? 'checked' : '' ?>>
            <span>
              <strong>⚡ <?= e(t('admin.pm.auto_payout')) ?></strong> — 
              <?= e(t('admin.pm.auto_payout_hint')) ?>
            </span>
          </label>

          <?php if ($hasExistingCreds): ?>
            <label class="wt-checkbox" style="margin-top:.5rem; color: var(--wt-danger, #ef4444)">
              <input type="checkbox" name="clear_api" value="1">
              <span>
                🗑 <strong><?= e(t('admin.pm.clear_api')) ?></strong> — 
                <small><?= e(t('admin.pm.clear_api_hint')) ?></small>
              </span>
            </label>
          <?php endif; ?>

          <!-- ====== Activation globale de la méthode ====== -->
          <h3 class="wt-admin-v2__form-section">✅ <?= e(t('admin.pm.section_status')) ?></h3>
          <label class="wt-checkbox wt-admin-v2__active-check">
            <input type="checkbox" name="active" value="1"
                   <?= !empty($editing['active']) || $editing === null ? 'checked' : '' ?>>
            <span><strong><?= e(t('common.active')) ?></strong> — <?= e(t('admin.active_hint')) ?></span>
          </label>

          <div class="wt-admin-v2__form-actions">
            <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg">
              <?= $editing ? '💾 ' . e(t('common.save')) : '➕ ' . e(t('common.add')) ?>
            </button>
            <?php if ($editing): ?>
              <a class="wt-btn wt-btn--ghost"
                 href="<?= e(wt_url('/admin/payment_methods.php')) ?>"><?= e(t('common.cancel')) ?></a>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <!-- ====== Liste existante ====== -->
      <section class="wt-admin-v2__list-section" data-reveal>
        <header class="wt-admin-v2__list-head">
          <h2 class="wt-admin-v2__list-title">📋 <?= e(t('admin.existing_items')) ?></h2>
          <span class="wt-muted"><?= count($rows) ?> <?= e(t('common.items')) ?></span>
        </header>

        <?php if (!$rows): ?>
          <div class="wt-admin-v2__empty">
            <span class="wt-admin-v2__empty-icon" aria-hidden="true">💳</span>
            <p><?= e(t('admin.pm.empty')) ?></p>
            <small class="wt-muted"><?= e(t('admin.pm.empty_hint')) ?></small>
          </div>
        <?php else: ?>
          <ul class="wt-admin-v2__entries">
            <?php foreach ($rows as $i => $r):
              $isActive = (int)$r['active'] === 1;
              $usage    = (int)$r['usage_count'];
              // Calcul du payout minimum pour info visuelle
              $minPayout = (float)$r['min_coins'] / max((float)$r['coins_per_unit'], 0.0001);
            ?>
              <li class="wt-admin-v2__entry <?= $isActive ? '' : 'is-inactive' ?>"
                  style="--idx:<?= (int)$i ?>">
                <div class="wt-admin-v2__entry-status">
                  <span class="wt-admin-v2__status-dot wt-admin-v2__status-dot--<?= $isActive ? 'on' : 'off' ?>"></span>
                </div>

                <div class="wt-admin-v2__entry-body">
                  <header class="wt-admin-v2__entry-head">
                    <strong><?= e($r['label']) ?></strong>
                    <code class="wt-mono wt-admin-v2__entry-key"><?= e($r['k']) ?></code>
                    <span class="wt-admin-v2__entry-tag"><?= e($r['currency']) ?></span>
                    <?php if (!empty($r['has_credentials'])): ?>
                      <span class="wt-admin-v2__entry-tag wt-admin-v2__entry-tag--ok"
                            title="<?= e(t('admin.pm.api_configured_tip')) ?>">🔑 API</span>
                    <?php endif; ?>
                    <?php if (!empty($r['auto_payout']) && !empty($r['has_credentials'])): ?>
                      <span class="wt-admin-v2__entry-tag wt-admin-v2__entry-tag--accent"
                            title="<?= e(t('admin.pm.auto_payout_tip')) ?>">⚡ Auto</span>
                    <?php elseif (!empty($r['auto_payout'])): ?>
                      <span class="wt-admin-v2__entry-tag wt-admin-v2__entry-tag--warn"
                            title="<?= e(t('admin.pm.auto_no_key_tip')) ?>">⚠ Auto inactif</span>
                    <?php endif; ?>
                    <small class="wt-mono">#<?= (int)$r['id'] ?></small>
                  </header>

                  <div class="wt-admin-v2__entry-meta">
                    <span title="<?= e(t('admin.pm.coins_per_unit')) ?>">
                      ⚖ 1 <?= e($r['currency']) ?> =
                      <?= e(rtrim(rtrim(number_format((float)$r['coins_per_unit'], 4, '.', ''), '0'), '.')) ?> Coins
                    </span>
                    <span title="<?= e(t('admin.pm.min_payout')) ?>">
                      ⬇ <?= e(rtrim(rtrim(number_format($minPayout, 6, '.', ''), '0'), '.')) ?>
                      <?= e($r['currency']) ?> min
                    </span>
                    <?php if ($r['max_coins'] !== null): ?>
                      <span title="<?= e(t('admin.pm.max_coins')) ?>">
                        ⬆ <?= e(rtrim(rtrim(number_format((float)$r['max_coins'], 0, '.', ' '), '0'), '.')) ?> Coins max
                      </span>
                    <?php endif; ?>
                    <span>🔢 <?= e(t('admin.pm.sort_order')) ?>: <?= (int)$r['sort_order'] ?></span>
                    <?php if ($usage > 0): ?>
                      <span title="<?= e(t('admin.pm.used_in_n_withdrawals')) ?>">
                        📊 <?= (int)$usage ?> <?= e(t('admin.pm.withdrawals')) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="wt-admin-v2__entry-actions">
                  <a class="wt-btn wt-btn--xs wt-btn--ghost"
                     href="?edit=<?= (int)$r['id'] ?>" title="<?= e(t('common.edit')) ?>">✏️</a>

                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="wt-btn wt-btn--xs wt-btn--ghost"
                            title="<?= $isActive ? e(t('common.disable')) : e(t('common.enable')) ?>">
                      <?= $isActive ? '⏸' : '▶' ?>
                    </button>
                  </form>

                  <?php if ($usage === 0): ?>
                    <button type="button"
                            class="wt-btn wt-btn--xs wt-btn--danger"
                            data-confirm
                            data-confirm-title="<?= e(t('admin.confirm_delete_title')) ?>"
                            data-confirm-body="<?= e(sprintf((string)t('admin.confirm_delete_body'), e($r['label']))) ?>"
                            data-confirm-ok="<?= e(t('common.delete')) ?>"
                            data-confirm-ok-class="wt-btn--danger"
                            data-confirm-post="<?= e(wt_url('/admin/payment_methods.php')) ?>"
                            data-confirm-data='<?= e(json_encode(['_csrf' => csrf_token(), 'action' => 'delete', 'id' => (int)$r['id']])) ?>'
                            title="<?= e(t('common.delete')) ?>">
                      🗑
                    </button>
                  <?php else: ?>
                    <span class="wt-btn wt-btn--xs wt-btn--ghost"
                          title="<?= e(t('admin.pm.cannot_delete_used')) ?>"
                          style="opacity:.4; cursor:not-allowed">🔒</span>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <!-- ====== Aide / exemples ====== -->
      <details class="wt-admin-v2__doc" data-reveal>
        <summary>
          <span aria-hidden="true">📖</span>
          <strong><?= e(t('admin.pm.examples_title')) ?></strong>
          <small class="wt-muted"><?= e(t('admin.pm.examples_lead')) ?></small>
        </summary>
        <div class="wt-admin-v2__doc-body">
          <p><strong><?= e(t('admin.pm.example_faucetpay')) ?></strong></p>
          <ul style="margin: .25rem 0 .85rem; padding-left: 1.25rem; font-size: .88rem; line-height: 1.7;">
            <li><code class="wt-admin-v2__inline-code">k</code> = <code class="wt-admin-v2__inline-code">faucetpay-usd</code></li>
            <li><code class="wt-admin-v2__inline-code">label</code> = "FaucetPay (USD)"</li>
            <li><code class="wt-admin-v2__inline-code">currency</code> = USD</li>
            <li><code class="wt-admin-v2__inline-code">coins_per_unit</code> = 10000 (1 USD = 10k Coins)</li>
            <li><code class="wt-admin-v2__inline-code">min_coins</code> = 5000 (retrait min : 0.5 USD)</li>
            <li><code class="wt-admin-v2__inline-code">address_label</code> = "Email FaucetPay"</li>
            <li><code class="wt-admin-v2__inline-code">address_placeholder</code> = "alice@faucetpay.io"</li>
          </ul>

          <p><strong><?= e(t('admin.pm.example_payeer')) ?></strong></p>
          <ul style="margin: .25rem 0 .85rem; padding-left: 1.25rem; font-size: .88rem; line-height: 1.7;">
            <li><code class="wt-admin-v2__inline-code">k</code> = <code class="wt-admin-v2__inline-code">payeer-usd</code></li>
            <li><code class="wt-admin-v2__inline-code">label</code> = "Payeer (USD)"</li>
            <li><code class="wt-admin-v2__inline-code">coins_per_unit</code> = 10000</li>
            <li><code class="wt-admin-v2__inline-code">min_coins</code> = 30000 (retrait min : 3 USD)</li>
            <li><code class="wt-admin-v2__inline-code">address_label</code> = "Numéro de compte Payeer"</li>
            <li><code class="wt-admin-v2__inline-code">address_placeholder</code> = "P1234567"</li>
          </ul>

          <p><strong><?= e(t('admin.pm.example_binance')) ?></strong></p>
          <ul style="margin: .25rem 0 .85rem; padding-left: 1.25rem; font-size: .88rem; line-height: 1.7;">
            <li><code class="wt-admin-v2__inline-code">k</code> = <code class="wt-admin-v2__inline-code">binance-usdt</code></li>
            <li><code class="wt-admin-v2__inline-code">label</code> = "Binance Pay (USDT)"</li>
            <li><code class="wt-admin-v2__inline-code">currency</code> = USDT</li>
            <li><code class="wt-admin-v2__inline-code">coins_per_unit</code> = 10000</li>
            <li><code class="wt-admin-v2__inline-code">min_coins</code> = 50000 (retrait min : 5 USDT)</li>
            <li><code class="wt-admin-v2__inline-code">address_label</code> = "Binance Pay ID"</li>
            <li><code class="wt-admin-v2__inline-code">address_placeholder</code> = "123456789"</li>
          </ul>
        </div>
      </details>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
