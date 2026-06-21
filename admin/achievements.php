<?php
/**
 * Wintaskly — Admin · Achievements (badges)
 *
 * Gestion complète des succès : création, édition, suppression, activation.
 *
 * BLINDAGE MÉTRIQUE (liste blanche) :
 *   Le champ `metric` n'est JAMAIS un texte libre. Il est rendu comme un
 *   <select> peuplé depuis wt_ach_metrics() (source unique de vérité), et
 *   RE-VALIDÉ côté serveur via wt_ach_metric_valid() avant tout INSERT/
 *   UPDATE. Une métrique hors liste est rejetée → impossible de créer un
 *   achievement avec une typo qui ne se débloquerait jamais.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.achievements');
$adminActive = 'achievements';
$db          = db();
$notice      = null;
$error       = null;

$validMetrics = wt_ach_metrics();           // registre = liste blanche
$validTiers   = ['bronze', 'silver', 'gold', 'platinum', 'special'];

/* ====================== ACTIONS POST ====================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_config') {
        wt_config_set('achievements.enabled', !empty($_POST['enabled']) ? '1' : '0');
        $notice = t('admin.ach.saved');

    } elseif ($action === 'create' || $action === 'update') {
        // ----- Récupération + nettoyage -----
        $k       = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)($_POST['k'] ?? ''))));
        $metric  = trim((string)($_POST['metric'] ?? ''));
        $tier    = trim((string)($_POST['tier'] ?? 'bronze'));
        $title   = trim((string)($_POST['title'] ?? ''));
        $desc    = trim((string)($_POST['description'] ?? ''));
        $icon    = trim((string)($_POST['icon'] ?? ''));
        $thresh  = max(0, (float)($_POST['threshold'] ?? 1));
        $rCoins  = max(0, (float)($_POST['reward_coins'] ?? 0));
        $rXp     = max(0, (int)($_POST['reward_xp'] ?? 0));
        $sort    = (int)($_POST['sort_order'] ?? 0);
        $active  = !empty($_POST['active']) ? 1 : 0;

        // ----- VALIDATION LISTE BLANCHE (le blindage clé) -----
        $errors = [];
        if ($k === '') {
            $errors[] = t('admin.ach.err_key');
        }
        if (!wt_ach_metric_valid($metric)) {
            // Métrique hors registre → rejet ferme
            $errors[] = sprintf((string) t('admin.ach.err_metric'), e($metric));
        }
        if (!in_array($tier, $validTiers, true)) {
            $tier = 'bronze'; // valeur sûre par défaut
        }
        if ($title === '') {
            $errors[] = t('admin.ach.err_title');
        }
        // Icône : on garde max 1-2 caractères (emoji) pour éviter injection
        if (mb_strlen($icon) > 8) {
            $icon = mb_substr($icon, 0, 8);
        }

        if (!empty($errors)) {
            $error = implode(' ', $errors);
        } else {
            try {
                if ($action === 'create') {
                    $stmt = $db->prepare(
                        "INSERT INTO achievements
                           (k, metric, threshold, tier, title, description, icon,
                            reward_coins, reward_xp, sort_order, active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $descN = $desc === '' ? null : $desc;
                    $iconN = $icon === '' ? null : $icon;
                    $stmt->bind_param(
                        'ssdssssdiii',
                        $k, $metric, $thresh, $tier, $title, $descN, $iconN,
                        $rCoins, $rXp, $sort, $active
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.ach.created');
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare(
                        "UPDATE achievements SET
                            k = ?, metric = ?, threshold = ?, tier = ?, title = ?,
                            description = ?, icon = ?, reward_coins = ?, reward_xp = ?,
                            sort_order = ?, active = ?
                          WHERE id = ?"
                    );
                    $descN = $desc === '' ? null : $desc;
                    $iconN = $icon === '' ? null : $icon;
                    $stmt->bind_param(
                        'ssdssssdiiii',
                        $k, $metric, $thresh, $tier, $title, $descN, $iconN,
                        $rCoins, $rXp, $sort, $active, $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.ach.updated');
                }
            } catch (Throwable $ex) {
                // Probable doublon de clé `k` (UNIQUE)
                $error = t('admin.ach.err_dup');
                error_log('[Wintaskly ach admin] ' . $ex->getMessage());
            }
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM achievements WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.ach.deleted');
    }
}

/* ====================== LECTURE ====================== */
$enabled = (string) cfg('achievements.enabled', '0') === '1';

