<?php
/**
 * Wintaskly — Double authentification multi-méthodes
 * ---------------------------------------------------------------------------
 * Trois méthodes coexistent, activables indépendamment par l'administrateur
 * puis par l'utilisateur :
 *
 *   totp   — application d'authentification. La plus sûre : le code est
 *            calculé hors ligne, rien ne transite par le réseau.
 *   email  — code à usage unique envoyé par e-mail. Pratique, mais la
 *            sécurité du compte dépend alors de celle de la boîte e-mail.
 *   sms    — code à usage unique envoyé par SMS. Le plus accessible, mais
 *            vulnérable au détournement de carte SIM. Nécessite un
 *            fournisseur externe.
 *
 * À quoi servent les CODES DE SECOURS : ils sont le seul recours quand le
 * téléphone est perdu ou la boîte e-mail inaccessible. Sans eux, la 2FA
 * transforme une protection en risque de perte définitive du compte.
 *
 * FORMATS retenus (demandés) :
 *   sms    — 8 chiffres. Saisie rapide sur un clavier numérique, et pas
 *            d'ambiguïté de casse à la lecture d'un SMS.
 *   email  — 7 caractères alphanumériques, sans caractères confondables.
 *   secours— 10 caractères alphanumériques, présentés par groupes de 5.
 *   totp   — 6 chiffres, imposé par le standard des applications.
 *
 * Les codes ne sont JAMAIS stockés en clair : seul leur hachage est
 * enregistré, comme un mot de passe.
 */
declare(strict_types=1);

/* Alphabet sans caractères confondables (ni 0/O, ni 1/I/L) : un code lu sur
   un écran ou recopié depuis un e-mail ne doit pas prêter à confusion. */
const WT_2FA_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

if (!function_exists('wt_2fa_method_ready')) {
    /**
     * La méthode dispose-t-elle de ce dont elle a besoin pour fonctionner ?
     *
     * GARDE-FOU EN AMONT. Activer une méthode dans l'admin ne suffit pas :
     * encore faut-il que son canal de délivrance existe. Sans ce contrôle,
     * un utilisateur pourrait activer le SMS, se déconnecter, et se
     * retrouver définitivement enfermé dehors en attendant un code qui ne
     * partira jamais.
     *
     * Dépendances réelles, méthode par méthode :
     *
     *   totp  — AUCUNE. Le code est calculé localement par une fonction de
     *           hachage sur l'horloge (RFC 6238). L'application de
     *           l'utilisateur fait le même calcul de son côté. Il n'y a ni
     *           appel réseau, ni fournisseur, ni coût. C'est précisément ce
     *           qui en fait la méthode la plus fiable — et celle qui
     *           continue de fonctionner même si l'e-mail et le SMS tombent.
     *
     *   email — dépend du système d'envoi d'e-mails. Si le pilote est en
     *           mode journal (développement), les codes ne partent pas.
     *
     *   sms   — dépend d'un fournisseur externe payant, à configurer
     *           (sms.provider + sms.api_key). Sans lui, rien ne part.
     */
    function wt_2fa_method_ready(string $method): bool
    {
        return match ($method) {
            'totp'  => true,
            'email' => wt_mail_is_operational(),
            'sms'   => trim((string) cfg('sms.provider', '')) !== ''
                       && trim((string) cfg('sms.api_key', '')) !== '',
            default => false,
        };
    }
}

if (!function_exists('wt_mail_is_operational')) {
    /**
     * L'envoi d'e-mails est-il réellement opérationnel ?
     *
     * En mode « log », les messages sont écrits dans un fichier et non
     * délivrés : la 2FA par e-mail serait alors un piège.
     */
    function wt_mail_is_operational(): bool
    {
        $cfg    = $GLOBALS['WT_CONFIG'] ?? [];
        $driver = $cfg['mail']['driver'] ?? null;
        if ($driver === null) {
            if (function_exists('cfg') && cfg('email.smtp_host', '')) {
                return true;
            }
            return (($cfg['environment'] ?? 'development') === 'production');
        }
        return $driver !== 'log';
    }
}

