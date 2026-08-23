<?php
/**
 * Wintaskly — dashboard/payout-addresses.php
 * ---------------------------------------------------------------------------
 * Déclaration et confirmation des adresses de retrait.
 *
 * Le parcours est en deux temps, volontairement :
 *
 *   1. AJOUT — l'adresse est enregistrée, mais inutilisable.
 *   2. CONFIRMATION — vérification renforcée, puis l'adresse devient
 *      utilisable pour un retrait.
 *
 * Séparer les deux est ce qui protège réellement : si l'ajout confirmait
 * immédiatement, une session détournée suffirait à enregistrer une adresse
 * et à retirer dans la foulée.
 *
 * La suppression n'exige PAS de vérification : retirer une adresse ne permet
 * de voler personne, et exiger un code pour se protéger de soi-même est une
 * friction inutile.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$u   = current_user();
$uid = (int) $u['id'];

$notice = null;
$error  = null;

/* Confirmation en attente : l'identifiant est mémorisé avant le passage par
   la vérification renforcée, et relu au retour. On ne le transmet pas par
   l'URL — un identifiant en clair dans un lien se rejoue trop facilement. */
if (!empty($_SESSION['payout_pending_id']) && wt_stepup_granted('payout_addr')) {
    $pid = (int) $_SESSION['payout_pending_id'];
    unset($_SESSION['payout_pending_id']);
    wt_stepup_consume('payout_addr');
    $notice = wt_payout_address_confirm($uid, $pid)
        ? t('payout.confirmed')
        : t('payout.confirm_failed');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $res = wt_payout_address_add(
            $uid,
            (int) ($_POST['method_id'] ?? 0),
            (string) ($_POST['address'] ?? ''),
            (string) ($_POST['label'] ?? '')
        );
        if (!empty($res['ok'])) {
            /* On enchaîne directement sur la vérification : laisser une
               adresse non confirmée dans la liste sans expliquer l'étape
               suivante produit surtout de la confusion. */
            $_SESSION['payout_pending_id'] = (int) $res['id'];
            header('Location: ' . wt_url('/dashboard/verify-action.php?a=payout_addr'));
            exit;
        }
        $error = t('payout.err_' . ($res['error'] ?? 'db'));
    }

    if ($action === 'confirm') {
        $_SESSION['payout_pending_id'] = (int) ($_POST['id'] ?? 0);
        header('Location: ' . wt_url('/dashboard/verify-action.php?a=payout_addr'));
        exit;
    }

    if ($action === 'delete') {
        $notice = wt_payout_address_delete($uid, (int) ($_POST['id'] ?? 0))
            ? t('payout.deleted') : t('payout.err_db');
    }
}

$addresses = wt_payout_addresses($uid);
$methods   = [];
try {
    $r = db()->query("SELECT id, label, address_label, address_placeholder
                        FROM withdrawal_methods WHERE active = 1 ORDER BY sort_order");
    while ($r && ($m = $r->fetch_assoc())) { $methods[] = $m; }
} catch (Throwable $e) { /* liste vide : le formulaire ne s'affiche pas */ }

$pageTitle = t('payout.title');
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-dash__content wt-dash-v2__content">
      <header class="wt-dash-v2__page-header" data-reveal>
        <a class="wt-dash-v2__back" href="<?= e(wt_url('/dashboard/withdraw.php')) ?>">← <?= e(t('common.back')) ?></a>
        <h1 class="wt-dash-v2__title"><?= e(t('payout.title')) ?></h1>
        <p class="wt-muted"><?= e(t('payout.lead')) ?></p>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

      <section class="wt-payaddr">
        <h2 class="wt-payaddr__h"><?= e(t('payout.my_addresses')) ?></h2>

        <?php if (!$addresses): ?>
          <p class="wt-payaddr__empty"><?= e(t('payout.empty')) ?></p>
        <?php else: ?>
          <ul class="wt-payaddr__list">
            <?php foreach ($addresses as $a): ?>
              <li class="wt-payaddr__item <?= $a['confirmed_at'] ? '' : 'is-pending' ?>">
                <div class="wt-payaddr__main">
                  <span class="wt-payaddr__method"><?= e($a['method_label']) ?></span>
                  <?php if (!empty($a['label'])): ?>
                    <span class="wt-payaddr__label"><?= e($a['label']) ?></span>
                  <?php endif; ?>
                  <code class="wt-payaddr__addr"><?= e($a['address']) ?></code>
                </div>
                <div class="wt-payaddr__state">
                  <?php if ($a['confirmed_at']): ?>
                    <span class="wt-payaddr__ok"><?= e(t('payout.confirmed_badge')) ?></span>
                  <?php else: ?>
                    <span class="wt-payaddr__pending"><?= e(t('payout.pending_badge')) ?></span>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="confirm">
                      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('payout.confirm_btn')) ?></button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('<?= e(t('payout.delete_confirm')) ?>')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('payout.delete_btn')) ?></button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <?php if ($methods): ?>
        <section class="wt-payaddr wt-payaddr--add">
          <h2 class="wt-payaddr__h"><?= e(t('payout.add_title')) ?></h2>
          <form method="post" class="wt-payaddr__form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('payout.f_method')) ?></span>
              <select class="wt-input" name="method_id" required>
                <?php foreach ($methods as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= e($m['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('payout.f_address')) ?></span>
              <input class="wt-input" type="text" name="address" maxlength="255" required
                     autocomplete="off" spellcheck="false">
              <small class="wt-field__hint"><?= e(t('payout.f_address_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('payout.f_label')) ?></span>
              <input class="wt-input" type="text" name="label" maxlength="60"
                     placeholder="<?= e(t('payout.f_label_ph')) ?>">
            </label>

            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('payout.add_btn')) ?></button>
          </form>
          <p class="wt-payaddr__why"><?= e(t('payout.why')) ?></p>
        </section>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
