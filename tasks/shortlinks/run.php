<?php
/**
 * Wintaskly — tasks/shortlinks/run.php
 *
 * Le parcours multi-étapes servi sous https://www.wintaskly.com/<code>
 * (réécriture dans .htaccess).
 *
 * Règle qui gouverne ce fichier : le formulaire ne porte que le jeton
 * d'étape. Le numéro d'étape affiché est décoratif ; celui qui compte
 * est relu en base à chaque requête. Un utilisateur qui édite le
 * formulaire ne peut donc rien avancer.
 *
 * L'URL de destination n'est jamais écrite dans la page tant que le
 * parcours n'est pas terminé : elle mène au rappel qui crédite, et la
 * connaître d'avance permettrait de sauter toutes les publicités.
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

$code = (string) ($_GET['c'] ?? '');
$run  = wt_sl_run_by_code($code);

/**
 * Page d'erreur commune.
 *
 * Volontairement identique pour un code inconnu, expiré, déjà consommé
 * ou appartenant à autrui : distinguer les cas indiquerait à un curieux
 * quels codes existent.
 */
function wt_sl_gone(string $reasonKey): void
{
    http_response_code(410);   // 410 et non 404 : le contenu a existé
    $pageTitle = t('sl.run.gone_title');
    include __DIR__ . '/../../header.php';
    ?>
    <main class="wt-main">
      <section class="wt-section" style="max-width:640px;margin:3rem auto;text-align:center">
        <h1><?= e(t('sl.run.gone_title')) ?></h1>
        <p class="wt-muted"><?= e(t($reasonKey)) ?></p>
        <p style="margin-top:1.4rem">
          <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/tasks/shortlinks/')) ?>">
            <?= e(t('sl.run.back_tasks')) ?>
          </a>
        </p>
      </section>
    </main>
    <?php
    include __DIR__ . '/../../footer.php';
    exit;
}

if ($run === null)                  { wt_sl_gone('sl.run.gone_unknown'); }
if ($run['status'] === 'termine')   { wt_sl_gone('sl.run.gone_done'); }
if ($run['status'] !== 'en_cours')  { wt_sl_gone('sl.run.gone_closed'); }

if (strtotime((string) $run['expires_at'] . ' UTC') < time()) {
    wt_sl_run_mark((int) $run['id'], 'expire');
    wt_sl_gone('sl.run.gone_expired');
}

/* Le parcours appartient à un utilisateur connecté. Le premier à
   l'ouvrir se l'approprie ; ensuite lui seul est admis. */
$me = current_user();
if (!$me) {
    header('Location: ' . wt_url('/auth/login.php?next=' . urlencode('/' . $code)));
    exit;
}
if (!wt_sl_run_bind_user($run, (int) $me['id'])) {
    wt_sl_gone('sl.run.gone_other');
}

$total   = max(1, (int) $run['steps_count']);
$message = null;

/* ------------------------------------------------------------------
 * Validation d'une étape
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    /* Seul champ du formulaire auquel on accorde un pouvoir. */
    $res = wt_sl_step_validate($run, (string) ($_POST['step_token'] ?? ''));

    if ($res['ok'] && $res['done']) {
        /* Terminé : c'est le seul moment où la destination sort de la
           base. On redirige sans jamais l'écrire dans la page. */
        $dest = (string) $run['destination'];
        header('Location: ' . $dest);
        exit;
    }

    if ($res['ok']) {
        /* Redirection après POST : sans elle, un rechargement renverrait
           le formulaire et consommerait un jeton pour rien. */
        header('Location: ' . wt_url('/' . $code));
        exit;
    }

    switch ($res['reason']) {
        case 'expired':   wt_sl_gone('sl.run.gone_expired');
        case 'bad_token': wt_sl_gone('sl.run.gone_replay');
        case 'too_fast':  $message = t('sl.run.too_fast'); break;
        default:          $message = t('sl.run.retry');    break;
    }
    $run = wt_sl_run_by_code($code) ?? $run;   // relit le jeton courant
}

