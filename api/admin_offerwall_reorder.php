<?php
/**
 * Wintaskly — POST /api/admin_offerwall_reorder.php
 *
 * Reçoit un nouvel ordre d'offerwalls après drag-and-drop dans
 * /admin/offerwalls.php et met à jour `sort_order` en BDD.
 *
 * Payload attendu (POST form-urlencoded ou JSON) :
 *   _csrf = "..."
 *   ids[] = 12
 *   ids[] = 5
 *   ids[] = 3
 *   ...
 *
 * L'ordre du tableau ids[] devient l'ordre final : le premier élément
 * reçoit sort_order=1, le suivant 2, etc.
 *
 * Réservé aux admins. Utilise une transaction pour garantir l'atomicité
 * (soit tous les UPDATE passent, soit aucun — pas d'état incohérent).
 *
 * Réponse JSON :
 *   { ok: true, count: 7 }   sur succès
 *   { ok: false, error: "..." } sur échec
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

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    wt_json(['ok' => false, 'error' => 'no_ids']);
}

// Sécurise : cast en int, filtre les <=0, et limite à 200 entrées max
$ids = array_values(array_filter(array_map('intval', $ids), function ($v) {
    return $v > 0;
}));
if (count($ids) > 200) {
    $ids = array_slice($ids, 0, 200);
}
if (empty($ids)) {
    wt_json(['ok' => false, 'error' => 'no_valid_ids']);
}

$db = db();

// Transaction : tous les UPDATE ou aucun (atomicité).
$db->begin_transaction();
try {
    $stmt = $db->prepare("UPDATE offerwalls SET sort_order = ? WHERE id = ?");
    foreach ($ids as $pos => $id) {
        $newOrder = $pos + 1;  // 1-indexé pour cohérence avec ce que l'admin voit
        $stmt->bind_param('ii', $newOrder, $id);
        $stmt->execute();
    }
    $stmt->close();
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    error_log('[Wintaskly admin_offerwall_reorder] ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => 'db_error']);
}

wt_json(['ok' => true, 'count' => count($ids)]);
