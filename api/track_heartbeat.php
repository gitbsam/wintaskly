<?php
/**
 * API : heartbeat de présence (rafraîchit last_activity de la session).
 * Appelé périodiquement en JS pour garder le statut "en ligne" à jour
 * même si l'utilisateur reste longtemps sur une même page sans naviguer.
 * N'incrémente pas page_views (pas une nouvelle page) et n'écrase jamais
 * le referrer d'origine.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wt_json(['ok' => false], 405);
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    wt_json(['ok' => false, 'message' => 'CSRF invalide'], 403);
}

if (function_exists('wt_track_heartbeat')) {
    wt_track_heartbeat();
}

wt_json(['ok' => true]);
