<?php
/**
 * Wintaskly — Module Anti-fraude
 * ─────────────────────────────────────────────────────────────────────
 * Détection automatique des comportements frauduleux. Conçu pour être :
 *   - Tolérant aux pannes (si une table manque, ne bloque pas le site)
 *   - 100% configurable via /admin/security.php
 *   - Non intrusif pour les utilisateurs légitimes
 *
 * Détections implémentées :
 *   1. Multi-comptes : trop de comptes créés depuis la même IP
 *   2. Limites de retrait : âge de compte minimum + email vérifié
 *   3. Score de risque : agrégation de signaux suspects
 *
 * Philosophie : on PRÉFÈRE signaler (flag) pour revue manuelle plutôt
 * que bloquer automatiquement, afin d'éviter les faux positifs qui
 * frustreraient de vrais utilisateurs. Le blocage dur est réservé aux
 * cas les plus évidents et reste configurable.
 */
declare(strict_types=1);

if (!function_exists('wt_fraud_cfg')) {
    /**
     * Lit une config anti-fraude avec valeur par défaut sûre.
     */
    function wt_fraud_cfg(string $key, string $default = ''): string
    {
        return (string) cfg('fraud.' . $key, $default);
    }
}

if (!function_exists('wt_fraud_log')) {
    /**
     * Journalise un événement anti-fraude (best-effort, ne plante jamais).
     *
     * @param string   $type     Type d'événement (multi_account, etc.)
     * @param string   $severity info | warning | critical
     * @param int|null $userId
     * @param string   $details
     */
    function wt_fraud_log(string $type, string $severity, ?int $userId, string $details = ''): void
    {
        try {
            $ipBin = function_exists('wt_ip_bin') ? wt_ip_bin() : null;
            $stmt = db()->prepare(
                "INSERT INTO fraud_events (user_id, event_type, severity, details, ip)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('issss', $userId, $type, $severity, $details, $ipBin);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly fraud] log failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_fraud_count_accounts_same_ip')) {
    /**
     * Compte le nombre de comptes enregistrés depuis une IP donnée.
     * Utilise la colonne users.ip_registered (déjà indexée).
     *
     * @param string|null $ipBin IP en binaire (sinon IP courante)
     * @return int
     */
    function wt_fraud_count_accounts_same_ip(?string $ipBin = null): int
    {
        if ($ipBin === null) {
            $ipBin = function_exists('wt_ip_bin') ? wt_ip_bin() : null;
        }
        if ($ipBin === null) {
            return 0;
        }
        try {
            $stmt = db()->prepare("SELECT COUNT(*) c FROM users WHERE ip_registered = ?");
            $stmt->bind_param('s', $ipBin);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wt_fraud_check_signup')) {
    /**
     * Vérifie une tentative d'inscription pour détecter le multi-compte.
     *
     * Retourne :
     *   ['allow' => bool, 'flag' => bool, 'reason' => string]
     *
     * - allow=false : l'inscription doit être bloquée (action 'block')
     * - flag=true   : autoriser mais marquer pour revue manuelle
     *
     * Appelé AVANT la création du compte. Si la détection est désactivée
     * ou indisponible, autorise toujours (fail-open pour ne pas bloquer
     * les inscriptions légitimes en cas de souci technique).
     */
    function wt_fraud_check_signup(): array
    {
        $result = ['allow' => true, 'flag' => false, 'reason' => ''];

        if (wt_fraud_cfg('multiaccount_enabled', '0') !== '1') {
            return $result;
        }

        $maxPerIp = max(1, (int) wt_fraud_cfg('multiaccount_max_per_ip', '3'));
        $existing = wt_fraud_count_accounts_same_ip();

        // Le compte en cours n'est pas encore créé, donc "existing" = comptes
        // déjà présents. Si on est au seuil ou au-dessus, c'est suspect.
        if ($existing >= $maxPerIp) {
            $action = wt_fraud_cfg('multiaccount_action', 'flag');
            $reason = sprintf('Multi-compte suspecté : %d comptes déjà sur cette IP (seuil %d)', $existing, $maxPerIp);

            wt_fraud_log('multi_account', $action === 'block' ? 'critical' : 'warning', null, $reason);

            if ($action === 'block') {
                $result['allow'] = false;
                $result['reason'] = $reason;
            } else {
                $result['flag'] = true;
                $result['reason'] = $reason;
            }
        }

        return $result;
    }
}

if (!function_exists('wt_fraud_flag_user')) {
    /**
     * Marque un utilisateur pour revue manuelle (sans le bloquer).
     * Met à jour risk_score, flagged_at, flag_reason.
     */
    function wt_fraud_flag_user(int $userId, int $riskDelta, string $reason): void
    {
        if ($userId <= 0) return;
        try {
            $stmt = db()->prepare(
                "UPDATE users
                    SET risk_score = LEAST(100, risk_score + ?),
                        flagged_at = UTC_TIMESTAMP(),
                        flag_reason = ?
                  WHERE id = ?"
            );
            $stmt->bind_param('isi', $riskDelta, $reason, $userId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly fraud] flag failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_fraud_check_withdrawal')) {
    /**
     * Vérifie si un utilisateur a le droit de retirer, selon les règles
     * anti-fraude (âge du compte, email vérifié, score de risque).
     *
     * Retourne :
     *   ['allow' => bool, 'reason' => string]
     *
     * @param array $user Données user (id, created_at, email_verified_at,
     *                    risk_score)
     */
    function wt_fraud_check_withdrawal(array $user): array
    {
        $result = ['allow' => true, 'reason' => ''];
        $userId = (int) ($user['id'] ?? 0);

        // 1. Email vérifié obligatoire ?
        if (wt_fraud_cfg('withdraw_require_verified_email', '1') === '1') {
            if (empty($user['email_verified_at'])) {
                $result['allow'] = false;
                $result['reason'] = 'email_not_verified';
                return $result;
            }
        }

        // 2. Âge minimum du compte
        $minAgeHours = (int) wt_fraud_cfg('withdraw_min_account_age_hours', '24');
        if ($minAgeHours > 0 && !empty($user['created_at'])) {
            $ageHours = (time() - strtotime($user['created_at'] . ' UTC')) / 3600;
            if ($ageHours < $minAgeHours) {
                $result['allow'] = false;
                $result['reason'] = 'account_too_young';
                $result['hours_left'] = (int) ceil($minAgeHours - $ageHours);
                wt_fraud_log('rapid_withdraw', 'warning', $userId,
                    sprintf('Retrait tenté à %dh (min %dh)', (int)$ageHours, $minAgeHours));
                return $result;
            }
        }

        // 3. Score de risque trop élevé → blocage
        $blockThreshold = (int) wt_fraud_cfg('risk_threshold_block', '80');
        $riskScore = (int) ($user['risk_score'] ?? 0);
        if ($blockThreshold > 0 && $riskScore >= $blockThreshold) {
            $result['allow'] = false;
            $result['reason'] = 'under_review';
            wt_fraud_log('high_risk_withdraw', 'critical', $userId,
                sprintf('Retrait bloqué : score de risque %d (seuil %d)', $riskScore, $blockThreshold));
            return $result;
        }

        return $result;
    }
}

if (!function_exists('wt_fraud_stats')) {
    /**
     * Statistiques anti-fraude pour le tableau de bord admin.
     */
    function wt_fraud_stats(): array
    {
        $stats = [
            'flagged_users'   => 0,
            'events_today'    => 0,
            'critical_events' => 0,
            'high_risk_users' => 0,
        ];
        try {
            $db = db();
            $stats['flagged_users'] = (int) (db_one("SELECT COUNT(*) c FROM users WHERE flagged_at IS NOT NULL")['c'] ?? 0);
            $stats['events_today']  = (int) (db_one("SELECT COUNT(*) c FROM fraud_events WHERE created_at >= UTC_DATE()")['c'] ?? 0);
            $stats['critical_events'] = (int) (db_one("SELECT COUNT(*) c FROM fraud_events WHERE severity='critical' AND created_at >= DATE_SUB(UTC_DATE(), INTERVAL 7 DAY)")['c'] ?? 0);
            $reviewThreshold = (int) wt_fraud_cfg('risk_threshold_review', '50');
            $stmt = $db->prepare("SELECT COUNT(*) c FROM users WHERE risk_score >= ?");
            $stmt->bind_param('i', $reviewThreshold);
            $stmt->execute();
            $stats['high_risk_users'] = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly fraud] stats: ' . $e->getMessage());
        }
        return $stats;
    }
}

if (!function_exists('wt_fraud_recent_events')) {
    /**
     * Derniers événements anti-fraude pour la revue admin.
     */
    function wt_fraud_recent_events(int $limit = 30): array
    {
        $events = [];
        try {
            $stmt = db()->prepare(
                "SELECT e.id, e.user_id, e.event_type, e.severity, e.details, e.created_at,
                        u.username
                   FROM fraud_events e
                   LEFT JOIN users u ON u.id = e.user_id
                  ORDER BY e.created_at DESC
                  LIMIT ?"
            );
            $stmt->bind_param('i', $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $events[] = $r; }
            $stmt->close();
        } catch (Throwable $e) {}
        return $events;
    }
}
