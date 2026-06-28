<?php
/**
 * Wintaskly — /dashboard/notifications.php (V8 modernisé).
 *
 * Liste des notifications utilisateur. Marquées comme lues à
 * l'ouverture de la page (côté serveur, en UPDATE direct).
 *
 * Améliorations V8 :
 *   - Cards riches au lieu d'une liste plate
 *   - Icône par type de notification (faucet, withdraw, referral...)
 *   - Empty state soigné
 *   - Bulk-delete conservé
 *
 * Hooks JS préservés : data-bulk-toolbar, data-bulk-toggle-all,
 * data-bulk-delete, data-bulk-item.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle = t('notif.title');
$u  = current_user();
$db = db();

/* Marquer toutes les notifications comme lues à l'ouverture de la page */
$stmt = $db->prepare(
    "UPDATE notifications
        SET read_at = UTC_TIMESTAMP()
      WHERE user_id = ?
        AND read_at IS NULL"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$stmt->close();

$rows = [];
$stmt = $db->prepare(
    "SELECT id, type, title, body, url, read_at, created_at
       FROM notifications
      WHERE user_id = ?
        AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
      ORDER BY id DESC
      LIMIT 200"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
$rows = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Mapping type → icône emoji + couleur d'accent */
$notifIcons = [
    'faucet'      => ['icon' => '💧', 'color' => 'cyan'],
    'shortlink'   => ['icon' => '🔗', 'color' => 'violet'],
    'ptc'         => ['icon' => '📺', 'color' => 'orange'],
    'offerwall'   => ['icon' => '🎁', 'color' => 'green'],
    'referral'    => ['icon' => '🤝', 'color' => 'pink'],
    'withdraw'    => ['icon' => '💸', 'color' => 'red'],
    'leaderboard' => ['icon' => '🏆', 'color' => 'gold'],
    'security'    => ['icon' => '🔒', 'color' => 'red'],
    'system'      => ['icon' => 'ℹ️', 'color' => 'gray'],
    'welcome'     => ['icon' => '👋', 'color' => 'gold'],
];

$dashActive = 'notifications';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-dash__content wt-dash-v2__content">

      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">🔔 <?= e(t('notif.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('notif.title')) ?></h1>
        <p class="wt-muted"><?= e(t('notif.lead')) ?></p>
      </header>

      <?php if (!$rows): ?>
        <div class="wt-dash-v2__empty" data-reveal>
          <span class="wt-dash-v2__empty-icon" aria-hidden="true">🔔</span>
          <p><?= e(t('notif.empty')) ?></p>
        </div>
      <?php else: ?>

        <div class="wt-dash-v2__bulk" data-bulk-toolbar data-reveal>
          <label class="wt-checkbox">
            <input type="checkbox" data-bulk-toggle-all>
            <span><?= e(t('common.select_all')) ?></span>
          </label>
          <button type="button" class="wt-btn wt-btn--xs wt-btn--danger" data-bulk-delete
                  data-endpoint="<?= e(wt_url('/api/notification_delete.php')) ?>"
                  data-csrf="<?= e(csrf_token()) ?>">
            🗑 <?= e(t('common.delete_selected')) ?>
          </button>
        </div>

        <ul class="wt-dash-v2__notifs" data-reveal>
          <?php foreach ($rows as $i => $n):
            $meta = $notifIcons[$n['type']] ?? ['icon' => 'ℹ️', 'color' => 'gray'];
          ?>
            <li class="wt-dash-v2__notif wt-dash-v2__notif--<?= e($meta['color']) ?>"
                data-msg-id="<?= (int)$n['id'] ?>"
                style="--idx:<?= (int)$i ?>">
              <label class="wt-checkbox wt-dash-v2__notif-check">
                <input type="checkbox" data-bulk-item value="<?= (int)$n['id'] ?>">
                <span class="sr-only"><?= e(t('common.select')) ?></span>
              </label>

              <span class="wt-dash-v2__notif-icon" aria-hidden="true">
                <?= $meta['icon'] ?>
              </span>

              <div class="wt-dash-v2__notif-body">
                <div class="wt-dash-v2__notif-title">
                  <?php if ($n['url']): ?>
                    <a href="<?= e($n['url']) ?>"><?= e($n['title']) ?></a>
                  <?php else: ?>
                    <?= e($n['title']) ?>
                  <?php endif; ?>
                </div>
                <?php if ($n['body']): ?>
                  <div class="wt-dash-v2__notif-text"><?= e($n['body']) ?></div>
                <?php endif; ?>
                <small class="wt-dash-v2__notif-time">
                  <span data-fmt-time data-utc="<?= e($n['created_at']) ?>" data-format="relative">
                    <?= e(wt_format_datetime($n['created_at'])) ?>
                  </span>
                </small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>

      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
