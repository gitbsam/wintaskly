<?php
/**
 * Wintaskly — POST /api/auth_forgot.php
 *
 * Reçoit une adresse email. Si elle existe et que le compte est actif,
 * on envoie un e-mail avec un token de réinitialisation (1h de durée).
 * Réponse TOUJOURS générique pour empêcher l'énumération de comptes.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$email = trim((string)($_POST['email'] ?? ''));
$generic = ['ok' => true, 'message' => (string) t('auth.forgot.sent_generic')];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // On répond positivement quand même pour ne pas révéler la validation
    wt_json($generic);
}

// Rate-limit léger : pas plus de 3 demandes par IP toutes les 15 min
$ipBin = wt_ip_bin();
[$blocked,] = auth_attempt_blocked('__forgot__:' . $email, $ipBin);
if ($blocked) {
    wt_json($generic);
}
auth_attempt_record('__forgot__:' . $email, $ipBin, false);

$db = db();
$stmt = $db->prepare(
    "SELECT id, username, email, status FROM users
      WHERE email = ? LIMIT 1"
);
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && in_array($row['status'], ['active', 'pending'], true)) {
    // Invalide les anciens tokens
    auth_tokens_revoke((int) $row['id'], 'reset_password');

    $ttl  = (int)($GLOBALS['WT_CONFIG']['auth']['reset_password_ttl'] ?? 3600);
    $raw  = auth_token_create((int) $row['id'], 'reset_password', $ttl);
    $link = wt_url('/auth/reset-password.php?token=' . urlencode($raw));

    wt_mail($row['email'], 'reset_password', [
        'username' => $row['username'],
        'link'     => $link,
    ]);
}

wt_json($generic);