$rows = [];
if ($res = $db->query(
    "SELECT id, k, metric, threshold, tier, title, description, icon,
            reward_coins, reward_xp, sort_order, active
       FROM achievements ORDER BY sort_order ASC, id ASC"
)) {
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $res->free();
}

// Stats globales
$totalUnlocks = (int) (db_one("SELECT COUNT(*) c FROM user_achievements")['c'] ?? 0);

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🏆 <?= e(t('admin.ach.eyebrow')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.achievements')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.ach.lead')) ?></p>
        </div>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= $error /* déjà échappé */ ?></div><?php endif; ?>

      <!-- ============ CONFIG GLOBALE ============ -->
      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <form method="post" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_config">
          <label class="wt-checkbox" style="display:flex;gap:.75rem;align-items:center">
            <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>
                   style="transform:scale(1.4)">
            <span><strong><?= e(t('admin.ach.enable')) ?></strong></span>
          </label>
          <div class="wt-muted" style="font-size:.9rem">
            🎯 <?= e(sprintf((string) t('admin.ach.total_unlocks'), number_format($totalUnlocks, 0, '.', ' '))) ?>
          </div>
          <button class="wt-btn wt-btn--primary wt-btn--sm"><?= e(t('common.save')) ?></button>
        </form>
      </section>

      <!-- ============ FORMULAIRE CRÉATION ============ -->
      <details class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <summary style="cursor:pointer;font-weight:700;font-size:1.05rem">
          ➕ <?= e(t('admin.ach.add_title')) ?>
        </summary>
        <form method="post" style="margin-top:1rem">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">

          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_key')) ?> *</span>
              <input class="wt-input wt-mono" type="text" name="k" required
                     placeholder="faucet_50" pattern="[a-z0-9_]+" maxlength="60">
              <small class="wt-field__hint"><?= e(t('admin.ach.f_key_hint')) ?></small>
            </label>

            <!-- ===== LE SELECT FERMÉ : métriques en liste blanche ===== -->
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_metric')) ?> *</span>
              <select class="wt-input" name="metric" required>
                <option value=""><?= e(t('admin.ach.f_metric_choose')) ?></option>
                <?php foreach ($validMetrics as $mKey => $mLabel): ?>
                  <option value="<?= e($mKey) ?>"><?= e($mLabel) ?> (<?= e($mKey) ?>)</option>
                <?php endforeach; ?>
              </select>
              <small class="wt-field__hint"><?= e(t('admin.ach.f_metric_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_threshold')) ?> *</span>
              <input class="wt-input" type="number" step="0.0001" min="0" name="threshold" value="10" required>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_tier')) ?></span>
              <select class="wt-input" name="tier">
                <?php foreach ($validTiers as $tv): ?>
                  <option value="<?= e($tv) ?>"><?= e(ucfirst($tv)) ?></option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_title')) ?> *</span>
              <input class="wt-input" type="text" name="title" required maxlength="120"
                     placeholder="<?= e(t('admin.ach.f_title_ph')) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_icon')) ?></span>
              <input class="wt-input" type="text" name="icon" maxlength="8" placeholder="🏆">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_coins')) ?></span>
              <input class="wt-input" type="number" step="0.0001" min="0" name="reward_coins" value="0">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_xp')) ?></span>
              <input class="wt-input" type="number" min="0" name="reward_xp" value="0">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ach.f_sort')) ?></span>
              <input class="wt-input" type="number" name="sort_order" value="0">
            </label>
          </div>

          <label class="wt-field" style="margin-top:1rem">
            <span class="wt-field__label"><?= e(t('admin.ach.f_desc')) ?></span>
            <input class="wt-input" type="text" name="description" maxlength="255"
                   placeholder="<?= e(t('admin.ach.f_desc_ph')) ?>">
          </label>

          <label class="wt-checkbox" style="margin:1rem 0;display:flex;gap:.5rem;align-items:center">
            <input type="checkbox" name="active" value="1" checked>
            <span><?= e(t('admin.ach.f_active')) ?></span>
          </label>

          <div>
            <button class="wt-btn wt-btn--primary"><?= e(t('admin.ach.create_btn')) ?></button>
          </div>
        </form>
      </details>

      <!-- ============ LISTE DES ACHIEVEMENTS ============ -->
      <section class="wt-card wt-card--padded">
        <h2 style="margin-top:0">📋 <?= e(t('admin.ach.list_title')) ?> (<?= count($rows) ?>)</h2>

        <?php if (empty($rows)): ?>
          <p class="wt-muted"><?= e(t('admin.ach.empty')) ?></p>
        <?php else: ?>
          <div class="wt-table-wrap">
            <table class="wt-table">
              <thead>
                <tr>
                  <th></th>
                  <th><?= e(t('admin.ach.col_title')) ?></th>
                  <th><?= e(t('admin.ach.col_metric')) ?></th>
                  <th><?= e(t('admin.ach.col_threshold')) ?></th>
                  <th><?= e(t('admin.ach.col_reward')) ?></th>
                  <th><?= e(t('admin.ach.col_active')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $a):
                  $metricKnown = wt_ach_metric_valid($a['metric']);
                ?>
                  <tr>
                    <td style="font-size:1.3rem"><?= e((string)($a['icon'] ?? '🏅')) ?></td>
                    <td>
                      <strong><?= e($a['title']) ?></strong>
                      <code style="font-size:.7rem;opacity:.5;display:block"><?= e($a['k']) ?></code>
                    </td>
                    <td>
                      <?php if ($metricKnown): ?>
                        <span style="font-size:.85rem"><?= e(wt_ach_metric_label($a['metric'])) ?></span>
                      <?php else: ?>
                        <span style="color:#ef4444;font-size:.8rem" title="Métrique inconnue">
                          ⚠ <?= e($a['metric']) ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="wt-mono"><?= e(rtrim(rtrim(number_format((float)$a['threshold'],4,'.',''),'0'),'.')) ?></td>
                    <td class="wt-mono" style="font-size:.85rem">
                      💰<?= e(rtrim(rtrim(number_format((float)$a['reward_coins'],4,'.',''),'0'),'.')) ?>
                      <?php if ((int)$a['reward_xp'] > 0): ?> · ⭐<?= (int)$a['reward_xp'] ?><?php endif; ?>
                    </td>
                    <td><?= (int)$a['active'] === 1 ? '✅' : '⏸' ?></td>
                    <td style="white-space:nowrap">
                      <button type="button" class="wt-btn wt-btn--ghost wt-btn--xs"
                              onclick='wtAchEdit(<?= json_encode($a, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
                      <form method="post" style="display:inline"
                            onsubmit="return confirm('<?= e(t('admin.ach.confirm_del')) ?>')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="wt-btn wt-btn--ghost wt-btn--xs" style="color:#ef4444">🗑</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </section>
  </div>
