<?php
/**
 * Wintaskly — Admin · CRUD des shortlinks (V8 modernisé).
 *
 * Actions :
 *   - create  : insertion
 *   - update  : mise à jour
 *   - delete  : suppression (cascade sur attempts + cooldowns)
 *   - toggle  : flip du flag active
 *
 * V8 : layout admin V8 + stats hero (total/actifs/perf) + form card +
 * liste en cards avec actions en pills colorées + modal confirm V8.
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.shortlinks');
$adminActive = 'shortlinks';
$db          = db();
$notice      = null;
$editing     = null;

/* =====================================================================
   DÉTECTION DES MIGRATIONS MANQUANTES
   =====================================================================
   Avant d'exécuter les requêtes qui utilisent les nouvelles colonnes
   (mode, api_endpoint, callback_key, provider_rate_*), on vérifie
   qu'elles existent vraiment en BDD. Si une migration n'a pas été
   appliquée par l'admin, on affiche un message CLAIR avec instructions
   au lieu de planter en 500.

   Tableau des colonnes attendues → migration qui les crée :
   ===================================================================== */
$expectedCols = [
    'mode'                    => 'migration_shortlinks_api.sql',
    'api_endpoint'            => 'migration_shortlinks_api.sql',
    'api_token'               => 'migration_shortlinks_api.sql',
    'callback_key'            => 'migration_shortlinks_api.sql',
    'provider_rate_amount'    => 'migration_shortlinks_rate.sql',
    'provider_rate_currency'  => 'migration_shortlinks_rate.sql',
    'provider_rate_per_views' => 'migration_shortlinks_rate.sql',
];

$existingCols = [];
if ($res = $db->query("SHOW COLUMNS FROM shortlinks")) {
    while ($r = $res->fetch_assoc()) {
        $existingCols[$r['Field']] = true;
    }
    $res->free();
}

$missingMigrations = [];
foreach ($expectedCols as $col => $migration) {
    if (!isset($existingCols[$col])) {
        $missingMigrations[$migration][] = $col;
    }
}

/* Si migrations manquantes, on AFFICHE la page d'aide et on s'arrête là.
   Pas de 500, pas de SQL qui plante : l'admin sait exactement quoi faire. */
if (!empty($missingMigrations)) {
    include __DIR__ . '/../header.php'; ?>
    <main class="wt-main wt-admin-v2">
      <div class="wt-admin-v2__layout">
        <?php include __DIR__ . '/_nav.php'; ?>
        <section class="wt-admin-v2__content">
          <header class="wt-admin-v2__page-header">
            <div>
              <h1 class="wt-admin-v2__title">🗄 Migrations SQL manquantes</h1>
              <p class="wt-muted">Pour activer toutes les fonctionnalités shortlinks, applique les migrations ci-dessous.</p>
            </div>
          </header>

          <div class="wt-alert wt-alert--warn" style="margin:1rem 0">
            <strong>⚠ Migrations à appliquer dans phpMyAdmin</strong> avant d'utiliser cette page.
          </div>

          <div class="wt-card wt-card--padded">
            <p>Les colonnes suivantes manquent dans la table <code>shortlinks</code> :</p>
            <ul style="line-height:1.8">
            <?php foreach ($missingMigrations as $migFile => $cols): ?>
              <li>
                <strong style="font-family:var(--wt-font-mono)"><?= e($migFile) ?></strong>
                — crée les colonnes : <code><?= e(implode(', ', $cols)) ?></code>
              </li>
            <?php endforeach; ?>
            </ul>

            <h3 style="margin-top:1.5rem">📋 Comment appliquer</h3>
            <ol style="line-height:1.8">
              <li>Allez dans <strong>phpMyAdmin</strong> (depuis votre cPanel LWS)</li>
              <li>Sélectionnez votre BDD <code>winta2810082</code></li>
              <li>Onglet <strong>SQL</strong></li>
              <li>Copie-colle le contenu de chaque fichier <code>sql/migration_*.sql</code> manquant (présent dans le ZIP Wintaskly)</li>
              <li>Clique <strong>Exécuter</strong></li>
              <li>Rechargez cette page : vous devriez voir le formulaire normal</li>
            </ol>

            <p class="wt-muted" style="margin-top:1rem;font-size:.9rem">
              💡 Les migrations sont <strong>idempotentes</strong> — si une colonne existe déjà, MySQL renvoie une erreur que tu peux ignorer.
              Le contenu des migrations se trouve dans <code>sql/</code> à la racine de votre install Wintaskly.
            </p>
          </div>
        </section>
      </div>
    </main>
    <?php include __DIR__ . '/../footer.php'; ?>
    <?php exit;
}

