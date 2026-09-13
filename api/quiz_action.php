<?php
/**
 * Wintaskly — api/quiz_action.php
 *
 * Deux actions : obtenir la question courante, ou y répondre.
 *
 * Le délai publicitaire est vérifié ici aussi, pas seulement dans le
 * navigateur : le compte à rebours affiché n'est qu'un affichage.
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

if (!wt_quiz_visible_for($u)) {
    echo json_encode(['ok' => false, 'error' => 'disabled', 'message' => t('quiz.err_disabled')]);
    exit;
}

$userId = (int) $u['id'];
$action = (string) ($_POST['action'] ?? '');

/** Messages destinés au joueur. */
$dire = static function (string $code): string {
    switch ($code) {
        case 'no_session':  return t('quiz.err_no_session');
        case 'no_question': return t('quiz.err_no_question');
        case 'cooldown':    return t('quiz.err_cooldown');
        case 'empty_bank':  return t('quiz.err_empty_bank');
        case 'too_fast':    return t('quiz.err_too_fast');
        default:            return t('common.error');
    }
};

if ($action === 'question') {
    $attente = wt_quiz_next_available($userId);
    if ($attente > 0) {
        echo json_encode([
            'ok' => false, 'error' => 'cooldown',
            'next' => $attente, 'message' => $dire('cooldown'),
        ]);
        exit;
    }

    $s = wt_quiz_session($userId);
    if (!$s) {
        echo json_encode(['ok' => false, 'error' => 'cooldown', 'message' => $dire('cooldown')]);
        exit;
    }

    /* Délai après une réponse : la publicité a besoin de quelques
       secondes pour se charger derrière la page. Contrôlé côté serveur
       parce qu'un compte à rebours affiché se supprime en deux clics
       dans la console. */
    $delai = max(0, (int) cfg('quiz.ads_seconds', 20));
    if ($delai > 0 && (int) $s['asked_cnt'] > 0 && empty($s['current_qid'])) {
        try {
            $d = db_one(
                "SELECT answered_at FROM quiz_answers
                  WHERE session_id = " . (int) $s['id'] . " ORDER BY id DESC LIMIT 1"
            );
            if ($d) {
                $reste = (strtotime((string) $d['answered_at'] . ' UTC') ?: 0) + $delai - time();
                if ($reste > 0) {
                    echo json_encode([
                        'ok' => false, 'error' => 'too_fast',
                        'wait' => $reste, 'message' => $dire('too_fast'),
                    ]);
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log('[Wintaskly quiz api] ' . $e->getMessage());
        }
    }

    $q = wt_quiz_pick_question($s);
    if (!$q) {
        echo json_encode(['ok' => false, 'error' => 'empty_bank', 'message' => $dire('empty_bank')]);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'question' => $q,
        'count'    => (int) $s['correct_cnt'],
        'goal'     => (int) $s['goal'],
        'asked'    => (int) $s['asked_cnt'],
        'reward'   => (float) $s['reward'],
    ]);
    exit;
}

if ($action === 'answer') {
    $r = wt_quiz_answer($userId, (string) ($_POST['given'] ?? ''));
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'message' => $dire($r['error'])]);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'correct'  => $r['correct'],
        /* La bonne réponse n'est transmise que si le joueur s'est
           trompé. Sur une bonne réponse, elle n'a pas à circuler. */
        'good'     => $r['good'],
        'explain'  => $r['explain'],
        'count'    => $r['count'],
        'goal'     => $r['goal'],
        'finished' => $r['finished'],
        'reward'   => $r['reward'],
        'wait'     => max(0, (int) cfg('quiz.ads_seconds', 20)),
        /* Progression de campagne : c'est elle qu'affiche la barre. */
        'camp'     => [
            'correct' => $r['camp_correct'],
            'goal'    => $r['camp_goal'],
            'pct'     => $r['camp_goal'] > 0
                       ? (int) round(min(100, $r['camp_correct'] / $r['camp_goal'] * 100))
                       : 0,
            'reached' => $r['camp_reached'],
            'reward'  => $r['camp_reward'],
        ],
        'next'     => $r['finished'] ? wt_quiz_next_available($userId) : 0,
        'message'  => $r['finished']
            ? t('quiz.msg_win', ['n' => number_format($r['reward'], 0, ',', ' ')])
            : ($r['correct']
                ? t('quiz.msg_good', ['n' => $r['goal'] - $r['count']])
                : t('quiz.msg_bad')),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown']);
