<?php
/**
 * Wintaskly — api/postback_cpx.php
 * ---------------------------------------------------------------------------
 * Réception des postbacks CPX Research.
 *
 * CPX appelle cette URL à chaque événement : sondage complété, écartage
 * indemnisé, évaluation, et — point crucial — ANNULATION pour fraude.
 *
 * URL à renseigner dans l'interface CPX (onglet « Paramètres de publication ») :
 *
 *   https://VOTRE-DOMAINE/api/postback_cpx.php?status={status}&trans_id={trans_id}
 *     &user_id={user_id}&subid_1={subid_1}&subid_2={subid_2}
 *     &amount_local={amount_local}&amount_usd={amount_usd}&type={type}
 *     &offer_id={offer_id}&ip_click={ip_click}&hash={secure_hash}
 *
 * TROIS PROTECTIONS, ET POURQUOI CHACUNE
 * --------------------------------------
 *
 * 1. SIGNATURE — CPX calcule md5({trans_id}-{secure_hash}). Sans cette
 *    vérification, n'importe qui connaissant l'URL pourrait se créditer des
 *    Coins par une simple requête GET. C'est la protection principale.
 *
 * 2. LISTE BLANCHE D'ADRESSES IP — les appels ne viennent que de trois
 *    adresses connues, documentées par CPX. Elle complète la signature :
 *    si le hachage venait à fuiter, l'origine resterait contrôlée. Elle
 *    peut être désactivée si votre hébergement place un proxy devant PHP
 *    et masque l'IP réelle.
 *
 * 3. IDEMPOTENCE — CPX rappelle la même URL en cas d'annulation, et peut
 *    répéter un appel en cas de doute réseau. Le couple (offerwall,
 *    trans_id) est unique : un même événement ne peut donc pas créditer
 *    deux fois.
 *
 * LE CAS status=2 : L'ANNULATION
 * ------------------------------
 * C'est le point que la plupart des intégrations oublient, et il coûte cher.
 * CPX détecte une fraude jusqu'à 60 jours après coup et rappelle cette URL
 * avec status=2. Il faut alors REPRENDRE les Coins déjà versés — sinon la
 * plateforme paie des sondages qui ne lui seront jamais réglés.
 *
 * Le solde peut être devenu insuffisant entre-temps (retrait effectué). On
 * autorise donc un solde négatif plutôt que d'abandonner la reprise :
 * l'utilisateur devra regagner ce qu'il doit avant de retirer à nouveau.
 * C'est le comportement standard du secteur.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

/* Réponse en texte brut : CPX attend un corps simple, pas du JSON. */
header('Content-Type: text/plain; charset=utf-8');

function cpx_fail(string $reason, int $code = 400): void
{
    /* Journalisation avant sortie : c'est le seul point par lequel passent
       tous les refus, donc le seul endroit où une trace est garantie. */
    if (function_exists('wt_postback_log')) {
        wt_postback_log('cpx', $reason, (string) ($_GET['trans_id'] ?? ''));
    }
    http_response_code($code);
    error_log('[Wintaskly CPX] refus : ' . $reason . ' — ' . ($_SERVER['QUERY_STRING'] ?? ''));
    echo $reason;
    exit;
}

/* ---- Réglages ---------------------------------------------------------- */
$appId  = trim((string) cfg('cpx.app_id', ''));
$secret = trim((string) cfg('cpx.secure_hash', ''));
$owKey  = trim((string) cfg('cpx.offerwall_key', 'cpx'));

if ($appId === '' || $secret === '') {
    cpx_fail('NOT_CONFIGURED', 503);
}

/* ---- Paramètres reçus -------------------------------------------------- */
$status   = (string) ($_GET['status']       ?? '');
$transId  = trim((string) ($_GET['trans_id'] ?? ''));
$userId   = (int)    ($_GET['user_id']      ?? 0);
$amtLocal = (float)  ($_GET['amount_local'] ?? 0);
$amtUsd   = (float)  ($_GET['amount_usd']   ?? 0);
$type     = (string) ($_GET['type']         ?? '');
$hash     = strtolower(trim((string) ($_GET['hash'] ?? '')));

