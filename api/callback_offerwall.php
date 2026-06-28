<?php
/**
 * Wintaskly — /api/callback_offerwall.php
 *
 * Postback Server-to-Server depuis un mur d'offres (Wannads, CPALead, …).
 *
 * Paramètres GET acceptés (le provider doit utiliser ces clés ou
 * être mappé via une couche de transformation) :
 *   - offerwall : clé interne (ex. "wannads")
 *   - user      : ID utilisateur Wintaskly
 *   - tx        : ID de transaction unique chez le provider
 *   - amount    : montant en Coins à créditer (entier ou décimal)
 *   - sig       : HMAC-SHA256( offerwall|user|tx|amount , callback_secret )
 *
 * Retours :
 *   200 OK + "OK"             si succès
 *   200 OK + "DUPLICATE"      si déjà traité (idempotence)
 *   403 + "BAD_SIGNATURE"     si signature invalide
 *   400 + "BAD_REQUEST"       si paramètre manquant
 *   404 + "OFFERWALL_NOT_FOUND" si clé inconnue
 *   404 + "USER_NOT_FOUND"
 *   500 + "ERR"
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

header('Content-Type: text/plain; charset=utf-8');

$k     = trim((string)($_GET['offerwall'] ?? ''));
$uid   = (int)($_GET['user']    ?? 0);
$tx    = trim((string)($_GET['tx']    ?? ''));
$amt   = (float)($_GET['amount'] ?? 0);
$sig   = trim((string)($_GET['sig']    ?? ''));

if ($k === '' || $uid <= 0 || $tx === '' || $amt <= 0 || $sig === '') {
    http_response_code(400);
    echo 'BAD_REQUEST';
    exit;
}

$db = db();

// 1) Récupération offerwall (clé + secret)
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
if (empty($ow['callback_secret'])) {
    http_response_code(403);
    echo 'NO_SECRET_CONFIGURED';
    exit;
}

// 2) Vérification HMAC-SHA256 en temps constant
// Déchiffrement du secret stocké (rétrocompatible : clair lu tel quel)
$cbSecretPlain = function_exists('wt_decrypt') ? wt_decrypt((string) $ow['callback_secret']) : (string) $ow['callback_secret'];
$payload   = $k . '|' . $uid . '|' . $tx . '|' . $amt;
$expected  = hash_hmac('sha256', $payload, $cbSecretPlain);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo 'BAD_SIGNATURE';
    exit;
}

// 3) Vérification utilisateur
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

// 4) Idempotence (uniq sur offerwall_id + external_tx_id)
$rawPayload = json_encode($_GET, JSON_UNESCAPED_SLASHES);
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
        echo 'DUPLICATE';
        exit;
    }

    // 5) Insertion
    $stmt = $db->prepare(
        "INSERT INTO offerwall_transactions
            (user_id, offerwall_id, external_tx_id, coins, status,
             raw_payload, signature, ip)
         VALUES (?, ?, ?, ?, 'credited', ?, ?, ?)"
    );
    $stmt->bind_param(
        'iisdsss',
        $uid, $owId, $tx, $amt, $rawPayload, $sig, $ipBin
    );
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // 6) Crédit + commission parrain 10 % (hors de la transaction "tx postback")
    award_user($uid, $amt, 0, 'offerwall', $ow['name'] . '#' . $tx);

    echo 'OK';

} catch (Throwable $e) {
    $db->rollback();
    error_log('callback_offerwall: ' . $e->getMessage());
    http_response_code(500);
    echo 'ERR';
}
