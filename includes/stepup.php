<?php
/**
 * Wintaskly — includes/stepup.php
 * ---------------------------------------------------------------------------
 * Vérification renforcée avant une action sensible (« step-up »).
 *
 * LE PROBLÈME
 * -----------
 * Une session ouverte suffisait pour effectuer des actions irréversibles :
 * régénérer les codes de secours, changer l'adresse e-mail du compte,
 * modifier une adresse de paiement. Or une session peut être détournée —
 * appareil laissé ouvert, cookie volé, ordinateur partagé.
 *
 * Ces trois actions ont un point commun : elles servent toutes à *prendre
 * le contrôle* d'un compte. C'est exactement ce qu'un attaquant fait en
 * premier. Elles doivent donc exiger une preuve supplémentaire, au moment
 * de l'action, indépendamment de la session.
 *
 * QUELLE PREUVE, ET DANS QUEL ORDRE
 * ---------------------------------
 * On demande la méthode la plus forte dont dispose l'utilisateur :
 *
 *   1. Application d'authentification (TOTP) — ne dépend d'aucun canal
 *      externe, donc insensible à une boîte e-mail compromise.
 *   2. SMS — dépend de l'opérateur, vulnérable au détournement de carte SIM,
 *      mais indépendant de l'e-mail.
 *   3. Code de secours — preuve de possession, valable une seule fois.
 *   4. E-mail — le repli. Suffisant quand rien d'autre n'est actif.
 *
 * ⚠️ CAS PARTICULIER DU CHANGEMENT D'ADRESSE E-MAIL
 * Pour cette action, l'e-mail ne peut PAS servir de preuve : c'est
 * précisément ce qu'on modifie, et un attaquant ayant accès à la boîte
 * validerait sa propre demande. Quand une méthode plus forte existe, elle
 * est donc imposée. Voir wt_stepup_methods() et son paramètre $excludeEmail.
 */
declare(strict_types=1);

if (!function_exists('wt_stepup_methods')) {

    /**
     * Méthodes de vérification utilisables pour une action sensible,
     * de la plus forte à la plus faible.
     *
     * @param array $user         Utilisateur courant
     * @param bool  $excludeEmail Exclure l'e-mail (changement d'adresse)
     * @return array Liste ordonnée : totp, sms, backup, email
     */
    function wt_stepup_methods(array $user, bool $excludeEmail = false): array
    {
        $out = [];
        $uid = (int) ($user['id'] ?? 0);

        if ((int) ($user['totp_enabled'] ?? 0) === 1
            && (!empty($user['has_totp_secret']) || !empty($user['totp_secret']))) {
            $out[] = 'totp';
        }
        if ((int) ($user['twofa_sms_enabled'] ?? 0) === 1
            && !empty($user['twofa_phone']) && !empty($user['twofa_phone_verified_at'])
            && function_exists('wt_2fa_method_ready') && wt_2fa_method_ready('sms')) {
            $out[] = 'sms';
        }
        if ($uid > 0 && function_exists('wt_2fa_backup_remaining')
            && wt_2fa_backup_remaining($uid) > 0) {
            $out[] = 'backup';
        }
        if (!$excludeEmail && !empty($user['email'])
            && function_exists('wt_mail_is_operational') && wt_mail_is_operational()) {
            $out[] = 'email';
        }

        return $out;
    }

    /**
     * Vérifie une preuve fournie par l'utilisateur.
     *
     * Renvoie true uniquement si la méthode fait partie de celles
     * autorisées pour cette action ET que le code est valide. Refuser une
     * méthode non autorisée est essentiel : sans ce contrôle, un attaquant
     * pourrait demander une vérification par e-mail alors qu'on l'avait
     * exclue.
     *
     * @param array  $user   Utilisateur courant
     * @param string $method Méthode revendiquée
     * @param string $code   Code saisi
     * @param array  $allowed Méthodes autorisées (issues de wt_stepup_methods)
     */
    function wt_stepup_verify(array $user, string $method, string $code, array $allowed): bool
    {
        $uid  = (int) ($user['id'] ?? 0);
        $code = trim($code);
        if ($uid <= 0 || $code === '' || !in_array($method, $allowed, true)) {
            return false;
        }

        if ($method === 'totp') {
            /* Le secret n'est pas dans current_user() (exclu volontairement) :
               on le relit ici, uniquement au moment de la vérification. */
            if (!function_exists('auth_totp_verify')) { return false; }
            $row = db_one("SELECT totp_secret FROM users WHERE id = ? LIMIT 1", [$uid], 'i');
            $secret = (string) ($row['totp_secret'] ?? '');
            return $secret !== '' && auth_totp_verify($secret, $code);
        }
        if ($method === 'backup') {
            return function_exists('wt_2fa_verify_backup_code')
                && wt_2fa_verify_backup_code($uid, $code);
        }
        if ($method === 'email' || $method === 'sms') {
            /* wt_2fa_verify_code() renvoie un tableau ['ok' => bool, ...] :
               le traiter comme un booléen aurait toujours donné vrai, un
               tableau non vide étant truthy — la vérification n'aurait
               servi à rien. */
            if (!function_exists('wt_2fa_verify_code')) { return false; }
            $res = wt_2fa_verify_code($uid, $method, $code);
            return is_array($res) ? !empty($res['ok']) : (bool) $res;
        }
        return false;
    }

    /**
     * Marque une action comme vérifiée pour une courte durée.
     *
     * Une fenêtre est nécessaire : sans elle, un formulaire en plusieurs
     * étapes redemanderait un code à chaque soumission. Elle est
     * volontairement courte, et liée à UNE action précise — vérifier un
     * changement d'e-mail n'autorise pas à régénérer des codes de secours.
     */
    function wt_stepup_grant(string $action, int $seconds = 300): void
    {
        $_SESSION['stepup'][$action] = time() + max(60, $seconds);
    }

    /** L'action a-t-elle été vérifiée récemment ? */
    function wt_stepup_granted(string $action): bool
    {
        $exp = (int) ($_SESSION['stepup'][$action] ?? 0);
        if ($exp > time()) { return true; }
        unset($_SESSION['stepup'][$action]);
        return false;
    }

    /** Révoque une autorisation après usage (jeton à usage unique). */
    function wt_stepup_consume(string $action): void
    {
        unset($_SESSION['stepup'][$action]);
    }

    /**
     * Libellé lisible d'une méthode, pour l'interface.
     */
    function wt_stepup_label(string $method): string
    {
        $map = [
            'totp'   => 'stepup.m_totp',
            'sms'    => 'stepup.m_sms',
            'backup' => 'stepup.m_backup',
            'email'  => 'stepup.m_email',
        ];
        return isset($map[$method]) ? (string) t($map[$method]) : $method;
    }
}