</main>

<!-- ============ MODALE D'ÉDITION ============ -->
<dialog id="wtAchEditModal" style="border:none;border-radius:16px;padding:0;max-width:700px;width:92%;background:var(--wt-bg-elev,#131829);color:var(--wt-text,#e8eaf0)">
  <form method="post" style="padding:1.5rem">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="ach_edit_id">

    <h2 style="margin-top:0">✏️ <?= e(t('admin.ach.edit_title')) ?></h2>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem">
      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_key')) ?> *</span>
        <input class="wt-input wt-mono" type="text" name="k" id="ach_edit_k" required pattern="[a-z0-9_]+" maxlength="60">
      </label>

      <!-- Le même select fermé en édition -->
      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_metric')) ?> *</span>
        <select class="wt-input" name="metric" id="ach_edit_metric" required>
          <?php foreach ($validMetrics as $mKey => $mLabel): ?>
            <option value="<?= e($mKey) ?>"><?= e($mLabel) ?> (<?= e($mKey) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_threshold')) ?> *</span>
        <input class="wt-input" type="number" step="0.0001" min="0" name="threshold" id="ach_edit_threshold" required>
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_tier')) ?></span>
        <select class="wt-input" name="tier" id="ach_edit_tier">
          <?php foreach ($validTiers as $tv): ?>
            <option value="<?= e($tv) ?>"><?= e(ucfirst($tv)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_title')) ?> *</span>
        <input class="wt-input" type="text" name="title" id="ach_edit_title" required maxlength="120">
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_icon')) ?></span>
        <input class="wt-input" type="text" name="icon" id="ach_edit_icon" maxlength="8">
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_coins')) ?></span>
        <input class="wt-input" type="number" step="0.0001" min="0" name="reward_coins" id="ach_edit_coins">
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_xp')) ?></span>
        <input class="wt-input" type="number" min="0" name="reward_xp" id="ach_edit_xp">
      </label>

      <label class="wt-field">
        <span class="wt-field__label"><?= e(t('admin.ach.f_sort')) ?></span>
        <input class="wt-input" type="number" name="sort_order" id="ach_edit_sort">
      </label>
    </div>

    <label class="wt-field" style="margin-top:1rem">
      <span class="wt-field__label"><?= e(t('admin.ach.f_desc')) ?></span>
      <input class="wt-input" type="text" name="description" id="ach_edit_desc" maxlength="255">
    </label>

    <label class="wt-checkbox" style="margin:1rem 0;display:flex;gap:.5rem;align-items:center">
      <input type="checkbox" name="active" value="1" id="ach_edit_active">
      <span><?= e(t('admin.ach.f_active')) ?></span>
    </label>

    <div style="display:flex;gap:.75rem;justify-content:flex-end">
      <button type="button" class="wt-btn wt-btn--ghost" onclick="document.getElementById('wtAchEditModal').close()">
        <?= e(t('common.cancel')) ?>
      </button>
      <button type="submit" class="wt-btn wt-btn--primary"><?= e(t('common.save')) ?></button>
    </div>
  </form>
</dialog>

<script>
/* Pré-remplit la modale d'édition avec les données de l'achievement cliqué */
function wtAchEdit(a) {
  document.getElementById('ach_edit_id').value        = a.id;
  document.getElementById('ach_edit_k').value         = a.k;
  document.getElementById('ach_edit_metric').value    = a.metric;
  document.getElementById('ach_edit_threshold').value = parseFloat(a.threshold);
  document.getElementById('ach_edit_tier').value      = a.tier;
  document.getElementById('ach_edit_title').value     = a.title;
  document.getElementById('ach_edit_icon').value      = a.icon || '';
  document.getElementById('ach_edit_coins').value     = parseFloat(a.reward_coins);
  document.getElementById('ach_edit_xp').value        = parseInt(a.reward_xp, 10);
  document.getElementById('ach_edit_sort').value      = parseInt(a.sort_order, 10);
  document.getElementById('ach_edit_desc').value      = a.description || '';
  document.getElementById('ach_edit_active').checked  = (parseInt(a.active, 10) === 1);
  document.getElementById('wtAchEditModal').showModal();
}
</script>

<?php include __DIR__ . '/../footer.php'; ?>
