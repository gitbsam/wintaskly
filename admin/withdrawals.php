<?php
/**
 * Wintaskly — Admin · Modération des retraits.
 *
 * Vue :
 *   - file des demandes en attente (par défaut),
 *   - historique complété / refusé via filtre `?status=…`.
 *
 * Actions :
 *   - complete : marque la demande comme effectuée (paiement manuel
 *                ou via API externe non gérée ici).
 *   - refuse   : demande un motif obligatoire, marque comme refusée,
 *                **re-crédite atomiquement** le solde de l'utilisateur
 *                et journalise une transaction de type 'admin'.
 */
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/payout_dispatcher.php';
$adminUser   = require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.withdrawals');
$adminActive = 'withdrawals';
$db          = db();
$notice      = null;
$error       = null;

/* ===== Actions ====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && in_array($action, ['complete', 'refuse'], true)) {

        $db->begin_transaction();
        try {
            $stmt = $db->prepare(
                "SELECT id, user_id, method_id, coins_amount, payout_amount,
                        payout_currency, payout_address, status
                   FROM withdrawals
                  WHERE id = ?
                  FOR UPDATE"
            );
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $wd = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$wd) {
                throw new RuntimeException('not_found');
            }
            if ($wd['status'] !== 'pending') {
                throw new RuntimeException('already_processed');
            }

            if ($action === 'complete') {
                /*
                 * Tentative de paiement automatique via le dispatcher.
                 * - Si la méthode a `auto_payout=1` ET une clé API valide,
                 *   le dispatcher appelle l'API du provider (FaucetPay, ...)
                 *   et retourne ok=true + txid.
                 * - Sinon (manuel) → ok=true mais manual_required=true :
                 *   l'admin a déjà envoyé à la main, on valide juste la BDD.
                 * - En cas d'échec API → ok=false : on ROLLBACK et on garde
                 *   en pending pour permettre une nouvelle tentative.
                 */
                $payout = wt_payout_dispatch($wd);

                if (!$payout['ok']) {
                    // Échec API : on remonte un état lisible et on annule la transaction
                    // (le retrait reste 'pending', l'admin pourra retenter ou refuser).
                    $db->rollback();
                    throw new RuntimeException('payout_failed:' . $payout['message']);
                }

                $txid     = $payout['txid'] ?? null;
                $isManual = !empty($payout['manual_required']);
                $mode     = $isManual ? 'manual' : 'auto';

                $stmt = $db->prepare(
                    "UPDATE withdrawals
                        SET status='completed',
                            processed_by=?,
                            processed_at=UTC_TIMESTAMP(),
                            payout_txid=?,
                            payout_mode=?
                      WHERE id=?"
                );
                $stmt->bind_param('issi', $adminUser['id'], $txid, $mode, $id);
                $stmt->execute();
                $stmt->close();
                $db->commit();

                // Message adapté selon le mode
                $notice = $isManual
                    ? (string) t('admin.wd.completed_manual')
                    : sprintf((string) t('admin.wd.completed_auto'), $txid ?? '—');

            } else { /* refuse */
                $reason = trim((string)($_POST['refused_reason'] ?? ''));
                if ($reason === '') {
                    throw new RuntimeException('reason_required');
                }
                if (wt_strlen($reason) > 240) $reason = wt_substr($reason, 0, 240);

                /* 1) Marque la demande refusée */
                $stmt = $db->prepare(
                    "UPDATE withdrawals
                        SET status='refused',
                            refused_reason=?,
                            processed_by=?,
                            processed_at=UTC_TIMESTAMP()
                      WHERE id=?"
                );
                $stmt->bind_param('sii', $reason, $adminUser['id'], $id);
                $stmt->execute();
                $stmt->close();

                /* 2) Re-crédite le solde de l'utilisateur */
                $coinsBack = (float) $wd['coins_amount'];
                $userId    = (int)   $wd['user_id'];
                $stmt = $db->prepare("UPDATE users SET coins = coins + ? WHERE id = ?");
                $stmt->bind_param('di', $coinsBack, $userId);
                $stmt->execute();
                $stmt->close();

                /* 3) Journalise la transaction "remboursement" */
                $meta = 'refund#' . $id . ' ' . $reason;
                $stmt = $db->prepare(
                    "INSERT INTO transactions (user_id, type, coins, xp, meta)
                     VALUES (?, 'admin', ?, 0, ?)"
                );
                $stmt->bind_param('ids', $userId, $coinsBack, $meta);
                $stmt->execute();
                $stmt->close();

                $db->commit();
                $notice = t('admin.saved');
            }
        } catch (Throwable $e) {
            // Si le dispatcher a déjà rollback (cas payout_failed), on évite
            // de re-rollback (génère un warning sinon).
            if ($db->in_transaction ?? true) {
                @$db->rollback();
            }

            $msg = $e->getMessage();
            if (str_starts_with($msg, 'payout_failed:')) {
                // Extrait le détail renvoyé par le dispatcher
                $error = sprintf(
                    (string) t('admin.wd.payout_failed'),
                    substr($msg, strlen('payout_failed:'))
                );
            } else {
                $error = match ($msg) {
                    'not_found'          => 'Demande introuvable',
                    'already_processed'  => 'Demande déjà traitée',
                    'reason_required'    => t('admin.wd.refuse_reason'),
                    default              => t('common.error'),
                };
            }
        }
    }
}

