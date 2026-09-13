<?php
/**
 * Wintaskly — Admin · WintQuiz
 *
 * Banque de questions : saisie unitaire, import en masse, réglages.
 *
 * L'import en masse n'est pas un luxe : une banque utile compte
 * plusieurs centaines de questions, et les saisir une par une dans un
 * formulaire prendrait des jours.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

$pageTitle   = t('admin.quiz.title');
$adminActive = 'quiz';
$db          = db();

$notice = null;
$error  = null;

/* ------------------------------------------------------------------
 * Traitement
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $id   = (int) ($_POST['id'] ?? 0);
        $q    = trim((string) ($_POST['question'] ?? ''));
        $a    = trim((string) ($_POST['a'] ?? ''));
        $b    = trim((string) ($_POST['b'] ?? ''));
        $c    = trim((string) ($_POST['c'] ?? ''));
        $d    = trim((string) ($_POST['d'] ?? ''));
        $cor  = strtolower(trim((string) ($_POST['correct'] ?? 'a')));
        $exp  = trim((string) ($_POST['explanation'] ?? ''));
        $cat  = trim((string) ($_POST['category'] ?? 'general')) ?: 'general';
        $diff = max(1, min(3, (int) ($_POST['difficulty'] ?? 1)));
        $lang = substr(trim((string) ($_POST['lang'] ?? 'fr')), 0, 2) ?: 'fr';
        $act  = !empty($_POST['active']) ? 1 : 0;

        if (!in_array($cor, ['a', 'b', 'c', 'd'], true)) { $cor = 'a'; }

        if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '') {
            $error = t('admin.quiz.err_fields');
        } else {
            if ($id > 0) {
                $st = $db->prepare(
                    "UPDATE quiz_questions SET question=?, a=?, b=?, c=?, d=?, correct=?,
                            explanation=?, category=?, difficulty=?, lang=?, active=?
                      WHERE id=?"
                );
                $st->bind_param('ssssssssisii', $q, $a, $b, $c, $d, $cor, $exp, $cat, $diff, $lang, $act, $id);
                $st->execute();
                $st->close();
                wt_admin_log('quiz_update', ['id' => $id], $id);
            } else {
                $st = $db->prepare(
                    "INSERT INTO quiz_questions (question,a,b,c,d,correct,explanation,category,difficulty,lang,active)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('ssssssssisi', $q, $a, $b, $c, $d, $cor, $exp, $cat, $diff, $lang, $act);
                $st->execute();
                $st->close();
                wt_admin_log('quiz_create', [], 0);
            }
            header('Location: ' . wt_url('/admin/quiz.php?saved=1'));
            exit;
        }

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            /* Les réponses déjà données sont conservées : elles
               justifient des coins versés. Seule la question part. */
            $st = $db->prepare("DELETE FROM quiz_questions WHERE id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $st->close();
            wt_admin_log('quiz_delete', ['id' => $id], $id);
        }
        header('Location: ' . wt_url('/admin/quiz.php'));
        exit;

    } elseif ($action === 'import') {
        /* Format attendu, une question par ligne, séparées par « | » :
             question | A | B | C | D | lettre | explication | catégorie
           Les deux derniers champs sont facultatifs.

           Le « | » plutôt que la virgule : les questions en contiennent
           presque toujours, le point-virgule aussi. La barre verticale
           est rare dans un texte ordinaire. */
        $texte = (string) ($_POST['bulk'] ?? '');
        $lang  = substr(trim((string) ($_POST['bulk_lang'] ?? 'fr')), 0, 2) ?: 'fr';
        $ok = 0; $ko = 0; $lignes = 0;

        $st = $db->prepare(
            "INSERT INTO quiz_questions (question,a,b,c,d,correct,explanation,category,lang,active)
             VALUES (?,?,?,?,?,?,?,?,?,1)"
        );
        foreach (preg_split('/\R/', $texte) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || str_starts_with($ligne, '#')) { continue; }
            $lignes++;
            $p = array_map('trim', explode('|', $ligne));
            if (count($p) < 6) { $ko++; continue; }
            $cor = strtolower($p[5]);
            if (!in_array($cor, ['a', 'b', 'c', 'd'], true)) { $ko++; continue; }
            if ($p[0] === '' || $p[1] === '' || $p[2] === '' || $p[3] === '' || $p[4] === '') { $ko++; continue; }

            $exp = $p[6] ?? '';
            $cat = ($p[7] ?? '') ?: 'general';
            $st->bind_param('sssssssss', $p[0], $p[1], $p[2], $p[3], $p[4], $cor, $exp, $cat, $lang);
            try { $st->execute(); $ok++; } catch (Throwable $e) { $ko++; }
        }
        $st->close();
        wt_admin_log('quiz_import', ['ok' => $ok, 'ko' => $ko], 0);
        $notice = t('admin.quiz.import_done', ['ok' => $ok, 'ko' => $ko, 'n' => $lignes]);

    } elseif ($action === 'new_campaign') {
        /* On incremente le numero plutot que d'effacer quiz_progress :
           l'historique reste consultable, et un joueur qui avait deja
           touche la cagnotte ne peut pas la retoucher sur l'ancienne
           campagne. */
        cfg_set('quiz.campaign', (string) (wt_quiz_campaign() + 1));
        cfg_set('quiz.start_date', gmdate('Y-m-d'));
        wt_admin_log('quiz_new_campaign', ['n' => wt_quiz_campaign()], 0);
        $notice = t('admin.quiz.campaign_done', ['n' => wt_quiz_campaign()]);

    } elseif ($action === 'settings') {
        foreach (['quiz.enabled', 'quiz.test_mode'] as $k) {
            cfg_set($k, !empty($_POST[str_replace('.', '_', $k)]) ? '1' : '0');
        }
        foreach (['quiz.goal' => 5, 'quiz.reward_coins' => 125, 'quiz.cooldown_hours' => 3,
                  'quiz.ads_seconds' => 20, 'quiz.repeat_after_days' => 30,
                  'quiz.campaign_goal' => 500, 'quiz.campaign_reward' => 5000,
                  'quiz.ads_per_answer' => 1] as $k => $def) {
            $v = $_POST[str_replace('.', '_', $k)] ?? null;
            if ($v !== null && $v !== '') { cfg_set($k, (string) max(0, (int) $v)); }
        }
        cfg_set('quiz.ads_url', trim((string) ($_POST['quiz_ads_url'] ?? '')));
        cfg_set('quiz.start_date', trim((string) ($_POST['quiz_start_date'] ?? '')));
        wt_admin_log('quiz_settings', [], 0);
        $notice = t('admin.saved');
    }
}