/* ---------- Traitements POST ---------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    /* ---- Raccourcisseur local : gestion des clés API ------------------
     *
     * Ces actions vivent avant la chaîne existante et se terminent par un
     * redirect, pour ne pas interférer avec le formulaire des shortlinks
     * classiques qui partage le même POST.
     * ------------------------------------------------------------------ */
    if (in_array($action, ['local_save', 'local_toggle', 'local_delete'], true)) {
        $lid = (int) ($_POST['local_id'] ?? 0);

        if ($action === 'local_delete' && $lid > 0) {
            $st = $db->prepare("DELETE FROM shortlinks_local WHERE id = ?");
            $st->bind_param('i', $lid);
            $st->execute();
            $st->close();
            wt_admin_log('shortlink_local_delete', ['id' => $lid], $lid);

        } elseif ($action === 'local_toggle' && $lid > 0) {
            $db->query("UPDATE shortlinks_local SET api_active = 1 - api_active WHERE id = " . $lid);
            wt_admin_log('shortlink_local_toggle', ['id' => $lid], $lid);

        } elseif ($action === 'local_save') {
            $title = trim((string) ($_POST['local_title'] ?? ''));

            /* Bornes hautes : steps_count tient dans un SMALLINT, et un
               parcours de plus de 1000 étapes n'a aucun sens économique —
               l'abandon est total bien avant. */
            $steps   = max(1, min(1000, (int) ($_POST['local_steps'] ?? 3)));
            $stepSec = max(1, min(600,  (int) ($_POST['local_step_seconds'] ?? 30)));
            $finSec  = max(1, min(600,  (int) ($_POST['local_final_seconds'] ?? 20)));
            $runMin  = max(1, min(1440, (int) ($_POST['local_run_minutes'] ?? 30)));
            $isLocal = !empty($_POST['local_is_local']) ? 1 : 0;
            $apiOn   = !empty($_POST['local_api_active']) ? 1 : 0;
            $rateAmt = max(0, (float) ($_POST['local_rate_amount'] ?? 0));
            $rateCur = strtoupper(substr(trim((string) ($_POST['local_rate_currency'] ?? 'EUR')), 0, 3));
            $ratePer = max(1, (int) ($_POST['local_rate_per_views'] ?? 1000));
            $cType   = ($_POST['local_content_type'] ?? 'blog') === 'url' ? 'url' : 'blog';
            $cRef    = trim((string) ($_POST['local_content_ref'] ?? ''));

            if ($title === '') {
                $notice = '⚠ ' . t('admin.sl.local.err_title');
                $noticeKind = 'error';
            } elseif ($lid > 0) {
                $st = $db->prepare(
                    "UPDATE shortlinks_local SET
                        title=?, is_local=?, api_active=?, steps_count=?, step_seconds=?,
                        final_seconds=?, run_minutes=?, rate_amount=?, rate_currency=?,
                        rate_per_views=?, content_type=?, content_ref=?
                      WHERE id=?"
                );
                $st->bind_param('siiiiiidsissi', $title, $isLocal, $apiOn, $steps, $stepSec,
                    $finSec, $runMin, $rateAmt, $rateCur, $ratePer, $cType, $cRef, $lid);
                $st->execute();
                $st->close();
                wt_admin_log('shortlink_local_update', ['id' => $lid], $lid);
            } else {
                /* La clé est tirée ici, jamais côté navigateur : une clé
                   produite en JavaScript serait prévisible et visible dans
                   le code source de la page. */
                $key = bin2hex(random_bytes(16));
                $st  = $db->prepare(
                    "INSERT INTO shortlinks_local
                       (title, is_local, api_key, api_active, steps_count, step_seconds,
                        final_seconds, run_minutes, rate_amount, rate_currency,
                        rate_per_views, content_type, content_ref)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('sisiiiiidsiss', $title, $isLocal, $key, $apiOn, $steps,
                    $stepSec, $finSec, $runMin, $rateAmt, $rateCur, $ratePer, $cType, $cRef);
                $st->execute();
                $lid = (int) $st->insert_id;
                $st->close();
                wt_admin_log('shortlink_local_create', ['id' => $lid], $lid);
            }
        }

        header('Location: ' . wt_url('/admin/shortlinks.php?local=' . $lid . '#local'));
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM shortlinks WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.deleted');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE shortlinks SET active = 1 - active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        }
    } elseif ($action === 'save') {
        $id        = (int)   ($_POST['id'] ?? 0);
        $name      = trim((string)($_POST['name'] ?? ''));
        $provider  = trim((string)($_POST['provider'] ?? 'manual'));
        $mode      = ($_POST['mode'] ?? 'manual') === 'api' ? 'api' : 'manual';
        $url       = trim((string)($_POST['destination_url'] ?? ''));
        $apiEnd    = trim((string)($_POST['api_endpoint'] ?? ''));
        $apiTok    = trim((string)($_POST['api_token']    ?? ''));
        $cbKey     = trim((string)($_POST['callback_key'] ?? ''));

        /* Clé de callback obligatoire, y compris en mode manuel.
         *
         * Elle authentifie le retour du raccourcisseur : sans elle, le
         * callback refuse la validation et AUCUN gain n'est crédité — sans
         * message visible pour l'utilisateur, qui conclut simplement que la
         * tâche ne marche pas.
         *
         * À la création, si le champ est laissé vide, on en génère une
         * plutôt que d'enregistrer un lien qui échouera silencieusement.
         * En édition, un champ vide signifie « conserver l'existante »
         * (le secret n'est jamais réaffiché), ce cas est traité plus bas. */
        if ($id <= 0 && $cbKey === '') {
            $cbKey = bin2hex(random_bytes(16));
        }

        // Préservation des secrets : si on édite et que le champ est laissé
        // vide (token masqué non re-saisi), on conserve la valeur en base.
        // $apiTok/$cbKey portent ici la valeur EN CLAIR (saisie) ou vide.
        $apiTokKeepExisting = false;
        $cbKeyKeepExisting  = false;
        if ($id > 0) {
            if ($apiTok === '') $apiTokKeepExisting = true;
            if ($cbKey  === '') $cbKeyKeepExisting  = true;
        }
        $reward    = (float) ($_POST['reward_coins'] ?? 0);
        $rewardXp  = (int)   ($_POST['reward_xp'] ?? 0);
        $cooldownH = (int)   ($_POST['cooldown_hours'] ?? 24);
        $gatewayS  = (int)   ($_POST['gateway_seconds'] ?? 10);
        // Tracking rentabilité (informatif, pas de logique métier dessus)
        $rateAmt   = (float) ($_POST['provider_rate_amount'] ?? 0);
        $rateCur   = strtoupper(substr(trim((string)($_POST['provider_rate_currency'] ?? 'USD')), 0, 3));
        $ratePer   = max(1, (int)($_POST['provider_rate_per_views'] ?? 1000));
        $active    = !empty($_POST['active']) ? 1 : 0;

        // Validation selon le mode :
        //   - 'manual' : destination_url doit être une URL valide (l'admin a déjà
        //                créé le shortlink chez le provider et collé l'URL)
        //   - 'api'    : destination_url = URL FINALE (ex: roboxvpn.com/i/6108)
        //                ET api_endpoint + api_token doivent être renseignés
        //                (Wintaskly appelle l'API du provider à chaque clic).
        $valid = ($name !== '' && filter_var($url, FILTER_VALIDATE_URL));
        if ($mode === 'api' && $apiEnd === '') {
            $valid = false;
            $notice = '⚠ ' . t('admin.sl.api_required');
            $noticeKind = 'error';
        }
        // En mode API, le token est requis SAUF s'il est déjà en base (édition)
        if ($mode === 'api' && $apiTok === '' && !$apiTokKeepExisting) {
            $valid = false;
            $notice = '⚠ ' . t('admin.sl.api_required');
            $noticeKind = 'error';
        }

        if ($valid) {
            // Récupère les secrets existants si on doit les préserver (édition
            // sans re-saisie). Ils sont déjà chiffrés en base → on les garde tels quels.
            $apiTokStore = null;
            $cbKeyStore  = null;
            if ($apiTokKeepExisting || $cbKeyKeepExisting) {
                $exStmt = $db->prepare("SELECT api_token, callback_key FROM shortlinks WHERE id = ?");
                $exStmt->bind_param('i', $id);
                $exStmt->execute();
                $exRow = $exStmt->get_result()->fetch_assoc();
                $exStmt->close();
                if ($apiTokKeepExisting) $apiTokStore = (string)($exRow['api_token'] ?? '');
                if ($cbKeyKeepExisting)  $cbKeyStore  = (string)($exRow['callback_key'] ?? '');
            }
            // Chiffrement des secrets nouvellement saisis (cohérent avec
            // payment_methods). Rétrocompatible : déchiffrés à la lecture.
            if ($apiTokStore === null) {
                $apiTokStore = ($apiTok !== '' && function_exists('wt_encrypt')) ? wt_encrypt($apiTok) : $apiTok;
            }
            if ($cbKeyStore === null) {
                $cbKeyStore = ($cbKey !== '' && function_exists('wt_encrypt')) ? wt_encrypt($cbKey) : $cbKey;
            }

            /* Filet de sécurité en édition.
             *
             * À la création, une clé de rappel absente est générée plus haut.
             * En édition, un champ vide veut dire « garder l'existante » — mais
             * si la ligne en base n'en a jamais eu (créée par SQL, ou avant que
             * cette génération existe), on la réenregistre vide.
             *
             * Or api/get_gateway_link.php n'emprunte la voie API que si les
             * QUATRE champs sont remplis, callback_key comprise. Avec une clé
             * vide, un lien déclaré en mode API repart en mode manuel et suit
             * destination_url — sans le moindre message. Le lien « fonctionne »,
             * mais pas du tout comme configuré.
             *
             * On la génère donc ici aussi, quel que soit le chemin emprunté. */
            if ($mode === 'api' && (string) $cbKeyStore === '') {
                $fresh       = bin2hex(random_bytes(16));
                $cbKeyStore  = function_exists('wt_encrypt') ? wt_encrypt($fresh) : $fresh;
            }
            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE shortlinks SET
                        name=?, provider=?, mode=?,
                        destination_url=?, api_endpoint=?, api_token=?, callback_key=?,
                        reward_coins=?, reward_xp=?, cooldown_hours=?, gateway_seconds=?,
                        provider_rate_amount=?, provider_rate_currency=?, provider_rate_per_views=?,
                        active=?
                     WHERE id=?"
                );
                $stmt->bind_param(
                    'sssssssdiiidsiii',
                    $name, $provider, $mode,
                    $url, $apiEnd, $apiTokStore, $cbKeyStore,
                    $reward, $rewardXp, $cooldownH, $gatewayS,
                    $rateAmt, $rateCur, $ratePer,
                    $active, $id
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.saved');
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO shortlinks
                       (name, provider, mode,
                        destination_url, api_endpoint, api_token, callback_key,
                        reward_coins, reward_xp, cooldown_hours, gateway_seconds,
                        provider_rate_amount, provider_rate_currency, provider_rate_per_views,
                        active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'sssssssdiiidsii',
                    $name, $provider, $mode,
                    $url, $apiEnd, $apiTokStore, $cbKeyStore,
                    $reward, $rewardXp, $cooldownH, $gatewayS,
                    $rateAmt, $rateCur, $ratePer,
                    $active
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.created');
            }
        }
    }
}

