<?php
/**
 * Wintaskly — dashboard/verify-action.php
 * ---------------------------------------------------------------------------
 * Page de vérification renforcée, commune à toutes les actions sensibles.
 *
 * Une seule page plutôt qu'un formulaire par action : la logique de
 * vérification est délicate (choix de la méthode, anti-abus, exclusion de
 * l'e-mail dans certains cas), et la dupliquer trois fois garantirait que
 * l'une des copies finisse par diverger — c'est ainsi que naissent les
 * failles.
 *
 * FONCTIONNEMENT
 *   1. L'action appelante redirige ici avec ?a=<action>.
 *   2. L'utilisateur choisit une méthode et reçoit/saisit un code.
 *   3. En cas de succès, wt_stepup_grant() ouvre une courte fenêtre, et
 *      l'utilisateur est renvoyé vers l'action d'origine, qui aboutit.
 *
 * L'autorisation est liée à UNE action précise et consommée à l'usage :
 * vérifier un changement d'e-mail n'autorise pas à régénérer des codes.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$u   = current_user();
$uid = (int) $u['id'];

/* Actions reconnues, avec leur destination de retour et leur politique.
   Liste fermée : une action inconnue est refusée plutôt qu'acceptée par
   défaut — sinon n'importe quel paramètre ouvrirait une autorisation. */
$ACTIONS = [
    'gen_backup'   => ['back' => '/dashboard/2fa-setup.php', 'no_email' => false],
    'change_email' => ['back' => '/dashboard/account.php',   'no_email' => true],
    'payout_addr'  => ['back' => '/dashboard/payout-addresses.php', 'no_email' => false],
];

$action = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
if (!isset($ACTIONS[$action])) {
    header('Location: ' . wt_url('/dashboard/'));
    exit;
}
$conf    = $ACTIONS[$action];
$backUrl = wt_url($conf['back']);

/* Pour un changement d'adresse e-mail, l'e-mail ne peut pas servir de
   preuve : c'est exactement ce qu'on modifie, et un attaquant ayant accès
   à la boîte validerait sa propre demande. */
$methods = wt_stepup_methods($u, $conf['no_email']);

$error = null;
$sent  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $step   = (string) ($_POST['step'] ?? '');
    $method = (string) ($_POST['method'] ?? '');

    if ($step === 'send' && in_array($method, ['email', 'sms'], true)
        && in_array($method, $methods, true)) {
        /* Envoi d'un code à usage unique. wt_2fa_issue_code() applique
           son propre anti-abus (délai minimal entre deux envois). */
        $res  = wt_2fa_issue_code($u, $method);
        $sent = !empty($res['ok']) ? $method : null;
        if (!$sent) {
            $error = t('stepup.err_send');
        }
    } elseif ($step === 'verify') {
        if (wt_stepup_verify($u, $method, (string) ($_POST['code'] ?? ''), $methods)) {
            wt_stepup_grant($action);
            /* Retour vers l'action d'origine, qui va désormais aboutir.
               Pour gen_backup, on repasse par le POST attendu. */
            if ($action === 'gen_backup') {
                $_SESSION['stepup_autorun'] = 'gen_backup';
            }
            header('Location: ' . $backUrl);
            exit;
        }
        $error = t('stepup.err_code');
    }
}

$pageTitle = t('stepup.title');
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-dash__content wt-dash-v2__content">
      <header class="wt-dash-v2__page-header" data-reveal>
        <a class="wt-dash-v2__back" href="<?= e($backUrl) ?>">← <?= e(t('common.back')) ?></a>
        <h1 class="wt-dash-v2__title"><?= e(t('stepup.title')) ?></h1>
        <p class="wt-muted"><?= e(t('stepup.' . $action . '_lead')) ?></p>
      </header>

      <?php if ($error): ?>
        <div class="wt-alert wt-alert--error"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if (!$methods): ?>
        <?php /* Aucune méthode disponible : on refuse plutôt que d'ouvrir
                 une porte dérobée. Cela n'arrive que si l'envoi d'e-mail
                 est hors service et qu'aucune 2FA n'est active. */ ?>
        <div class="wt-alert wt-alert--error"><?= e(t('stepup.no_method')) ?></div>
      <?php else: ?>
        <section class="wt-stepup">
          <p class="wt-stepup__intro"><?= e(t('stepup.intro')) ?></p>

          <?php if ($sent): ?>
            <div class="wt-alert wt-alert--success">
              <?= e(sprintf((string) t('stepup.sent'), wt_stepup_label($sent))) ?>
            </div>
          <?php endif; ?>

          <form method="post" class="wt-stepup__form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="a" value="<?= e($action) ?>">
            <input type="hidden" name="step" value="verify">

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('stepup.method')) ?></span>
              <select class="wt-input" name="method">
                <?php foreach ($methods as $m): ?>
                  <option value="<?= e($m) ?>" <?= $sent === $m ? 'selected' : '' ?>>
                    <?= e(wt_stepup_label($m)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('stepup.code')) ?></span>
              <input class="wt-input" type="text" name="code" autocomplete="one-time-code"
                     inputmode="text" maxlength="16" required>
            </label>

            <div class="wt-stepup__actions">
              <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('stepup.confirm')) ?></button>
            </div>
          </form>

          <?php
            /* Bouton d'envoi séparé, pour les méthodes qui nécessitent
               qu'un code soit expédié avant d'être saisi. */
            $sendable = array_values(array_intersect($methods, ['email', 'sms']));
          ?>
          <?php if ($sendable): ?>
            <form method="post" class="wt-stepup__send">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="a" value="<?= e($action) ?>">
              <input type="hidden" name="step" value="send">
              <?php foreach ($sendable as $m): ?>
                <button class="wt-btn wt-btn--ghost" type="submit" name="method" value="<?= e($m) ?>">
                  <?= e(sprintf((string) t('stepup.send_via'), wt_stepup_label($m))) ?>
                </button>
              <?php endforeach; ?>
            </form>
          <?php endif; ?>

          <p class="wt-stepup__why"><?= e(t('stepup.why')) ?></p>
        </section>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
