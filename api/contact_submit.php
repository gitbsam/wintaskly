<?php
/**
 * Wintaskly — POST /api/contact_submit.php
 *
 * Flux dual :
 *   - Utilisateur connecté : crée un support_ticket lié au compte, le
 *     premier support_message, puis renvoie l'URL /dashboard/messages.
 *   - Invité : génère un guest_token cryptographique unique, persiste
 *     le ticket + message en DB, envoie un e-mail de confirmation avec
 *     lien, ET renvoie le track_url dans la réponse JSON pour
 *     affichage immédiat sur le formulaire.
 *
 * Anti-abus pour les invités :
 *   1. Honeypot (champ "website" caché)
 *   2. Délai minimum 3s entre affichage du form et soumission
 *   3. Captcha math (a + b = ?) stocké en session
 *   4. Rate-limit IP : 5 soumissions / 15 min (via auth_attempts)
 *   5. Validation stricte (longueurs, email RFC, body non-vide)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null))   wt_json(['ok' => false, 'error' => t('common.error')], 403);

// Honeypot (anti-bot le plus basique mais efficace)
if (!empty($_POST['website'])) {
    // On feint le succès pour ne pas signaler au bot que sa technique est détectée
    wt_json(['ok' => true, 'message' => (string) t('contact.user_thanks')]);
}

$u       = current_user();
$subject = trim((string)($_POST['subject'] ?? ''));
$body    = trim((string)($_POST['body']    ?? ''));

if ($subject === '' || wt_strlen($subject) > 180)   wt_json(['ok' => false, 'error' => t('contact.invalid_subject')]);
if ($body    === '' || wt_strlen($body)    > 5000)  wt_json(['ok' => false, 'error' => t('contact.invalid_body')]);

$db    = db();
$ipBin = wt_ip_bin();

// =====================================================================
// CAS A : utilisateur connecté — flux direct
// =====================================================================
if ($u) {
    $db->begin_transaction();
    try {
        $userId = (int) $u['id'];
        $stmt = $db->prepare(
            "INSERT INTO support_tickets
                (user_id, subject, status, last_reply_by, last_reply_at, ip)
             VALUES (?, ?, 'open', 'user', UTC_TIMESTAMP(), ?)"
        );
        $stmt->bind_param('iss', $userId, $subject, $ipBin);
        $stmt->execute();
        $ticketId = (int) $stmt->insert_id;
        $stmt->close();

        $stmt = $db->prepare(
            "INSERT INTO support_messages (ticket_id, author_role, author_id, body)
             VALUES (?, 'user', ?, ?)"
        );
        $stmt->bind_param('iis', $ticketId, $userId, $body);
        $stmt->execute();
        $stmt->close();

        wt_notify(
            $userId,
            'support_open',
            (string) t('contact.notif_opened'),
            $subject,
            wt_url('/dashboard/messages.php?ticket=' . $ticketId)
        );

        $db->commit();
        wt_json([
            'ok'       => true,
            'message'  => (string) t('contact.user_thanks'),
            'redirect' => wt_url('/dashboard/messages.php?ticket=' . $ticketId),
        ]);
    } catch (Throwable $e) {
        $db->rollback();
        error_log('contact_submit/user: ' . $e->getMessage());
        wt_json(['ok' => false, 'error' => t('common.error')], 500);
    }
}

// =====================================================================
// CAS B : invité — défenses anti-abus + persistance + email
// =====================================================================

// ----- Défense 1 (PRIORITAIRE) : rate-limit IP via auth_attempts
// On enregistre AVANT toute validation pour limiter les bots qui spam
// même du contenu invalide (emails malformés, body vide, etc.).
// Le compteur est par IP, indépendant de l'identifier précis.
$ipKey = 'contact:ip:' . bin2hex($ipBin ?? '');
auth_attempt_record($ipKey, $ipBin, false);

list($blocked, $resetIn) = auth_attempt_blocked($ipKey, $ipBin);
if ($blocked) {
    wt_json([
        'ok' => false,
        'error' => t('contact.error_rate_limit'),
        'cooldown' => $resetIn,
    ]);
}

// ----- Validations format
$name  = trim((string)($_POST['name']  ?? ''));
$email = trim((string)($_POST['email'] ?? ''));

if ($name  === '' || wt_strlen($name) > 120)        wt_json(['ok' => false, 'error' => t('contact.invalid_name')]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))     wt_json(['ok' => false, 'error' => t('auth.invalid_email')]);
if (wt_strlen($email) > 190)                        wt_json(['ok' => false, 'error' => t('auth.invalid_email')]);

// ----- Défense 2 : délai minimum 3 secondes entre affichage du form et soumission
$shownAt = (int)($_SESSION['wt_contact_form_shown_at'] ?? 0);
if ($shownAt === 0) {
    // Pas de session timestamp → submission directe sans GET sur la page = bot
    wt_json(['ok' => false, 'error' => t('contact.error_too_fast')]);
}
if (time() - $shownAt < 3) {
    wt_json(['ok' => false, 'error' => t('contact.error_too_fast')]);
}

// ----- Défense 3 : captcha math
$captchaInput = (int)($_POST['captcha'] ?? -1);
$expected     = (int)($_SESSION['wt_contact_captcha']['r'] ?? -2);
if ($captchaInput !== $expected) {
    // On regénère un nouveau captcha pour ne pas permettre le brute force
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['wt_contact_captcha'] = ['a' => $a, 'b' => $b, 'r' => $a + $b];
    wt_json(['ok' => false, 'error' => t('contact.captcha_failed'), 'captcha_new' => true]);
}

// ----- Persistance + mail
$db->begin_transaction();
try {
    // Génération du token de suivi (48 hex chars)
    $token = bin2hex(random_bytes(24));

    $stmt = $db->prepare(
        "INSERT INTO support_tickets
            (guest_email, guest_name, guest_token, subject,
             status, last_reply_by, last_reply_at, ip)
         VALUES (?, ?, ?, ?, 'open', 'guest', UTC_TIMESTAMP(), ?)"
    );
    $stmt->bind_param('sssss', $email, $name, $token, $subject, $ipBin);
    $stmt->execute();
    $ticketId = (int) $stmt->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT INTO support_messages (ticket_id, author_role, author_id, body)
         VALUES (?, 'guest', NULL, ?)"
    );
    $stmt->bind_param('is', $ticketId, $body);
    $stmt->execute();
    $stmt->close();

    $db->commit();

    // On reset le captcha + le timestamp après envoi valide
    unset($_SESSION['wt_contact_captcha'], $_SESSION['wt_contact_form_shown_at']);

    // Marqueur de succès pour le rate-limit (efface l'historique d'échecs)
    auth_attempt_record($ipKey, $ipBin, true);

    $trackUrl = wt_url('/help/contact-track/' . $token);

    // E-mail de confirmation au visiteur — non-bloquant si SMTP indispo
    try {
        wt_mail($email, 'security_alert', [
            'username' => $name,
            'link'     => $trackUrl,
            'body'     => (string) t('contact.mail_body'),
        ]);
    } catch (Throwable $e) {
        error_log('contact_submit/mail: ' . $e->getMessage());
        // On poursuit : le track_url sera renvoyé dans la réponse JSON,
        // l'utilisateur ne perd pas son lien même si le mail échoue.
    }

    wt_json([
        'ok'        => true,
        'message'   => (string) t('contact.guest_thanks'),
        'track_url' => $trackUrl,
        'ticket_id' => $ticketId,
    ]);

} catch (Throwable $e) {
    $db->rollback();
    error_log('contact_submit/guest: ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => t('common.error')], 500);
}
