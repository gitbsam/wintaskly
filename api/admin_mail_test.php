<?php
/**
 * Wintaskly — POST /api/admin_mail_test.php
 *
 * Diagnostic complet du système d'envoi d'emails.
 *
 * Envoie un email TEST à l'admin connecté (ou à l'adresse fournie),
 * et retourne un rapport détaillé indiquant :
 *   - Quel driver a été utilisé (smtp/mail/log)
 *   - Si le driver SMTP : la config résolue (host/port/user — pas le pass)
 *   - Le résultat exact (true/false)
 *   - L'erreur précise en cas d'échec
 *
 * Réservé aux admins. Pas de cooldown (l'admin teste à volonté).
 *
 * Réponse JSON :
 *   { ok: true, driver: 'smtp', sent_to: '...', host: '...', port: 587 }
 *   { ok: false, driver: 'mail', error: '...', detail: '...' }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$u = current_user();
if (!$u || ($u['role'] ?? 'user') !== 'admin') {
    wt_json(['ok' => false, 'error' => 'forbidden'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wt_json(['ok' => false, 'error' => 'method'], 405);
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    wt_json(['ok' => false, 'error' => 'csrf'], 403);
}

// Email cible : par défaut l'email de l'admin, ou un autre fourni
$to = trim((string)($_POST['to'] ?? ''));
if ($to === '') {
    $to = (string) $u['email'];
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    wt_json(['ok' => false, 'error' => 'invalid_email']);
}

// ============================================================================
// Récupère la config résolue (sans afficher le mot de passe)
// ============================================================================
$cfg = $GLOBALS['WT_CONFIG'] ?? [];
$smtpEnabled = function_exists('cfg_bool') ? cfg_bool('email.smtp_enabled', false) : false;
$driver = $cfg['mail']['driver'] ?? null;
if ($smtpEnabled) {
    $driver = 'smtp';
} elseif ($driver === null) {
    $driver = (($cfg['environment'] ?? 'development') === 'production') ? 'mail' : 'log';
}

$report = [
    'driver'  => $driver,
    'sent_to' => $to,
];

if ($driver === 'smtp') {
    $report['smtp_host']  = (string) cfg('email.smtp_host', '');
    $report['smtp_port']  = (int)    cfg('email.smtp_port', 587);
    $report['smtp_user']  = (string) cfg('email.smtp_user', '');
    $report['smtp_encryption'] = (string) cfg('email.smtp_encryption', 'tls');
    // Masque le mot de passe — on ne donne que sa longueur pour confirmer qu'il est rempli
    $passLen = strlen((string) cfg('email.smtp_pass', ''));
    $report['smtp_pass_length'] = $passLen;
    if ($passLen === 0) {
        wt_json([
            'ok' => false,
            'error' => 'smtp_no_password',
            'detail' => 'Le mot de passe SMTP n\'est pas configuré dans /admin/settings.php → SMTP.',
            'report' => $report,
        ]);
    }
    if ($report['smtp_host'] === '') {
        wt_json([
            'ok' => false,
            'error' => 'smtp_no_host',
            'detail' => 'Le serveur SMTP (host) n\'est pas configuré.',
            'report' => $report,
        ]);
    }
}

// ============================================================================
// Tente l'envoi avec capture du dernier log mailer
// ============================================================================
$subject = '✅ Wintaskly — Test SMTP réussi';
$siteName = (string) (cfg('site.name', '') ?: ($cfg['site']['name'] ?? 'Wintaskly'));
$now = date('Y-m-d H:i:s');

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:system-ui,sans-serif;background:#0a0e1a;color:#e8eaf0;padding:2rem">'
      . '<div style="max-width:520px;margin:0 auto;background:#131829;border:1px solid #2a3252;border-radius:12px;padding:2rem">'
      . '<h1 style="color:#ff9933">🎉 Test SMTP Wintaskly</h1>'
      . '<p>Si tu lis ce message, la configuration mail de <strong>' . htmlspecialchars($siteName) . '</strong> fonctionne parfaitement.</p>'
      . '<table style="font-family:monospace;font-size:.85rem;margin-top:1rem">'
      . '<tr><td style="padding:.3rem .5rem;opacity:.7">Driver :</td><td>' . htmlspecialchars($driver) . '</td></tr>'
      . '<tr><td style="padding:.3rem .5rem;opacity:.7">Envoyé à :</td><td>' . htmlspecialchars($to) . '</td></tr>'
      . '<tr><td style="padding:.3rem .5rem;opacity:.7">Quand :</td><td>' . htmlspecialchars($now) . '</td></tr>'
      . '</table>'
      . '<p style="margin-top:2rem;opacity:.6;font-size:.85rem">Ce mail est un test envoyé depuis /admin/settings.php · Wintaskly</p>'
      . '</div></body></html>';

$text = "Test SMTP Wintaskly réussi !\n\n"
      . "Driver : {$driver}\n"
      . "Envoyé à : {$to}\n"
      . "Quand : {$now}\n\n"
      . "Si tu lis ce message, la config mail fonctionne.";

// On capture le dernier log mailer pour avoir le détail en cas d'échec
$sent = false;
$caughtErr = null;
try {
    $sent = WtMailer::deliver($to, $subject, $html, $text);
} catch (Throwable $e) {
    $caughtErr = $e->getMessage();
}

if ($sent) {
    wt_json([
        'ok'      => true,
        'message' => 'Email envoyé à ' . $to . '. Vérifie ta boîte de réception (et les spams) dans quelques secondes.',
        'report'  => $report,
    ]);
}

// ============================================================================
// Échec : on tente d'expliquer pourquoi
// ============================================================================
$hints = [];
if ($driver === 'mail') {
    $hints[] = 'La fonction PHP mail() est souvent bloquée sur les hébergeurs mutualisés (LWS, OVH, Hostinger, etc.) pour prévenir le spam.';
    $hints[] = 'Solution recommandée : configure SMTP via /admin/settings.php → SMTP, en utilisant ton propre compte (Gmail, Brevo, Mailjet, SendGrid, ou le SMTP de ton hébergeur).';
}
if ($driver === 'smtp') {
    $hints[] = 'Connexion SMTP échouée. Vérifie :';
    $hints[] = '· Le serveur (host) — ex : smtp.gmail.com, smtp-relay.brevo.com, etc.';
    $hints[] = '· Le port — 587 (TLS) ou 465 (SSL)';
    $hints[] = '· L\'identifiant et mot de passe (mot de passe d\'application Gmail, pas le mot de passe normal)';
    $hints[] = '· Le chiffrement : TLS ou SSL selon ton fournisseur';
    $hints[] = '· Ton hébergeur peut bloquer les ports 587/465 sortants — contacte le support LWS.';
}
if ($driver === 'log') {
    $hints[] = 'Le driver actuel est "log" — les mails sont écrits dans error.log au lieu d\'être envoyés.';
    $hints[] = 'Active "Activer SMTP" dans /admin/settings.php puis configure tes credentials.';
}

wt_json([
    'ok'     => false,
    'error'  => 'send_failed',
    'detail' => $caughtErr ?: 'Aucun détail technique. Consulte error.log pour le log précis du mailer.',
    'hints'  => $hints,
    'report' => $report,
]);