if (!function_exists('wt_2fa_admin_methods')) {
    /**
     * Méthodes autorisées par l'administrateur, dans l'ordre de préférence.
     *
     * L'ordre compte : la première méthode disponible pour l'utilisateur est
     * celle qui lui sera proposée en premier à la connexion. Il pourra en
     * changer, mais le chemin par défaut doit être le plus sûr possible.
     *
     * @return string[]
     */
    function wt_2fa_admin_methods(): array
    {
        $order = [];
        // La méthode marquée « recommandée » en admin passe devant
        $preferred = (string) cfg('2fa.preferred', 'totp');
        foreach (['totp', 'email', 'sms'] as $m) {
            // Activée en admin ET techniquement délivrable : les deux
            // conditions sont nécessaires (voir wt_2fa_method_ready).
            if ((string) cfg('2fa.' . $m . '_enabled', $m === 'sms' ? '0' : '1') === '1'
                && wt_2fa_method_ready($m)) {
                $order[] = $m;
            }
        }
        if (in_array($preferred, $order, true)) {
            $order = array_merge([$preferred], array_values(array_diff($order, [$preferred])));
        }
        return $order;
    }
}

if (!function_exists('wt_2fa_user_methods')) {
    /**
     * Méthodes réellement utilisables par CE compte, dans l'ordre de priorité.
     *
     * Une méthode doit être activée des deux côtés : par l'administrateur ET
     * par l'utilisateur. Si l'admin désactive le SMS, un compte qui l'avait
     * activé ne le voit plus — le contrôle reste côté plateforme.
     *
     * @param array $user Ligne users
     * @return string[]
     */
    function wt_2fa_user_methods(array $user): array
    {
        $allowed = wt_2fa_admin_methods();
        $out = [];
        foreach ($allowed as $m) {
            $ok = match ($m) {
                'totp'  => (int) ($user['totp_enabled'] ?? 0) === 1 && !empty($user['totp_secret']),
                'email' => (int) ($user['twofa_email_enabled'] ?? 0) === 1 && !empty($user['email']),
                'sms'   => (int) ($user['twofa_sms_enabled'] ?? 0) === 1
                           && !empty($user['twofa_phone'])
                           && !empty($user['twofa_phone_verified_at']),
                default => false,
            };
            if ($ok) { $out[] = $m; }
        }
        // Choix personnel de l'utilisateur : il passe devant, s'il reste valide
        $pref = (string) ($user['twofa_preferred'] ?? '');
        if ($pref !== '' && in_array($pref, $out, true)) {
            $out = array_merge([$pref], array_values(array_diff($out, [$pref])));
        }
        return $out;
    }
}

if (!function_exists('wt_2fa_lockout_risk')) {
    /**
     * Le compte risque-t-il de se retrouver enfermé dehors ?
     *
     * Situation redoutée : la seule méthode 2FA du compte devient
     * indisponible — l'admin désactive le SMS, le fournisseur tombe, la
     * boîte e-mail n'est plus accessible — et l'utilisateur n'a aucun code
     * de secours. Le compte devient alors irrécupérable sans intervention
     * manuelle.
     *
     * Cette fonction permet d'avertir AVANT que ça n'arrive : à
     * l'activation de la 2FA, et dans les réglages du compte.
     *
     * @return array{risk:bool, reason:string}
     */
    function wt_2fa_lockout_risk(array $user): array
    {
        $methods = wt_2fa_user_methods($user);
        if ($methods === []) {
            return ['risk' => false, 'reason' => ''];   // pas de 2FA, pas de risque
        }
        $backups = wt_2fa_backup_remaining((int) $user['id']);
        if ($backups > 0) {
            // Des codes de secours existent : c'est précisément le recours
            // prévu pour ce cas. Aucun risque d'enfermement.
            return ['risk' => false, 'reason' => ''];
        }
        if ((string) cfg('2fa.backup_enabled', '1') === '1') {
            return ['risk' => true, 'reason' => 'no_backup_codes'];
        }
        /* Codes de secours désactivés par l'admin : le risque devient réel
           si la seule méthode du compte dépend d'un canal externe (boîte
           e-mail perdue, téléphone volé). Le TOTP, lui, reste utilisable
           tant que l'utilisateur a sauvegardé sa clé. */
        if (count($methods) === 1 && in_array($methods[0], ['email', 'sms'], true)) {
            return ['risk' => true, 'reason' => 'single_external_method'];
        }
        return ['risk' => false, 'reason' => ''];
    }
}

if (!function_exists('wt_2fa_is_enabled')) {
    /** Le compte a-t-il au moins une méthode 2FA utilisable ? */
    function wt_2fa_is_enabled(array $user): bool
    {
        return wt_2fa_user_methods($user) !== [];
    }
}

if (!function_exists('wt_2fa_generate_code')) {
    /**
     * Génère un code au format propre à la méthode.
     * Utilise random_int() (générateur cryptographique), jamais rand().
     */
    function wt_2fa_generate_code(string $method): string
    {
        if ($method === 'sms') {
            // 8 chiffres : saisie au clavier numérique, aucune ambiguïté de casse
            $c = '';
            for ($i = 0; $i < 8; $i++) { $c .= (string) random_int(0, 9); }
            return $c;
        }
        // e-mail : 7 caractères alphanumériques sans caractères confondables
        $n = strlen(WT_2FA_ALPHABET) - 1;
        $c = '';
        for ($i = 0; $i < 7; $i++) { $c .= WT_2FA_ALPHABET[random_int(0, $n)]; }
        return $c;
    }
}