/* ===== Filtre & chargement ========================================= */
$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'completed', 'refused', 'all'], true)) {
    $statusFilter = 'pending';
}

$sql = "SELECT w.*,
               u.username, u.email, u.coins AS user_balance,
               m.label AS method_label, m.k AS method_k
          FROM withdrawals w
          JOIN users u             ON u.id = w.user_id
          JOIN withdrawal_methods m ON m.id = w.method_id";
$params = [];
$types  = '';
if ($statusFilter !== 'all') {
    $sql   .= " WHERE w.status = ?";
    $params[] = $statusFilter;
    $types   .= 's';
}
$sql .= " ORDER BY (w.status='pending') DESC, w.id DESC LIMIT 200";

$stmt = $db->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res  = $stmt->get_result();
$rows = [];
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===== Compteurs par statut ======================================== */
$counts = ['pending' => 0, 'completed' => 0, 'refused' => 0];
if ($r = $db->query("SELECT status, COUNT(*) c FROM withdrawals GROUP BY status")) {
    while ($x = $r->fetch_assoc()) $counts[$x['status']] = (int) $x['c'];
    $r->free();
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">💸 <?= e(t('admin.eyebrow_withdrawals')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.wd.queue')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.wd.lead')) ?></p>
        </div>
      </header>

    <?php if ($notice): ?>
      <div class="wt-alert wt-alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="wt-alert wt-alert--error"><?= e($error) ?></div>
    <?php endif; ?>

    <p class="wt-muted" style="font-size:.85rem"><?= e(t('admin.wd.refunded')) ?></p>

    <div class="wt-admin__tabs" style="margin:1rem 0">
      <?php foreach (['pending','completed','refused','all'] as $st):
        $lbl = $st === 'all' ? 'Toutes' : t('wd.status.' . $st);
        $c   = $st === 'all' ? array_sum($counts) : ($counts[$st] ?? 0);
        $active = $st === $statusFilter ? 'is-active' : '';
      ?>
        <a class="wt-btn wt-btn--xs wt-btn--ghost <?= $active ?>"
           href="?status=<?= e($st) ?>"><?= e($lbl) ?> (<?= (int)$c ?>)</a>
      <?php endforeach; ?>
    </div>

    <div class="wt-table-wrap">
      <table class="wt-table">
        <thead>
          <tr>
            <th>#</th>
            <th><?= e(t('common.date')) ?></th>
            <th>Utilisateur</th>
            <th><?= e(t('wd.method')) ?></th>
            <th class="wt-table__num"><?= e(t('common.coins')) ?></th>
            <th class="wt-table__num"><?= e(t('wd.payout')) ?></th>
            <th>Adresse</th>
            <th><?= e(t('common.status')) ?></th>
            <th class="wt-table__actions"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="wt-muted"><?= e(t('common.empty')) ?></td></tr>
        <?php else: foreach ($rows as $r):
          $badge = $r['status'] === 'completed' ? 'completed'
                 : ($r['status'] === 'refused'  ? 'refused' : 'pending');
        ?>
          <tr>
            <td><?= (int) $r['id'] ?></td>
            <td>
              <span data-fmt-time data-utc="<?= e($r['created_at']) ?>">
                <?= e(wt_format_datetime($r['created_at'])) ?>
              </span>
            </td>
            <td>
              <strong><?= e($r['username']) ?></strong><br>
              <span class="wt-muted" style="font-size:.78rem"><?= e($r['email']) ?></span><br>
              <span class="wt-muted" style="font-size:.78rem">
                Solde : <?= e(rtrim(rtrim(number_format((float)$r['user_balance'], 4, '.', ''), '0'), '.')) ?>
              </span>
            </td>
            <td>
              <?= e($r['method_label']) ?>
              <br><code class="wt-mono" style="font-size:.7rem"><?= e($r['method_k']) ?></code>
            </td>
            <td class="wt-table__num">
              <?= e(rtrim(rtrim(number_format((float)$r['coins_amount'], 4, '.', ''), '0'), '.')) ?>
            </td>
            <td class="wt-table__num">
              <?= e(rtrim(rtrim(number_format((float)$r['payout_amount'], 8, '.', ''), '0'), '.')) ?>
              <?= e($r['payout_currency']) ?>
            </td>
            <td>
              <code class="wt-mono" style="word-break:break-all;font-size:.75rem">
                <?= e($r['payout_address']) ?>
              </code>
            </td>
            <td>
              <span class="wt-badge wt-badge--<?= e($badge) ?>">
                <?= e(t('wd.status.' . $r['status'])) ?>
              </span>
              <?php if ($r['status'] === 'refused' && !empty($r['refused_reason'])): ?>
                <div class="wt-muted" style="font-size:.72rem;margin-top:.25rem">
                  <?= e($r['refused_reason']) ?>
                </div>
              <?php endif; ?>
              <?php if ($r['status'] !== 'pending' && !empty($r['processed_at'])): ?>
                <div class="wt-muted" style="font-size:.7rem;margin-top:.15rem">
                  <span data-fmt-time data-utc="<?= e($r['processed_at']) ?>">
                    <?= e(wt_format_datetime($r['processed_at'])) ?>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td class="wt-table__actions">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="complete">
                  <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="wt-btn wt-btn--xs wt-btn--primary"
                          onclick="return confirm('Marquer comme complété ?');">
                    ✓ <?= e(t('admin.wd.complete')) ?>
                  </button>
                </form>

                <details style="display:inline-block;vertical-align:top;margin-left:.25rem">
                  <summary class="wt-btn wt-btn--xs wt-btn--danger" style="cursor:pointer">
                    ✖ <?= e(t('admin.wd.refuse')) ?>
                  </summary>
                  <form method="post" style="margin-top:.5rem;display:flex;flex-direction:column;gap:.4rem;min-width:240px">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="refuse">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <input class="wt-input wt-input--sm"
                           type="text"
                           name="refused_reason"
                           required maxlength="240"
                           placeholder="<?= e(t('admin.wd.refuse_reason')) ?>">
                    <button type="submit" class="wt-btn wt-btn--xs wt-btn--danger">
                      <?= e(t('common.confirm')) ?>
                    </button>
                  </form>
                </details>
              <?php else: ?>
                <span class="wt-muted" style="font-size:.75rem">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
