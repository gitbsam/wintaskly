<?php
/**
 * Wintaskly — Admin · Cron & tâches planifiées (V8 enrichi)
 *
 * Améliorations vs version initiale :
 *   - Dashboard santé (last run, succès 24h, erreurs 24h, durée moy.)
 *   - Alerte rouge si > 60min sans run (cron probablement cassé)
 *   - URL avec bouton copier + bouton tester
 *   - Tâches avec bouton "Exécuter cette tâche" + ETA prochain run
 *   - Logs filtrables par statut + par tâche + pagination
 *   - Détail d'un run dans modal (résumé/erreur complets)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/cron.php';

$adminUser   = require_admin();
$pageTitle   = t('admin.title') . ' — ' . t('admin.cron');
$adminActive = 'cron';
$db          = db();
$notice      = null;
$error       = null;

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    wt_cron_load_tasks();

    if ($action === 'rotate_token') {
        $newTok = wt_cron_rotate_token();
        $notice = sprintf((string) t('admin.cron.token_rotated'), $newTok);
    } elseif ($action === 'run_now') {
        $report = wt_cron_run(force: true);
        $notice = sprintf((string) t('admin.cron.forced_done'), count($report));
    } elseif ($action === 'run_one') {
        $key = (string)($_POST['task'] ?? '');
        try {
            $r = wt_cron_run_one($key, force: true);
            if ($r['status'] === 'success') {
                $notice = sprintf((string) t('admin.cron.task_ok'), $key, $r['summary'] ?? '');
            } elseif ($r['status'] === 'error') {
                $error = sprintf((string) t('admin.cron.task_err'), $key, $r['error'] ?? '');
            } else {
                $notice = sprintf((string) t('admin.cron.task_skip'), $key);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'clear_logs') {
        $db->query("DELETE FROM cron_runs");
        $notice = t('admin.cron.logs_cleared');
    }
}

/* ====================== DONNÉES ====================== */
$token = (string) cfg('cron.token', '');
if ($token === '') {
    $token = wt_cron_rotate_token();
    $notice = ($notice ? $notice . ' · ' : '') . t('admin.cron.token_init');
}

wt_cron_load_tasks();
$tasks = $GLOBALS['wt_cron_tasks'] ?? [];

/* --- Dashboard santé : stats 24h --- */
$health = [
    'last_run_at'  => null,
    'success_24h'  => 0,
    'error_24h'    => 0,
    'skipped_24h'  => 0,
    'avg_ms_24h'   => 0,
    'is_stale'     => true,
];

$row = $db->query(
    "SELECT MAX(started_at) m FROM cron_runs"
)->fetch_assoc();
$health['last_run_at'] = $row['m'] ?? null;
if ($health['last_run_at']) {
    $health['is_stale'] = (time() - strtotime($health['last_run_at'] . ' UTC')) > 3900; // > 65 min
}

$row = $db->query(
    "SELECT
        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) ok,
        SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END) err,
        SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) skp,
        COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0) avg_s
      FROM cron_runs
     WHERE started_at >= UTC_TIMESTAMP() - INTERVAL " . WT_PERIOD_CRON_HEALTH_HOURS . " HOUR"
)->fetch_assoc();
$health['success_24h'] = (int)($row['ok'] ?? 0);
$health['error_24h']   = (int)($row['err'] ?? 0);
$health['skipped_24h'] = (int)($row['skp'] ?? 0);
$health['avg_ms_24h']  = (int)((float)($row['avg_s'] ?? 0) * 1000);

$totalAttempts = $health['success_24h'] + $health['error_24h'];
$successRate   = $totalAttempts > 0
              ? round(($health['success_24h'] / $totalAttempts) * 100, 1)
              : null;

/* --- Filtres logs : ?fs=all|success|error|skipped & ?ft=task_key --- */
$fs = (string)($_GET['fs'] ?? 'all');
if (!in_array($fs, ['all', 'success', 'error', 'skipped'], true)) $fs = 'all';
$ft = (string)($_GET['ft'] ?? 'all');

$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset  = ($pageNum - 1) * $perPage;

/* Compteurs par statut pour pills */
$row = $db->query(
    "SELECT status, COUNT(*) c FROM cron_runs GROUP BY status"
)->fetch_all(MYSQLI_ASSOC);
$counts = ['all' => 0, 'success' => 0, 'error' => 0, 'skipped' => 0, 'running' => 0];
foreach ($row as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
}

/* Distinct task keys disponibles dans les logs */
$taskKeys = [];
if ($res = $db->query("SELECT DISTINCT task FROM cron_runs ORDER BY task ASC")) {
    while ($r = $res->fetch_assoc()) $taskKeys[] = $r['task'];
    $res->free();
}

