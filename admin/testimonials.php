<?php
/**
 * Wintaskly — Admin · Modération des témoignages.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.testimonials');
$adminActive = 'testimonials';
$db          = db();
$notice      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)   ($_POST['id']     ?? 0);
    if ($id > 0) {
        if ($action === 'approve') {
            $db->query(
                "UPDATE testimonials
                    SET status='approved', approved_at=UTC_TIMESTAMP(), reject_reason=NULL
                  WHERE id=" . $id
            );
            $notice = t('admin.saved');
        } elseif ($action === 'reject') {
            $reason = trim((string)($_POST['reject_reason'] ?? ''));
            $stmt = $db->prepare(
                "UPDATE testimonials
                    SET status='rejected', reject_reason=?
                  WHERE id=?"
            );
            $stmt->bind_param('si', $reason, $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        } elseif ($action === 'feature') {
            $stmt = $db->prepare("UPDATE testimonials SET featured = 1 - featured WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM testimonials WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.deleted');
        }
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending','approved','rejected','all'], true)) {
    $statusFilter = 'pending';
}
$sql = "SELECT t.*, u.username FROM testimonials t
          JOIN users u ON u.id = t.user_id";
$params = []; $types = '';
if ($statusFilter !== 'all') {
    $sql .= " WHERE t.status = ?";
    $params[] = $statusFilter; $types = 's';
}
$sql .= " ORDER BY t.id DESC LIMIT 200";

$stmt = $db->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">⭐ <?= e(t('admin.eyebrow_testimonials')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.testimonials')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.testimonials.lead')) ?></p>
        </div>
      </header>
    <?php if ($notice): ?>
      <div class="wt-alert wt-alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <div class="wt-admin__tabs" style="margin:1rem 0">
      <?php foreach (['pending','approved','rejected','all'] as $st):
        $active = $st === $statusFilter ? 'is-active' : '';
        $lbl = $st === 'all' ? 'Toutes' : ($st === 'pending' ? 'En attente' : ($st === 'approved' ? 'Approuvées' : 'Refusées'));
      ?>
        <a class="wt-btn wt-btn--xs wt-btn--ghost <?= $active ?>" href="?status=<?= e($st) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="wt-table-wrap">
      <table class="wt-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Auteur</th>
            <th>Note</th>
            <th>Titre / Commentaire</th>
            <th><?= e(t('common.status')) ?></th>
            <th class="wt-table__actions"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="wt-muted"><?= e(t('common.empty')) ?></td></tr>
        <?php else: foreach ($rows as $r):
          $badge = $r['status']==='approved' ? 'completed' : ($r['status']==='rejected' ? 'refused' : 'pending');
        ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <strong><?= e($r['username']) ?></strong>
              <div class="wt-muted" style="font-size:.72rem">
                <span data-fmt-time data-utc="<?= e($r['created_at']) ?>">
                  <?= e(wt_format_datetime($r['created_at'])) ?>
                </span>
              </div>
            </td>
            <td>
              <?php for ($i=1; $i<=5; $i++): ?>
                <span class="wt-star <?= $i <= (int)$r['rating'] ? 'is-on' : '' ?>" aria-hidden="true">★</span>
              <?php endfor; ?>
            </td>
            <td style="max-width:380px">
              <strong><?= e($r['title']) ?></strong>
              <?php if (!empty($r['featured'])): ?>
                <span class="wt-pill wt-pill--ok" style="margin-left:.25rem">FEATURED</span>
              <?php endif; ?>
              <div class="wt-muted" style="font-size:.85rem;margin-top:.25rem">
                <?= nl2br(e(wt_strlen($r['body']) > 260 ? wt_substr($r['body'], 0, 260) . '…' : $r['body'])) ?>
              </div>
            </td>
            <td>
              <span class="wt-badge wt-badge--<?= e($badge) ?>"><?= e($r['status']) ?></span>
              <?php if ($r['status']==='rejected' && !empty($r['reject_reason'])): ?>
                <div class="wt-muted" style="font-size:.72rem"><?= e($r['reject_reason']) ?></div>
              <?php endif; ?>
            </td>
            <td class="wt-table__actions">
              <?php if ($r['status']!=='approved'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                  <button class="wt-btn wt-btn--xs wt-btn--primary">✓ Approuver</button>
                </form>
              <?php endif; ?>

              <?php if ($r['status']!=='rejected'): ?>
                <details style="display:inline-block">
                  <summary class="wt-btn wt-btn--xs wt-btn--danger" style="cursor:pointer">✖ Refuser</summary>
                  <form method="post" style="margin-top:.4rem;display:flex;flex-direction:column;gap:.3rem;min-width:220px">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <input class="wt-input wt-input--sm" type="text" name="reject_reason"
                           placeholder="Motif" maxlength="180">
                    <button class="wt-btn wt-btn--xs wt-btn--danger">Confirmer</button>
                  </form>
                </details>
              <?php endif; ?>

              <form method="post" style="display:inline">
                <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="feature">
                <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                <button class="wt-btn wt-btn--xs wt-btn--ghost"
                        title="Basculer mise en avant">★</button>
              </form>

              <form method="post" style="display:inline"
                    onsubmit="return confirm('<?= e(t('admin.confirm_delete')) ?>')">
                <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                <button class="wt-btn wt-btn--xs wt-btn--danger">🗑</button>
              </form>
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
