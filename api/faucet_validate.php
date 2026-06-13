<?php
/**
 * API : validation finale du Faucet (étape 3 → crédit).
 *
 * COUCHES DE SÉCURITÉ ANTI-TRICHE :
 *   1. CSRF token (depuis $_SESSION).
 *   2. Authentification obligatoire.
 *   3. Jeton de session Faucet présent, valide, "open" et appartenant à l'utilisateur.
 *   4. Identité réseau cohérente (IP/UA — soft check, log si mismatch).
 *   5. Honeypots : si l'un des deux champs masqués est rempli → ban + rejet.
 *   6. Checkbox "not_robot" obligatoirement cochée.
 *   7. Captcha visuel : le slug envoyé doit correspondre EXACTEMENT au
 *      captcha_target stocké en base au moment de l'étape 1.
 *   8. Timer 5 minutes : NOW() <= expires_at, strict.
 *   9. Délai minimum : la soumission ne doit pas être quasi-instantanée
 *      après step1_at (anti-bot ultra-rapide). Seuil : 8 secondes.
 *   10. Le jeton est immédiatement marqué "consumed" (one-shot) avant
 *       toute attribution → impossible de rejouer la requête.
 *   11. Crédit + XP + commission de parrainage exécutés en transaction.
 *   12. next_claim_at = NOW() + 3h (paramétrable).
 *
 * En cas d'échec : status "rejected" + raison + ban automatique sur honeypot.
 */

declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wt_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}
// Standard projet : '_csrf'. Fallback 'csrf' pour rétro-compat clients en cache.
if (!csrf_check($_POST['_csrf'] ?? $_POST['csrf'] ?? null)) {
    wt_json(['ok' => false, 'message' => 'CSRF invalide'], 403);
}

$user = current_user();
if (!$user) {
    wt_json(['ok' => false, 'message' => 'Auth requise', 'redirect' => wt_url('/auth/login.php')], 401);
}

$token       = (string)($_POST['token']      ?? '');
$picked      = (string)($_POST['picked']     ?? '');
$notRobot    = (string)($_POST['not_robot']  ?? '') === '1';
$honeyA      = trim((string)($_POST['website']  ?? ''));
$honeyB      = trim((string)($_POST['address2'] ?? ''));

$db = db();

// ---- 1) Honeypots : un humain ne touche jamais à ces champs --------
if ($honeyA !== '' || $honeyB !== '') {
    flag_cheat($user['id'], 'honeypot_filled', true);
    wt_json([
        'ok'       => false,
        'message'  => t('faucet.cheat'),
        'redirect' => wt_url('/tasks/faucet/'),
    ], 400);
}

// ---- 2) Récupération de la session (avec lock pour éviter rejeu) ----
$db->begin_transaction();