/* Requête runs filtrée */
$where = [];
$bindTypes = '';
$bindVals  = [];
if ($fs !== 'all') {
    $where[] = 'status = ?';
    $bindTypes .= 's';
    $bindVals[] = $fs;
}
if ($ft !== 'all' && $ft !== '') {
    $where[] = 'task = ?';
    $bindTypes .= 's';
    $bindVals[] = $ft;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

/* Compte filtré pour pagination */
$cntSql = "SELECT COUNT(*) c FROM cron_runs" . $whereSql;
if ($bindTypes) {
    $stmt = $db->prepare($cntSql);
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $totalFiltered = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
} else {
    $totalFiltered = (int)($db->query($cntSql)->fetch_assoc()['c'] ?? 0);
}
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));

$runs    = [];
$listSql = "SELECT id, task, started_at, finished_at, status, summary, error
              FROM cron_runs" . $whereSql . "
             ORDER BY id DESC
             LIMIT ? OFFSET ?";
$stmt = $db->prepare($listSql);
$bindTypes2 = $bindTypes . 'ii';
$bindVals2  = $bindVals;
$bindVals2[] = $perPage;
$bindVals2[] = $offset;
$stmt->bind_param($bindTypes2, ...$bindVals2);
$stmt->execute();
$res = $stmt->get_result();
$runs = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* URL publique pour configurer le cron du serveur */
$baseUrl = defined('WT_BASE_URL') ? rtrim(WT_BASE_URL, '/') : '';
$cronUrl = $baseUrl . '/api/cron.php?token=' . urlencode($token);

