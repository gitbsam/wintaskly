<?php
/**
 * Wintaskly — /api/dripoffers_postback.php
 *
 * Postback Server-to-Server pour Drip Offers.
 * Documentation : https://dripoffers.com/docs/
 *
 * Paramètres POST / REQUEST envoyés par Drip Offers :
 *   - subId     : ID utilisateur Wintaskly
 *   - transId   : ID de transaction unique chez Drip Offers
 *   - reward    : Montant des Coins / Points attribués
 *   - status    : "1" = Crédit, "2" = Chargeback (débit)
 *   - signature : md5(subId . transId . reward . secret)
 *   - userIp, country, offer_name, offer_type, payout, debug...
 *
 * Retours :
 *   "ok"                      si succès (exigé par Drip Offers)
 *   "ok"                      si déjà traité (idempotence)
 *   403 + "BAD_SIGNATURE"     si signature invalide
 *   403 + "INVALID_IP"        si requête en dehors des IP autorisées
 *   400 + "BAD_REQUEST"       si paramètre manquant
 *   404 + "OFFERWALL_NOT_FOUND" si l'offerwall 'dripoffers' n'existe pas en BDD
 *   404 + "USER_NOT_FOUND"
 *   500 + "ERR"
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

header('Content-Type: text/plain; charset=utf-8');

// 1) Extraction de l'IP réelle du client (prise en compte des proxys / Cloudflare)
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] 
         ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
         ?? $_SERVER['REMOTE_ADDR'] 
         ?? '';

if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

// 2) Récupération du mur d'offres en BDD (clé + secret)
$k  = 'dripoffers';
$db = db();

$stmt = $db->prepare(
    "SELECT id, name, callback_secret, active
       FROM offerwalls
      WHERE k = ?
      LIMIT 1"
);
$stmt->bind_param('s', $k);
$stmt->execute();
$ow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ow) {
    http_response_code(404);
    echo 'OFFERWALL_NOT_FOUND';
    exit;
}
if ((int) $ow['active'] !== 1) {
    http_response_code(403);
    echo 'OFFERWALL_DISABLED';
    exit;
}

// 3) Vérification optionnelle de l'IP émettrice
$checkIp    = cfg_bool('dripoffers.check_ip', true);
$rawIps     = (string) cfg('dripoffers.allowed_ips', '89.116.149.103');
$allowedIps = array_filter(array_map('trim', explode(',', $rawIps)));

/* ⚠️ FAILLE CORRIGÉE
 *
 * Le paramètre `debug=1` désactivait la liste blanche d'adresses IP pour
 * n'importe quel appelant, en production et sans limite de durée. La
 * signature restait exigée, donc ce n'était pas un contournement complet —
 * mais si le secret venait à fuiter (fichier partagé, capture d'écran,
 * message), la liste blanche était la dernière barrière, et `debug=1`
 * l'effaçait.
 *
 * Le mode test est désormais commandé par un réglage d'administration
 * ASSORTI D'UNE ÉCHÉANCE : on l'active pour l'intégration, il se referme
 * seul. Un mode test qu'on doit penser à désactiver reste ouvert des mois. */
$debugUntil  = (int) cfg('dripoffers.debug_until', 0);
$isDebugTest = $debugUntil > time();

if ($checkIp && !$isDebugTest) {
    if (!in_array($clientIp, $allowedIps, true)) {
        http_response_code(403);
        if (function_exists('wt_postback_log')) {
            wt_postback_log('dripoffers', 'INVALID_IP', $clientIp);
        }
        echo 'INVALID_IP';
        exit;
    }
}

// 4) Récupération des paramètres Drip Offers
$uid    = (int) ($_REQUEST['subId']     ?? 0);
$tx     = trim((string) ($_REQUEST['transId']  ?? ''));
$amt    = (float) ($_REQUEST['reward']   ?? 0);
$sig    = trim((string) ($_REQUEST['signature'] ?? ''));
$status = (int) ($_REQUEST['status']    ?? 1); // 1 = Valid, 2 = Chargeback

if ($uid <= 0 || $tx === '' || $amt <= 0 || $sig === '') {
    http_response_code(400);
    echo 'BAD_REQUEST';
    exit;
}

// Récupération de la clé secrète configurée dans l'admin
$secretKey = (string) cfg('dripoffers.secret_key', '');
if (empty($secretKey) && !empty($ow['callback_secret'])) {
    $secretKey = function_exists('wt_decrypt') ? wt_decrypt((string)$ow['callback_secret']) : (string)$ow['callback_secret'];
}

if (empty($secretKey)) {
    http_response_code(403);
    echo 'NO_SECRET_CONFIGURED';
    exit;
}

// 5) Vérification de la signature MD5 : md5(subId . transId . reward . secret)
$payload  = $uid . $tx . $_REQUEST['reward'] . $secretKey;
$expected = md5($payload);

if (!hash_equals(strtolower($expected), strtolower($sig))) {
    http_response_code(403);
    /* Répété, ce motif signifie presque toujours que le secret enregistré
       ne correspond plus à celui du fournisseur — pas qu'on vous attaque. */
    if (function_exists('wt_postback_log')) {
        wt_postback_log('dripoffers', 'BAD_SIGNATURE', $tx);
    }
    echo 'BAD_SIGNATURE';
    exit;
}

// 6) Vérification de l'utilisateur
$stmt = $db->prepare("SELECT id, status FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo 'USER_NOT_FOUND';
    exit;
}
if ($user['status'] !== 'active') {
    http_response_code(403);
    echo 'USER_INACTIVE';
    exit;
}

// En cas de chargeback (status == 2), le montant devient négatif
$finalAmt = ($status === 2) ? -abs($amt) : abs($amt);

// 7) Traitement avec idempotence (uniq sur offerwall_id + external_tx_id)
$rawPayload = json_encode($_REQUEST, JSON_UNESCAPED_SLASHES);
$ipBin      = wt_ip_bin();

$db->begin_transaction();
try {
    $owId = (int) $ow['id'];

    $stmt = $db->prepare(
        "SELECT id, status FROM offerwall_transactions
          WHERE offerwall_id = ? AND external_tx_id = ?
          FOR UPDATE"
    );
    $stmt->bind_param('is', $owId, $tx);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $db->commit();
        echo 'ok'; // Exigé par Drip Offers
        exit;
    }

    // Insertion de la transaction
    $txStatus = ($status === 2) ? 'chargeback' : 'credited';
    $stmt = $db->prepare(
        "INSERT INTO offerwall_transactions
            (user_id, offerwall_id, external_tx_id, coins, status,
             raw_payload, signature, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'iisdssss',
        $uid, $owId, $tx, $finalAmt, $txStatus, $rawPayload, $sig, $ipBin
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // Crédit / Débit de l'utilisateur
    award_user($uid, $finalAmt, 0, 'offerwall', $ow['name'] . '#' . $tx);

    // Drip Offers exige exactement "ok"
    echo 'ok';

} catch (Throwable $e) {
    $db->rollback();
    error_log('dripoffers_postback: ' . $e->getMessage());
    http_response_code(500);
    echo 'ERR';
}