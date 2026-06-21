<?php
/**
 * Wintaskly — POST /api/withdraw_submit.php
 *
 * Soumet une demande de retrait :
 *   - décrémente le solde de l'utilisateur (transaction atomique),
 *   - insère une ligne `withdrawals` (status='pending') et une
 *     transaction de type 'withdraw' (coins négatifs),
 *   - les Coins seront re-crédités si l'admin refuse plus tard.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . wt_url('/dashboard/withdraw.php'));
    exit;
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    http_response_code(403);
    exit('csrf');
}

$u  = current_user();
$db = db();

$methodId = (int)   ($_POST['method_id']     ?? 0);
$coins    = (float) ($_POST['coins_amount']  ?? 0);
$address  = trim((string)($_POST['payout_address'] ?? ''));

if ($methodId <= 0 || $coins <= 0 || $address === '') {
    header('Location: ' . wt_url('/dashboard/withdraw.php?err=address'));
    exit;
}
if ($u['status'] !== 'active') {
    header('Location: ' . wt_url('/dashboard/withdraw.php?err=suspect'));
    exit;
}

$db->begin_transaction();
try {
    /* 1) Méthode */
    $stmt = $db->prepare(
        "SELECT id, label, currency, coins_per_unit, min_coins, max_coins, active
           FROM withdrawal_methods
          WHERE id = ? LIMIT 1 FOR UPDATE"
    );
    $stmt->bind_param('i', $methodId);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$m || (int) $m['active'] !== 1) {
        $db->rollback();
        header('Location: ' . wt_url('/dashboard/withdraw.php?err=address'));
        exit;
    }

    /* 2) Bornes */
    if ($coins < (float) $m['min_coins']) {
        $db->rollback();
        header('Location: ' . wt_url('/dashboard/withdraw.php?err=min'));
        exit;
    }
    if (!empty($m['max_coins']) && $coins > (float) $m['max_coins']) {
        $db->rollback();
        header('Location: ' . wt_url('/dashboard/withdraw.php?err=max'));
        exit;
    }

    /* 3) Lock + vérification du solde */
    $stmt = $db->prepare("SELECT coins, status, created_at, email_verified_at, risk_score FROM users WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'active') {
        $db->rollback();
        header('Location: ' . wt_url('/dashboard/withdraw.php?err=suspect'));
        exit;
    }

    /* 3b) Contrôles anti-fraude (âge du compte, email vérifié, score) */
    if (function_exists('wt_fraud_check_withdrawal')) {
        $fraudW = wt_fraud_check_withdrawal([
            'id'                => (int) $u['id'],
            'created_at'        => $row['created_at'],
            'email_verified_at' => $row['email_verified_at'],
            'risk_score'        => $row['risk_score'],
        ]);
        if (!$fraudW['allow']) {
            $db->rollback();
            // Code d'erreur précis pour un message clair côté utilisateur
            $errCode = $fraudW['reason']; // email_not_verified | account_too_young | under_review
            header('Location: ' . wt_url('/dashboard/withdraw.php?err=' . urlencode($errCode)));
            exit;
        }
    }

    if ((float) $row['coins'] < $coins) {
        $db->rollback();
        header('Location: ' . wt_url('/dashboard/withdraw.php?err=balance'));
        exit;
    }

    /* 4) Calcul du payout */
    $ratio   = max(1.0, (float) $m['coins_per_unit']);
    $payout  = round($coins / $ratio, 8);
    $currency = (string) $m['currency'];
    $ipBin   = wt_ip_bin();

    /* 5) Décrémente solde */
    $stmt = $db->prepare("UPDATE users SET coins = coins - ? WHERE id = ?");
    $stmt->bind_param('di', $coins, $u['id']);
    $stmt->execute();
    $stmt->close();

    /* 6) Crée la demande */
    $negCoins = -1.0 * $coins;
    $stmt = $db->prepare(
        "INSERT INTO withdrawals
            (user_id, method_id, coins_amount, payout_amount,
             payout_currency, payout_address, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'iiddsss',
        $u['id'], $methodId, $coins, $payout, $currency, $address, $ipBin
    );
    $stmt->execute();
    $wdId = $stmt->insert_id;
    $stmt->close();

    /* 7) Journalise la transaction (coins négatifs) */
    $meta = 'withdraw#' . $wdId . ' ' . $payout . ' ' . $currency;
    $stmt = $db->prepare(
        "INSERT INTO transactions (user_id, type, coins, xp, meta)
         VALUES (?, 'withdraw', ?, 0, ?)"
    );
    $stmt->bind_param('ids', $u['id'], $negCoins, $meta);
    $stmt->execute();
    $stmt->close();

    $db->commit();

    header('Location: ' . wt_url('/dashboard/withdraw.php?ok=1'));
    exit;

} catch (Throwable $e) {
    $db->rollback();
    error_log('withdraw_submit: ' . $e->getMessage());
    header('Location: ' . wt_url('/dashboard/withdraw.php?err=server'));
    exit;
}