if ($transId === '' || $userId <= 0 || $hash === '') {
    cpx_fail('MISSING_PARAMS');
}

/* ---- 1) Signature ------------------------------------------------------ */
/* md5({trans_id}-{secure_hash}), conformément à la documentation CPX.
   hash_equals() plutôt que == : la comparaison est à temps constant, ce qui
   évite de laisser deviner le hachage caractère par caractère. */
$expected = md5($transId . '-' . $secret);
if (!hash_equals($expected, $hash)) {
    /* Journal de diagnostic.
     *
     * Un BAD_HASH a presque toujours la même cause : le hachage renseigné
     * en administration n'est pas celui de votre compte CPX (régénéré,
     * mal copié, ou espace parasite en début/fin).
     *
     * On journalise les deux valeurs pour permettre la comparaison — le
     * SECRET lui-même n'apparaît jamais, seulement le résultat calculé.
     * Sans cette trace, diagnostiquer revient à deviner. */
    error_log(sprintf(
        '[Wintaskly CPX] BAD_HASH — trans_id=%s attendu=%s recu=%s '
        . '(verifiez le hachage de securite dans Admin > Reglages > CPX Research)',
        $transId, $expected, $hash
    ));
    cpx_fail('BAD_HASH', 403);
}

/* ---- 2) Liste blanche d'adresses IP ------------------------------------ */
if ((string) cfg('cpx.check_ip', '1') === '1') {
    $allowed = array_filter(array_map('trim', explode(',', (string) cfg(
        'cpx.allowed_ips',
        '188.40.3.73,2a01:4f8:d0a:30ff::2,157.90.97.92'
    ))));
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($allowed && !in_array($remote, $allowed, true)) {
        cpx_fail('IP_NOT_ALLOWED', 403);
    }
}

/* ---- 3) Utilisateur ---------------------------------------------------- */
$user = db_one("SELECT id, username, status FROM users WHERE id = ? LIMIT 1", [$userId], 'i');
if (!$user) {
    cpx_fail('UNKNOWN_USER', 404);
}

/* ---- Mur d'offres correspondant ---------------------------------------- */
$ow = db_one("SELECT id, name FROM offerwalls WHERE k = ? LIMIT 1", [$owKey], 's');
if (!$ow) {
    cpx_fail('OFFERWALL_MISSING', 503);
}
$owId = (int) $ow['id'];

$db      = db();
$payload = (string) ($_SERVER['QUERY_STRING'] ?? '');
$ipBin   = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;

/* ======================================================================
 * ANNULATION (status = 2)
 * ====================================================================== */
