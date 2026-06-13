<?php
/**
 * Wintaskly — POST /api/auth_resend_verification.php
 *
 * Renvoie l'e-mail de vérification au compte en attente,
 * avec un cooldown serveur de 60 secondes entre deux envois.
 *
 * Réponse JSON :
 *   { ok:true }
 *   { ok:false, error:'…', cooldown?:N }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$db = db();

/* ====================================================================
   Résout l'utilisateur à qui renvoyer le mail.

   Sources possibles (par ordre de priorité) :
   1) $_SESSION['pending_resend_uid']  — posé par /api/auth_signup.php au
      moment de l'inscription, c'est le cas nominal.
   2) current_user() avec status='pending'  — fallback si la session
      'pending_resend_uid' a été perdue (regen, autre tab, etc.) mais
      l'utilisateur est tout de même connecté à un compte non vérifié.

   Si aucune source n'est trouvée, on retourne un message d'erreur clair
   pour que le user comprenne qu'il doit se reconnecter d'abord.
   ==================================================================== */
$uid = (int)($_SESSION['pending_resend_uid'] ?? 0);

if ($uid <= 0) {
    // Fallback : on essaie via current_user()
    $cu = current_user();
    if ($cu && empty($cu['email_verified_at'])) {
        $uid = (int) $cu['id'];
        // On (ré)injecte en session pour les prochains clics
        $_SESSION['pending_resend_uid'] = $uid;
    }
}

if ($uid <= 0) {
    // Vraiment aucun contexte → message explicite
    wt_json([
        'ok'    => false,
        'error' => t('auth.verify_email.no_context'),
    ]);
}

// Cooldown 60 s en session
$nextAllowed = (int)($_SESSION['next_resend_at'] ?? 0);
$now = time();
if ($nextAllowed > $now) {
    wt_json([
        'ok' => false,
        'error' => t('auth.verify_email.cooldown'),
        'cooldown' => $nextAllowed - $now,
    ], 429);
}

$stmt = $db->prepare(
    "SELECT id, username, email, status, email_verified_at
       FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Si l'email est DÉJÀ vérifié, pas la peine de renvoyer — message clair
if (!$row) {
    wt_json(['ok' => false, 'error' => t('common.error')]);
}
if (!empty($row['email_verified_at']) || $row['status'] === 'active') {
    wt_json([
        'ok'      => false,
        'error'   => t('auth.verify_email.already_verified'),
        'already' => true,
    ]);
}
if ($row['status'] !== 'pending') {
    wt_json(['ok' => false, 'error' => t('common.error')]);
}

// Invalide les anciens tokens et en génère un nouveau
auth_tokens_revoke((int) $row['id'], 'verify_email');

$ttl  = (int)($GLOBALS['WT_CONFIG']['auth']['verify_email_ttl'] ?? 86400);
$raw  = auth_token_create((int) $row['id'], 'verify_email', $ttl);
$link = wt_url('/auth/verify-email.php?token=' . urlencode($raw));

// Tente l'envoi du mail — si échec, on log et on dit clairement à l'user
$sent = wt_mail($row['email'], 'verify_email', [
    'username' => $row['username'],
    'link'     => $link,
]);

if (!$sent) {
    // L'envoi a échoué (SMTP down, config mailer incorrecte, etc.)
    // On ne pose PAS le cooldown pour que l'user puisse retenter sans
    // attendre 60s (ce serait frustrant si c'est un bug serveur).
    error_log('[Wintaskly auth_resend] wt_mail failed for user_id=' . $row['id']
            . ' email=' . $row['email']);
    wt_json([
        'ok'    => false,
        'error' => t('auth.verify_email.send_failed'),
    ]);
}

$_SESSION['next_resend_at'] = $now + 60;
wt_json(['ok' => true, 'cooldown' => 60]);