if (!function_exists('wt_2fa_issue_code')) {
    /**
     * Crée un code à usage unique et l'envoie par la méthode demandée.
     *
     * Invalide les codes précédents non utilisés : demander un nouveau code
     * doit rendre l'ancien inopérant, sinon plusieurs codes valides
     * circuleraient en même temps.
     *
     * @return array{ok:bool, error?:string, ttl?:int}
     */
    function wt_2fa_issue_code(array $user, string $method): array
    {
        if (!in_array($method, ['email', 'sms'], true)) {
            return ['ok' => false, 'error' => 'method_unsupported'];
        }
        $db  = db();
        $uid = (int) $user['id'];
        $ttl = max(1, (int) cfg('2fa.code_ttl_minutes', 10));

        try {
            // Un seul code vivant à la fois pour cette méthode
            $stmt = $db->prepare(
                "UPDATE user_2fa_codes SET used_at = UTC_TIMESTAMP()
                  WHERE user_id = ? AND method = ? AND used_at IS NULL"
            );
            $stmt->bind_param('is', $uid, $method);
            $stmt->execute();
            $stmt->close();

            $code = wt_2fa_generate_code($method);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $ip   = function_exists('wt_ip_bin') ? wt_ip_bin() : null;

            $stmt = $db->prepare(
                "INSERT INTO user_2fa_codes (user_id, method, code_hash, expires_at, ip)
                 VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? MINUTE, ?)"
            );
            $stmt->bind_param('issis', $uid, $method, $hash, $ttl, $ip);
            $stmt->execute();
            $stmt->close();

            // Envoi
            if ($method === 'email') {
                wt_mail((string) $user['email'], 'twofa_code', [
                    'username' => (string) ($user['username'] ?? ''),
                    'code'     => $code,
                    'minutes'  => $ttl,
                ]);
            } else {
                wt_sms_send((string) $user['twofa_phone'],
                    sprintf((string) t('auth.2fa.sms_body'), $code, $ttl));
            }
            return ['ok' => true, 'ttl' => $ttl];
        } catch (Throwable $e) {
            error_log('[Wintaskly 2FA issue] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'issue_failed'];
        }
    }
}

if (!function_exists('wt_2fa_verify_code')) {
    /**
     * Vérifie un code e-mail ou SMS.
     *
     * Le nombre de tentatives est plafonné : au-delà, le code est brûlé et il
     * faut en demander un nouveau. Sans cela, un code de 7 ou 8 caractères
     * serait devinable par force brute sur la durée de validité.
     *
     * @return array{ok:bool, error?:string}
     */
    function wt_2fa_verify_code(int $userId, string $method, string $code): array
    {
        $db   = db();
        $code = trim($code);
        $max  = max(1, (int) cfg('2fa.max_attempts', 5));

        try {
            $stmt = $db->prepare(
                "SELECT id, code_hash, attempts FROM user_2fa_codes
                  WHERE user_id = ? AND method = ? AND used_at IS NULL
                    AND expires_at > UTC_TIMESTAMP()
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->bind_param('is', $userId, $method);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return ['ok' => false, 'error' => 'code_expired'];
            }
            if ((int) $row['attempts'] >= $max) {
                $db->query("UPDATE user_2fa_codes SET used_at = UTC_TIMESTAMP() WHERE id = " . (int) $row['id']);
                return ['ok' => false, 'error' => 'too_many_attempts'];
            }
            if (!password_verify($code, (string) $row['code_hash'])) {
                $stmt = $db->prepare("UPDATE user_2fa_codes SET attempts = attempts + 1 WHERE id = ?");
                $id = (int) $row['id'];
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                return ['ok' => false, 'error' => 'code_invalid'];
            }
            // Succès : le code est consommé
            $stmt = $db->prepare("UPDATE user_2fa_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?");
            $id = (int) $row['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('[Wintaskly 2FA verify] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'verify_failed'];
        }
    }
}

