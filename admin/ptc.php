<?php
/**
 * Wintaskly — Admin · CRUD des annonces PTC (V8 modernisé).
 *
 * V8 : layout admin V8 + stats hero (total/actifs/vues) + form card
 * découpé en sections (Visuel / Récompense / Anti-fraude) + liste
 * en cards avec compteur de vues + modal confirm V8.
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.ptc');
$adminActive = 'ptc';
$db          = db();
$notice      = null;
$editing     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM ptc_ads WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.deleted');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE ptc_ads SET active = 1 - active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        }
    } elseif ($action === 'save') {
        $id        = (int)   ($_POST['id'] ?? 0);
        $title     = trim((string)($_POST['title'] ?? ''));
        $desc      = trim((string)($_POST['description'] ?? ''));
        $url       = trim((string)($_POST['url'] ?? ''));
        $coins     = (float) ($_POST['reward_coins'] ?? 0);
        $xp        = (int)   ($_POST['reward_xp'] ?? 0);
        $duration  = (int)   ($_POST['duration_seconds'] ?? 15);
        $daily     = (int)   ($_POST['daily_view_limit'] ?? 1000);
        $cooldown  = (int)   ($_POST['cooldown_hours'] ?? 24);
        $active    = !empty($_POST['active']) ? 1 : 0;

        if ($title !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE ptc_ads SET
                        title=?, description=?, url=?, reward_coins=?, reward_xp=?,
                        duration_seconds=?, daily_view_limit=?, cooldown_hours=?, active=?
                     WHERE id=?"
                );
                $stmt->bind_param(
                    'sssdiiiiii',
                    $title, $desc, $url, $coins, $xp, $duration, $daily, $cooldown, $active, $id
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.saved');
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO ptc_ads
                       (title, description, url, reward_coins, reward_xp,
                        duration_seconds, daily_view_limit, cooldown_hours, active)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'sssdiiiii',
                    $title, $desc, $url, $coins, $xp, $duration, $daily, $cooldown, $active
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.created');
            }
        }
    }
}

if (!empty($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $db->prepare(
        "SELECT id, title, description, url, reward_coins, reward_xp,
                duration_seconds, daily_view_limit, cooldown_hours, active
           FROM ptc_ads WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

$rows = [];
if ($res = $db->query(
    "SELECT id, title, reward_coins, duration_seconds, daily_view_limit, total_views, active
       FROM ptc_ads ORDER BY id DESC"
)) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* Stats hero */
$nbTotal  = count($rows);
$nbActive = count(array_filter($rows, fn ($r) => (int)$r['active'] === 1));
$totalViews = 0;
foreach ($rows as $r) $totalViews += (int)($r['total_views'] ?? 0);

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">📺 <?= e(t('admin.eyebrow_ptc')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.ptc')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.ptc.lead')) ?></p>
        </div>
        <?php if ($editing): ?>
          <a class="wt-btn wt-btn--ghost wt-btn--xs"
             href="<?= e(wt_url('/admin/ptc.php')) ?>">
            ← <?= e(t('admin.exit_edit_mode')) ?>
          </a>
        <?php endif; ?>
      </header>

      <?php if ($notice): ?>
        <div class="wt-alert wt-alert--success" data-reveal>✓ <?= e($notice) ?></div>
      <?php endif; ?>

      <!-- Stats -->
      <section class="wt-admin-v2__stats" data-reveal>
        <article class="wt-admin-v2__stat" style="--idx:0">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">📊</span>
          <div>
            <small><?= e(t('admin.stat.total')) ?></small>
            <strong><?= (int)$nbTotal ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat wt-admin-v2__stat--ok" style="--idx:1">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">✅</span>
          <div>
            <small><?= e(t('admin.stat.active')) ?></small>
            <strong><?= (int)$nbActive ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat" style="--idx:2">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">👁</span>
          <div>
            <small><?= e(t('admin.stat.total_views')) ?></small>
            <strong><?= number_format($totalViews, 0, '.', ' ') ?></strong>
          </div>
        </article>
      </section>

      <!-- Form -->
      <article class="wt-admin-v2__card" data-reveal>
        <header class="wt-admin-v2__card-head">
          <span class="wt-admin-v2__card-icon" aria-hidden="true">
            <?= $editing ? '✏️' : '➕' ?>
          </span>
          <div>
            <h2>
              <?= $editing
                    ? e(sprintf((string)t('admin.edit_item'), '#' . (int)$editing['id']))
                    : e(t('admin.new_ptc')) ?>
            </h2>
            <small class="wt-muted">
              <?= e($editing ? t('admin.edit_lead') : t('admin.ptc.new_lead')) ?>
            </small>
          </div>
        </header>

        <form method="post" class="wt-admin-v2__form-body">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id"     value="<?= (int)($editing['id'] ?? 0) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ptc.title')) ?></span>
            <input class="wt-input" type="text" name="title" required maxlength="180"
                   value="<?= e((string)($editing['title'] ?? '')) ?>"
                   placeholder="<?= e(t('admin.ptc.title_placeholder')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ptc.description')) ?></span>
            <textarea class="wt-input wt-textarea" rows="2" name="description" maxlength="500"
                      placeholder="<?= e(t('admin.ptc.description_placeholder')) ?>"><?= e((string)($editing['description'] ?? '')) ?></textarea>
          </label>

          <label class="wt-field">
            <span class="wt-field__label">🎯 <?= e(t('admin.ptc.url')) ?></span>
            <input class="wt-input" type="url" name="url" required
                   value="<?= e((string)($editing['url'] ?? '')) ?>"
                   placeholder="https://...">
          </label>

          <div class="wt-admin-v2__grid-4">
            <label class="wt-field">
              <span class="wt-field__label">💰 <?= e(t('admin.sl.reward')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0" name="reward_coins"
                     value="<?= e((string)($editing['reward_coins'] ?? '0.5')) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⚡ <?= e(t('admin.sl.reward_xp')) ?></span>
              <input class="wt-input" type="number" min="0" name="reward_xp"
                     value="<?= (int)($editing['reward_xp'] ?? 1) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label">⏱ <?= e(t('admin.ptc.duration')) ?></span>
              <input class="wt-input" type="number" min="5" name="duration_seconds"
                     value="<?= (int)($editing['duration_seconds'] ?? 15) ?>">
              <small class="wt-field__hint"><?= e(t('admin.ptc.duration_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label">🕐 <?= e(t('admin.ptc.cooldown')) ?></span>
              <input class="wt-input" type="number" min="1" name="cooldown_hours"
                     value="<?= (int)($editing['cooldown_hours'] ?? 24) ?>">
            </label>
          </div>

          <label class="wt-field">
            <span class="wt-field__label">🛡 <?= e(t('admin.ptc.daily_limit')) ?></span>
            <input class="wt-input" type="number" min="1" name="daily_view_limit"
                   value="<?= (int)($editing['daily_view_limit'] ?? 1000) ?>">
            <small class="wt-field__hint"><?= e(t('admin.ptc.daily_limit_hint')) ?></small>
          </label>

          <label class="wt-checkbox wt-admin-v2__active-check">
            <input type="checkbox" name="active" value="1"
                   <?= !empty($editing['active']) || $editing === null ? 'checked' : '' ?>>
            <span><strong><?= e(t('common.active')) ?></strong> — <?= e(t('admin.active_hint')) ?></span>
          </label>

          <div class="wt-admin-v2__form-actions">
            <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg">
              <?= $editing ? '💾 ' . e(t('common.save')) : '➕ ' . e(t('common.add')) ?>
            </button>
            <?php if ($editing): ?>
              <a class="wt-btn wt-btn--ghost"
                 href="<?= e(wt_url('/admin/ptc.php')) ?>"><?= e(t('common.cancel')) ?></a>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <!-- List -->
      <section class="wt-admin-v2__list-section" data-reveal>
        <header class="wt-admin-v2__list-head">
          <h2 class="wt-admin-v2__list-title">📋 <?= e(t('admin.existing_items')) ?></h2>
          <span class="wt-muted"><?= count($rows) ?> <?= e(t('common.items')) ?></span>
        </header>

        <?php if (!$rows): ?>
          <div class="wt-admin-v2__empty">
            <span class="wt-admin-v2__empty-icon" aria-hidden="true">📺</span>
            <p><?= e(t('admin.empty_ptc')) ?></p>
          </div>
        <?php else: ?>
          <ul class="wt-admin-v2__entries">
            <?php foreach ($rows as $i => $r):
              $isActive = (int)$r['active'] === 1;
            ?>
              <li class="wt-admin-v2__entry <?= $isActive ? '' : 'is-inactive' ?>"
                  style="--idx:<?= (int)$i ?>">
                <div class="wt-admin-v2__entry-status">
                  <span class="wt-admin-v2__status-dot wt-admin-v2__status-dot--<?= $isActive ? 'on' : 'off' ?>"></span>
                </div>

                <div class="wt-admin-v2__entry-body">
                  <header class="wt-admin-v2__entry-head">
                    <strong><?= e($r['title']) ?></strong>
                    <small class="wt-mono">#<?= (int)$r['id'] ?></small>
                  </header>

                  <div class="wt-admin-v2__entry-meta">
                    <span>💰 <?= e(rtrim(rtrim(number_format((float)$r['reward_coins'], 4, '.', ''), '0'), '.')) ?></span>
                    <span>⏱ <?= (int)$r['duration_seconds'] ?>s</span>
                    <span>🛡 <?= (int)$r['daily_view_limit'] ?>/<?= e(t('admin.ptc.per_day')) ?></span>
                    <span>👁 <?= number_format((int)($r['total_views'] ?? 0), 0, '.', ' ') ?></span>
                  </div>
                </div>

                <div class="wt-admin-v2__entry-actions">
                  <a class="wt-btn wt-btn--xs wt-btn--ghost"
                     href="?edit=<?= (int)$r['id'] ?>" title="<?= e(t('common.edit')) ?>">✏️</a>

                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="wt-btn wt-btn--xs wt-btn--ghost"
                            title="<?= $isActive ? e(t('common.disable')) : e(t('common.enable')) ?>">
                      <?= $isActive ? '⏸' : '▶' ?>
                    </button>
                  </form>

                  <button type="button"
                          class="wt-btn wt-btn--xs wt-btn--danger"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.confirm_delete_title')) ?>"
                          data-confirm-body="<?= e(sprintf((string)t('admin.confirm_delete_body'), e($r['title']))) ?>"
                          data-confirm-ok="<?= e(t('common.delete')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-post="<?= e(wt_url('/admin/ptc.php')) ?>"
                          data-confirm-data='<?= e(json_encode(['_csrf' => csrf_token(), 'action' => 'delete', 'id' => (int)$r['id']])) ?>'
                          title="<?= e(t('common.delete')) ?>">
                    🗑
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
