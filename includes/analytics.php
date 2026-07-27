<?php
/**
 * Wintaskly — Suivi des visiteurs en temps réel (V8.26.0)
 * ----------------------------------------------------------------------
 * Une session = une ligne dans visitor_sessions, identifiée par
 * session_id() PHP. Mise à jour à chaque chargement de page (via
 * wt_track_visitor(), appelée depuis header.php) et via un heartbeat JS
 * léger pour les sessions restant longtemps sur une même page.
 *
 * Confidentialité : l'IP est stockée en binaire (wt_ip_bin(), comme le
 * module anti-fraude), jamais en clair. Le referrer n'est capturé qu'à
 * la création de la session (source de trafic d'origine), jamais écrasé
 * par la navigation interne ensuite. Aucun fingerprinting, aucun cookie
 * tiers — uniquement l'identifiant de session technique déjà utilisé
 * pour le CSRF/l'authentification.
 */
declare(strict_types=1);

if (!function_exists('wt_track_visitor')) {
    /**
     * Enregistre/actualise l'activité de la session courante.
     * Non bloquant : toute erreur est journalisée mais n'interrompt jamais
     * le rendu de la page (le tracking est secondaire, pas critique).
     */
    function wt_track_visitor(): void
    {
        try {
            $db = db();
            $sessionKey = (string) session_id();
            if ($sessionKey === '') {
                return; // pas de session active, rien à tracker
            }

            $userId = null;
            if (function_exists('current_user')) {
                $u = current_user();
                if ($u) { $userId = (int) $u['id']; }
            }

            $ipBin = function_exists('wt_ip_bin') ? wt_ip_bin() : null;
            $ua    = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
            $page  = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 250);
            $ref   = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 250);

            // Referrer externe seulement (on ignore les referrers internes au
            // site, pour ne garder que les vraies sources de trafic externes)
            $baseUrl = (string) ($GLOBALS['WT_CONFIG']['base_url'] ?? '');
            $isExternalRef = $ref !== '' && $baseUrl !== '' && stripos($ref, $baseUrl) !== 0;
            $refToStore = $isExternalRef ? $ref : '';

            // UPSERT : crée la session si absente, sinon met à jour l'activité.
            // Le referrer n'est fixé QU'À LA CRÉATION (VALUES() côté INSERT),
            // jamais réécrit ensuite (préserve la source de trafic d'origine).
            $stmt = $db->prepare(
                "INSERT INTO visitor_sessions
                    (session_key, user_id, ip_bin, user_agent, current_page,
                     referrer, started_at, last_activity, page_views)
                 VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1)
                 ON DUPLICATE KEY UPDATE
                    user_id       = VALUES(user_id),
                    current_page  = VALUES(current_page),
                    last_activity = UTC_TIMESTAMP(),
                    page_views    = page_views + 1"
            );
            $stmt->bind_param('sissss', $sessionKey, $userId, $ipBin, $ua, $page, $refToStore);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly visitor_tracking] ' . $e->getMessage());
        }
    }
}

if (!function_exists('wt_track_heartbeat')) {
    /**
     * Rafraîchit uniquement last_activity (appelé par le heartbeat JS),
     * sans incrémenter page_views (ce n'est pas une nouvelle page).
     */
    function wt_track_heartbeat(): void
    {
        try {
            $db = db();
            $sessionKey = (string) session_id();
            if ($sessionKey === '') {
                return;
            }
            $stmt = $db->prepare(
                "UPDATE visitor_sessions SET last_activity = UTC_TIMESTAMP() WHERE session_key = ?"
            );
            $stmt->bind_param('s', $sessionKey);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('[Wintaskly visitor_heartbeat] ' . $e->getMessage());
        }
    }
}
