<?php
/**
 * Wintaskly — /admin/users.php
 *
 * Gestion des utilisateurs : liste, recherche, actions de modération.
 *
 * Actions disponibles (toutes via /api/admin_user_action.php) :
 *   • Activer / suspendre / bannir un compte
 *   • Forcer email vérifié (cas support)
 *   • Réinitialiser TOTP (clé perdue)
 *   • Annuler une suppression en attente
 *   • Promouvoir / rétrograder le rôle admin
 *   • Supprimer définitivement (purge totale, FK ON DELETE CASCADE)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

$pageTitle   = t('admin.users');
$adminActive = 'users';
$db          = db();
$me          = current_user();

/* ---- Recherche + pagination ---- */
$q       = trim((string)($_GET['q']   ?? ''));
$page    = max(1, (int)($_GET['p']   ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

/* Filtre status / role */
$fStatus = (string)($_GET['s'] ?? '');
$fRole   = (string)($_GET['r'] ?? '');
$pending = isset($_GET['pending']) ? 1 : 0;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($q !== '') {
    $where[]   = "(u.username LIKE ? OR u.email LIKE ?)";
    $like      = '%' . $q . '%';
    $params[]  = $like; $params[] = $like;
    $types    .= 'ss';
}
if (in_array($fStatus, ['active', 'pending', 'suspended', 'banned'], true)) {
    $where[]  = "u.status = ?";
    $params[] = $fStatus;
    $types   .= 's';
}
if (in_array($fRole, ['user', 'admin'], true)) {
    $where[]  = "u.role = ?";
    $params[] = $fRole;
    $types   .= 's';
}
if ($pending) {
    $where[] = "u.delete_requested_at IS NOT NULL";
}

$whereSql = implode(' AND ', $where);

/* Count total */
$sql = "SELECT COUNT(*) c FROM users u WHERE $whereSql";
$stmt = $db->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Liste paginée */
$sql = "SELECT u.id, u.username, u.email, u.role, u.status, u.coins, u.level,
               u.created_at, u.last_login_at, u.email_verified_at,
               u.totp_enabled, u.tfa_email_enabled, u.tfa_sms_enabled,
               u.delete_requested_at, u.country
          FROM users u
         WHERE $whereSql
         ORDER BY u.id DESC
         LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$params2 = $params;
$params2[] = $perPage;
$params2[] = $offset;
$types2 = $types . 'ii';
$stmt->bind_param($types2, ...$params2);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalPages = max(1, (int) ceil($total / $perPage));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
    <header data-reveal>
      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">👥 <?= e(t('admin.eyebrow_users')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.users')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.users.lead')) ?></p>
        </div>
      </header>
      <p class="wt-muted">
        <?= e(t('admin.users_lead')) ?>
        · <strong><?= (int)$total ?></strong> <?= e(t('admin.users_total')) ?>
      </p>
    </header>

    <!-- =============== FILTRES =============== -->
    <form class="wt-form wt-form--grid" method="get" data-reveal style="margin-bottom:1rem">
      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.users_search')) ?></span>
        <input class="wt-input wt-input--sm" type="search" name="q" value="<?= e($q) ?>" placeholder="username ou email">
      </label>
      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('common.status')) ?></span>
        <select class="wt-input wt-input--sm" name="s">
          <option value="">—</option>
          <?php foreach (['active','pending','suspended','banned'] as $s): ?>
            <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>>
              <?= e(t('user.status.' . $s)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.users_role')) ?></span>
        <select class="wt-input wt-input--sm" name="r">
          <option value="">—</option>
          <option value="user"  <?= $fRole === 'user'  ? 'selected' : '' ?>>user</option>
          <option value="admin" <?= $fRole === 'admin' ? 'selected' : '' ?>>admin</option>
        </select>
      </label>
      <label class="wt-field wt-field--check">
        <input type="checkbox" name="pending" value="1" <?= $pending ? 'checked' : '' ?>>
        <?= e(t('admin.users_pending_delete')) ?>
      </label>
      <div class="wt-form__actions wt-field--wide">
        <button type="submit" class="wt-btn wt-btn--primary wt-btn--xs"><?= e(t('common.filter')) ?></button>
        <a href="<?= e(wt_url('/admin/users.php')) ?>" class="wt-btn wt-btn--ghost wt-btn--xs"><?= e(t('common.reset')) ?></a>
      </div>
    </form>

    <!-- =============== LISTE =============== -->
    <div class="wt-table-wrap" data-reveal>
      <table class="wt-table">
        <thead>
          <tr>
            <th>#</th>
            <th><?= e(t('admin.users_col_user')) ?></th>
            <th><?= e(t('admin.users_col_status')) ?></th>
            <th><?= e(t('admin.users_col_2fa')) ?></th>
            <th><?= e(t('admin.users_col_coins')) ?></th>
            <th><?= e(t('admin.users_col_last_login')) ?></th>
            <th><?= e(t('admin.users_col_actions')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $row): ?>
          <?php
            $statusClass = $row['status'] === 'active'    ? 'wt-badge--completed'
                         : ($row['status'] === 'pending'  ? 'wt-badge--pending'
                         : 'wt-badge--refused');
            $isMe        = (int)$row['id'] === (int)$me['id'];
            $hasDel      = !empty($row['delete_requested_at']);
            $tfa         = (int)$row['totp_enabled'] + (int)$row['tfa_email_enabled'] + (int)$row['tfa_sms_enabled'];
          ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td>
              <strong><?= e($row['username']) ?></strong>
              <?php if ($row['role'] === 'admin'): ?>
                <span class="wt-pill-badge" style="background:#facc15;color:#000">admin</span>
              <?php endif; ?>
              <?php if ($isMe): ?>
                <span class="wt-pill-badge"><?= e(t('admin.users_you')) ?></span>
              <?php endif; ?>
              <br>
              <small class="wt-muted"><?= e($row['email']) ?></small>
              <?php if (empty($row['email_verified_at'])): ?>
                <small style="color:#facc15">· <?= e(t('admin.users_unverified')) ?></small>
              <?php endif; ?>
              <?php if ($row['country']): ?>
                <small class="wt-muted">· <?= e($row['country']) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <span class="wt-badge <?= $statusClass ?>">
                <?= e(t('user.status.' . $row['status'])) ?>
              </span>
              <?php if ($hasDel): ?>
                <br><small style="color:#ef4444">
                  ⏳ <?= e(t('admin.users_delete_pending')) ?>
                </small>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($tfa > 0): ?>
                <span class="wt-pill-badge" style="background:#22c55e;color:#000"><?= $tfa ?> 🔐</span>
              <?php else: ?>
                <span class="wt-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="wt-table__num">
              <?= e(number_format((float)$row['coins'], 2, '.', ' ')) ?>
              <br><small class="wt-muted">Lv <?= (int)$row['level'] ?></small>
            </td>
            <td>
              <?php if ($row['last_login_at']): ?>
                <small data-fmt-time data-utc="<?= e($row['last_login_at']) ?>">
                  <?= e(wt_format_datetime($row['last_login_at'], 'd/m H:i')) ?>
                </small>
              <?php else: ?>
                <small class="wt-muted">—</small>
              <?php endif; ?>
            </td>
            <td class="wt-table__actions">
              <?php if (!$isMe): ?>
                <!-- Status toggles -->
                <?php if ($row['status'] !== 'active'): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_activate_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_activate_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"activate","user_id":<?= (int)$row['id'] ?>}'>
                    ✅ <?= e(t('admin.users_activate')) ?>
                  </button>
                <?php endif; ?>
                <?php if ($row['status'] !== 'suspended'): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_suspend_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_suspend_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('admin.users_suspend')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"suspend","user_id":<?= (int)$row['id'] ?>}'>
                    ⏸️
                  </button>
                <?php endif; ?>
                <?php if ($row['status'] !== 'banned'): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_ban_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_ban_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('admin.users_ban')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-typed="BAN"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"ban","user_id":<?= (int)$row['id'] ?>}'>
                    🚫
                  </button>
                <?php endif; ?>

                <!-- Helpers -->
                <?php if (empty($row['email_verified_at'])): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_verify_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_verify_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"verify_email","user_id":<?= (int)$row['id'] ?>}'>
                    ✉️✓
                  </button>
                <?php endif; ?>

                <?php if ((int)$row['totp_enabled'] === 1): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_reset_totp_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_reset_totp_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"reset_totp","user_id":<?= (int)$row['id'] ?>}'>
                    🔓 2FA
                  </button>
                <?php endif; ?>

                <?php if ($hasDel): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_cancel_delete_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_cancel_delete_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"cancel_delete","user_id":<?= (int)$row['id'] ?>}'>
                    ↩️
                  </button>
                <?php endif; ?>

                <?php if ($row['role'] === 'user'): ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_promote_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_promote_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-typed="PROMOTE"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"promote","user_id":<?= (int)$row['id'] ?>}'>
                    👑
                  </button>
                <?php else: ?>
                  <button class="wt-btn wt-btn--xs wt-btn--ghost"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.users_demote_title')) ?>"
                          data-confirm-body="<?= e(t('admin.users_demote_body', ['u' => $row['username']])) ?>"
                          data-confirm-ok="<?= e(t('common.confirm')) ?>"
                          data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                          data-confirm-data='{"action":"demote","user_id":<?= (int)$row['id'] ?>}'>
                    👤
                  </button>
                <?php endif; ?>

                <button class="wt-btn wt-btn--xs wt-btn--ghost"
                        data-confirm
                        data-confirm-title="<?= e(t('admin.users_delete_title')) ?>"
                        data-confirm-body="<?= e(t('admin.users_delete_body', ['u' => $row['username']])) ?>"
                        data-confirm-ok="<?= e(t('admin.users_delete_ok')) ?>"
                        data-confirm-ok-class="wt-btn--danger"
                        data-confirm-typed="DELETE"
                        data-confirm-post="<?= e(wt_url('/api/admin_user_action.php')) ?>"
                        data-confirm-data='{"action":"hard_delete","user_id":<?= (int)$row['id'] ?>}'>
                  🗑️
                </button>
              <?php else: ?>
                <span class="wt-muted"><?= e(t('admin.users_self_protected')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($users)): ?>
          <tr><td colspan="7" style="text-align:center;padding:2rem">
            <span class="wt-muted"><?= e(t('admin.users_empty')) ?></span>
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <nav class="wt-form__actions" style="justify-content:center;margin-top:1rem" data-reveal>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <?php
            $u = $_GET;
            $u['p'] = $i;
            $href = wt_url('/admin/users.php') . '?' . http_build_query($u);
          ?>
          <a class="wt-btn wt-btn--xs <?= $i === $page ? 'wt-btn--primary' : 'wt-btn--ghost' ?>"
             href="<?= e($href) ?>"><?= $i ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>

  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
