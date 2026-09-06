<?php
/**
 * Wintaskly — Admin · Registre des recettes
 *
 * Enregistre ce que les prestataires versent réellement, pour le
 * confronter aux tarifs annoncés saisis dans `shortlinks`.
 *
 * L'intérêt n'est pas comptable, il est décisionnel : le rapport entre
 * ce qui rentre et le nombre de participations créditées sur la même
 * période donne le taux de validation réel. C'est lui qui dit si une
 * récompense peut être augmentée, pas la brochure du prestataire.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = 'Recettes — Wintaskly';
$adminActive = 'revenues';
$db          = db();

$notice = null;
$error  = null;

/* Devises acceptées à la saisie. La conversion est figée au moment de
   l'enregistrement : une recette de mars ne doit pas changer de valeur
   parce que l'euro a bougé en juin. */
$currencies = ['USD', 'EUR', 'BTC', 'LTC', 'TRX', 'USDT'];

/* ------------------------------------------------------------------
 * Traitement
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $st = $db->prepare("DELETE FROM revenue_entries WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $st->close();
        wt_admin_log('revenue_delete', ['id' => $id], $id);
        header('Location: ' . wt_url('/admin/revenues.php'));
        exit;
    }

    if ($action === 'save') {
        $kind     = in_array($_POST['source_kind'] ?? '', ['shortlink','offerwall','ptc','ads','other'], true)
                  ? (string) $_POST['source_kind'] : 'shortlink';
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $provider = trim((string) ($_POST['provider'] ?? ''));
        $pStart   = (string) ($_POST['period_start'] ?? '');
        $pEnd     = (string) ($_POST['period_end'] ?? '');
        $declared = max(0, (float) ($_POST['declared_amount'] ?? 0));
        $received = max(0, (float) ($_POST['received_amount'] ?? 0));
        $cur      = in_array($_POST['currency'] ?? '', $currencies, true)
                  ? (string) $_POST['currency'] : 'USD';
        $status   = in_array($_POST['status'] ?? '', ['attendu','recu','impaye'], true)
                  ? (string) $_POST['status'] : 'attendu';
        $paidAt   = trim((string) ($_POST['paid_at'] ?? '')) ?: null;
        $ref      = trim((string) ($_POST['reference'] ?? '')) ?: null;
        $notes    = trim((string) ($_POST['notes'] ?? '')) ?: null;

        /* Cours figé à l'enregistrement. L'euro est la base du système et
           ne figure pas dans le cache ; toute autre devise en vient. Un
           cours introuvable vaut 0, ce qui rendrait la recette nulle sans
           le dire : on refuse plutôt que d'enregistrer un zéro trompeur. */
        $rates   = $GLOBALS['ratesToEur'] ?? ['EUR' => 1.0];
        $eurRate = ($cur === 'EUR') ? 1.0 : (float) ($rates[$cur] ?? 0);

        if ($provider === '' || $pStart === '' || $pEnd === '') {
            $error = t('admin.rev.err_required');
        } elseif ($pEnd < $pStart) {
            $error = t('admin.rev.err_period');
        } elseif ($eurRate <= 0) {
            $error = t('admin.rev.err_rate', ['cur' => $cur]);
        } else {
            $receivedEur = round($received * $eurRate, 4);
            $srcId       = $sourceId > 0 ? $sourceId : null;

            if ($id > 0) {
                $st = $db->prepare(
                    "UPDATE revenue_entries SET
                        source_kind=?, source_id=?, provider=?, period_start=?, period_end=?,
                        declared_amount=?, received_amount=?, currency=?, eur_rate=?,
                        received_eur=?, status=?, paid_at=?, reference=?, notes=?
                      WHERE id=?"
                );
                $st->bind_param('sisssddsddssssi', $kind, $srcId, $provider, $pStart, $pEnd,
                    $declared, $received, $cur, $eurRate, $receivedEur, $status,
                    $paidAt, $ref, $notes, $id);
                $st->execute();
                $st->close();
                wt_admin_log('revenue_update', ['id' => $id], $id);
            } else {
                $st = $db->prepare(
                    "INSERT INTO revenue_entries
                       (source_kind, source_id, provider, period_start, period_end,
                        declared_amount, received_amount, currency, eur_rate,
                        received_eur, status, paid_at, reference, notes)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $st->bind_param('sisssddsddssss', $kind, $srcId, $provider, $pStart, $pEnd,
                    $declared, $received, $cur, $eurRate, $receivedEur, $status,
                    $paidAt, $ref, $notes);
                $st->execute();
                $id = (int) $st->insert_id;
                $st->close();
                wt_admin_log('revenue_create', ['id' => $id], $id);
            }
            header('Location: ' . wt_url('/admin/revenues.php?saved=1'));
            exit;
        }
    }
}

