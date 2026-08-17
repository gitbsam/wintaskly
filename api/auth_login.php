<?php
/**
 * Wintaskly — POST /api/auth_login.php
 *
 * Réponse JSON :
 *   { ok: true,  redirect: '/dashboard/' }
 *   { ok: true,  two_factor_required: true, redirect: '/auth/verify-2fa.php' }
 *   { ok: false, error: '…', cooldown?: secondes }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password   = (string)        ($_POST['password']   ?? '');
$remember   = !empty($_POST['remember']);

if ($identifier === '' || $password === '') {
    wt_json(['ok' => false, 'error' => t('auth.required')]);
}

$ipBin = wt_ip_bin();

// 1) Rate-limit
[$blocked, $left] = auth_attempt_blocked($identifier, $ipBin);
if ($blocked) {
    wt_json([
        'ok' => false,
        'error' => t('auth.rate_limited'),
        'cooldown' => $left,
    ], 429);
}

// 2) Recherche utilisateur (email OU username)
$db = db();
$stmt = $db->prepare(
    "SELECT id, username, email, password_hash, status, role,
            totp_enabled, totp_secret,
            twofa_email_enabled, twofa_sms_enabled,
            twofa_phone, twofa_phone_verified_at, twofa_preferred
       FROM users
      WHERE email = ? OR username = ?
      LIMIT 1"
);
$stmt->bind_param('ss', $identifier, $identifier);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 3) Vérification mot de passe (constante-time grâce à password_verify)
if (!$row || !password_verify($password, $row['password_hash'])) {
    auth_attempt_record($identifier, $ipBin, false);
    wt_json(['ok' => false, 'error' => t('auth.invalid')]);
}

// 4) Statut compte
if ($row['status'] === 'banned' || $row['status'] === 'suspended') {
    auth_attempt_record($identifier, $ipBin, false);
    wt_json(['ok' => false, 'error' => t('auth.banned')]);
}
if ($row['status'] === 'pending') {
    auth_attempt_record($identifier, $ipBin, false);
    // On garde le contexte pour la page verify-email
    $_SESSION['pending_verify_email'] = $row['email'];
    wt_json([
        'ok' => false,
        'error' => t('auth.email_not_verified'),
        'redirect' => wt_url('/auth/verify-email.php'),
    ]);
}

// 5) Branche 2FA
/* On ne teste plus seulement le TOTP : le compte peut utiliser l'e-mail, le
   SMS, ou plusieurs méthodes. wt_2fa_user_methods() ne retourne que celles
   qui sont activées par l'admin ET par l'utilisateur ET techniquement
   délivrables — un canal non configuré ne peut donc pas bloquer l'accès. */
$twofaMethods = wt_2fa_user_methods($row);
if ($twofaMethods !== []) {
    auth_attempt_record($identifier, $ipBin, true);
    $_SESSION['pending_2fa_uid']      = (int) $row['id'];
    $_SESSION['pending_2fa_remember'] = $remember ? 1 : 0;
    $_SESSION['pending_2fa_methods']  = $twofaMethods;
    // Méthode proposée en premier = la plus prioritaire (choix utilisateur,
    // sinon recommandation admin). L'utilisateur pourra en changer.
    $_SESSION['pending_2fa_method']   = $twofaMethods[0];
    wt_json([
        'ok' => true,
        'two_factor_required' => true,
        'method'   => $twofaMethods[0],
        'redirect' => wt_url('/auth/verify-2fa.php'),
    ]);
}

// 6) Connexion normale
auth_attempt_record($identifier, $ipBin, true);
session_regenerate_id(true);
$_SESSION['uid'] = (int) $row['id'];
// On stocke aussi le rôle pour permettre au middleware maintenance (init.php)
// de savoir rapidement si c'est un admin sans avoir à requêter la BDD.
$_SESSION['role'] = (string) ($row['role'] ?? 'user');

// Alerte de connexion : même sans 2FA, l'utilisateur doit pouvoir détecter
// un accès qu'il n'a pas initié. C'est la seule chose qu'un attaquant
// disposant du mot de passe ne peut pas empêcher.
if (function_exists('wt_2fa_alert_new_login')) {
    wt_2fa_alert_new_login($row);
}

$upd = $db->prepare(
    "UPDATE users
        SET last_login_at = UTC_TIMESTAMP(),
            last_login_ip = ?
      WHERE id = ?"
);
$upd->bind_param('si', $ipBin, $row['id']);
$upd->execute();
$upd->close();

if ($remember) {
    auth_remember_set((int) $row['id']);
}

wt_json(['ok' => true, 'redirect' => wt_url('/dashboard/')]);
