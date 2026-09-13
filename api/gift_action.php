<?php
/**
 * Wintaskly — api/gift_action.php
 *
 * Trois actions : ouvrir le parcours publicitaire d'une boîte, le
 * clôturer pour révéler le lot, ou engager un ticket pour appliquer le
 * multiplicateur proposé.
 *
 * Le lot n'est jamais transmis avant la fin du parcours : une boîte
 * dont on connaîtrait le contenu à l'avance n'aurait plus d'intérêt, et
 * on choisirait la meilleure.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

if (!wt_gift_visible_for($u)) {
    echo json_encode(['ok' => false, 'error' => 'disabled', 'message' => t('gift.err_disabled')]);
    exit;
}

$userId = (int) $u['id'];
$action = (string) ($_POST['action'] ?? '');

$dire = static function (string $code): string {
    switch ($code) {
        case 'taken':        return t('gift.err_taken');
        case 'too_fast':     return t('gift.err_too_fast');
        case 'used':         return t('gift.err_used');
        case 'not_found':    return t('gift.err_not_found');
        case 'round_closed': return t('gift.err_round_closed');
        case 'no_tickets':   return t('gift.err_no_tickets');
        case 'already':      return t('gift.err_already');
        case 'no_offer':     return t('gift.err_no_offer');
        default:             return t('common.error');
    }
};

if ($action === 'open') {
    $r = wt_gift_open_start($userId, (int) ($_POST['box_id'] ?? 0));
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'token'   => $r['token'],
        'seconds' => $r['seconds'],
        /* Sans URL configurée, on ouvre la page d'accueil : le parcours
           reste testable sans dépendre d'une régie. */
        'url'     => trim((string) cfg('gift.ads_url', '')) ?: wt_url('/'),
    ]);
    exit;
}

if ($action === 'reveal') {
    $r = wt_gift_open_finish($userId, (string) ($_POST['token'] ?? ''));
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }

    /* Libellé du lot. Le jackpot et le gros gain méritent d'être
       nommés : recevoir 5 000 coins sans savoir que c'était LE jackpot
       enlève tout le sel. */
    if ($r['kind'] === 'jackpot') {
        $msg = t('gift.won_jackpot', ['n' => number_format($r['coins'], 0, ',', ' ')]);
    } elseif ($r['kind'] === 'big') {
        $msg = t('gift.won_big', ['n' => number_format($r['coins'], 0, ',', ' ')]);
    } elseif ($r['kind'] === 'xp') {
        $msg = t('gift.won_xp', ['n' => (int) $r['xp']]);
    } else {
        $msg = t('gift.won_coins', ['n' => number_format($r['coins'], 0, ',', ' ')]);
    }

    echo json_encode([
        'ok'      => true,
        'kind'    => $r['kind'],
        'coins'   => $r['coins'],
        'xp'      => $r['xp'],
        'offer'   => $r['offer'],
        'left'    => $r['left'],
        'balance' => wt_tickets_balance($userId),
        'message' => $msg,
    ]);
    exit;
}

if ($action === 'boost') {
    $r = wt_gift_boost($userId, (int) ($_POST['box_id'] ?? 0));
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'bonus'   => $r['bonus'],
        'pct'     => $r['pct'],
        'balance' => wt_tickets_balance($userId),
        'message' => t('gift.boost_done', [
            'p' => $r['pct'],
            'n' => number_format($r['bonus'], 0, ',', ' '),
        ]),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown']);
