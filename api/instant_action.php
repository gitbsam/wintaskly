<?php
/**
 * Wintaskly — api/instant_action.php
 *
 * Trois actions : ouvrir un parcours publicitaire, le clôturer, ou
 * miser des tickets.
 *
 * Tous les contrôles qui comptent — temps écoulé, jeton déjà utilisé,
 * plafond quotidien, solde de tickets — sont refaits ici. Ce que le
 * navigateur envoie n'est qu'une intention.
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

if (!wt_instant_enabled()) {
    echo json_encode(['ok' => false, 'error' => 'disabled']);
    exit;
}

$userId = (int) $u['id'];
$action = (string) ($_POST['action'] ?? '');
$gameId = (int) ($_POST['game_id'] ?? 0);

/** Messages destinés au joueur. Un code d'erreur brut n'aide personne. */
$dire = static function (string $code) use (&$game): string {
    switch ($code) {
        case 'daily':       return t('instant.err_daily');
        case 'cooldown':    return t('instant.err_cooldown');
        case 'too_fast':    return t('instant.err_too_fast');
        case 'used':        return t('instant.err_used');
        case 'not_found':   return t('instant.err_not_found');
        case 'no_tickets':  return t('instant.err_no_tickets');
        case 'unavailable': return t('instant.err_unavailable');
        default:            return t('common.error');
    }
};

/* Le jeu est relu en base : le navigateur ne décide ni du gain, ni du
   mode, ni de la visibilité. */
$game = null;
if ($gameId > 0) {
    try {
        $game = db_one("SELECT * FROM instant_games WHERE id = " . $gameId);
    } catch (Throwable $e) {
        error_log('[Wintaskly instant api] ' . $e->getMessage());
    }
}
if (!$game || !wt_instant_visible_for($game, $u)) {
    echo json_encode(['ok' => false, 'error' => 'unavailable', 'message' => $dire('unavailable')]);
    exit;
}

if ($action === 'start' && $game['mode'] === 'ads') {
    $r = wt_instant_ads_start($userId, $game);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'token'   => $r['token'],
        'seconds' => $r['seconds'],
        /* L'URL publicitaire est celle de la zone dédiée. Si aucune
           n'est configurée, on renvoie la page d'accueil du site : le
           parcours reste jouable en test sans dépendre d'une régie. */
        'url'     => trim((string) cfg('instant.ads_url', '')) ?: wt_url('/'),
    ]);
    exit;
}

if ($action === 'finish' && $game['mode'] === 'ads') {
    $r = wt_instant_ads_finish($userId, (string) ($_POST['token'] ?? ''));
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'won'     => $r['won'],
        'coins'   => $r['coins'],
        /* left n'est rendu qu'en cas de défaite : sur un gain, il vaut
           zéro et n'est pas transmis. */
        'left'    => $r['won'] ? null : $r['left'],
        'message' => $r['won']
            ? t('instant.msg_won', ['n' => number_format($r['coins'], 0, ',', ' ')])
            : t('instant.msg_lost', ['n' => $r['left']]),
    ]);
    exit;
}

if ($action === 'play' && $game['mode'] === 'tickets') {
    $mise = (int) ($_POST['stake'] ?? 1);

    /* La mise doit figurer parmi celles proposées par le jeu. Sans ce
       contrôle, une requête forgée pourrait miser n'importe quoi. */
    $permises = array_map('intval', explode(',', (string) $game['ticket_options']));
    if (!in_array($mise, $permises, true)) {
        echo json_encode(['ok' => false, 'error' => 'stake', 'message' => t('instant.err_stake')]);
        exit;
    }

    $r = wt_instant_play_tickets($userId, $gameId, $mise);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }

    echo json_encode([
        'ok'      => true,
        'wins'    => $r['wins'],
        'coins'   => $r['coins'],
        'plays'   => $r['plays'],
        'balance' => wt_tickets_balance($userId),
        'left'    => $r['wins'] > 0 ? null : $r['left'],
        'message' => $r['wins'] > 0
            ? t('instant.msg_won', ['n' => number_format($r['coins'], 0, ',', ' ')])
            : t('instant.msg_lost', ['n' => $r['left']]),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown']);