try {
    $stmt = $db->prepare(
        "SELECT id, user_id, token, step1_at, expires_at, status,
                captcha_target, ip, user_agent
           FROM faucet_sessions
          WHERE token = ?
          LIMIT 1
          FOR UPDATE"
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        $db->rollback();
        wt_json(['ok' => false, 'message' => 'Jeton invalide', 'redirect' => wt_url('/tasks/faucet/')], 400);
    }
    if ((int)$session['user_id'] !== (int)$user['id']) {
        flag_cheat($user['id'], 'token_user_mismatch', true);
        $db->rollback();
        wt_json(['ok' => false, 'message' => t('faucet.cheat'), 'redirect' => wt_url('/tasks/faucet/')], 403);
    }
    if ($session['status'] !== 'open') {
        $db->rollback();
        wt_json([
            'ok'       => false,
            'message'  => 'Session déjà consommée ou invalide',
            'redirect' => wt_url('/tasks/faucet/'),
        ], 409);
    }

    // ---- 3) Timer 5 minutes ----------------------------------------
    $now       = time();
    $startTs   = strtotime($session['step1_at'] . ' UTC');
    $expiresTs = strtotime($session['expires_at'] . ' UTC');
    if ($now > $expiresTs) {
        $stmt = $db->prepare(
            "UPDATE faucet_sessions
                SET status='expired', reject_reason='timeout'
              WHERE id = ?"
        );
        $stmt->bind_param('i', $session['id']);
        $stmt->execute();
        $stmt->close();
        $db->commit();
        wt_json([
            'ok'       => false,
            'message'  => t('faucet.timeout'),
            'redirect' => wt_url('/tasks/faucet/'),
        ], 410);
    }

    // ---- 4) Délai minimum (anti-bot ultra-rapide) -------------------
    if (($now - $startTs) < 8) {
        $stmt = $db->prepare(
            "UPDATE faucet_sessions
                SET status='rejected', reject_reason='too_fast'
              WHERE id = ?"
        );
        $stmt->bind_param('i', $session['id']);
        $stmt->execute();
        $stmt->close();
        flag_cheat($user['id'], 'too_fast');
        $db->commit();
        wt_json([
            'ok'       => false,
            'message'  => t('faucet.cheat'),
            'redirect' => wt_url('/tasks/faucet/'),
        ], 400);
    }

    // ---- 5) Checkbox + captcha -------------------------------------
    if (!$notRobot) {
        $stmt = $db->prepare(
            "UPDATE faucet_sessions
                SET status='rejected', reject_reason='not_robot_unchecked'
              WHERE id = ?"
        );
        $stmt->bind_param('i', $session['id']);
        $stmt->execute();
        $stmt->close();
        $db->commit();
        wt_json([
            'ok'       => false,
            'message'  => 'Veuillez cocher la case anti-robot.',
            'redirect' => wt_url('/tasks/faucet/'),
        ], 400);
    }

    if (!hash_equals($session['captcha_target'], $picked)) {
        $stmt = $db->prepare(
            "UPDATE faucet_sessions
                SET status='rejected', reject_reason='captcha_mismatch'
              WHERE id = ?"
        );
        $stmt->bind_param('i', $session['id']);
        $stmt->execute();
        $stmt->close();
        flag_cheat($user['id'], 'captcha_mismatch');
        $db->commit();
        wt_json([
            'ok'       => false,
            'message'  => t('faucet.cheat'),
            'redirect' => wt_url('/tasks/faucet/'),
        ], 400);
    }

    // ---- 6) On marque la session "consumed" AVANT crédit ------------
    $stmt = $db->prepare(
        "UPDATE faucet_sessions
            SET status='consumed', step3_at = NOW()
          WHERE id = ? AND status = 'open'"
    );
    $stmt->bind_param('i', $session['id']);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        $db->rollback();
        wt_json(['ok' => false, 'message' => 'Conflit', 'redirect' => wt_url('/tasks/faucet/')], 409);
    }
    $stmt->close();

    // ---- 7) Calcul des récompenses ---------------------------------
    $rewardCoins = (float)cfg('faucet_reward_coins', '25.0000');
    $rewardXp    = (int)cfg('faucet_reward_xp', '10');

    // award_user lance sa propre transaction interne — on commit la nôtre d'abord
    $sessionId = (int)$session['id'];
    $db->commit();

    $result = award_user($user['id'], $rewardCoins, $rewardXp, 'faucet', 'session:' . $sessionId);

    // ---- 8) Enregistrement de la réclamation + cooldown 3h ---------
    $cooldownSec = (int)cfg('faucet_cooldown_seconds', 10800);
    $nextAt      = gmdate('Y-m-d H:i:s', time() + $cooldownSec);
    $ipBin       = wt_ip_bin();

    $stmt = $db->prepare(
        "INSERT INTO faucet_claims
           (user_id, session_id, coins_awarded, xp_awarded, ip, next_claim_at)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iidiss', $user['id'], $sessionId, $rewardCoins, $rewardXp, $ipBin, $nextAt);
    $stmt->execute();
    $stmt->close();

    wt_json([
        'ok'        => true,
        'message'   => t('faucet.success'),
        'coins'     => $rewardCoins,
        'xp'        => $rewardXp,
        'new_level' => $result['new_level'],
        'next'      => wt_url('/tasks/faucet/'),
    ]);

} catch (Throwable $e) {
    if ($db->errno || $db->thread_id) {
        @$db->rollback();
    }
    if (!empty($GLOBALS['WT_CONFIG']['debug'])) {
        wt_json(['ok' => false, 'message' => 'Erreur : ' . $e->getMessage()], 500);
    }
    wt_json(['ok' => false, 'message' => t('common.error')], 500);
}
