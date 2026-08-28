<?php
/**
 * Wintaskly — POST /api/auth_signup.php
 *
 * Réponse JSON :
 *   { ok:true, redirect:'/auth/verify-email.php' }
 *   { ok:false, error:'…' }
 *
 * Le compte est créé en statut 'pending' et reçoit immédiatement un
 * e-mail de vérification. L'utilisateur est ensuite redirigé vers la
 * page d'attente.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

// 1) Honeypot : si l'un des champs piégés est rempli → bot
if (!empty($_POST['website']) || !empty($_POST['phone_number'])) {
    // Réponse mensongèrement neutre pour ne pas révéler le piège
    wt_json(['ok' => false, 'error' => t('common.error')]);
}

// 1bis) Limite d'inscriptions par IP : barrière simple contre la création
//       massive de comptes, principal vecteur de fraude sur ce type de
//       plateforme. Le seuil laisse passer un usage familial normal.
if (function_exists('wt_signup_rate_exceeded') && wt_signup_rate_exceeded(wt_ip_bin())) {
    wt_json(['ok' => false, 'error' => t('auth.signup_rate_limited')]);
}

$username = trim((string)($_POST['username'] ?? ''));
$email    = trim((string)($_POST['email']    ?? ''));
$pass     = (string)      ($_POST['password'] ?? '');
$refCode  = trim((string)($_POST['ref_code'] ?? ''));
$accept   = !empty($_POST['accept_terms']);

if (!$accept) {
    wt_json(['ok' => false, 'error' => t('auth.must_accept')]);
}

// 2) Validations
if (!preg_match('/^[a-zA-Z0-9_.\-]{3,40}$/', $username)) {
    wt_json(['ok' => false, 'error' => t('auth.invalid_username')]);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    wt_json(['ok' => false, 'error' => t('auth.invalid_email')]);
}
if (strlen($pass) < 8) {
    wt_json(['ok' => false, 'error' => t('auth.weak')]);
}

// 3) Unicité
$db = db();
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
$stmt->bind_param('ss', $email, $username);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    $stmt->close();
    wt_json(['ok' => false, 'error' => t('auth.taken')]);
}
$stmt->close();

// 4) Résolution code parrainage (silencieuse si introuvable)
$referrerId = null;
if ($refCode !== '') {
    $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
    $stmt->bind_param('s', $refCode);
    $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) {
        $referrerId = (int) $r['id'];
    }
    $stmt->close();
}

// 5) Création du compte (statut 'pending')
// Vérification anti-fraude multi-comptes (avant création).
// fail-open : si la détection est off ou indisponible, on autorise.
$fraudCheck = function_exists('wt_fraud_check_signup')
    ? wt_fraud_check_signup()
    : ['allow' => true, 'flag' => false, 'reason' => ''];

if (!$fraudCheck['allow']) {
    // Bloqué par la règle multi-comptes (action 'block')
    wt_json(['ok' => false, 'error' => t('auth.signup_blocked')]);
}

$hash   = password_hash($pass, PASSWORD_DEFAULT);
$rc     = generate_referral_code();
$ipBin  = wt_ip_bin();
$lang   = $GLOBALS['WT_LANG_CODE'] ?? 'fr';
$theme  = $GLOBALS['WT_THEME']     ?? 'dark';
$status = 'pending';

$stmt = $db->prepare(
    "INSERT INTO users
       (username, email, password_hash, referral_code,
        referrer_id, lang, theme, ip_registered, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'ssssissss',
    $username, $email, $hash, $rc,
    $referrerId, $lang, $theme, $ipBin, $status
);

if (!$stmt->execute()) {
    $stmt->close();
    wt_json(['ok' => false, 'error' => t('auth.taken')]);
}
$newId = (int) $stmt->insert_id;
$stmt->close();

// Si l'inscription a été signalée (action 'flag'), on marque le compte
// pour revue manuelle sans bloquer l'utilisateur.
if (!empty($fraudCheck['flag']) && function_exists('wt_fraud_flag_user')) {
    wt_fraud_flag_user($newId, 40, $fraudCheck['reason']);
}

/* ---- Rattachement à une campagne d'acquisition -----------------------
 * Deux chemins : le cookie posé sur /campagn, sinon le paramètre `camp`
 * du lien d'inscription (navigateur bloquant les cookies, changement
 * d'appareil). Aucune récompense n'est versée ici : elle dépend d'une
 * activité qui n'a pas encore eu lieu. Un échec ne bloque jamais la
 * création du compte. */
if (function_exists('wt_campaign_attach_user')) {
    if (!wt_campaign_attach_user($newId)) {
        $campCode = preg_replace('/[^A-Za-z0-9]/', '',
                        (string) ($_POST['camp'] ?? $_GET['camp'] ?? '')) ?? '';
        if ($campCode !== '') {
            $camp = wt_campaign_find($campCode);
            if ($camp && wt_campaign_is_live($camp)) {
                try {
                    $stmt2 = db()->prepare(
                        "INSERT IGNORE INTO campaign_visits
                           (campaign_id, visitor_key, converted_user_id, converted_at)
                         VALUES (?, ?, ?, UTC_TIMESTAMP())"
                    );
                    $cid2 = (int) $camp['id'];
                    $vkey = bin2hex(random_bytes(16));
                    $stmt2->bind_param('isi', $cid2, $vkey, $newId);
                    $stmt2->execute();
                    $stmt2->close();
                } catch (Throwable $e) {
                    error_log('[Wintaskly campaign] rattachement direct : ' . $e->getMessage());
                }
            }
        }
    }
}

// Nettoyage cookie de parrainage
if (isset($_COOKIE['wt_ref'])) {
    setcookie('wt_ref', '', time() - 3600, '/');
}

// 6) Génération + envoi du token de vérification
$ttl    = (int)($GLOBALS['WT_CONFIG']['auth']['verify_email_ttl'] ?? 86400);
$raw    = auth_token_create($newId, 'verify_email', $ttl);
$link   = wt_url('/auth/verify-email.php?token=' . urlencode($raw));

wt_mail($email, 'verify_email', [
    'username' => $username,
    'link'     => $link,
]);

// 7) Mémorise l'email pour l'afficher sur /auth/verify-email.php
$_SESSION['pending_verify_email'] = $email;
$_SESSION['pending_resend_uid']   = $newId; // pour le bouton « Renvoyer »

wt_json([
    'ok'       => true,
    'redirect' => wt_url('/auth/verify-email.php'),
]);
