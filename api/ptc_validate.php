<?php
/**
 * Wintaskly — POST /api/ptc_validate.php
 *
 * Valide la fin d'une session PTC :
 *   - vérifie auth + CSRF,
 *   - vérifie token + user (FOR UPDATE),
 *   - exige que la session soit active et non expirée,
 *   - vérifie que la durée minimale a été respectée (anti-bot click immédiat),
 *   - vérifie le slug captcha (hash_equals),
 *   - marque atomique status='consumed', insère ptc_views, crédite.
 *
 * Réponse JSON :
 *   { ok: true, coins, xp }
 *   { ok: false, error: 'message' }
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') wt_json(['ok' => false, 'error' => 'method'], 405);
if (!csrf_check($_POST['_csrf'] ?? null)) wt_json(['ok' => false, 'error' => 'csrf'], 403);

$u = current_user();
if (!$u) wt_json(['ok' => false, 'error' => 'auth'], 401);

$token = trim((string) ($_POST['token'] ?? ''));
$slug  = trim((string) ($_POST['slug']  ?? ''));
if ($token === '' || $slug === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    wt_json(['ok' => false, 'error' => 'payload']);
}

$db = db();
$db->begin_transaction();

try {
    /* 1) Verrou ligne — session + user + active */
    $stmt = $db->prepare(
        "SELECT s.id, s.ptc_id, s.captcha_target, s.started_at, s.expires_at, s.status,
                a.reward_coins, a.reward_xp, a.duration_seconds, a.cooldown_hours, a.title
           FROM ptc_sessions s
           JOIN ptc_ads a ON a.id = s.ptc_id
          WHERE s.token = ? AND s.user_id = ?
          LIMIT 1
          FOR UPDATE"
    );
    $stmt->bind_param('si', $token, $u['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => 'token']);
    }
    if ($row['status'] !== 'active') {
        $db->rollback();
        wt_json(['ok' => false, 'error' => 'consumed']);
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        // Marque expirée puis renvoie
        $upd = $db->prepare("UPDATE ptc_sessions SET status='expired' WHERE id = ?");
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
        $db->commit();
        wt_json(['ok' => false, 'error' => t('faucet.timeout')]);
    }

    /* 2) Délai minimum respecté (anti-validation immédiate) */
    $elapsed = time() - strtotime($row['started_at'] . ' UTC');
    $minWait = max(5, (int) $row['duration_seconds'] - 2); // tolérance 2s
    if ($elapsed < $minWait) {
        flag_cheat((int) $u['id'], 'ptc_too_fast', false);
        $upd = $db->prepare("UPDATE ptc_sessions SET status='rejected', reject_reason='too_fast' WHERE id = ?");
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
        $db->commit();
        wt_json(['ok' => false, 'error' => t('faucet.cheat')]);
    }

    /* 3) Vérification captcha (constant-time) */
    if (!hash_equals((string) $row['captcha_target'], $slug)) {
        wt_json(['ok' => false, 'error' => t('faucet.cheat')]);
    }

    /* 4) Marquage consumed AVANT crédit (anti-replay) */
    $upd = $db->prepare("UPDATE ptc_sessions SET status='consumed' WHERE id = ? AND status='active'");
    $upd->bind_param('i', $row['id']);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();
    if ($affected !== 1) {
        $db->rollback();
        wt_json(['ok' => false, 'error' => 'race']);
    }

    /* 5) Historique + cooldown */
    $cooldownH = max(1, (int) $row['cooldown_hours']);
    $ptcId     = (int) $row['ptc_id'];
    $coins     = (float) $row['reward_coins'];
    $xp        = (int) $row['reward_xp'];

    $ins = $db->prepare(
        "INSERT INTO ptc_views (user_id, ptc_id, coins, xp, next_view_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR)"
    );
    $ins->bind_param('iidii', $u['id'], $ptcId, $coins, $xp, $cooldownH);
    $ins->execute();
    $ins->close();

    /* 6) Compteur global de vues sur l'annonce */
    $upd = $db->prepare("UPDATE ptc_ads SET total_views = total_views + 1 WHERE id = ?");
    $upd->bind_param('i', $ptcId);
    $upd->execute();
    $upd->close();

    $db->commit();

    /* 7) Crédit (transaction interne) + commission parrain 10% */
    award_user((int) $u['id'], $coins, $xp, 'ptc', 'ptc#' . $ptcId);

    wt_json(['ok' => true, 'coins' => $coins, 'xp' => $xp]);

} catch (Throwable $e) {
    $db->rollback();
    error_log('ptc_validate: ' . $e->getMessage());
    wt_json(['ok' => false, 'error' => 'server'], 500);
}