/* Helpers */
$fmtDuration = static function (int $sec): string {
    if ($sec >= 86400) return floor($sec / 86400) . ' j';
    if ($sec >= 3600)  return floor($sec / 3600) . ' h';
    if ($sec >= 60)    return floor($sec / 60) . ' min';
    return $sec . ' s';
};

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content wt-admin-cron" data-reveal>
    <header class="wt-admin-cron__header">
      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🕐 <?= e(t('admin.eyebrow_cron')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.cron')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.cron.lead')) ?></p>
        </div>
      </header>
      <p class="wt-page__lead"><?= e(t('admin.cron.lead')) ?></p>
    </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

    <!-- =================== ALERTE SANTÉ =================== -->
    <?php if ($health['is_stale'] && $health['last_run_at']): ?>
      <div class="wt-alert wt-alert--error wt-admin-cron__alert">
        <strong>⚠️ <?= e(t('admin.cron.alert_stale_title')) ?></strong>
        <p><?= e(t('admin.cron.alert_stale_text')) ?></p>
        <small>
          <?= e(t('admin.cron.alert_last')) ?>
          <time data-fmt-time data-utc="<?= e($health['last_run_at']) ?>" data-format="relative">
            <?= e(wt_format_datetime($health['last_run_at'])) ?>
          </time>
        </small>
      </div>
    <?php elseif (!$health['last_run_at']): ?>
      <div class="wt-alert wt-alert--info wt-admin-cron__alert">
        <strong>ℹ️ <?= e(t('admin.cron.alert_never_title')) ?></strong>
        <p><?= e(t('admin.cron.alert_never_text')) ?></p>
      </div>
    <?php endif; ?>

    <!-- =================== DASHBOARD SANTÉ =================== -->
    <section class="wt-admin-cron__health">
      <article class="wt-admin-cron__stat">
        <small><?= e(t('admin.cron.stat_last_run')) ?></small>
        <strong>
          <?php if ($health['last_run_at']): ?>
            <time data-fmt-time data-utc="<?= e($health['last_run_at']) ?>" data-format="relative">
              <?= e(date('H:i', strtotime($health['last_run_at']))) ?>
            </time>
          <?php else: ?>
            —
          <?php endif; ?>
        </strong>
      </article>
      <article class="wt-admin-cron__stat wt-admin-cron__stat--ok">
        <small><?= e(t('admin.cron.stat_success_24h')) ?></small>
        <strong><?= (int)$health['success_24h'] ?></strong>
      </article>
      <article class="wt-admin-cron__stat <?= $health['error_24h'] > 0 ? 'wt-admin-cron__stat--err' : '' ?>">
        <small><?= e(t('admin.cron.stat_error_24h')) ?></small>
        <strong><?= (int)$health['error_24h'] ?></strong>
      </article>
      <article class="wt-admin-cron__stat">
        <small><?= e(t('admin.cron.stat_success_rate')) ?></small>
        <strong><?= $successRate !== null ? $successRate . ' %' : '—' ?></strong>
      </article>
      <article class="wt-admin-cron__stat">
        <small><?= e(t('admin.cron.stat_avg_duration')) ?></small>
        <strong><?= $health['avg_ms_24h'] > 0 ? $health['avg_ms_24h'] . ' ms' : '—' ?></strong>
      </article>
    </section>

    <!-- =================== URL + TOKEN =================== -->
    <section class="wt-admin-cron__card">
      <header class="wt-admin-cron__card-head">
        <h2><?= e(t('admin.cron.url_title')) ?></h2>
        <p class="wt-muted"><?= e(t('admin.cron.url_lead')) ?></p>
      </header>

      <div class="wt-admin-cron__url-block">
        <label class="wt-admin-cron__url-label"><?= e(t('admin.cron.url_field')) ?></label>
        <div class="wt-admin-cron__url-input">
          <input type="text" readonly
                 value="<?= e($cronUrl) ?>"
                 data-cron-url
                 class="wt-input wt-admin-cron__url-text">
          <button type="button" class="wt-btn wt-btn--xs wt-btn--ghost"
                  data-cron-copy
                  data-copy-target="[data-cron-url]"
                  data-copy-label="<?= e(t('admin.cron.copied')) ?>">
            📋 <?= e(t('admin.cron.copy')) ?>
          </button>
          <a class="wt-btn wt-btn--xs wt-btn--ghost"
             href="<?= e($cronUrl) ?>"
             target="_blank"
             rel="noopener noreferrer">
            ↗ <?= e(t('admin.cron.test')) ?>
          </a>
        </div>
      </div>

      <details class="wt-admin-cron__details" open>
        <summary><?= e(t('admin.cron.crontab_show')) ?></summary>

        <!-- =====================================================================
             COMMANDE OFFICIELLE LWS (à copier-coller dans l'interface cron LWS)

             LWS demande UNIQUEMENT la commande à exécuter (pas la planification
             */5 * * * * — c'est le panneau LWS qui configure l'horaire via UI).

             On ajoute -H "Cache-Control: no-cache" pour forcer LWS Varnish à ne
             PAS servir une réponse cachée du cron (sinon il ne s'exécute jamais
             pour de vrai côté backend). C'est l'incantation officielle LWS.
             ===================================================================== -->
        <div style="margin-top:1rem">
          <strong style="display:block;margin-bottom:.4rem">
            🏢 <?= e(t('admin.cron.lws_label')) ?>
          </strong>
          <div class="wt-admin-cron__url-input">
            <input type="text" readonly
                   value='curl -s -H "Cache-Control: no-cache" "<?= e($cronUrl) ?>" > /dev/null'
                   data-cron-lws
                   class="wt-input wt-admin-cron__url-text wt-mono"
                   style="font-size:.82rem">
            <button type="button" class="wt-btn wt-btn--xs wt-btn--primary"
                    data-cron-copy
                    data-copy-target="[data-cron-lws]"
                    data-copy-label="<?= e(t('admin.cron.copied')) ?>">
              📋 <?= e(t('admin.cron.copy')) ?>
            </button>
          </div>
          <p class="wt-muted" style="font-size:.85rem;margin-top:.4rem">
            <?= e(t('admin.cron.lws_hint')) ?>
          </p>
        </div>

        <!-- =====================================================================
             Format crontab générique (pour serveurs dédiés / VPS où l'admin
             a accès direct au fichier crontab via `crontab -e`).
             ===================================================================== -->
        <div style="margin-top:1.5rem">
          <strong style="display:block;margin-bottom:.4rem">
            🖥 <?= e(t('admin.cron.generic_label')) ?>
          </strong>
          <pre class="wt-admin-cron__crontab"><code>*/5 * * * * curl -s -H "Cache-Control: no-cache" "<?= e($cronUrl) ?>" > /dev/null</code></pre>
          <p class="wt-muted" style="font-size:.85rem">
            <?= e(t('admin.cron.crontab_hint')) ?>
          </p>
        </div>
      </details>

      <footer class="wt-admin-cron__card-actions">
        <form method="post" class="wt-inline"
              onsubmit="return confirm('<?= e(t('admin.cron.confirm_rotate')) ?>')">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="rotate_token">
          <button class="wt-btn wt-btn--xs wt-btn--ghost">
            ↻ <?= e(t('admin.cron.btn_rotate')) ?>
          </button>
        </form>
        <form method="post" class="wt-inline">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="run_now">
          <button class="wt-btn wt-btn--xs wt-btn--primary">
            ▶ <?= e(t('admin.cron.btn_run_all')) ?>
          </button>
        </form>
      </footer>
    </section>

    <!-- =================== TÂCHES =================== -->
    <section class="wt-admin-cron__card">
      <header class="wt-admin-cron__card-head">
        <h2><?= e(t('admin.cron.tasks_title')) ?> <small class="wt-muted">(<?= count($tasks) ?>)</small></h2>
        <p class="wt-muted"><?= e(t('admin.cron.tasks_lead')) ?></p>
      </header>

      <?php if (!$tasks): ?>
        <div class="wt-admin-cron__empty">
          <span aria-hidden="true">📭</span>
          <p><?= e(t('admin.cron.tasks_empty')) ?></p>
        </div>
      <?php else: ?>
        <ul class="wt-admin-cron__tasks">
          <?php foreach ($tasks as $key => $task):
              $lastTs = wt_cron_last_success($key);
              $now    = time();
              $ago    = $lastTs ? ($now - $lastTs) : null;
              $due    = $ago === null || $ago >= $task['every'];
              $nextTs = $lastTs ? ($lastTs + $task['every']) : $now;
              $eta    = max(0, $nextTs - $now);
          ?>
            <li class="wt-admin-cron__task <?= $due ? 'is-due' : 'is-fresh' ?>">
              <div class="wt-admin-cron__task-main">
                <div class="wt-admin-cron__task-name">
                  <strong><?= e($key) ?></strong>
                  <?php if ($due): ?>
                    <span class="wt-pill wt-pill--warn">⏰ <?= e(t('admin.cron.task_due')) ?></span>
                  <?php else: ?>
                    <span class="wt-pill wt-pill--ok">✓ <?= e(t('admin.cron.task_fresh')) ?></span>
                  <?php endif; ?>
                </div>
                <div class="wt-admin-cron__task-meta">
                  <span>
                    <?= e(t('admin.cron.task_freq')) ?>:
                    <code><?= e($fmtDuration((int)$task['every'])) ?></code>
                  </span>
                  <span>
                    <?= e(t('admin.cron.task_last')) ?>:
                    <?php if ($lastTs): ?>
                      <time data-fmt-time data-utc="<?= e(gmdate('Y-m-d H:i:s', $lastTs)) ?>"
                            data-format="relative">
                        <?= e(date('d/m H:i', $lastTs)) ?>
                      </time>
                    <?php else: ?>
                      <span class="wt-muted"><?= e(t('admin.cron.task_never')) ?></span>
                    <?php endif; ?>
                  </span>
                  <span>
                    <?= e(t('admin.cron.task_next')) ?>:
                    <?php if (!$due && $eta > 0): ?>
                      <strong><?= e($fmtDuration($eta)) ?></strong>
                    <?php else: ?>
                      <strong class="wt-admin-cron__task-now"><?= e(t('admin.cron.task_now')) ?></strong>
                    <?php endif; ?>
                  </span>
                </div>
              </div>
              <div class="wt-admin-cron__task-action">
                <form method="post" class="wt-inline">
                  <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="run_one">
                  <input type="hidden" name="task"   value="<?= e($key) ?>">
                  <button class="wt-btn wt-btn--xs <?= $due ? 'wt-btn--primary' : 'wt-btn--ghost' ?>">
                    ▶ <?= e(t('admin.cron.task_run')) ?>
                  </button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- =================== LOGS =================== -->
    <section class="wt-admin-cron__card">
      <header class="wt-admin-cron__card-head">
        <h2>
          <?= e(t('admin.cron.logs_title')) ?>
          <small class="wt-muted">(<?= (int)$totalFiltered ?>)</small>
        </h2>

        <form method="post" class="wt-inline" style="margin-left:auto"
              onsubmit="return confirm('<?= e(t('admin.cron.confirm_clear_logs')) ?>')">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="clear_logs">
          <button class="wt-btn wt-btn--xs wt-btn--danger">
            🗑 <?= e(t('admin.cron.btn_clear_logs')) ?>
          </button>
        </form>
      </header>

      <!-- Filtres : statut -->
      <nav class="wt-admin-cron__filters" aria-label="<?= e(t('admin.cron.filter_status')) ?>">
        <?php
          $ftKeep = $ft !== 'all' ? '&ft=' . urlencode($ft) : '';
          $filterDefs = [
            'all'     => [t('admin.cron.filter_all'),     'all'],
            'success' => '✓ ' . t('admin.cron.filter_success'),
            'error'   => '✗ ' . t('admin.cron.filter_error'),
            'skipped' => '⏭ ' . t('admin.cron.filter_skipped'),
          ];
          foreach ($filterDefs as $key => $label):
              $count = $counts[$key] ?? 0;
              $href  = wt_url('/admin/cron.php?fs=' . urlencode($key) . $ftKeep);
        ?>
          <a class="wt-admin-cron__filter <?= $fs === $key ? 'is-active' : '' ?>" href="<?= e($href) ?>">
            <?= e(is_array($label) ? $label[0] : $label) ?>
            <span class="wt-admin-cron__filter-count wt-admin-cron__filter-count--<?= e($key) ?>"><?= (int)$count ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <!-- Filtres : tâche -->
      <?php if (count($taskKeys) > 1): ?>
        <nav class="wt-admin-cron__filters wt-admin-cron__filters--small"
             aria-label="<?= e(t('admin.cron.filter_task')) ?>">
          <a class="wt-admin-cron__filter <?= $ft === 'all' ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/admin/cron.php?fs=' . urlencode($fs) . '&ft=all')) ?>">
            <?= e(t('admin.cron.filter_all_tasks')) ?>
          </a>
          <?php foreach ($taskKeys as $tk): ?>
            <a class="wt-admin-cron__filter <?= $ft === $tk ? 'is-active' : '' ?>"
               href="<?= e(wt_url('/admin/cron.php?fs=' . urlencode($fs) . '&ft=' . urlencode($tk))) ?>">
              <code><?= e($tk) ?></code>
            </a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <?php if (!$runs): ?>
        <div class="wt-admin-cron__empty">
          <span aria-hidden="true">📋</span>
          <p><?= e(t('admin.cron.logs_empty')) ?></p>
        </div>
      <?php else: ?>
        <ul class="wt-admin-cron__logs">
          <?php foreach ($runs as $r):
              $dur = $r['finished_at']
                   ? strtotime($r['finished_at']) - strtotime($r['started_at'])
                   : null;
              $hasDetail = !empty($r['error']) || (!empty($r['summary']) && strlen($r['summary']) > 80);
          ?>
            <li class="wt-admin-cron__log wt-admin-cron__log--<?= e($r['status']) ?>">
              <div class="wt-admin-cron__log-meta">
                <time data-fmt-time data-utc="<?= e($r['started_at']) ?>">
                  <?= e(wt_format_datetime($r['started_at'])) ?>
                </time>
                <code class="wt-admin-cron__log-task"><?= e($r['task']) ?></code>
              </div>

              <div class="wt-admin-cron__log-body">
                <?php if (!empty($r['error'])): ?>
                  <strong class="wt-admin-cron__log-error"><?= e($r['error']) ?></strong>
                <?php elseif (!empty($r['summary'])): ?>
                  <span class="wt-admin-cron__log-summary"><?= e($r['summary']) ?></span>
                <?php else: ?>
                  <span class="wt-muted">—</span>
                <?php endif; ?>
              </div>

              <div class="wt-admin-cron__log-side">
                <?php
                  $statusLabel = match ($r['status']) {
                      'success' => '✓ ' . t('admin.cron.filter_success'),
                      'error'   => '✗ ' . t('admin.cron.filter_error'),
                      'skipped' => '⏭ ' . t('admin.cron.filter_skipped'),
                      default   => $r['status'],
                  };
                ?>
                <span class="wt-admin-cron__log-status"><?= e($statusLabel) ?></span>
                <small><?= $dur !== null ? (int)$dur . ' s' : '—' ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($totalPages > 1): ?>
          <nav class="wt-admin-cron__pagination" aria-label="<?= e(t('admin.cron.pagination')) ?>">
            <?php
              $linkBase = wt_url('/admin/cron.php?fs=' . urlencode($fs)
                              . ($ft !== 'all' ? '&ft=' . urlencode($ft) : '')
                              . '&p=');
            ?>
            <a class="wt-btn wt-btn--xs wt-btn--ghost <?= $pageNum <= 1 ? 'is-disabled' : '' ?>"
               <?= $pageNum > 1 ? 'href="' . e($linkBase . ($pageNum - 1)) . '"' : '' ?>>
              ← <?= e(t('common.previous')) ?>
            </a>
            <span class="wt-admin-cron__page-info">
              <?= e(sprintf((string) t('admin.cron.page_info'), $pageNum, $totalPages)) ?>
            </span>
            <a class="wt-btn wt-btn--xs wt-btn--ghost <?= $pageNum >= $totalPages ? 'is-disabled' : '' ?>"
               <?= $pageNum < $totalPages ? 'href="' . e($linkBase . ($pageNum + 1)) . '"' : '' ?>>
              <?= e(t('common.next')) ?> →
            </a>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>

  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