if (!function_exists('wt_2fa_generate_backup_codes')) {
    /**
     * Génère un lot de codes de secours et remplace les précédents.
     *
     * Les codes sont retournés EN CLAIR une seule fois, à cet instant : ils
     * ne sont ensuite plus consultables, seuls leurs hachages sont conservés.
     * L'utilisateur doit donc les enregistrer immédiatement.
     *
     * @return string[] les codes en clair, à afficher une seule fois
     */
    function wt_2fa_generate_backup_codes(int $userId): array
    {
        $db    = db();
        $count = max(4, min(20, (int) cfg('2fa.backup_count', 10)));
        $n     = strlen(WT_2FA_ALPHABET) - 1;
        $codes = [];

        try {
            $stmt = $db->prepare("DELETE FROM user_backup_codes WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();

            $ins = $db->prepare("INSERT INTO user_backup_codes (user_id, code_hash) VALUES (?, ?)");
            for ($i = 0; $i < $count; $i++) {
                $c = '';
                for ($j = 0; $j < 10; $j++) { $c .= WT_2FA_ALPHABET[random_int(0, $n)]; }
                $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);   // lisibilité
                $hash = password_hash($c, PASSWORD_DEFAULT);
                $ins->bind_param('is', $userId, $hash);
                $ins->execute();
            }
            $ins->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly 2FA backup] ' . $e->getMessage());
            return [];
        }
        return $codes;
    }
}

if (!function_exists('wt_2fa_verify_backup_code')) {
    /**
     * Vérifie un code de secours et le consomme.
     *
     * Le tiret de lisibilité est retiré avant vérification : l'utilisateur
     * peut saisir le code avec ou sans, en majuscules ou minuscules.
     */
    function wt_2fa_verify_backup_code(int $userId, string $code): bool
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if ($clean === '') { return false; }
        try {
            $db = db();
            $stmt = $db->prepare(
                "SELECT id, code_hash FROM user_backup_codes
                  WHERE user_id = ? AND used_at IS NULL"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            foreach ($rows as $r) {
                if (password_verify($clean, (string) $r['code_hash'])) {
                    $stmt = $db->prepare("UPDATE user_backup_codes SET used_at = UTC_TIMESTAMP() WHERE id = ?");
                    $id = (int) $r['id'];
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly 2FA backup verify] ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('wt_2fa_backup_remaining')) {
    /** Nombre de codes de secours encore utilisables. */
    function wt_2fa_backup_remaining(int $userId): int
    {
        try {
            $stmt = db()->prepare(
                "SELECT COUNT(*) c FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL"
            );
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $n = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wt_sms_send')) {
    /**
     * Envoi d'un SMS.
     *
     * ⚠️ Aucun fournisseur n'est intégré par défaut : l'envoi de SMS est un
     * service payant qui exige un compte chez un opérateur (Twilio, Vonage,
     * OVH, Brevo...). Tant que 'sms.provider' n'est pas configuré, la
     * fonction journalise le message et retourne false — le code n'est donc
     * jamais délivré, et la méthode SMS reste inutilisable.
     *
     * C'est volontaire : mieux vaut un échec explicite qu'un utilisateur
     * bloqué devant un code qui n'arrivera jamais.
     */
    function wt_sms_send(string $phone, string $message): bool
    {
        $provider = trim((string) cfg('sms.provider', ''));
        if ($provider === '') {
            error_log('[Wintaskly SMS] aucun fournisseur configure — message non envoye a ' . $phone);
            return false;
        }
        // Point d'extension : brancher ici l'API du fournisseur retenu.
        error_log('[Wintaskly SMS] fournisseur "' . $provider . '" non implemente');
        return false;
    }
}

if (!function_exists('wt_2fa_alert_new_login')) {
    /**
     * Alerte de connexion depuis un nouvel appareil.
     *
     * C'est la détection la plus efficace d'un compte compromis : même avec
     * un mot de passe volé, l'attaquant ne peut pas empêcher l'alerte de
     * partir. On notifie par e-mail ET dans le centre de notifications.
     */
    function wt_2fa_alert_new_login(array $user): void
    {
        if ((string) cfg('2fa.alert_new_login', '1') !== '1') { return; }
        try {
            $when = function_exists('wt_format_datetime')
                    ? wt_format_datetime(gmdate('Y-m-d H:i:s'))
                    : gmdate('Y-m-d H:i');
            $ipTxt = $_SERVER['REMOTE_ADDR'] ?? '';

            if (function_exists('wt_notify')) {
                wt_notify((int) $user['id'], 'security',
                    (string) t('auth.alert_login_title'),
                    sprintf((string) t('auth.alert_login_body'), $when));
            }
            if (!empty($user['email'])) {
                wt_mail((string) $user['email'], 'security_alert', [
                    'username' => (string) ($user['username'] ?? ''),
                    'event'    => (string) t('auth.alert_login_title'),
                    'when'     => $when,
                    'ip'       => $ipTxt,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly 2FA alert] ' . $e->getMessage());
        }
    }
}
