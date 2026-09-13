<?php
/**
 * Wintaskly — Admin · Instant Gagnant
 *
 * Création et réglage des jeux à compteur partagé.
 *
 * Chaque jeu porte un compteur commun à tous les joueurs : une partie
 * le décrémente de 1, celui qui l'amène à zéro remporte le gain. Le
 * nombre de parties détermine donc directement ce que le jeu vous
 * coûte, et c'est le réglage à ne pas prendre à la légère.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

$pageTitle   = t('admin.instant.title');
$adminActive = 'instant';
$db          = db();

$notice = null;
$error  = null;

/* ------------------------------------------------------------------
 * Traitement
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        /* Les parties jouées sont conservées : elles justifient les
           coins déjà versés. Seul le jeu disparaît. */
        $st = $db->prepare("DELETE FROM instant_games WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        wt_admin_log('instant_delete', ['id' => $id], $id);
        header('Location: ' . wt_url('/admin/instant.php'));
        exit;

    } elseif ($action === 'reset' && $id > 0) {
        /* Remise du compteur à son total. À n'utiliser qu'en test :
           en production, cela efface la progression des joueurs qui
           ont déjà consommé des parties sans rien recevoir. */
        $st = $db->prepare("UPDATE instant_games SET parts_left = parts_total WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        wt_admin_log('instant_reset', ['id' => $id], $id);
        header('Location: ' . wt_url('/admin/instant.php?reset=1'));
        exit;

    } elseif ($action === 'save') {
        $name    = trim((string) ($_POST['name'] ?? ''));
        $mode    = ($_POST['mode'] ?? 'ads') === 'tickets' ? 'tickets' : 'ads';
        $reward  = max(0, (float) ($_POST['reward_coins'] ?? 0));
        $parts   = max(1, min(10000, (int) ($_POST['parts_total'] ?? 7)));
        $opts    = trim((string) ($_POST['ticket_options'] ?? '1,2,5,10'));
        $cool    = max(0, min(720, (int) ($_POST['cooldown_hours'] ?? 0)));
        $daily   = max(0, min(500, (int) ($_POST['daily_max'] ?? 4)));
        $active  = !empty($_POST['active']) ? 1 : 0;
        $test    = !empty($_POST['test_mode']) ? 1 : 0;
        $launch  = trim((string) ($_POST['launch_at'] ?? '')) ?: null;
        $sort    = max(0, (int) ($_POST['sort_order'] ?? 0));

        /* On ne garde que des entiers positifs, dédoublonnés et triés :
           une liste saisie à la main contient toujours un espace, un
           doublon ou un zéro. */
        $liste = array_values(array_unique(array_filter(
            array_map('intval', preg_split('/[^0-9]+/', $opts) ?: []),
            static fn(int $v): bool => $v > 0
        )));
        sort($liste);
        $opts = $liste ? implode(',', array_slice($liste, 0, 8)) : '1';

        if ($name === '') {
            $error = t('admin.instant.err_name');
        } else {
            if ($id > 0) {
                $st = $db->prepare(
                    "UPDATE instant_games SET
                        name=?, mode=?, reward_coins=?, parts_total=?, ticket_options=?,
                        cooldown_hours=?, daily_max=?, active=?, test_mode=?,
                        launch_at=?, sort_order=?
                      WHERE id=?"
                );
                $st->bind_param('ssdisiiiisii', $name, $mode, $reward, $parts, $opts,
                    $cool, $daily, $active, $test, $launch, $sort, $id);
                $st->execute();
                $st->close();

                /* Si le total augmente, le compteur restant doit suivre :
                   laisser 3 parties restantes sur un total passé à 50
                   ferait gagner le prochain joueur presque aussitôt. */
                $st = $db->prepare(
                    "UPDATE instant_games SET parts_left = parts_total
                      WHERE id = ? AND parts_left > parts_total"
                );
                $st->bind_param('i', $id);
                $st->execute();
                $st->close();

                wt_admin_log('instant_update', ['id' => $id], $id);
            } else {
                $st = $db->prepare(
                    "INSERT INTO instant_games
                       (name, mode, reward_coins, parts_total, parts_left, ticket_options,
                        cooldown_hours, daily_max, active, test_mode, launch_at, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('ssdiisiiiisi', $name, $mode, $reward, $parts, $parts, $opts,
                    $cool, $daily, $active, $test, $launch, $sort);
                $st->execute();
                $id = (int) $st->insert_id;
                $st->close();
                wt_admin_log('instant_create', ['id' => $id], $id);
            }
            header('Location: ' . wt_url('/admin/instant.php?saved=1'));
            exit;
        }

    } elseif ($action === 'tickets') {
        /* Attribution manuelle de tickets, pour tester sans vente. */
        $uid = (int) ($_POST['user_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        if ($uid > 0 && $qty !== 0) {
            $notice = wt_tickets_add($uid, $qty, 'admin', 'Attribution manuelle')
                ? t('admin.instant.tickets_done', ['n' => $qty, 'u' => $uid])
                : null;
            if ($notice === null) { $error = t('admin.instant.tickets_fail'); }
        }
    }
}

/* ------------------------------------------------------------------
 * Lecture
 * ------------------------------------------------------------------ */
$games   = [];
$tableOk = true;
try {
    if ($res = $db->query("SELECT * FROM instant_games ORDER BY sort_order, id")) {
        while ($r = $res->fetch_assoc()) { $games[] = $r; }
        $res->free();
    }
} catch (Throwable $e) {
    $tableOk = false;
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
foreach ($games as $g) { if ((int) $g['id'] === $editId) { $edit = $g; break; } }

$venteOn = (string) cfg('instant.tickets_purchase_enabled', '0') === '1';

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <div>
          <h1>🎰 <?= e(t('admin.instant.title')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.instant.lead')) ?></p>
        </div>
      </header>

      <?php if (!$tableOk): ?>
        <div class="wt-alert wt-alert--warn"><?= e(t('admin.instant.no_table')) ?></div>
      <?php else: ?>

        <?php if (!empty($_GET['saved'])): ?>
          <div class="wt-alert wt-alert--success"><?= e(t('admin.saved')) ?></div>
        <?php endif; ?>
        <?php if (!empty($_GET['reset'])): ?>
          <div class="wt-alert wt-alert--success"><?= e(t('admin.instant.reset_done')) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($venteOn): ?>
          <div class="wt-alert wt-alert--error">
            <strong><?= e(t('admin.instant.sale_on_title')) ?></strong>
            <p style="margin:.35rem 0 0;font-size:.9rem"><?= e(t('admin.instant.sale_on_text')) ?></p>
          </div>
        <?php endif; ?>

        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="name"><?= e(t('admin.instant.f_name')) ?></label>
              <input class="wt-input" id="name" name="name" required maxlength="120"
                     value="<?= e((string) ($edit['name'] ?? '')) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="mode"><?= e(t('admin.instant.f_mode')) ?></label>
              <select class="wt-input" id="mode" name="mode">
                <option value="ads" <?= (($edit['mode'] ?? 'ads') === 'ads') ? 'selected' : '' ?>>
                  <?= e(t('admin.instant.mode_ads')) ?>
                </option>
                <option value="tickets" <?= (($edit['mode'] ?? '') === 'tickets') ? 'selected' : '' ?>>
                  <?= e(t('admin.instant.mode_tickets')) ?>
                </option>
              </select>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="reward_coins"><?= e(t('admin.instant.f_reward')) ?></label>
              <input class="wt-input" type="number" step="1" min="0" id="reward_coins"
                     name="reward_coins" value="<?= e((string) (int) ($edit['reward_coins'] ?? 5000)) ?>">
              <small class="wt-field__hint" data-instant-eur></small>
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="parts_total"><?= e(t('admin.instant.f_parts')) ?></label>
              <input class="wt-input" type="number" min="1" max="10000" id="parts_total"
                     name="parts_total" value="<?= (int) ($edit['parts_total'] ?? 7) ?>">
              <small class="wt-field__hint"><?= e(t('admin.instant.f_parts_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="ticket_options"><?= e(t('admin.instant.f_options')) ?></label>
              <input class="wt-input" id="ticket_options" name="ticket_options"
                     value="<?= e((string) ($edit['ticket_options'] ?? '1,2,5,10')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.instant.f_options_hint')) ?></small>
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="daily_max"><?= e(t('admin.instant.f_daily')) ?></label>
              <input class="wt-input" type="number" min="0" max="500" id="daily_max"
                     name="daily_max" value="<?= (int) ($edit['daily_max'] ?? 4) ?>">
              <small class="wt-field__hint"><?= e(t('admin.instant.f_daily_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="cooldown_hours"><?= e(t('admin.instant.f_cooldown')) ?></label>
              <input class="wt-input" type="number" min="0" max="720" id="cooldown_hours"
                     name="cooldown_hours" value="<?= (int) ($edit['cooldown_hours'] ?? 0) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="sort_order"><?= e(t('admin.instant.f_sort')) ?></label>
              <input class="wt-input" type="number" min="0" id="sort_order"
                     name="sort_order" value="<?= (int) ($edit['sort_order'] ?? 0) ?>">
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="launch_at"><?= e(t('admin.instant.f_launch')) ?></label>
              <input class="wt-input" type="datetime-local" id="launch_at" name="launch_at"
                     value="<?= e($edit && $edit['launch_at'] ? str_replace(' ', 'T', substr((string) $edit['launch_at'], 0, 16)) : '') ?>">
              <small class="wt-field__hint"><?= e(t('admin.instant.f_launch_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="active" value="1"
                       <?= (int) ($edit['active'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('admin.instant.f_active')) ?></span>
              </label>
              <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.5rem">
                <input type="checkbox" name="test_mode" value="1"
                       <?= (int) ($edit['test_mode'] ?? 1) === 1 ? 'checked' : '' ?>>
                <span><?= e(t('admin.instant.f_test')) ?></span>
              </label>
              <small class="wt-field__hint"><?= e(t('admin.instant.f_test_hint')) ?></small>
            </div>
          </div>

          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.instant.save')) ?></button>
            <?php if ($edit): ?>
              <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/admin/instant.php')) ?>"><?= e(t('admin.instant.new')) ?></a>
            <?php endif; ?>
          </div>
        </form>

        <?php
        /* Projection economique, calculee sur les reglages enregistres.
         *
         * Voie publicitaire : une partie = un parcours = un affichage,
         * et il en faut parts_total pour que quelqu'un gagne.
         * Voie tickets : aucun affichage, donc aucune recette — le
         * calculateur le dira franchement plutot que d'afficher une
         * marge qui n'existe pas. */
        if ($games) {
            $cycles = [];
            foreach ($games as $g) {
                $parts = max(1, (int) $g['parts_total']);
                $vues  = $g['mode'] === 'ads' ? $parts : 0;
                $cycles[$g['name'] . ' — ' . t('admin.instant.cyc', ['n' => $parts])]
                    = [$vues, (float) $g['reward_coins']];
            }
            echo wt_econ_render($cycles, t('econ.title'));
        }
        ?>

        <?php if ($games): ?>
          <h2 style="margin-top:2rem"><?= e(t('admin.instant.list')) ?></h2>
          <table class="wt-admin-v2__table">
            <thead>
              <tr>
                <th><?= e(t('admin.instant.c_name')) ?></th>
                <th><?= e(t('admin.instant.c_mode')) ?></th>
                <th><?= e(t('admin.instant.c_reward')) ?></th>
                <th><?= e(t('admin.instant.c_counter')) ?></th>
                <th><?= e(t('admin.instant.c_stats')) ?></th>
                <th><?= e(t('admin.instant.c_state')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($games as $g): ?>
                <tr>
                  <td><?= e((string) $g['name']) ?></td>
                  <td><?= e($g['mode'] === 'ads' ? t('admin.instant.mode_ads') : t('admin.instant.mode_tickets')) ?></td>
                  <td><?= e(number_format((float) $g['reward_coins'], 0, ',', ' ')) ?> c<br>
                      <small class="wt-muted"><?= e(number_format((float) $g['reward_coins'] / 10000, 2, ',', ' ')) ?> €</small></td>
                  <td><strong><?= (int) $g['parts_left'] ?></strong> / <?= (int) $g['parts_total'] ?></td>
                  <td><?= (int) $g['plays_total'] ?> <?= e(t('admin.instant.plays')) ?><br>
                      <small class="wt-muted"><?= (int) $g['wins_total'] ?> <?= e(t('admin.instant.wins')) ?></small></td>
                  <td>
                    <?php if ((int) $g['active'] !== 1): ?>
                      <span class="wt-muted">○ <?= e(t('admin.instant.st_off')) ?></span>
                    <?php elseif ((int) $g['test_mode'] === 1): ?>
                      <span style="color:var(--wt-warn)">● <?= e(t('admin.instant.st_test')) ?></span>
                    <?php else: ?>
                      <span style="color:var(--wt-success)">● <?= e(t('admin.instant.st_live')) ?></span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space:nowrap">
                    <a class="wt-btn wt-btn--ghost wt-btn--sm"
                       href="<?= e(wt_url('/admin/instant.php?edit=' . (int) $g['id'])) ?>"><?= e(t('admin.instant.edit')) ?></a>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= e(t('admin.instant.confirm_reset')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="reset">
                      <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.instant.reset')) ?></button>
                    </form>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= e(t('admin.instant.confirm_del')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.instant.del')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <h2 style="margin-top:2rem"><?= e(t('admin.instant.tickets_title')) ?></h2>
        <p class="wt-muted" style="font-size:.9rem"><?= e(t('admin.instant.tickets_lead')) ?></p>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="tickets">
          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="user_id"><?= e(t('admin.instant.f_user')) ?></label>
              <input class="wt-input" type="number" min="1" id="user_id" name="user_id" required>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="qty"><?= e(t('admin.instant.f_qty')) ?></label>
              <input class="wt-input" type="number" id="qty" name="qty" required value="10">
              <small class="wt-field__hint"><?= e(t('admin.instant.f_qty_hint')) ?></small>
            </div>
          </div>
          <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.instant.tickets_give')) ?></button>
        </form>

      <?php endif; ?>

    </section>
  </div>
</main>

<script>
/* Equivalent en euros du gain, mis a jour pendant la saisie.
   Un gain se regle en coins, mais se decide en euros : afficher les
   deux evite de valider 500 000 coins en croyant en saisir 5 000. */
(function () {
  var champ = document.getElementById('reward_coins');
  var cible = document.querySelector('[data-instant-eur]');
  if (!champ || !cible) { return; }
  function maj() {
    var v = parseFloat(champ.value) || 0;
    cible.textContent = (v / 10000).toLocaleString(undefined,
      { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  champ.addEventListener('input', maj);
  maj();
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