/* ---------- Édition d'un item (mode formulaire prérempli) ----------- */
if (!empty($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $db->prepare(
        "SELECT id, name, provider, mode,
                destination_url, api_endpoint, api_token, callback_key,
                reward_coins, reward_xp, cooldown_hours, gateway_seconds,
                provider_rate_amount, provider_rate_currency, provider_rate_per_views,
                active
           FROM shortlinks WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

/* ---------- Taux de conversion pour le calculateur de rentabilité ----
   On récupère depuis withdrawal_methods : "X coins = 1 unité de devise".
   Ex: coins_per_unit=10000 pour USD → 1 USD = 10000 coins → 1 coin = 0.0001 USD.

   On expose un dictionnaire { "USD": 10000, "EUR": 9300, ... } au JS pour
   qu'il calcule en temps réel selon la devise sélectionnée par l'admin
   (provider_rate_currency).

   Si aucune devise n'est définie : fallback à 10000 coins = 1 USD.
   -------------------------------------------------------------------- */
$coinsPerUnit = ['USD' => 10000.0];  // fallback par défaut

/* On entoure d'un try/catch car sur LWS, mysqli est configuré en mode
   strict (MYSQLI_REPORT_STRICT) : une requête sur une table inexistante
   lève une mysqli_sql_exception au lieu de retourner false. Si la table
   withdrawal_methods n'existe pas encore (migration non appliquée), on
   garde simplement le fallback USD sans planter la page. */
try {
    if ($res = $db->query("SELECT currency, MIN(coins_per_unit) AS rate
                             FROM withdrawal_methods
                            WHERE active = 1 AND coins_per_unit > 0
                            GROUP BY currency")) {
        while ($row = $res->fetch_assoc()) {
            $coinsPerUnit[strtoupper((string)$row['currency'])] = (float)$row['rate'];
        }
        $res->free();
    }
} catch (Throwable $e) {
    // Table withdrawal_methods absente ou colonne manquante → fallback USD.
    // On log discrètement pour info, mais la page continue de fonctionner.
    error_log('[Wintaskly shortlinks] withdrawal_methods unavailable: ' . $e->getMessage());
}

/* ---------------------------------------------------------------
 * Taux coins → devises pour le calculateur
 *
 * Attention au sens de coins_per_unit. Le libellé de l'admin dit
 * « coins pour 1 unité de la devise », mais la formule de paiement
 * (api/withdraw_submit.php : payout = coins / coins_per_unit / taux)
 * la traite comme des COINS PAR EURO : la division par le taux fait
 * ensuite la conversion vers la devise. C'est cette seconde lecture
 * qui fait foi ici, puisque c'est elle qui sort l'argent.
 *
 * On retient le minimum : la méthode qui donne le plus de valeur à
 * chaque coin, donc le pire cas pour le site. Un utilisateur qui
 * compare choisira celle-là.
 * --------------------------------------------------------------- */
$coinsDerived = [];
$eurRates     = $GLOBALS['ratesToEur'] ?? ['EUR' => 1.0];
$coinsPerEur  = $coinsPerUnit ? min($coinsPerUnit) : null;

$coinsPerUnit = [];
if ($coinsPerEur !== null && $coinsPerEur > 0) {
    $coinsPerUnit['EUR'] = $coinsPerEur;
    foreach (['USD','GBP','BTC','LTC','DOGE','XMR'] as $cur) {
        $r = (float) ($eurRates[$cur] ?? 0);
        if ($r > 0) {
            $coinsPerUnit[$cur] = $coinsPerEur * $r;
            $coinsDerived[]     = $cur;
        }
    }
}
if (!$coinsPerUnit) { $coinsPerUnit = ['EUR' => 10000.0]; }


/* ---------- Liste --------------------------------------------------- */
$rows = [];
if ($res = $db->query(
    /* Les colonnes de santé ne sont ajoutées que par
       sql/migration_provider_health.sql. On les demande uniquement si
       elles existent : sur une base non migrée, la page continue de
       fonctionner normalement, sans bloc d'état. */
    "SELECT id, name, provider, reward_coins, reward_xp,
            cooldown_hours, gateway_seconds, active"
    . (isset($existingCols['fail_streak'])
        ? ", last_check_at, last_http_code, fail_streak" : "")
    . " FROM shortlinks ORDER BY id DESC"
)) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* ---------- Stats hero --------------------------------------------- */
$nbTotal  = count($rows);
$nbActive = count(array_filter($rows, fn ($r) => (int)$r['active'] === 1));
$totalRewards = 0.0;
foreach ($rows as $r) $totalRewards += (float)$r['reward_coins'];

/* Raccourcisseur local : lignes existantes et ligne en cours d'édition */
$localRows = [];
try {
    if ($res = $db->query("SELECT * FROM shortlinks_local ORDER BY created_at DESC")) {
        while ($r = $res->fetch_assoc()) { $localRows[] = $r; }
        $res->free();
    }
} catch (Throwable $e) {
    /* Table absente : la section invite à réimporter schema.sql plutôt
       que de rendre une page blanche. */
    $localRows = null;
}

$editLocalId = (int) ($_GET['local'] ?? 0);
$editLocal   = null;
if ($editLocalId > 0 && is_array($localRows)) {
    foreach ($localRows as $r) {
        if ((int) $r['id'] === $editLocalId) { $editLocal = $r; break; }
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
          <span class="wt-eyebrow">🔗 <?= e(t('admin.eyebrow_shortlinks')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.shortlinks')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.shortlinks.lead')) ?></p>
        </div>
        <?php if ($editing): ?>
          <a class="wt-btn wt-btn--ghost wt-btn--xs"
             href="<?= e(wt_url('/admin/shortlinks.php')) ?>">
            ← <?= e(t('admin.exit_edit_mode')) ?>
          </a>
        <?php endif; ?>
      </header>

      <?php if ($notice): ?>
        <div class="wt-alert wt-alert--success" data-reveal>
          ✓ <?= e($notice) ?>
        </div>
      <?php endif; ?>

      <!-- ====== Stats hero ====== -->
      <section class="wt-admin-v2__stats" data-reveal>
        <article class="wt-admin-v2__stat" style="--idx:0">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">📊</span>
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
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">💰</span>
          <div>
            <small><?= e(t('admin.stat.total_rewards')) ?></small>
            <strong>
              <?= e(rtrim(rtrim(number_format($totalRewards, 4, '.', ''), '0'), '.')) ?>
              <em><?= e(t('common.coins')) ?></em>
            </strong>
          </div>
        </article>
      </section>

      <!-- ====== Form card (create / edit) ====== -->
      <article class="wt-admin-v2__card" data-reveal>
        <header class="wt-admin-v2__card-head">
          <span class="wt-admin-v2__card-icon" aria-hidden="true">
            <?= $editing ? '✏️' : '➕' ?>
          </span>
          <div>
            <h2>
              <?= $editing
                    ? e(sprintf((string)t('admin.edit_item'), '#' . (int)$editing['id']))
                    : e(t('admin.new_shortlink')) ?>
            </h2>
            <small class="wt-muted">
              <?= e($editing ? t('admin.edit_lead') : t('admin.shortlinks.new_lead')) ?>
            </small>
          </div>
        </header>

        <form method="post" class="wt-admin-v2__form-body">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id"     value="<?= (int)($editing['id'] ?? 0) ?>">

          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.sl.name')) ?></span>
              <input class="wt-input" type="text" name="name" required maxlength="120"
                     value="<?= e((string)($editing['name'] ?? '')) ?>"
                     placeholder="Linkvertise — Niveau 1">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.sl.provider')) ?></span>
              <input class="wt-input" type="text" name="provider" maxlength="64"
                     value="<?= e((string)($editing['provider'] ?? 'manual')) ?>"
                     placeholder="linkvertise / adfly / manual">
              <small class="wt-field__hint"><?= e(t('admin.sl.provider_hint')) ?></small>
            </label>
          </div>

          <label class="wt-field">
            <span class="wt-field__label">⚙️ <?= e(t('admin.sl.mode')) ?></span>
            <select class="wt-input" name="mode" data-sl-mode>
              <?php $curMode = (string)($editing['mode'] ?? 'manual'); ?>
              <option value="manual" <?= $curMode === 'manual' ? 'selected' : '' ?>><?= e(t('admin.sl.mode_manual')) ?></option>
              <option value="api"    <?= $curMode === 'api'    ? 'selected' : '' ?>><?= e(t('admin.sl.mode_api')) ?></option>
            </select>
            <small class="wt-field__hint"><?= e(t('admin.sl.mode_hint')) ?></small>
          </label>

          <label class="wt-field">
            <span class="wt-field__label">🎯 <?= e(t('admin.sl.destination')) ?></span>
            <input class="wt-input" type="url" name="destination_url" required
                   value="<?= e((string)($editing['destination_url'] ?? '')) ?>"
                   placeholder="https://...">
            <small class="wt-field__hint" data-sl-dest-hint><?= e(t('admin.sl.destination_hint')) ?></small>
          </label>

          <!-- Bloc API : visible uniquement en mode 'api' (toggle via JS data-sl-mode) -->
          <div data-sl-api-fields style="<?= $curMode === 'api' ? '' : 'display:none' ?>">
            <label class="wt-field">
              <span class="wt-field__label">🔗 <?= e(t('admin.sl.api_endpoint')) ?></span>
              <input class="wt-input wt-mono" type="url" name="api_endpoint"
                     value="<?= e((string)($editing['api_endpoint'] ?? '')) ?>"
                     placeholder="https://exe.io/api">
              <small class="wt-field__hint"><?= e(t('admin.sl.api_endpoint_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">🔐 <?= e(t('admin.sl.api_token')) ?></span>
              <?php
                // Sécurité : on ne réaffiche jamais le token en clair.
                // Présence détectée via déchiffrement (rétrocompatible).
                $slTokRaw = (string)($editing['api_token'] ?? '');
                $slTokSet = $slTokRaw !== '';
              ?>
              <input class="wt-input wt-mono" type="password" name="api_token"
                     autocomplete="new-password"
                     placeholder="<?= $slTokSet ? '••••••••••••••••• (token enregistré)' : '060a23e2dcb8ac3f1f4c7a9f16cbc...' ?>">
              <small class="wt-field__hint"><?= e(t('admin.sl.api_token_hint')) ?></small>
            </label>
          </div>

          <?php
            /* Clé callback : auto-générée pour les NOUVEAUX shortlinks.
               L'admin peut la regénérer manuellement via le bouton 🎲.
               Pour les liens EXISTANTS, on garde la clé déjà en BDD (ne pas
               la changer sans raison casserait les callbacks en cours).
               Génération : 64 caractères hex (256 bits d'entropie). */
            $cbKeyValue = (string)($editing['callback_key'] ?? '');
            if ($cbKeyValue === '') {
                $cbKeyValue = bin2hex(random_bytes(32));
            }
          ?>
          <label class="wt-field">
            <span class="wt-field__label">🔑 <?= e(t('admin.sl.callback_key')) ?></span>
            <div class="wt-input-group" style="display:flex;gap:.5rem;align-items:stretch">
              <input class="wt-input wt-mono" type="text" name="callback_key" maxlength="190"
                     value="<?= e($cbKeyValue) ?>"
                     placeholder="<?= e(t('admin.sl.callback_key_placeholder')) ?>"
                     data-sl-cb-key
                     style="flex:1;min-width:0">
              <button type="button" class="wt-btn wt-btn--ghost wt-btn--sm"
                      data-sl-cb-regen
                      title="<?= e(t('admin.sl.callback_key_regen')) ?>"
                      style="flex:0 0 auto;white-space:nowrap">
                🎲 <?= e(t('admin.sl.callback_key_regen')) ?>
              </button>
            </div>
            <small class="wt-field__hint"><?= e(t('admin.sl.callback_key_hint')) ?></small>
          </label>

          <!-- ════════════════════════════════════════════════════════
               SECTION : Rentabilité (informationnel pour aide à la décision)

               L'admin saisit le tarif que le provider lui paie (ex: 12 USD
               pour 1000 vues). Wintaskly affiche en temps réel :
                 - Le coût/vue
                 - Combien de coins il devrait distribuer pour rester
                   rentable selon le % de partage souhaité

               Ces champs ne changent PAS la logique de récompense :
               c'est uniquement le champ "Coins" plus bas qui est utilisé
               pour récompenser. Ce calculateur sert juste à éclairer
               l'admin sur ses marges.
               ════════════════════════════════════════════════════════ -->
          <fieldset class="wt-fieldset" style="border:1px solid var(--wt-border);border-radius:var(--wt-radius-md);padding:1rem;margin:1rem 0">
            <legend style="padding:0 .5rem;font-weight:600">
              💹 <?= e(t('admin.sl.rate.legend')) ?>
            </legend>

            <div class="wt-admin-v2__grid-3" style="gap:.75rem">
              <label class="wt-field">
                <span class="wt-field__label"><?= e(t('admin.sl.rate.amount')) ?></span>
                <input class="wt-input" type="number" step="0.0001" min="0"
                       name="provider_rate_amount"
                       value="<?= e((string)($editing['provider_rate_amount'] ?? '0')) ?>"
                       placeholder="12.00"
                       data-sl-rate-amount>
              </label>

              <label class="wt-field">
                <span class="wt-field__label"><?= e(t('admin.sl.rate.currency')) ?></span>
                <select class="wt-input" name="provider_rate_currency" data-sl-rate-currency>
                  <?php $curCur = strtoupper((string)($editing['provider_rate_currency'] ?? 'USD')); ?>
                  <?php foreach (['USD','EUR','GBP','BTC','LTC','DOGE','XMR'] as $cur): ?>
                    <?php if (!isset($coinsPerUnit[$cur])) { continue; } ?>
                    <option value="<?= e($cur) ?>" <?= $curCur === $cur ? 'selected' : '' ?>>
                      <?= e($cur) ?><?= in_array($cur, $coinsDerived, true) ? ' — estimé' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="wt-field">
                <span class="wt-field__label"><?= e(t('admin.sl.rate.per_views')) ?></span>
                <input class="wt-input" type="number" min="1" name="provider_rate_per_views"
                       value="<?= (int)($editing['provider_rate_per_views'] ?? 1000) ?>"
                       data-sl-rate-per-views>
              </label>
            </div>

            <!-- Calculateur en temps réel -->
            <div id="wt-sl-calc" data-sl-calc
                 style="margin-top:1rem;padding:1rem;background:var(--wt-bg-soft);border-radius:var(--wt-radius-md);font-size:.9rem;line-height:1.7;display:none">
              <strong>📊 <?= e(t('admin.sl.rate.calc_title')) ?></strong>
              <div style="margin-top:.5rem">
                <span data-sl-calc-perview></span><br>
                <span data-sl-calc-coinsneeded></span><br>
                <span data-sl-calc-userget></span><br>
                <span data-sl-calc-margin></span>
              </div>
              <?php if ($coinsDerived): ?>
                <p class="wt-muted" style="margin:.6rem 0 0;font-size:.82rem">
                  <?= e(implode(', ', $coinsDerived)) ?> :
                  taux déduit du cache des taux de change, faute de méthode de
                  retrait dans cette devise. Il bouge avec le marché — revérifiez
                  la marge après un changement de cours.
                </p>
              <?php endif; ?>
            </div>
          </fieldset>

          <div class="wt-admin-v2__grid-4">
            <label class="wt-field">
              <span class="wt-field__label">💰 <?= e(t('admin.sl.reward')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0" name="reward_coins"
                     value="<?= e((string)($editing['reward_coins'] ?? '0.5')) ?>"
                     data-sl-reward-coins>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⚡ <?= e(t('admin.sl.reward_xp')) ?></span>
              <input class="wt-input" type="number" min="0" name="reward_xp"
                     value="<?= (int)($editing['reward_xp'] ?? 1) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label">🕐 <?= e(t('admin.sl.cooldown_h')) ?></span>
              <input class="wt-input" type="number" min="1" name="cooldown_hours"
                     value="<?= (int)($editing['cooldown_hours'] ?? 24) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⏳ <?= e(t('admin.sl.gateway_s')) ?></span>
              <input class="wt-input" type="number" min="3" name="gateway_seconds"
                     value="<?= (int)($editing['gateway_seconds'] ?? 10) ?>">
            </label>
          </div>

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
                 href="<?= e(wt_url('/admin/shortlinks.php')) ?>"><?= e(t('common.cancel')) ?></a>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <!-- ====== Liste existante ====== -->
      <!-- ============ Raccourcisseur Wintaskly : clés API ============ -->
      <section class="wt-admin-v2__list-section" id="local" data-reveal>
        <header class="wt-admin-v2__list-head">
          <h2 class="wt-admin-v2__list-title">🔑 <?= e(t('admin.sl.local.title')) ?></h2>
        </header>

        <?php if ($localRows === null): ?>
          <div class="wt-alert wt-alert--warn"><?= e(t('admin.sl.local.no_table')) ?></div>
        <?php else: ?>

          <p class="wt-muted" style="margin:.2rem 0 1rem"><?= e(t('admin.sl.local.intro')) ?></p>

          <form method="post" class="wt-admin-v2__form" style="margin-bottom:1.4rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="local_save">
            <input type="hidden" name="local_id" value="<?= (int) ($editLocal['id'] ?? 0) ?>">

            <div class="wt-field">
              <label class="wt-field__label" for="local_title"><?= e(t('admin.sl.local.f_title')) ?></label>
              <input class="wt-input" id="local_title" name="local_title" required maxlength="120"
                     value="<?= e((string) ($editLocal['title'] ?? '')) ?>"
                     placeholder="<?= e(t('admin.sl.local.f_title_ph')) ?>">
            </div>

            <?php if ($editLocal): ?>
              <?php $localApiUrl = wt_url('/api') . '?api=' . $editLocal['api_key']; ?>
              <div class="wt-field">
                <label class="wt-field__label"><?= e(t('admin.sl.local.f_key')) ?></label>
                <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                  <input class="wt-input" readonly style="flex:1;min-width:240px;font-family:monospace"
                         value="<?= e((string) $editLocal['api_key']) ?>" data-copy-src>
                  <button type="button" class="wt-btn wt-btn--ghost wt-btn--sm" data-copy-btn data-copied="<?= e(t('admin.sl.local.copied')) ?>"><?= e(t('admin.sl.local.copy')) ?></button>
                </div>
              </div>
              <div class="wt-field">
                <label class="wt-field__label"><?= e(t('admin.sl.local.f_endpoint')) ?></label>
                <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap">
                  <input class="wt-input" readonly style="flex:1;min-width:240px;font-family:monospace"
                         value="<?= e($localApiUrl) ?>" data-copy-src>
                  <button type="button" class="wt-btn wt-btn--ghost wt-btn--sm" data-copy-btn data-copied="<?= e(t('admin.sl.local.copied')) ?>"><?= e(t('admin.sl.local.copy')) ?></button>
                </div>
                <small class="wt-field__hint"><?= e(t('admin.sl.local.f_endpoint_hint')) ?></small>
              </div>
            <?php endif; ?>

            <div class="wt-admin-v2__form-grid">
              <div class="wt-field">
                <label class="wt-field__label" for="local_steps"><?= e(t('admin.sl.local.f_steps')) ?></label>
                <input class="wt-input" type="number" id="local_steps" name="local_steps"
                       min="1" max="1000" value="<?= (int) ($editLocal['steps_count'] ?? 3) ?>">
                <small class="wt-field__hint"><?= e(t('admin.sl.local.f_steps_hint')) ?></small>
              </div>
              <div class="wt-field">
                <label class="wt-field__label" for="local_step_seconds"><?= e(t('admin.sl.local.f_step_sec')) ?></label>
                <input class="wt-input" type="number" id="local_step_seconds" name="local_step_seconds"
                       min="1" max="600" value="<?= (int) ($editLocal['step_seconds'] ?? 30) ?>">
              </div>
              <div class="wt-field">
                <label class="wt-field__label" for="local_final_seconds"><?= e(t('admin.sl.local.f_final_sec')) ?></label>
                <input class="wt-input" type="number" id="local_final_seconds" name="local_final_seconds"
                       min="1" max="600" value="<?= (int) ($editLocal['final_seconds'] ?? 20) ?>">
              </div>
              <div class="wt-field">
                <label class="wt-field__label" for="local_run_minutes"><?= e(t('admin.sl.local.f_run_min')) ?></label>
                <input class="wt-input" type="number" id="local_run_minutes" name="local_run_minutes"
                       min="1" max="1440" value="<?= (int) ($editLocal['run_minutes'] ?? 30) ?>">
                <small class="wt-field__hint"><?= e(t('admin.sl.local.f_run_min_hint')) ?></small>
              </div>
            </div>

            <div class="wt-admin-v2__form-grid">
              <div class="wt-field">
                <label class="wt-field__label" for="local_rate_amount"><?= e(t('admin.sl.local.f_rate')) ?></label>
                <input class="wt-input" type="number" step="0.0001" min="0"
                       id="local_rate_amount" name="local_rate_amount"
                       value="<?= e((string) ($editLocal['rate_amount'] ?? '0')) ?>">
                <small class="wt-field__hint"><?= e(t('admin.sl.local.f_rate_hint')) ?></small>
              </div>
              <div class="wt-field">
                <label class="wt-field__label" for="local_rate_currency"><?= e(t('admin.sl.local.f_currency')) ?></label>
                <select class="wt-input" id="local_rate_currency" name="local_rate_currency">
                  <?php foreach (['EUR','USD'] as $cur): ?>
                    <option value="<?= e($cur) ?>" <?= (($editLocal['rate_currency'] ?? 'EUR') === $cur) ? 'selected' : '' ?>><?= e($cur) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="wt-field">
                <label class="wt-field__label" for="local_rate_per_views"><?= e(t('admin.sl.local.f_per')) ?></label>
                <input class="wt-input" type="number" min="1" id="local_rate_per_views"
                       name="local_rate_per_views" value="<?= (int) ($editLocal['rate_per_views'] ?? 1000) ?>">
              </div>
            </div>

            <div class="wt-field">
              <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="local_api_active" value="1" <?= (int) ($editLocal['api_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('admin.sl.local.f_api_active')) ?></span>
              </label>
              <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.4rem">
                <input type="checkbox" name="local_is_local" value="1" <?= (int) ($editLocal['is_local'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('admin.sl.local.f_is_local')) ?></span>
              </label>
            </div>

            <input type="hidden" name="local_content_type" value="<?= e((string) ($editLocal['content_type'] ?? 'blog')) ?>">
            <input type="hidden" name="local_content_ref" value="<?= e((string) ($editLocal['content_ref'] ?? '')) ?>">

            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem">
              <button class="wt-btn wt-btn--primary" type="submit"><?= e($editLocal ? t('admin.sl.local.save') : t('admin.sl.local.generate')) ?></button>
              <?php if ($editLocal): ?>
                <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/admin/shortlinks.php#local')) ?>"><?= e(t('admin.sl.local.new')) ?></a>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($localRows): ?>
            <table class="wt-admin-v2__table">
              <thead>
                <tr>
                  <th><?= e(t('admin.sl.local.c_title')) ?></th>
                  <th><?= e(t('admin.sl.local.c_key')) ?></th>
                  <th><?= e(t('admin.sl.local.c_steps')) ?></th>
                  <th><?= e(t('admin.sl.local.c_rate')) ?></th>
                  <th><?= e(t('admin.sl.local.c_state')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($localRows as $r): ?>
                  <tr>
                    <td><?= e((string) $r['title']) ?></td>
                    <td><code><?= e(substr((string) $r['api_key'], 0, 8)) ?>…</code></td>
                    <td><?= (int) $r['steps_count'] ?> × <?= (int) $r['step_seconds'] ?> s</td>
                    <td><?= e(number_format((float) $r['rate_amount'], 2, ',', ' ')) ?> <?= e((string) $r['rate_currency']) ?> / <?= (int) $r['rate_per_views'] ?></td>
                    <td><?= (int) $r['api_active'] === 1
                          ? '<span style="color:var(--wt-ok,#2e7d32)">● ' . e(t('admin.sl.local.on')) . '</span>'
                          : '<span class="wt-muted">○ ' . e(t('admin.sl.local.off')) . '</span>' ?></td>
                    <td style="white-space:nowrap">
                      <a class="wt-btn wt-btn--ghost wt-btn--sm" href="<?= e(wt_url('/admin/shortlinks.php?local=' . (int) $r['id'] . '#local')) ?>"><?= e(t('admin.sl.local.edit')) ?></a>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="local_toggle">
                        <input type="hidden" name="local_id" value="<?= (int) $r['id'] ?>">
                        <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e((int) $r['api_active'] === 1 ? t('admin.sl.local.disable') : t('admin.sl.local.enable')) ?></button>
                      </form>
                      <form method="post" style="display:inline" onsubmit="return confirm('<?= e(t('admin.sl.local.confirm_del')) ?>')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="local_delete">
                        <input type="hidden" name="local_id" value="<?= (int) $r['id'] ?>">
                        <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.sl.local.del')) ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="wt-admin-v2__list-section" data-reveal>
        <header class="wt-admin-v2__list-head">
          <h2 class="wt-admin-v2__list-title">📋 <?= e(t('admin.existing_items')) ?></h2>
          <span class="wt-muted"><?= count($rows) ?> <?= e(t('common.items')) ?></span>
        </header>

        <?php if (!$rows): ?>
          <div class="wt-admin-v2__empty">
            <span class="wt-admin-v2__empty-icon" aria-hidden="true">🔗</span>
            <p><?= e(t('admin.empty_shortlinks')) ?></p>
          </div>
        <?php else: ?>
          <ul class="wt-admin-v2__entries">
            <?php foreach ($rows as $i => $r):
              $isActive = (int)$r['active'] === 1;
            ?>
              <li class="wt-admin-v2__entry <?= $isActive ? '' : 'is-inactive' ?>"
                  style="--idx:<?= (int)$i ?>">
                <div class="wt-admin-v2__entry-status">
                  <span class="wt-admin-v2__status-dot wt-admin-v2__status-dot--<?= $isActive ? 'on' : 'off' ?>"></span>
                </div>

                <div class="wt-admin-v2__entry-body">
                  <header class="wt-admin-v2__entry-head">
                    <strong><?= e($r['name']) ?></strong>
                    <small class="wt-mono">#<?= (int)$r['id'] ?></small>
                    <?php if (!empty($r['provider']) && $r['provider'] !== 'manual'): ?>
                      <span class="wt-admin-v2__entry-tag"><?= e($r['provider']) ?></span>
                    <?php endif; ?>
                  </header>

                  <?php
                    /* État de santé du fournisseur.
                       Alimenté par la tâche planifiée `provider_health`.
                       Trois cas distincts, à ne pas confondre :
                         • jamais contrôlé  → rien n'est affiché (pas d'info
                           n'est pas une mauvaise nouvelle) ;
                         • échecs en cours  → avertissement avec le compteur,
                           pour agir AVANT la désactivation automatique ;
                         • sain             → discret, on ne surcharge pas. */
                    $_hFail  = (int) ($r['fail_streak'] ?? 0);
                    $_hCode  = (int) ($r['last_http_code'] ?? 0);
                    $_hWhen  = $r['last_check_at'] ?? null;
                    $_hLimit = max(1, (int) cfg('health.fail_limit', 3));
                  ?>
                  <?php if ($_hWhen && $_hFail > 0): ?>
                    <div class="wt-admin-health wt-admin-health--warn">
                      ⚠️ <?= e(sprintf((string) t('admin.health.failing'), $_hFail, $_hLimit)) ?>
                      <?php if ($_hCode > 0): ?>
                        <span class="wt-admin-health__code">HTTP <?= $_hCode ?></span>
                      <?php else: ?>
                        <span class="wt-admin-health__code"><?= e(t('admin.health.unreachable')) ?></span>
                      <?php endif; ?>
                    </div>
                  <?php elseif ($_hWhen): ?>
                    <div class="wt-admin-health wt-admin-health--ok">
                      ✅ <?= e(t('admin.health.ok')) ?>
                      <span class="wt-admin-health__code"><?= e(wt_format_datetime((string) $_hWhen, 'd/m H:i')) ?></span>
                    </div>
                  <?php endif; ?>

                  <div class="wt-admin-v2__entry-meta">
                    <span title="<?= e(t('admin.sl.reward')) ?>">
                      💰 <?= e(rtrim(rtrim(number_format((float)$r['reward_coins'], 4, '.', ''), '0'), '.')) ?>
                    </span>
                    <span title="<?= e(t('admin.sl.reward_xp')) ?>">
                      ⚡ <?= (int)$r['reward_xp'] ?> XP
                    </span>
                    <span title="<?= e(t('admin.sl.cooldown_h')) ?>">
                      🕐 <?= (int)$r['cooldown_hours'] ?>h
                    </span>
                    <span title="<?= e(t('admin.sl.gateway_s')) ?>">
                      ⏳ <?= (int)$r['gateway_seconds'] ?>s
                    </span>
                  </div>
                </div>

                <div class="wt-admin-v2__entry-actions">
                  <a class="wt-btn wt-btn--xs wt-btn--ghost"
                     href="?edit=<?= (int)$r['id'] ?>" title="<?= e(t('common.edit')) ?>">
                    ✏️
                  </a>

                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="wt-btn wt-btn--xs wt-btn--ghost"
                            title="<?= $isActive ? e(t('common.disable')) : e(t('common.enable')) ?>">
                      <?= $isActive ? '⏸' : '▶' ?>
                    </button>
                  </form>

                  <button type="button"
                          class="wt-btn wt-btn--xs wt-btn--danger"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.confirm_delete_title')) ?>"
                          data-confirm-body="<?= e(sprintf((string)t('admin.confirm_delete_body'), e($r['name']))) ?>"
                          data-confirm-ok="<?= e(t('common.delete')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-post="<?= e(wt_url('/admin/shortlinks.php')) ?>"
                          data-confirm-data='<?= e(json_encode(['_csrf' => csrf_token(), 'action' => 'delete', 'id' => (int)$r['id']])) ?>'
                          title="<?= e(t('common.delete')) ?>">
                    🗑
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<script>
/* Toggle des champs API selon le mode sélectionné (manual/api).
   Met aussi à jour le hint du champ destination_url pour clarifier ce que
   l'admin doit y mettre (URL pré-générée vs URL finale du partenaire). */
(function () {
  const modeSel = document.querySelector('[data-sl-mode]');
  const apiBlock = document.querySelector('[data-sl-api-fields]');
  const destHint = document.querySelector('[data-sl-dest-hint]');
  if (!modeSel || !apiBlock) return;

  const hintManual = <?= json_encode(t('admin.sl.destination_hint')) ?>;
  const hintApi    = <?= json_encode(t('admin.sl.destination_hint_api')) ?>;

  function update() {
    const isApi = modeSel.value === 'api';
    apiBlock.style.display = isApi ? '' : 'none';
    if (destHint) destHint.textContent = isApi ? hintApi : hintManual;
  }

  modeSel.addEventListener('change', update);
  update();
})();

/* Bouton 🎲 : regénère une callback_key aléatoire (64 chars hex).
   Utilisé si l'admin veut changer la clé déjà pré-remplie. */
(function () {
  const btn = document.querySelector('[data-sl-cb-regen]');
  const input = document.querySelector('[data-sl-cb-key]');
  if (!btn || !input) return;

  btn.addEventListener('click', function () {
    // Génère 32 octets (256 bits) → 64 caractères hex
    const arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    const hex = Array.from(arr)
                     .map(function (b) { return b.toString(16).padStart(2, '0'); })
                     .join('');
    input.value = hex;
    input.focus();
    input.select();
    // Petit flash visuel pour confirmer
    const orig = btn.textContent;
    btn.textContent = '✓ Régénérée';
    setTimeout(function () { btn.innerHTML = '🎲 ' + <?= json_encode(t('admin.sl.callback_key_regen')) ?>; }, 1200);
  });
})();

/* ════════════════════════════════════════════════════════════════════
   CALCULATEUR DE RENTABILITÉ (temps réel)

   Quand l'admin saisit "12 USD pour 1000 vues" :
     - On calcule le coût par vue (en devise provider)
     - On le convertit en coins (via le taux withdrawal_methods)
     - On compare avec les coins distribués (reward_coins)
     - On affiche la marge brute Wintaskly

   Si l'admin change reward_coins, le calc se met aussi à jour.
   Tout est purement informatif — la BDD garde reward_coins comme source
   de vérité pour les vraies récompenses.
   ════════════════════════════════════════════════════════════════════ */
(function () {
  const amtEl = document.querySelector('[data-sl-rate-amount]');
  const curEl = document.querySelector('[data-sl-rate-currency]');
  const perEl = document.querySelector('[data-sl-rate-per-views]');
  const rewardEl = document.querySelector('[data-sl-reward-coins]');
  const calcBox = document.querySelector('[data-sl-calc]');
  if (!amtEl || !curEl || !perEl || !rewardEl || !calcBox) return;

  // Taux : { "USD": 10000, "EUR": 9300, ... } — coins pour 1 unité de devise
  const COINS_PER_UNIT = <?= json_encode($coinsPerUnit, JSON_UNESCAPED_UNICODE) ?>;

  // Textes i18n (templates avec %s à remplacer)
  const T = {
    perview:     <?= json_encode(t('admin.sl.rate.calc_perview')) ?>,
    coinsneeded: <?= json_encode(t('admin.sl.rate.calc_coinsneeded')) ?>,
    userget:     <?= json_encode(t('admin.sl.rate.calc_userget')) ?>,
    margin_pos:  <?= json_encode(t('admin.sl.rate.calc_margin_pos')) ?>,
    margin_neg:  <?= json_encode(t('admin.sl.rate.calc_margin_neg')) ?>,
    no_rate:     <?= json_encode(t('admin.sl.rate.calc_no_rate')) ?>,
  };

  function fmt(n, dec) { return Number(n).toFixed(dec === undefined ? 4 : dec); }
  function tpl(str, vars) {
    return Object.keys(vars).reduce(function (acc, k) {
      return acc.replace('{' + k + '}', vars[k]);
    }, str);
  }

  function update() {
    const amt    = parseFloat(amtEl.value)    || 0;
    const cur    = (curEl.value || 'USD').toUpperCase();
    const per    = parseInt(perEl.value, 10)  || 1000;
    const reward = parseFloat(rewardEl.value) || 0;

    if (amt <= 0 || per <= 0) {
      // Pas assez d'infos → affiche un état neutre
      calcBox.style.display = 'block';
      calcBox.querySelector('[data-sl-calc-perview]').textContent     = T.no_rate;
      calcBox.querySelector('[data-sl-calc-coinsneeded]').textContent = '';
      calcBox.querySelector('[data-sl-calc-userget]').textContent     = '';
      calcBox.querySelector('[data-sl-calc-margin]').textContent      = '';
      return;
    }

    const rate = COINS_PER_UNIT[cur] || COINS_PER_UNIT['USD'] || 10000;

    // Coût provider par vue (en devise)
    const costPerView = amt / per;
    // Coût provider par vue (en coins)
    const costPerViewCoins = costPerView * rate;
    // Combien le user gagne en devise (reward_coins ÷ rate)
    const userGetCurrency = reward / rate;
    // Marge brute en coins = ce qu'on encaisse - ce qu'on distribue
    const marginCoins  = costPerViewCoins - reward;
    const marginPct    = costPerViewCoins > 0 ? (marginCoins / costPerViewCoins * 100) : 0;

    calcBox.style.display = 'block';
    calcBox.querySelector('[data-sl-calc-perview]').textContent =
      tpl(T.perview, { cost: fmt(costPerView, 6), cur: cur, coins: fmt(costPerViewCoins, 2) });
    calcBox.querySelector('[data-sl-calc-coinsneeded]').textContent =
      tpl(T.coinsneeded, { coins: fmt(costPerViewCoins, 2) });
    calcBox.querySelector('[data-sl-calc-userget]').textContent =
      tpl(T.userget, { reward: fmt(reward, 4), cur: cur, value: fmt(userGetCurrency, 6) });

    const marginEl = calcBox.querySelector('[data-sl-calc-margin]');
    if (marginCoins >= 0) {
      marginEl.textContent = tpl(T.margin_pos, { coins: fmt(marginCoins, 4), pct: fmt(marginPct, 1) });
      marginEl.style.color = 'var(--wt-success, #22c55e)';
    } else {
      marginEl.textContent = tpl(T.margin_neg, { coins: fmt(Math.abs(marginCoins), 4), pct: fmt(Math.abs(marginPct), 1) });
      marginEl.style.color = 'var(--wt-danger, #ef4444)';
    }
  }

  [amtEl, curEl, perEl, rewardEl].forEach(function (el) {
    el.addEventListener('input', update);
    el.addEventListener('change', update);
  });
  update();
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
