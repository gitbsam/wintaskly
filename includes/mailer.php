<?php
/**
 * Wintaskly — Moteur d'expédition d'e-mails (mailing engine).
 *
 * Stratégie :
 *   1. Si Composer a été utilisé (`vendor/autoload.php` présent) et que
 *      PHPMailer est installé (`composer require phpmailer/phpmailer`),
 *      on l'utilise.
 *   2. Sinon, on bascule sur un mini-client SMTP autonome (RFC 5321 +
 *      STARTTLS + AUTH LOGIN). Ça suffit pour parler à Gmail et à la
 *      plupart des relais professionnels.
 *
 * L'application appelle :
 *   wt_mail('foo@bar.com', 'verify_email', [
 *       'username' => 'Alice',
 *       'link'     => 'https://…/auth/verify-email.php?token=…',
 *   ]);
 *
 * Les templates HTML sont rendus inline (table-based, hors-CSS externe)
 * pour rester compatibles avec Outlook, Gmail, Apple Mail.
 *
 * Le sujet et le corps sont localisés via includes/lang/*.php.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------
// 1) API publique
// ---------------------------------------------------------------------
if (!function_exists('wt_mail')) {
    /**
     * Envoie un e-mail transactionnel.
     *
     * @param string $to     Adresse e-mail du destinataire.
     * @param string $kind   Identifiant de template :
     *                       'verify_email' | 'reset_password' | 'security_alert'
     * @param array  $vars   Variables interpolées dans le template.
     * @return bool          true si remis au MTA, false sinon.
     */
    function wt_mail(string $to, string $kind, array $vars = []): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        try {
            $payload = WtMailer::buildTemplate($kind, $vars);
            return WtMailer::deliver($to, $payload['subject'], $payload['html'], $payload['text']);
        } catch (Throwable $e) {
            WtMailer::log('wt_mail: ' . $e->getMessage());
            return false;
        }
    }
}