/* ------------------------------------------------------------------
 * Lecture
 * ------------------------------------------------------------------ */
$tableOk = true;
$stats   = ['n' => 0, 'act' => 0, 'asked' => 0];
try {
    $r = db_one("SELECT COUNT(*) n, SUM(active) act, SUM(asked) asked FROM quiz_questions");
    $stats = ['n' => (int) $r['n'], 'act' => (int) $r['act'], 'asked' => (int) $r['asked']];
} catch (Throwable $e) {
    $tableOk = false;
}

$page    = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 25;
$filtre  = trim((string) ($_GET['q'] ?? ''));

$liste = [];
$total = 0;
if ($tableOk) {
    try {
        if ($filtre !== '') {
            $like = '%' . $filtre . '%';
            $st = $db->prepare("SELECT COUNT(*) n FROM quiz_questions WHERE question LIKE ? OR category LIKE ?");
            $st->bind_param('ss', $like, $like);
            $st->execute();
            $total = (int) ($st->get_result()->fetch_assoc()['n'] ?? 0);
            $st->close();

            $st = $db->prepare(
                "SELECT * FROM quiz_questions WHERE question LIKE ? OR category LIKE ?
                  ORDER BY id DESC LIMIT ? OFFSET ?"
            );
            $off = ($page - 1) * $perPage;
            $st->bind_param('ssii', $like, $like, $perPage, $off);
        } else {
            $total = (int) (db_one("SELECT COUNT(*) n FROM quiz_questions")['n'] ?? 0);
            $st = $db->prepare("SELECT * FROM quiz_questions ORDER BY id DESC LIMIT ? OFFSET ?");
            $off = ($page - 1) * $perPage;
            $st->bind_param('ii', $perPage, $off);
        }
        $st->execute();
        $res = $st->get_result();
        while ($x = $res->fetch_assoc()) { $liste[] = $x; }
        $st->close();
    } catch (Throwable $e) {
        error_log('[Wintaskly quiz admin] ' . $e->getMessage());
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
if ($editId > 0 && $tableOk) {
    try { $edit = db_one("SELECT * FROM quiz_questions WHERE id = " . $editId); } catch (Throwable $e) {}
}

$pages = max(1, (int) ceil($total / $perPage));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <div>
          <h1>🧠 <?= e(t('admin.quiz.title')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.quiz.lead')) ?></p>
        </div>
      </header>

      <?php if (!$tableOk): ?>
        <div class="wt-alert wt-alert--warn"><?= e(t('admin.quiz.no_table')) ?></div>
      <?php else: ?>

        <?php if (!empty($_GET['saved'])): ?>
          <div class="wt-alert wt-alert--success"><?= e(t('admin.saved')) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

        <?php
        /* Une banque trop courte se voit tout de suite : à 3 h de délai,
           un joueur assidu fait 8 parties par jour, soit 40 bonnes
           réponses. Le prévenir vaut mieux qu'il ne le découvre par les
           répétitions. */
        $jours = $stats['act'] > 0 ? $stats['act'] / 40 : 0;
        if ($stats['act'] < 200):
        ?>
          <div class="wt-alert wt-alert--warn">
            <strong><?= e(t('admin.quiz.thin_title', ['n' => $stats['act']])) ?></strong>
            <p style="margin:.35rem 0 0;font-size:.9rem">
              <?= e(t('admin.quiz.thin_text', ['d' => number_format($jours, 1, ',', ' ')])) ?>
            </p>
          </div>
        <?php endif; ?>

        <div class="wt-admin-v2__stats">
          <div><strong><?= (int) $stats['n'] ?></strong><br><small class="wt-muted"><?= e(t('admin.quiz.s_total')) ?></small></div>
          <div><strong><?= (int) $stats['act'] ?></strong><br><small class="wt-muted"><?= e(t('admin.quiz.s_active')) ?></small></div>
          <div><strong><?= (int) $stats['asked'] ?></strong><br><small class="wt-muted"><?= e(t('admin.quiz.s_asked')) ?></small></div>
        </div>

        <h2 style="margin-top:1.6rem"><?= e($edit ? t('admin.quiz.edit_title') : t('admin.quiz.add_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <div class="wt-field">
            <label class="wt-field__label" for="question"><?= e(t('admin.quiz.f_question')) ?></label>
            <textarea class="wt-input" id="question" name="question" rows="2" required
                      maxlength="400"><?= e((string) ($edit['question'] ?? '')) ?></textarea>
          </div>

          <?php foreach (['a', 'b', 'c', 'd'] as $lettre): ?>
            <div class="wt-field">
              <label class="wt-field__label" for="op<?= $lettre ?>">
                <?= e(t('admin.quiz.f_option', ['l' => strtoupper($lettre)])) ?>
              </label>
              <div style="display:flex;align-items:center;gap:.6rem">
                <input class="wt-input" id="op<?= $lettre ?>" name="<?= $lettre ?>" required maxlength="200"
                       value="<?= e((string) ($edit[$lettre] ?? '')) ?>">
                <label style="display:flex;align-items:center;gap:.35rem;white-space:nowrap;cursor:pointer">
                  <input type="radio" name="correct" value="<?= $lettre ?>"
                         <?= (($edit['correct'] ?? 'a') === $lettre) ? 'checked' : '' ?>>
                  <span><?= e(t('admin.quiz.f_correct')) ?></span>
                </label>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="wt-field">
            <label class="wt-field__label" for="explanation"><?= e(t('admin.quiz.f_explain')) ?></label>
            <input class="wt-input" id="explanation" name="explanation" maxlength="400"
                   value="<?= e((string) ($edit['explanation'] ?? '')) ?>">
            <small class="wt-field__hint"><?= e(t('admin.quiz.f_explain_hint')) ?></small>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="category"><?= e(t('admin.quiz.f_category')) ?></label>
              <input class="wt-input" id="category" name="category" maxlength="60"
                     value="<?= e((string) ($edit['category'] ?? 'general')) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="difficulty"><?= e(t('admin.quiz.f_difficulty')) ?></label>
              <select class="wt-input" id="difficulty" name="difficulty">
                <?php foreach ([1 => t('admin.quiz.d_easy'), 2 => t('admin.quiz.d_mid'), 3 => t('admin.quiz.d_hard')] as $v => $l): ?>
                  <option value="<?= $v ?>" <?= ((int) ($edit['difficulty'] ?? 1) === $v) ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="lang"><?= e(t('admin.quiz.f_lang')) ?></label>
              <select class="wt-input" id="lang" name="lang">
                <option value="fr" <?= (($edit['lang'] ?? 'fr') === 'fr') ? 'selected' : '' ?>>Français</option>
                <option value="en" <?= (($edit['lang'] ?? '') === 'en') ? 'selected' : '' ?>>English</option>
              </select>
            </div>
          </div>

          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin:.6rem 0">
            <input type="checkbox" name="active" value="1" <?= ((int) ($edit['active'] ?? 1) === 1) ? 'checked' : '' ?>>
            <span><?= e(t('admin.quiz.f_active')) ?></span>
          </label>

          <div style="display:flex;gap:.6rem;flex-wrap:wrap">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.quiz.save')) ?></button>
            <?php if ($edit): ?>
              <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/admin/quiz.php')) ?>"><?= e(t('admin.quiz.new')) ?></a>
            <?php endif; ?>
          </div>
        </form>

        <h2 style="margin-top:2rem"><?= e(t('admin.quiz.import_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="import">
          <div class="wt-field">
            <label class="wt-field__label" for="bulk"><?= e(t('admin.quiz.f_bulk')) ?></label>
            <textarea class="wt-input" id="bulk" name="bulk" rows="8"
                      placeholder="<?= e(t('admin.quiz.f_bulk_ph')) ?>"></textarea>
            <small class="wt-field__hint"><?= e(t('admin.quiz.f_bulk_hint')) ?></small>
          </div>
          <div class="wt-field" style="max-width:200px">
            <label class="wt-field__label" for="bulk_lang"><?= e(t('admin.quiz.f_lang')) ?></label>
            <select class="wt-input" id="bulk_lang" name="bulk_lang">
              <option value="fr">Français</option>
              <option value="en">English</option>
            </select>
          </div>
          <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.quiz.import_go')) ?></button>
        </form>

        <h2 style="margin-top:2rem"><?= e(t('admin.quiz.settings_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="settings">
          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_goal"><?= e(t('admin.quiz.f_goal')) ?></label>
              <input class="wt-input" type="number" min="1" max="20" id="quiz_goal" name="quiz_goal"
                     value="<?= (int) cfg('quiz.goal', 5) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_reward_coins"><?= e(t('admin.quiz.f_reward')) ?></label>
              <input class="wt-input" type="number" min="0" id="quiz_reward_coins" name="quiz_reward_coins"
                     value="<?= (int) cfg('quiz.reward_coins', 125) ?>">
              <small class="wt-field__hint" data-quiz-eur></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_cooldown_hours"><?= e(t('admin.quiz.f_cooldown')) ?></label>
              <input class="wt-input" type="number" min="0" max="72" id="quiz_cooldown_hours" name="quiz_cooldown_hours"
                     value="<?= (int) cfg('quiz.cooldown_hours', 3) ?>">
            </div>
          </div>
          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_ads_seconds"><?= e(t('admin.quiz.f_ads_sec')) ?></label>
              <input class="wt-input" type="number" min="5" max="120" id="quiz_ads_seconds" name="quiz_ads_seconds"
                     value="<?= (int) cfg('quiz.ads_seconds', 20) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_repeat_after_days"><?= e(t('admin.quiz.f_repeat')) ?></label>
              <input class="wt-input" type="number" min="0" max="365" id="quiz_repeat_after_days" name="quiz_repeat_after_days"
                     value="<?= (int) cfg('quiz.repeat_after_days', 30) ?>">
              <small class="wt-field__hint"><?= e(t('admin.quiz.f_repeat_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_start_date"><?= e(t('admin.quiz.f_start')) ?></label>
              <input class="wt-input" type="date" id="quiz_start_date" name="quiz_start_date"
                     value="<?= e((string) cfg('quiz.start_date', '')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.quiz.f_start_hint')) ?></small>
            </div>
          </div>
          <div class="wt-field">
            <label class="wt-field__label" for="quiz_ads_url"><?= e(t('admin.quiz.f_ads_url')) ?></label>
            <input class="wt-input" id="quiz_ads_url" name="quiz_ads_url"
                   value="<?= e((string) cfg('quiz.ads_url', '')) ?>">
          </div>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <input type="checkbox" name="quiz_enabled" value="1" <?= ((string) cfg('quiz.enabled', '1') === '1') ? 'checked' : '' ?>>
            <span><?= e(t('admin.quiz.f_enabled')) ?></span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.5rem">
            <input type="checkbox" name="quiz_test_mode" value="1" <?= ((string) cfg('quiz.test_mode', '1') === '1') ? 'checked' : '' ?>>
            <span><?= e(t('admin.quiz.f_test')) ?></span>
          </label>
          <button class="wt-btn wt-btn--primary" type="submit" style="margin-top:.8rem"><?= e(t('admin.quiz.save')) ?></button>
        </form>

        <h2 style="margin-top:2rem"><?= e(t('admin.quiz.campaign_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="settings">
          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_campaign_goal"><?= e(t('admin.quiz.f_camp_goal')) ?></label>
              <input class="wt-input" type="number" min="1" max="100000" id="quiz_campaign_goal"
                     name="quiz_campaign_goal" value="<?= (int) cfg('quiz.campaign_goal', 500) ?>">
              <small class="wt-field__hint"><?= e(t('admin.quiz.f_camp_goal_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_campaign_reward"><?= e(t('admin.quiz.f_camp_reward')) ?></label>
              <input class="wt-input" type="number" min="0" id="quiz_campaign_reward"
                     name="quiz_campaign_reward" value="<?= (int) cfg('quiz.campaign_reward', 5000) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="quiz_ads_per_answer"><?= e(t('admin.quiz.f_ads_per')) ?></label>
              <input class="wt-input" type="number" min="0" max="10" id="quiz_ads_per_answer"
                     name="quiz_ads_per_answer" value="<?= (int) cfg('quiz.ads_per_answer', 1) ?>">
              <small class="wt-field__hint"><?= e(t('admin.quiz.f_ads_per_hint')) ?></small>
            </div>
          </div>
          <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.quiz.save')) ?></button>
        </form>

        <?php
        /* Projection calculee sur les reglages ENREGISTRES, pas sur ce
           qui est saisi : afficher une projection d'un reglage non
           valide donnerait une fausse assurance. */
        $parAnswer = max(0, (int) cfg('quiz.ads_per_answer', 1));
        $objRound  = max(1, (int) cfg('quiz.goal', 5));
        $objCamp   = max(1, (int) cfg('quiz.campaign_goal', 500));
        echo wt_econ_render([
            t('admin.quiz.cyc_round', ['n' => $objRound])
                => [$objRound * $parAnswer, (float) cfg('quiz.reward_coins', 125)],
            t('admin.quiz.cyc_camp', ['n' => $objCamp])
                => [$objCamp * $parAnswer,
                    (float) cfg('quiz.campaign_reward', 5000)
                    + floor($objCamp / $objRound) * (float) cfg('quiz.reward_coins', 125)],
        ], t('econ.title'));
        ?>

        <form method="post" style="margin-bottom:1.5rem"
              onsubmit="return confirm('<?= e(t('admin.quiz.confirm_campaign')) ?>')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="new_campaign">
          <button class="wt-btn wt-btn--ghost" type="submit">
            <?= e(t('admin.quiz.new_campaign', ['n' => wt_quiz_campaign()])) ?>
          </button>
          <small class="wt-field__hint" style="display:block;margin-top:.3rem">
            <?= e(t('admin.quiz.new_campaign_hint')) ?>
          </small>
        </form>

        <h2 style="margin-top:2rem"><?= e(t('admin.quiz.list_title', ['n' => $total])) ?></h2>
        <form method="get" style="margin-bottom:.8rem;display:flex;gap:.5rem;flex-wrap:wrap">
          <input class="wt-input" name="q" value="<?= e($filtre) ?>"
                 placeholder="<?= e(t('admin.quiz.f_search')) ?>" style="max-width:320px">
          <button class="wt-btn wt-btn--ghost" type="submit"><?= e(t('admin.quiz.search')) ?></button>
        </form>

        <?php if ($liste): ?>
          <table class="wt-admin-v2__table">
            <thead>
              <tr>
                <th><?= e(t('admin.quiz.c_question')) ?></th>
                <th><?= e(t('admin.quiz.c_correct')) ?></th>
                <th><?= e(t('admin.quiz.c_cat')) ?></th>
                <th><?= e(t('admin.quiz.c_rate')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($liste as $x): ?>
                <?php
                  $taux = (int) $x['asked'] > 0
                      ? round((int) $x['correct_n'] / (int) $x['asked'] * 100)
                      : null;
                ?>
                <tr<?= (int) $x['active'] !== 1 ? ' style="opacity:.5"' : '' ?>>
                  <td><?= e(mb_strimwidth((string) $x['question'], 0, 90, '…', 'UTF-8')) ?></td>
                  <td><strong><?= e(strtoupper((string) $x['correct'])) ?></strong></td>
                  <td><small><?= e((string) $x['category']) ?> · <?= e((string) $x['lang']) ?></small></td>
                  <td>
                    <?php if ($taux === null): ?>
                      <span class="wt-muted">—</span>
                    <?php else: ?>
                      <?= (int) $taux ?> %<br>
                      <small class="wt-muted"><?= (int) $x['asked'] ?> <?= e(t('admin.quiz.times')) ?></small>
                    <?php endif; ?>
                  </td>
                  <td style="white-space:nowrap">
                    <a class="wt-btn wt-btn--ghost wt-btn--sm"
                       href="<?= e(wt_url('/admin/quiz.php?edit=' . (int) $x['id'])) ?>"><?= e(t('admin.quiz.edit')) ?></a>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= e(t('admin.quiz.confirm_del')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.quiz.del')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if ($pages > 1): ?>
            <nav style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:1rem">
              <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
                <a class="wt-btn wt-btn--sm <?= $i === $page ? 'wt-btn--primary' : 'wt-btn--ghost' ?>"
                   href="<?= e(wt_url('/admin/quiz.php?p=' . $i . ($filtre !== '' ? '&q=' . urlencode($filtre) : ''))) ?>"><?= $i ?></a>
              <?php endfor; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>

      <?php endif; ?>

    </section>
  </div>
</main>

<script>
/* Equivalent en euros de la cagnotte, pendant la saisie. */
(function () {
  var c = document.getElementById('quiz_reward_coins');
  var o = document.querySelector('[data-quiz-eur]');
  if (!c || !o) { return; }
  function maj() {
    var v = parseFloat(c.value) || 0;
    o.textContent = (v / 10000).toLocaleString(undefined,
      { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  c.addEventListener('input', maj); maj();
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
