<?php
/**
 * Wintaskly — POST /api/account_email.php
 *
 * Changement d'adresse e-mail (exige le mot de passe actuel).
 * Après modification, l'e-mail repasse en non-vérifié et un nouveau
 * mail de vérification est envoyé. La 2FA email est aussi désactivée
 * automatiquement (sécurité : l'ancienne adresse ne doit plus servir).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

$u   = current_user();
$uid = (int) $u['id'];
$db  = db();

$newEmail = trim((string)($_POST['email']    ?? ''));
$password = (string)($_POST['password'] ?? '');

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || wt_strlen($newEmail) > 190) {
    wt_json(['ok' => false, 'error' => t('auth.invalid_email')]);
}
if ($password === '') {
    wt_json(['ok' => false, 'error' => t('account.password_required')]);
}

/* ---- Vérification renforcée ------------------------------------------
 *
 * Le mot de passe seul ne suffit pas pour cette action. Changer l'adresse
 * e-mail revient à changer la clé du compte : c'est elle qui permet de
 * réinitialiser le mot de passe. Un mot de passe issu d'une fuite, combiné
 * à une session ouverte, permettait donc une prise de contrôle complète.
 *
 * Quand une méthode plus forte existe (application, SMS, code de secours),
 * elle est exigée. L'e-mail est délibérément EXCLU comme preuve : un
 * attaquant ayant accès à la boîte validerait sa propre demande.
 *
 * Si aucune méthode forte n'est active, on retombe sur le mot de passe
 * seul — c'est le maximum possible, et cela n'affaiblit rien par rapport
 * à l'existant. */
$stepMethods = wt_stepup_methods($u, true);   // true = e-mail exclu
if ($stepMethods && !wt_stepup_granted('change_email')) {
    wt_json([
        'ok'       => false,
        'error'    => t('account.email_needs_stepup'),
        'stepup'   => wt_url('/dashboard/verify-action.php?a=change_email'),
    ]);
}

/* ---- Vérification password actuel ---- */
$stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || !password_verify($password, $row['password_hash'])) {
    auth_attempt_record('email_change:' . $uid, wt_ip_bin(), false);
    wt_json(['ok' => false, 'error' => t('account.password_invalid')]);
}

/* Rate-limit pour cette action sensible */
list($blocked, $resetIn) = auth_attempt_blocked('email_change:' . $uid, wt_ip_bin());
if ($blocked) {
    wt_json([
        'ok' => false,
        'error' => t('contact.error_rate_limit'),
        'cooldown' => $resetIn,
    ]);
}

if ($newEmail === $u['email']) {
    wt_json(['ok' => false, 'error' => t('account.email_unchanged')]);
}

/* ---- Unicité ---- */
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
$stmt->bind_param('si', $newEmail, $uid);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($exists) wt_json(['ok' => false, 'error' => t('account.email_taken')]);

/* ---- Mise à jour : on RESET email_verified_at + tfa_email_enabled ---- */
$db->begin_transaction();
try {
    $stmt = $db->prepare(
        /* On désactive la 2FA par e-mail : la nouvelle adresse n'est pas
           encore vérifiée, l'utiliser comme second facteur serait absurde.
           Les DEUX colonnes sont remises à zéro — le schéma en contient
           deux pour la même chose (tfa_* héritée, twofa_* utilisée par le
           moteur actuel), et n'en traiter qu'une laissait la 2FA e-mail
           active sur une adresse non vérifiée. */
        "UPDATE users SET email = ?, email_verified_at = NULL,
                tfa_email_enabled = 0, twofa_email_enabled = 0
          WHERE id = ?"
    );
    $stmt->bind_param('si', $newEmail, $uid);
    $stmt->execute();
    $stmt->close();

    auth_attempt_record('email_change:' . $uid, wt_ip_bin(), true);

    // Génère un nouveau token de vérification
    $token = auth_token_create($uid, 'verify_email', (int) ($GLOBALS['WT_CONFIG']['auth']['verify_email_ttl'] ?? 86400));
    $verifyUrl = wt_url('/auth/verify-email.php?token=' . $token);

    $db->commit();

    /* Autorisation consommée : à usage unique.
       La laisser valide permettrait de rejouer l'action pendant sa fenêtre
       de validité, par exemple pour enchaîner un second changement. */
    wt_stepup_consume('change_email');

    try {
        wt_mail($newEmail, 'verify_email', [
            'username' => $u['username'],
            'link'     => $verifyUrl,
        ]);
    } catch (Throwable $e) {
        error_log('account_email/mail: ' . $e->getMessage());
    }

    wt_json([
        'ok'      => true,
        'message' => (string) t('account.email_changed_check_inbox'),
    ]);
} catch (Throwable $e) {
    $db->rollback();
    error_log('account_email: ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => t('common.error')], 500);
}