// ---------------------------------------------------------------------
// 2) Classe principale
// ---------------------------------------------------------------------
final class WtMailer
{
    // -----------------------------------------------------------------
    // 2.1) Routage : 3 modes (driver) selon la config
    //
    //   - 'log'   → Journalise dans logs/ au lieu d'envoyer (DEV)
    //   - 'mail'  → mail() natif PHP via sendmail système (PROD par défaut)
    //   - 'smtp'  → SMTP configuré via /admin/settings.php (PROD recommandé)
    //
    // Priorité (du plus prioritaire au moins) :
    //   1. cfg('email.smtp_enabled') = true en BDD → driver=smtp
    //   2. $cfg['mail']['driver'] dans config.php → respect du choix manuel
    //   3. Fallback : 'mail' en prod, 'log' en dev
    // -----------------------------------------------------------------
    public static function deliver(string $to, string $subject, string $html, string $text): bool
    {
        $cfg = self::cfg();

        // --- Détermine le driver à utiliser ---
        $driver = $cfg['mail']['driver'] ?? null;

        // L'admin a-t-il activé SMTP via /admin/settings.php ?
        // (cfg() lit la table BDD `config`, plus prioritaire que le fichier)
        if (function_exists('cfg') && function_exists('cfg_bool')) {
            if (cfg_bool('email.smtp_enabled', false)) {
                $driver = 'smtp';
            }
        }

        // Fallback automatique selon environnement
        if ($driver === null) {
            $driver = (($cfg['environment'] ?? 'development') === 'production') ? 'mail' : 'log';
        }

        // --- Route selon le driver ---
        switch ($driver) {
            case 'log':
                return self::sendViaLog($to, $subject, $html, $text);

            case 'mail':
                return self::sendViaNativeMail($to, $subject, $html, $text);

            case 'smtp':
                // Lit le SMTP depuis la table config BDD (modifiable via admin)
                // OU fallback sur config.php $cfg['mail']['prod'] / ['dev']
                $smtp = self::resolveSmtpConfig();
                if (!$smtp) {
                    self::log('SMTP enabled but no config found, falling back to mail()');
                    return self::sendViaNativeMail($to, $subject, $html, $text);
                }

                // PHPMailer si dispo, sinon raw SMTP
                $autoload = __DIR__ . '/../vendor/autoload.php';
                if (is_file($autoload)) {
                    require_once $autoload;
                    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                        return self::sendViaPHPMailer($to, $subject, $html, $text, $smtp);
                    }
                }
                return self::sendViaRawSMTP($to, $subject, $html, $text, $smtp);

            default:
                self::log('Unknown mail driver: ' . $driver);
                return false;
        }
    }

    // -----------------------------------------------------------------
    // 2.1.a) Driver "log" — journalise au lieu d'envoyer
    // -----------------------------------------------------------------
    private static function sendViaLog(string $to, string $subject, string $html, string $text): bool
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $logFile = $logDir . '/mail.log';

        $entry = '[' . date('Y-m-d H:i:s') . '] '
               . "TO: $to | SUBJECT: $subject\n"
               . "---\n" . $text . "\n===\n\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        return true;
    }

    // -----------------------------------------------------------------
    // 2.1.b) Driver "mail" — mail() natif PHP (sendmail système)
    //
    // Sur LWS, mail() utilise /usr/sbin/sendmail configuré par défaut.
    // L'expéditeur visible vient de $cfg['mail']['from'].
    // Délivrabilité moyenne (peut tomber en spam) mais zéro config.
    // -----------------------------------------------------------------
    private static function sendViaNativeMail(string $to, string $subject, string $html, string $text): bool
    {
        $cfg = self::cfg();

        /* ------------------------------------------------------------------
           Résolution de l'expéditeur :
           Priorité BDD (modifiable via /admin/settings.php) > config.php > fallback.

           Avant ce fix, on lisait UNIQUEMENT $cfg['mail']['from'] (donc le
           fichier config.php). Conséquence : si l'admin modifiait l'adresse
           depuis le panel admin, le changement n'était PAS appliqué.
           Pire : si le From: était `no-reply@localhost` ou un domaine non
           corrélé au domaine d'envoi, certains FAI (Hotmail, Outlook, Yahoo)
           rejetaient le mail silencieusement pour cause de SPF/DKIM invalide.

           Cette résolution lit la BDD en priorité, et applique aussi une
           validation : si le from final n'est pas un email valide, on
           fallback sur no-reply@<host> pour éviter une erreur fatale.
           ------------------------------------------------------------------ */
        $from     = '';
        $fromName = '';
        $replyTo  = '';
        if (function_exists('cfg')) {
            $from     = (string) cfg('email.from_address', '');
            $fromName = (string) cfg('email.from_name', '');
            $replyTo  = (string) cfg('email.contact_to', '');
        }
        // Fallback : fichier config.php
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = $cfg['mail']['from'] ?? '';
        }
        // Ultime fallback : no-reply@<host>
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            // Strip port et www. pour avoir un domaine propre
            $host = preg_replace('/:\d+$/', '', $host);
            $host = preg_replace('/^www\./', '', $host);
            $from = 'no-reply@' . $host;
        }
        if ($fromName === '') {
            $fromName = $cfg['mail']['from_name'] ?? 'Wintaskly';
        }
        if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $replyTo = $cfg['mail']['reply_to'] ?? $from;
        }

        // Boundary pour multipart/alternative (HTML + text)
        $boundary = 'wt_' . bin2hex(random_bytes(8));

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'Reply-To: <' . $replyTo . '>',
            'X-Mailer: Wintaskly',
        ];

        $body = "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
              . quoted_printable_encode($text) . "\r\n\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
              . quoted_printable_encode($html) . "\r\n\r\n"
              . "--$boundary--\r\n";

        // Subject encodé UTF-8 pour gérer les accents
        $encodedSubject = self::encodeHeader($subject);

        /* Sur LWS, on ajoute le paramètre -f pour forcer l'enveloppe SMTP
           (Return-Path). Sans ça, le from envelope par défaut peut être
           un truc style "www-data@srv042.lws-hosting.com" et Hotmail
           rejette en spam ou silencieux. */
        $additionalParams = '-f' . $from;

        $ok = @mail($to, $encodedSubject, $body, implode("\r\n", $headers), $additionalParams);
        if (!$ok) {
            self::log("sendViaNativeMail FAILED to={$to} from={$from} subject=" . substr($subject, 0, 60));
        } else {
            self::log("sendViaNativeMail OK to={$to} from={$from}");
        }
        return $ok;
    }

    private static function encodeHeader_NEW_REMOVED(string $value): string
    {
        return self::encodeHeader($value);
    }

    // -----------------------------------------------------------------
    // 2.1.c) Resolver SMTP : essaie BDD admin d'abord, puis config.php
    // -----------------------------------------------------------------
    private static function resolveSmtpConfig(): ?array
    {
        // 1) Essaie depuis la table config (modifiable via /admin/settings.php)
        if (function_exists('cfg') && cfg('email.smtp_host', '')) {
            return [
                'host' => (string) cfg('email.smtp_host', ''),
                'port' => (int)    cfg('email.smtp_port', 587),
                'user' => (string) cfg('email.smtp_user', ''),
                'pass' => (string) cfg('email.smtp_pass', ''),
                'tls'  => cfg('email.smtp_encryption', 'tls') === 'tls',
            ];
        }

        // 2) Fallback sur config.php (ancienne logique)
        $cfg    = self::cfg();
        $envKey = ($cfg['environment'] ?? 'development') === 'production' ? 'prod' : 'dev';
        return $cfg['mail'][$envKey] ?? null;
    }

    // -----------------------------------------------------------------
    // 2.2) Sortie PHPMailer (si dispo)
    // -----------------------------------------------------------------
    private static function sendViaPHPMailer(string $to, string $subject, string $html, string $text, array $smtp): bool
    {
        $cfg = self::cfg();
        $cls = '\\PHPMailer\\PHPMailer\\PHPMailer';
        /** @var \PHPMailer\PHPMailer\PHPMailer $m */
        $m = new $cls(true);
        try {
            $m->isSMTP();
            $m->Host       = $smtp['host'];
            $m->Port       = (int) $smtp['port'];
            $m->SMTPAuth   = true;
            $m->Username   = $smtp['user'];
            $m->Password   = $smtp['pass'];
            $m->SMTPSecure = !empty($smtp['tls']) ? 'tls' : 'ssl';
            $m->CharSet    = 'UTF-8';

            $m->setFrom(
                $cfg['mail']['from']      ?? $smtp['user'],
                $cfg['mail']['from_name'] ?? 'Wintaskly'
            );
            if (!empty($cfg['mail']['reply_to'])) {
                $m->addReplyTo($cfg['mail']['reply_to']);
            }
            $m->addAddress($to);

            $m->isHTML(true);
            $m->Subject = $subject;
            $m->Body    = $html;
            $m->AltBody = $text;

            $m->send();
            return true;
        } catch (Throwable $e) {
            self::log('PHPMailer: ' . $e->getMessage());
            return false;
        }
    }

    // -----------------------------------------------------------------
    // 2.3) Client SMTP maison (fallback sans dépendance)
    // -----------------------------------------------------------------
    private static function sendViaRawSMTP(string $to, string $subject, string $html, string $text, array $smtp): bool
    {
        $cfg     = self::cfg();
        $from    = $cfg['mail']['from']      ?? $smtp['user'];
        $fromN   = $cfg['mail']['from_name'] ?? 'Wintaskly';
        $replyTo = $cfg['mail']['reply_to']  ?? '';
        $host    = (string) $smtp['host'];
        $port    = (int)    $smtp['port'];
        $tls     = !empty($smtp['tls']);

        $errno = 0; $errstr = '';
        $sockHost = ($port === 465) ? 'ssl://' . $host : $host;
        $sock = @stream_socket_client(
            $sockHost . ':' . $port,
            $errno, $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
        if (!$sock) {
            self::log("SMTP connect failed: $errstr ($errno)");
            return false;
        }
        stream_set_timeout($sock, 15);

        $expect = function (int $code) use ($sock): bool {
            while (!feof($sock)) {
                $line = fgets($sock, 1024);
                if ($line === false) return false;
                if (strlen($line) < 4) return false;
                // multi-ligne : "250-…" puis "250 …"
                $sep = $line[3] ?? ' ';
                $c   = (int) substr($line, 0, 3);
                if ($c !== $code) {
                    WtMailer::log('SMTP unexpected: ' . trim($line));
                    return false;
                }
                if ($sep === ' ') return true;
            }
            return false;
        };
        $say = function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };

        // Bannière initiale
        if (!$expect(220)) { fclose($sock); return false; }

        $ehloName = parse_url($cfg['base_url'] ?? 'wintaskly.local', PHP_URL_HOST) ?: 'wintaskly.local';

        $say("EHLO $ehloName");
        if (!$expect(250)) { fclose($sock); return false; }

        if ($tls && $port !== 465) {
            $say('STARTTLS');
            if (!$expect(220)) { fclose($sock); return false; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!stream_socket_enable_crypto($sock, true, $crypto)) {
                self::log('STARTTLS handshake failed');
                fclose($sock);
                return false;
            }
            $say("EHLO $ehloName");
            if (!$expect(250)) { fclose($sock); return false; }
        }

        // Auth LOGIN
        $say('AUTH LOGIN');
        if (!$expect(334)) { fclose($sock); return false; }
        $say(base64_encode((string) $smtp['user']));
        if (!$expect(334)) { fclose($sock); return false; }
        $say(base64_encode((string) $smtp['pass']));
        if (!$expect(235)) { fclose($sock); return false; }

        // Envelope
        $say("MAIL FROM:<{$from}>");
        if (!$expect(250)) { fclose($sock); return false; }
        $say("RCPT TO:<{$to}>");
        if (!$expect(250)) { fclose($sock); return false; }

        // DATA
        $say('DATA');
        if (!$expect(354)) { fclose($sock); return false; }

        $boundary = 'wt-' . bin2hex(random_bytes(8));
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $ehloName . '>';

        $headers   = [];
        $headers[] = 'From: ' . self::encodeHeader($fromN) . ' <' . $from . '>';
        $headers[] = 'To: ' . $to;
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'X-Mailer: Wintaskly';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body  = '';
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($text) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($html) . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        // Dot-stuffing : toute ligne commençant par "." doit être préfixée d'un "."
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($sock, $message);
        fwrite($sock, "\r\n.\r\n");

        if (!$expect(250)) {
            self::log('Message rejected at DATA');
            fclose($sock);
            return false;
        }
        $say('QUIT');
        // pas besoin d'attendre la réponse 221, le message est délivré
        fclose($sock);
        return true;
    }

    // -----------------------------------------------------------------
    // 2.4) Templates HTML
    // -----------------------------------------------------------------
    /**
     * Construit le triplet (sujet + HTML + texte brut) pour un type de mail.
     */
    public static function buildTemplate(string $kind, array $vars): array
    {
        $cfg      = self::cfg();
        $brand    = $cfg['mail']['from_name'] ?? 'Wintaskly';
        $baseUrl  = rtrim((string)($cfg['base_url'] ?? ''), '/');
        $cguUrl   = $baseUrl . '/legal/cgu.php';
        $privUrl  = $baseUrl . '/legal/privacy.php';

        $username = (string)($vars['username'] ?? 'utilisateur');
        $link     = (string)($vars['link']     ?? $baseUrl);
        $code     = (string)($vars['code']     ?? '');

        switch ($kind) {
            case 'verify_email':
                $subject = function_exists('t') ? t('mail.verify.subject') : 'Vérifie ton adresse e-mail';
                $title   = function_exists('t') ? t('mail.verify.title')   : 'Bienvenue sur Wintaskly';
                $body    = function_exists('t') ? t('mail.verify.body')    : 'Confirme ton adresse en cliquant sur le bouton ci-dessous.';
                $cta     = function_exists('t') ? t('mail.verify.cta')     : 'Vérifier mon adresse';
                $notice  = function_exists('t') ? t('mail.verify.notice')  : 'Ce lien expire dans 24 heures.';
                $accent  = '#2563eb'; // bleu électrique
                break;

            case 'reset_password':
                $subject = function_exists('t') ? t('mail.reset.subject') : 'Réinitialise ton mot de passe';
                $title   = function_exists('t') ? t('mail.reset.title')   : 'Réinitialisation de mot de passe';
                $body    = function_exists('t') ? t('mail.reset.body')    : 'Tu as demandé à réinitialiser ton mot de passe. Clique sur le bouton ci-dessous pour en choisir un nouveau.';
                $cta     = function_exists('t') ? t('mail.reset.cta')     : 'Choisir un nouveau mot de passe';
                $notice  = function_exists('t') ? t('mail.reset.notice')  : 'Ce lien expire dans 1 heure. Si tu n\'es pas à l\'origine de cette demande, ignore ce message.';
                $accent  = '#f59e0b'; // or premium
                break;

            case 'security_alert':
            default:
                $subject = function_exists('t') ? t('mail.alert.subject') : 'Alerte de sécurité Wintaskly';
                $title   = function_exists('t') ? t('mail.alert.title')   : 'Connexion détectée';
                $body    = (string)($vars['body'] ?? 'Une activité notable a été détectée sur ton compte.');
                $cta     = function_exists('t') ? t('mail.alert.cta')     : 'Vérifier mon compte';
                $notice  = function_exists('t') ? t('mail.alert.notice')  : 'Si tu es à l\'origine de cette action, tu peux ignorer ce message.';
                $accent  = '#dc2626';
                break;
        }

        $html = self::renderShell([
            'brand'    => $brand,
            'title'    => $title,
            'body'     => $body,
            'username' => $username,
            'cta'      => $cta,
            'link'     => $link,
            'code'     => $code,
            'notice'   => $notice,
            'accent'   => $accent,
            'cgu'      => $cguUrl,
            'privacy'  => $privUrl,
        ]);

        $text = self::renderText([
            'brand'    => $brand,
            'title'    => $title,
            'body'     => $body,
            'username' => $username,
            'cta'      => $cta,
            'link'     => $link,
            'code'     => $code,
            'notice'   => $notice,
        ]);

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    private static function renderShell(array $v): string
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $codeBlock = '';
        if (!empty($v['code'])) {
            $codeBlock = '
              <tr><td align="center" style="padding:24px 0">
                <div style="display:inline-block;padding:14px 24px;background:#0b1220;border:1px solid #1f2937;border-radius:12px;
                            font-family:\'JetBrains Mono\',Consolas,monospace;font-size:30px;letter-spacing:6px;color:#fde68a">
                  ' . $e($v['code']) . '
                </div>
              </td></tr>';
        }

        $btnBlock = '';
        if (!empty($v['link'])) {
            $btnBlock = '
              <tr><td align="center" style="padding:8px 0 28px">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                  <tr><td align="center" style="border-radius:999px;background:' . $e($v['accent']) . '">
                    <a href="' . $e($v['link']) . '"
                       style="display:inline-block;padding:14px 28px;color:#0b1220;
                              font-family:Manrope,Arial,sans-serif;font-weight:700;font-size:15px;
                              text-decoration:none;border-radius:999px">
                      ' . $e($v['cta']) . '
                    </a>
                  </td></tr>
                </table>
              </td></tr>
              <tr><td align="center" style="padding:0 24px 16px">
                <p style="margin:0;color:#94a3b8;font:13px/1.5 Manrope,Arial,sans-serif">
                  Ou copie ce lien dans ton navigateur :<br>
                  <a href="' . $e($v['link']) . '" style="color:#93c5fd;word-break:break-all">' . $e($v['link']) . '</a>
                </p>
              </td></tr>';
        }

        return '<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="supported-color-schemes" content="dark light">
<title>' . $e($v['title']) . '</title>
</head>
<body style="margin:0;background:#0a0d14;color:#e5e7eb;font-family:Manrope,Arial,sans-serif">
  <!-- Pré-header invisible pour booster la prévisualisation -->
  <div style="display:none;max-height:0;overflow:hidden;opacity:0">
    ' . $e($v['title']) . ' — ' . $e(self::truncate((string) $v['body'], 80)) . '
  </div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0a0d14;padding:24px 0">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
             style="max-width:600px;width:100%;background:#0f172a;border:1px solid #1f2937;border-radius:16px;overflow:hidden">

        <!-- Header avec logo -->
        <tr><td align="center" style="padding:32px 0 8px;background:linear-gradient(135deg,#0b1220,#0f172a)">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td style="width:42px;height:42px;background:' . $e($v['accent']) . ';border-radius:12px;
                         font-family:\'Bricolage Grotesque\',Georgia,serif;font-weight:700;color:#0b1220;
                         font-size:24px;text-align:center;vertical-align:middle;line-height:42px">W</td>
              <td style="padding-left:10px;color:#f3f4f6;
                         font-family:\'Bricolage Grotesque\',Georgia,serif;font-size:22px;font-weight:700">
                ' . $e($v['brand']) . '
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- Titre -->
        <tr><td align="center" style="padding:18px 24px 4px">
          <h1 style="margin:0;color:#f9fafb;
                     font-family:\'Bricolage Grotesque\',Georgia,serif;font-size:24px;font-weight:600;line-height:1.3">
            ' . $e($v['title']) . '
          </h1>
        </td></tr>

        <!-- Corps -->
        <tr><td style="padding:8px 32px 8px">
          <p style="margin:0 0 12px;color:#cbd5e1;font:15px/1.6 Manrope,Arial,sans-serif">
            Bonjour <strong style="color:#f3f4f6">' . $e($v['username']) . '</strong>,
          </p>
          <p style="margin:0;color:#cbd5e1;font:15px/1.6 Manrope,Arial,sans-serif">
            ' . $e($v['body']) . '
          </p>
        </td></tr>

        ' . $codeBlock . '
        ' . $btnBlock . '

        <!-- Note sécurité -->
        <tr><td style="padding:0 32px 24px">
          <div style="padding:14px 16px;background:#0b1220;border-left:3px solid ' . $e($v['accent']) . ';border-radius:8px">
            <p style="margin:0;color:#94a3b8;font:13px/1.5 Manrope,Arial,sans-serif">
              🔒 ' . $e($v['notice']) . '
            </p>
          </div>
        </td></tr>

        <!-- Footer -->
        <tr><td align="center" style="padding:20px 24px 28px;border-top:1px solid #1f2937">
          <p style="margin:0 0 6px;color:#64748b;font:12px/1.5 Manrope,Arial,sans-serif">
            <a href="' . $e($v['cgu'])     . '" style="color:#64748b;text-decoration:none">CGU</a>
            &nbsp;·&nbsp;
            <a href="' . $e($v['privacy']) . '" style="color:#64748b;text-decoration:none">Confidentialité</a>
          </p>
          <p style="margin:0;color:#475569;font:11px/1.5 Manrope,Arial,sans-serif">
            © ' . date('Y') . ' ' . $e($v['brand']) . ' — Built for the curious.
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>';
    }

    private static function renderText(array $v): string
    {
        $lines   = [];
        $lines[] = $v['title'];
        $lines[] = str_repeat('-', function_exists('mb_strlen')
                                    ? (int) mb_strlen((string) $v['title'])
                                    : strlen((string) $v['title']));
        $lines[] = '';
        $lines[] = 'Bonjour ' . $v['username'] . ',';
        $lines[] = '';
        $lines[] = $v['body'];
        $lines[] = '';
        if (!empty($v['code'])) {
            $lines[] = 'Code : ' . $v['code'];
            $lines[] = '';
        }
        if (!empty($v['link'])) {
            $lines[] = $v['cta'] . ' : ' . $v['link'];
            $lines[] = '';
        }
        $lines[] = '— ' . $v['notice'];
        $lines[] = '';
        $lines[] = '© ' . date('Y') . ' Wintaskly';
        return implode("\r\n", $lines);
    }

    // -----------------------------------------------------------------
    // 2.5) Utilitaires
    // -----------------------------------------------------------------
    private static function cfg(): array
    {
        return $GLOBALS['WT_CONFIG'] ?? [];
    }

    private static function encodeHeader(string $s): string
    {
        // Encode en =?UTF-8?B?…?= si la chaîne contient des non-ASCII
        if (preg_match('/[^\x20-\x7e]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }

    /** Tronque proprement (mbstring si dispo, sinon substr). */
    private static function truncate(string $s, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $max, 'UTF-8');
        }
        return substr($s, 0, $max);
    }

    public static function log(string $msg): void
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        @file_put_contents(
            $logDir . '/mail.log',
            '[' . gmdate('c') . "] $msg\n",
            FILE_APPEND
        );
    }
}