if ($status === '2') {
    /* On ne reprend que ce qui a effectivement été crédité. Reprendre une
       transaction inconnue créerait une dette sortie de nulle part. */
    $orig = db_one(
        "SELECT id, coins, status FROM offerwall_transactions
          WHERE offerwall_id = ? AND external_tx_id = ? LIMIT 1",
        [$owId, $transId], 'is'
    );

    if (!$orig || $orig['status'] !== 'credited') {
        echo 'OK_NOTHING_TO_REVERSE';
        exit;
    }

    $take = (float) $orig['coins'];
    try {
        $db->begin_transaction();

        /* Solde autorisé à devenir négatif : entre le crédit et l'annonce
           de fraude (jusqu'à 60 jours), l'utilisateur a pu retirer. Ne rien
           reprendre reviendrait à absorber la perte à sa place. */
        $st = $db->prepare("UPDATE users SET coins = coins - ? WHERE id = ?");
        $st->bind_param('di', $take, $userId);
        $st->execute();
        $st->close();

        $st = $db->prepare(
            "UPDATE offerwall_transactions
                SET status = 'rejected', reject_reason = 'cpx_chargeback'
              WHERE id = ?"
        );
        $st->bind_param('i', $orig['id']);
        $st->execute();
        $st->close();

        /* Trace comptable : sans elle, l'utilisateur verrait son solde
           baisser sans explication dans son historique. */
        $neg  = -$take;
        $meta = 'cpx_chargeback:' . $transId;
        $st = $db->prepare(
            "INSERT INTO transactions (user_id, type, coins, meta)
             /* Type 'admin' : 'adjustment' n'existe pas dans l'ENUM
                de la table, l'insertion aurait echoue et annule toute la
                reprise — le solde serait reste credite. */
             VALUES (?, 'admin', ?, ?)"
        );
        $st->bind_param('ids', $userId, $neg, $meta);
        $st->execute();
        $st->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        error_log('[Wintaskly CPX] reprise echouee : ' . $e->getMessage());
        cpx_fail('REVERSE_FAILED', 500);
    }

    if (function_exists('wt_notify')) {
        wt_notify($userId, 'system',
            (string) t('cpx.notif_reversed_title'),
            (string) t('cpx.notif_reversed_body'));
    }
    echo 'OK_REVERSED';
    exit;
}

/* ======================================================================
 * CRÉDIT (status = 1)
 * ====================================================================== */
if ($status !== '1') {
    cpx_fail('BAD_STATUS');
}

if ($user['status'] !== 'active') {
    /* Compte suspendu : on enregistre l'événement sans créditer, pour
       garder la trace si la suspension est levée. */
    try {
        $st = $db->prepare(
            "INSERT IGNORE INTO offerwall_transactions
               (user_id, offerwall_id, external_tx_id, coins, status, reject_reason, raw_payload, ip)
             VALUES (?, ?, ?, ?, 'rejected', 'user_not_active', ?, ?)"
        );
        $st->bind_param('iisdss', $userId, $owId, $transId, $amtLocal, $payload, $ipBin);
        $st->execute();
        $st->close();
    } catch (Throwable $e) { /* trace seulement */ }
    echo 'OK_USER_INACTIVE';
    exit;
}

if ($amtLocal <= 0) {
    /* Montant nul : cas des évaluations non rémunérées. On acquitte sans
       créditer, sinon CPX rappellerait indéfiniment. */
    echo 'OK_ZERO';
    exit;
}

try {
    $db->begin_transaction();

    /* Idempotence : la clé unique (offerwall_id, external_tx_id) fait
       échouer l'insertion d'un doublon. On teste affected_rows plutôt que
       de vérifier avant — entre un SELECT et un INSERT, deux appels
       simultanés passeraient tous les deux. */
    $st = $db->prepare(
        "INSERT IGNORE INTO offerwall_transactions
           (user_id, offerwall_id, external_tx_id, coins, status, raw_payload, signature, ip)
         VALUES (?, ?, ?, ?, 'credited', ?, ?, ?)"
    );
    $st->bind_param('iisdsss', $userId, $owId, $transId, $amtLocal, $payload, $hash, $ipBin);
    $st->execute();
    $inserted = $st->affected_rows > 0;
    $st->close();

    if (!$inserted) {
        $db->rollback();
        echo 'OK_DUPLICATE';
        exit;
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('[Wintaskly CPX] insertion echouee : ' . $e->getMessage());
    cpx_fail('DB_ERROR', 500);
}

/* Crédit hors transaction : award_user() gère lui-même XP, niveau et
   commission de parrainage, et ouvre sa propre transaction. L'imbriquer
   provoquerait une validation implicite au mauvais moment. */
$label = ($type !== '' ? $type : 'survey') . '#' . $transId;
award_user($userId, $amtLocal, 0, 'offerwall', 'CPX ' . $label);

if (function_exists('wt_postback_log')) {
    wt_postback_log('cpx', 'OK', $transId);
}
echo 'OK';