/* ------------------------------------------------------------------
 * Affichage
 * ------------------------------------------------------------------ */
$step     = (int) $run['step'];
$isFinal  = ($step >= $total);
$seconds  = $isFinal ? (int) $run['final_seconds'] : (int) $run['step_seconds'];

/* Le contenu lu pendant le parcours. Un article du blog est rendu
   directement, sans iframe : la plupart des régies interdisent leurs
   tags dans une iframe, et une iframe embarquerait tout le gabarit en
   double. */
$article = null;
if ($run['content_type'] === 'blog') {
    try {
        $ref = (int) $run['content_ref'];
        $article = $ref > 0
            ? db_one("SELECT title, excerpt, body FROM blog_posts WHERE id = " . $ref . " AND status = 'published'")
            : db_one("SELECT title, excerpt, body FROM blog_posts WHERE status = 'published' ORDER BY published_at DESC LIMIT 1");
    } catch (Throwable $e) {
        /* On journalise, mais on ne masque pas : sans article la page
           n'affiche que des publicités et un bouton, ce qui ressemble à
           une page cassée. Mieux vaut le voir dans le journal. */
        error_log('[Wintaskly sl_run] article introuvable : ' . $e->getMessage());
    }
}

$pageTitle = $isFinal ? t('sl.run.final_title') : t('sl.run.title');
include __DIR__ . '/../../header.php';
?>
<main class="wt-main wt-slrun">
  <section class="wt-section" style="max-width:820px;margin:0 auto">

    <?php if ($message): ?>
      <div class="wt-alert wt-alert--warn"><?= e($message) ?></div>
    <?php endif; ?>

    <header class="wt-slrun__head">
      <h1><?= e($isFinal ? t('sl.run.final_title') : t('sl.run.title')) ?></h1>
      <p class="wt-muted">
        <?= $isFinal
            ? e(t('sl.run.final_lead'))
            : e(t('sl.run.step_of', ['n' => $step + 1, 'total' => $total])) ?>
      </p>
      <div class="wt-slrun__bar" aria-hidden="true">
        <span style="width:<?= (int) round(min(100, $step / $total * 100)) ?>%"></span>
      </div>
    </header>

    <?= wt_ad_zone('shortlink_gateway') ?>

    <?php if ($isFinal): ?>
      <div class="wt-slrun__final">
        <p><?= e(t('sl.run.final_thanks')) ?></p>
      </div>
    <?php elseif ($article): ?>
      <article class="wt-slrun__article">
        <h2><?= e((string) $article['title']) ?></h2>
        <?php if (!empty($article['excerpt'])): ?>
          <p class="wt-muted"><?= e((string) $article['excerpt']) ?></p>
        <?php endif; ?>
        <?php /* Corps rendu tel quel, comme dans blog/post.php : le HTML
                 vient de l'administration, pas d'un utilisateur. */ ?>
        <div class="wt-slrun__body"><?= $article['body'] ?></div>
      </article>
    <?php endif; ?>

    <?= wt_ad_zone('tasks_index_mid') ?>

    <form method="post" class="wt-slrun__form" data-slrun-form>
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <?php /* Le seul champ qui décide. L'étape, elle, est en base. */ ?>
      <input type="hidden" name="step_token" value="<?= e((string) $run['step_token']) ?>">

      <p class="wt-slrun__timer" data-slrun-timer data-seconds="<?= (int) $seconds ?>"
         data-ready="<?= e(t('sl.run.ready')) ?>">
        <?= e(t('sl.run.wait', ['s' => $seconds])) ?>
      </p>

      <button class="wt-btn wt-btn--primary wt-btn--lg" type="submit" data-slrun-go disabled>
        <?= e($isFinal ? t('sl.run.finish') : t('sl.run.next')) ?>
      </button>
    </form>

  </section>
</main>
<?php include __DIR__ . '/../../footer.php'; ?>