/* ------------------------------------------------------------------
 * Lecture
 * ------------------------------------------------------------------ */
$rows      = [];
$tableOk   = true;
try {
    if ($res = $db->query("SELECT * FROM revenue_entries ORDER BY period_start DESC, id DESC")) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
    }
} catch (Throwable $e) {
    $tableOk = false;
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
foreach ($rows as $r) { if ((int) $r['id'] === $editId) { $edit = $r; break; } }

/* Liste des shortlinks, pour rattacher une recette à une ligne précise. */
$shortlinks = [];
try {
    if ($res = $db->query("SELECT id, name, provider FROM shortlinks ORDER BY name")) {
        while ($r = $res->fetch_assoc()) { $shortlinks[] = $r; }
        $res->free();
    }
} catch (Throwable $e) { /* table absente : le champ restera vide */ }

/**
 * Participations validées sur une période, pour un shortlink ou tous.
 *
 * C'est le dénominateur du taux de validation : combien de fois vous
 * avez crédité un utilisateur, à opposer à ce que le prestataire a
 * réellement payé sur la même période.
 */
function wt_rev_attempts(string $start, string $end, ?int $shortlinkId): int
{
    try {
        $sql = "SELECT COUNT(*) c FROM shortlink_attempts
                 WHERE status = 'valide'
                   AND completed_at >= ? AND completed_at < DATE_ADD(?, INTERVAL 1 DAY)";
        if ($shortlinkId) { $sql .= " AND shortlink_id = " . (int) $shortlinkId; }
        $st = db()->prepare($sql);
        $st->bind_param('ss', $start, $end);
        $st->execute();
        $n = (int) ($st->get_result()->fetch_assoc()['c'] ?? 0);
        $st->close();
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <div>
          <h1>💶 <?= e(t('admin.rev.title')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.rev.lead')) ?></p>
        </div>
      </header>

      <?php if (!$tableOk): ?>
        <div class="wt-alert wt-alert--warn"><?= e(t('admin.rev.no_table')) ?></div>
      <?php else: ?>

        <?php if (!empty($_GET['saved'])): ?>
          <div class="wt-alert wt-alert--success"><?= e(t('admin.saved')) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="wt-alert wt-alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="source_kind"><?= e(t('admin.rev.f_kind')) ?></label>
              <select class="wt-input" id="source_kind" name="source_kind">
                <?php foreach (['shortlink','offerwall','ptc','ads','other'] as $k): ?>
                  <option value="<?= e($k) ?>" <?= (($edit['source_kind'] ?? 'shortlink') === $k) ? 'selected' : '' ?>>
                    <?= e(t('admin.rev.kind_' . $k)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="wt-field">
              <label class="wt-field__label" for="provider"><?= e(t('admin.rev.f_provider')) ?></label>
              <input class="wt-input" id="provider" name="provider" required maxlength="60"
                     value="<?= e((string) ($edit['provider'] ?? '')) ?>"
                     placeholder="<?= e(t('admin.rev.f_provider_ph')) ?>">
            </div>

            <div class="wt-field">
              <label class="wt-field__label" for="source_id"><?= e(t('admin.rev.f_source')) ?></label>
              <select class="wt-input" id="source_id" name="source_id">
                <option value="0"><?= e(t('admin.rev.f_source_all')) ?></option>
                <?php foreach ($shortlinks as $sl): ?>
                  <option value="<?= (int) $sl['id'] ?>"
                    <?= ((int) ($edit['source_id'] ?? 0) === (int) $sl['id']) ? 'selected' : '' ?>>
                    <?= e((string) $sl['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <small class="wt-field__hint"><?= e(t('admin.rev.f_source_hint')) ?></small>
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="period_start"><?= e(t('admin.rev.f_start')) ?></label>
              <input class="wt-input" type="date" id="period_start" name="period_start" required
                     value="<?= e((string) ($edit['period_start'] ?? date('Y-m-01', strtotime('-1 month')))) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="period_end"><?= e(t('admin.rev.f_end')) ?></label>
              <input class="wt-input" type="date" id="period_end" name="period_end" required
                     value="<?= e((string) ($edit['period_end'] ?? date('Y-m-t', strtotime('-1 month')))) ?>">
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="declared_amount"><?= e(t('admin.rev.f_declared')) ?></label>
              <input class="wt-input" type="number" step="0.0001" min="0"
                     id="declared_amount" name="declared_amount"
                     value="<?= e((string) ($edit['declared_amount'] ?? '0')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.rev.f_declared_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="received_amount"><?= e(t('admin.rev.f_received')) ?></label>
              <input class="wt-input" type="number" step="0.0001" min="0"
                     id="received_amount" name="received_amount"
                     value="<?= e((string) ($edit['received_amount'] ?? '0')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.rev.f_received_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="currency"><?= e(t('admin.rev.f_currency')) ?></label>
              <select class="wt-input" id="currency" name="currency">
                <?php foreach ($currencies as $c): ?>
                  <option value="<?= e($c) ?>" <?= (($edit['currency'] ?? 'USD') === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
              <small class="wt-field__hint"><?= e(t('admin.rev.f_currency_hint')) ?></small>
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="status"><?= e(t('admin.rev.f_status')) ?></label>
              <select class="wt-input" id="status" name="status">
                <?php foreach (['attendu','recu','impaye'] as $st): ?>
                  <option value="<?= e($st) ?>" <?= (($edit['status'] ?? 'attendu') === $st) ? 'selected' : '' ?>>
                    <?= e(t('admin.rev.st_' . $st)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="paid_at"><?= e(t('admin.rev.f_paid_at')) ?></label>
              <input class="wt-input" type="date" id="paid_at" name="paid_at"
                     value="<?= e((string) ($edit['paid_at'] ?? '')) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="reference"><?= e(t('admin.rev.f_reference')) ?></label>
              <input class="wt-input" id="reference" name="reference" maxlength="120"
                     value="<?= e((string) ($edit['reference'] ?? '')) ?>"
                     placeholder="<?= e(t('admin.rev.f_reference_ph')) ?>">
            </div>
          </div>

          <div class="wt-field">
            <label class="wt-field__label" for="notes"><?= e(t('admin.rev.f_notes')) ?></label>
            <textarea class="wt-input" id="notes" name="notes" rows="2"><?= e((string) ($edit['notes'] ?? '')) ?></textarea>
          </div>

          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.rev.save')) ?></button>
            <?php if ($edit): ?>
              <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/admin/revenues.php')) ?>"><?= e(t('admin.rev.new')) ?></a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($rows): ?>
          <h2 style="margin-top:2rem"><?= e(t('admin.rev.history')) ?></h2>
          <table class="wt-admin-v2__table">
            <thead>
              <tr>
                <th><?= e(t('admin.rev.c_period')) ?></th>
                <th><?= e(t('admin.rev.c_provider')) ?></th>
                <th><?= e(t('admin.rev.c_declared')) ?></th>
                <th><?= e(t('admin.rev.c_received')) ?></th>
                <th><?= e(t('admin.rev.c_attempts')) ?></th>
                <th><?= e(t('admin.rev.c_per1000')) ?></th>
                <th><?= e(t('admin.rev.c_status')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $n = wt_rev_attempts((string) $r['period_start'], (string) $r['period_end'],
                                       $r['source_id'] ? (int) $r['source_id'] : null);
                  /* Recette réelle pour 1000 participations créditées.
                     C'est le chiffre à comparer à provider_rate_amount :
                     l'écart entre les deux est votre taux de validation. */
                  $per1000 = $n > 0 ? ((float) $r['received_eur'] / $n * 1000) : null;
                ?>
                <tr>
                  <td><?= e(substr((string) $r['period_start'], 0, 10)) ?><br>
                      <small class="wt-muted">→ <?= e(substr((string) $r['period_end'], 0, 10)) ?></small></td>
                  <td><?= e((string) $r['provider']) ?></td>
                  <td><?= e(number_format((float) $r['declared_amount'], 2, ',', ' ')) ?> <?= e((string) $r['currency']) ?></td>
                  <td><?= e(number_format((float) $r['received_amount'], 2, ',', ' ')) ?> <?= e((string) $r['currency']) ?><br>
                      <small class="wt-muted"><?= e(number_format((float) $r['received_eur'], 2, ',', ' ')) ?> EUR</small></td>
                  <td><?= $n > 0 ? number_format($n, 0, ',', ' ') : '<span class="wt-muted">—</span>' ?></td>
                  <td>
                    <?= $per1000 !== null
                        ? '<strong>' . e(number_format($per1000, 2, ',', ' ')) . ' EUR</strong>'
                        : '<span class="wt-muted">—</span>' ?>
                  </td>
                  <td><?= e(t('admin.rev.st_' . $r['status'])) ?></td>
                  <td style="white-space:nowrap">
                    <a class="wt-btn wt-btn--ghost wt-btn--sm"
                       href="<?= e(wt_url('/admin/revenues.php?edit=' . (int) $r['id'])) ?>"><?= e(t('admin.rev.edit')) ?></a>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?= e(t('admin.rev.confirm_del')) ?>')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.rev.del')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <p class="wt-muted" style="font-size:.86rem;margin-top:.8rem">
            <?= e(t('admin.rev.per1000_hint')) ?>
          </p>
        <?php endif; ?>

      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
