<?php
/**
 * Wintaskly — /api/auth_2fa_send.php
 * ---------------------------------------------------------------------------
 * Envoie un code à usage unique par e-mail ou SMS pendant la phase de
 * connexion, quand l'utilisateur choisit cette méthode ou en change.
 *
 * SÉCURITÉ — points volontaires :
 *
 *   • Accessible uniquement avec une session « 2FA en attente ». Sans mot de
 *     passe valide au préalable, aucun code ne peut être déclenché : on
 *     n'offre pas un moyen d'inonder la boîte e-mail d'un tiers.
 *
 *   • La méthode demandée doit figurer parmi celles réellement disponibles
 *     pour ce compte (activées côté admin ET côté utilisateur ET dont le
 *     canal est configuré).
 *
 *   • Un délai minimal sépare deux envois : sans lui, un clic répété
 *     enverrait des dizaines de messages et brûlerait le quota du
 *     fournisseur — sans compter la gêne pour l'utilisateur.
 *
 *   • La réponse ne révèle jamais l'adresse ni le numéro complet : seule une
 *     forme masquée est renvoyée, pour que l'utilisateur reconnaisse le
 *     canal sans qu'un tiers puisse le découvrir.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wt_json(['ok' => false, 'error' => t('common.error')]);
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    wt_json(['ok' => false, 'error' => t('common.csrf')]);
}

$uid = (int) ($_SESSION['pending_2fa_uid'] ?? 0);
if ($uid <= 0) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.expired')]);
}

$method = (string) ($_POST['method'] ?? '');
if (!in_array($method, ['email', 'sms'], true)) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.err_method_unsupported')]);
}

$db   = db();
$stmt = $db->prepare(
    "SELECT id, username, email, status, totp_enabled, totp_secret,
            twofa_email_enabled, twofa_sms_enabled,
            twofa_phone, twofa_phone_verified_at, twofa_preferred
       FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] !== 'active') {
    wt_json(['ok' => false, 'error' => t('auth.2fa.expired')]);
}
if (!in_array($method, wt_2fa_user_methods($user), true)) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.err_method_unsupported')]);
}

/* Anti-abus : un envoi toutes les 60 secondes au maximum, par compte et par
   méthode. On s'appuie sur la date du dernier code émis. */
$stmt = $db->prepare(
    "SELECT created_at FROM user_2fa_codes
      WHERE user_id = ? AND method = ?
      ORDER BY id DESC LIMIT 1"
);
$stmt->bind_param('is', $uid, $method);
$stmt->execute();
$last = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($last && (time() - strtotime((string) $last['created_at'] . ' UTC')) < 60) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.err_too_soon')]);
}

$res = wt_2fa_issue_code($user, $method);
if (empty($res['ok'])) {
    wt_json(['ok' => false, 'error' => t('auth.2fa.err_' . ($res['error'] ?? 'issue_failed'))]);
}

// La méthode courante devient celle qui vient d'être utilisée
$_SESSION['pending_2fa_method'] = $method;

/**
 * Masque un canal de contact : l'utilisateur doit reconnaître le sien sans
 * qu'un tiers ayant volé le mot de passe puisse découvrir l'adresse exacte.
 */
$mask = static function (string $value, string $kind): string {
    if ($kind === 'sms') {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        return strlen($digits) > 4 ? '•••• ' . substr($digits, -4) : '••••';
    }
    $at = strpos($value, '@');
    if ($at === false) { return '•••'; }
    $name = substr($value, 0, $at);
    $keep = max(1, min(2, strlen($name)));
    return substr($name, 0, $keep) . str_repeat('•', 4) . substr($value, $at);
};

wt_json([
    'ok'      => true,
    'method'  => $method,
    'target'  => $mask($method === 'sms' ? (string) $user['twofa_phone'] : (string) $user['email'], $method),
    'ttl'     => (int) ($res['ttl'] ?? 10),
    'message' => $method === 'sms' ? t('auth.2fa.code_sent_sms') : t('auth.2fa.code_sent'),
]);
