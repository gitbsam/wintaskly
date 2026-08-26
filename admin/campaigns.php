<?php
/**
 * Wintaskly — admin/campaigns.php
 * ---------------------------------------------------------------------------
 * Gestion des campagnes d'acquisition.
 *
 * CE QUE CETTE PAGE PERMET DE DÉCIDER
 * -----------------------------------
 * L'intérêt n'est pas de lister des campagnes, c'est de répondre à une seule
 * question : quel site partenaire vaut son prix ?
 *
 * Trois indicateurs y répondent, et ils sont affichés côte à côte :
 *   • CLICS REÇUS contre clics annoncés — un écart important signale un
 *     emplacement survendu.
 *   • TAUX DE CONVERSION — du trafic qui arrive et repart aussitôt coûte
 *     autant que du trafic qui s'inscrit.
 *   • COÛT PAR MEMBRE ACQUIS — le seul chiffre qui compte vraiment. Un site
 *     cher qui convertit bien peut battre un site bon marché qui ne convertit
 *     pas.
 *
 * Le détail visiteur par visiteur sert à comprendre un chiffre anormal :
 * cinquante visites de deux secondes depuis la même empreinte, ce n'est pas
 * de l'audience, c'est du trafic artificiel — et cela se voit ici.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$db          = db();
$adminActive = 'campaigns';
$pageTitle   = t('admin.title') . ' — ' . t('admin.campaigns');

$notice = null;
$error  = null;

/** Code court, lisible, sans caractères ambigus (0/O, 1/I/l). */
function wt_campaign_gen_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 9; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        /* Collision très improbable, mais un code déjà pris dirigerait les
           visiteurs vers la mauvaise campagne : on vérifie. */
        $exists = db_one("SELECT id FROM campaigns WHERE code = ? LIMIT 1", [$code], 's');
    } while ($exists);
    return $code;
}

/* ---- Actions ---------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name   = trim((string) ($_POST['name'] ?? ''));
        $reward = (float) ($_POST['reward_coins'] ?? 0);

        if ($name === '') {
            $error = t('admin.camp.err_name');
        } else {
            $fields = [
                'name'             => $name,
                'site_name'        => trim((string) ($_POST['site_name'] ?? '')),
                'site_url'         => trim((string) ($_POST['site_url'] ?? '')),
                'placement'        => trim((string) ($_POST['placement'] ?? '')),
                'expected_clicks'  => max(0, (int) ($_POST['expected_clicks'] ?? 0)),
                'expected_seconds' => max(0, (int) ($_POST['expected_seconds'] ?? 0)),
                'budget_eur'       => max(0, (float) ($_POST['budget_eur'] ?? 0)),
                'reward_coins'     => max(0, $reward),
                'status'           => in_array($_POST['status'] ?? '', ['draft','active','paused','cancelled','ended'], true)
                                      ? (string) $_POST['status'] : 'draft',
                'starts_at'        => trim((string) ($_POST['starts_at'] ?? '')) ?: null,
                'ends_at'          => trim((string) ($_POST['ends_at'] ?? '')) ?: null,
                'notes'            => trim((string) ($_POST['notes'] ?? '')),
            ];

            try {
                if ($id > 0) {
                    $stmt = $db->prepare(
                        "UPDATE campaigns SET name=?, site_name=?, site_url=?, placement=?,
                                expected_clicks=?, expected_seconds=?, budget_eur=?,
                                reward_coins=?, status=?, starts_at=?, ends_at=?, notes=?
                          WHERE id=?"
                    );
                    $stmt->bind_param(
                        'ssssiiddssssi',
                        $fields['name'], $fields['site_name'], $fields['site_url'], $fields['placement'],
                        $fields['expected_clicks'], $fields['expected_seconds'], $fields['budget_eur'],
                        $fields['reward_coins'], $fields['status'], $fields['starts_at'],
                        $fields['ends_at'], $fields['notes'], $id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.camp.saved');
                } else {
                    /* L'année vient de la date de début si elle est fournie,
                       sinon de l'année courante. Elle n'est plus modifiable
                       ensuite : elle fait partie de l'URL distribuée. */
                    $year = (int) date('Y', $fields['starts_at'] ? strtotime($fields['starts_at']) : time());
                    $code = wt_campaign_gen_code();
                    $stmt = $db->prepare(
                        "INSERT INTO campaigns
                           (code, year, name, site_name, site_url, placement,
                            expected_clicks, expected_seconds, budget_eur, reward_coins,
                            status, starts_at, ends_at, notes)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $stmt->bind_param(
                        'sissssiiddssss',   // placement est du TEXTE : un 'i' l'aurait converti en 0
                        $code, $year, $fields['name'], $fields['site_name'], $fields['site_url'],
                        $fields['placement'], $fields['expected_clicks'], $fields['expected_seconds'],
                        $fields['budget_eur'], $fields['reward_coins'], $fields['status'],
                        $fields['starts_at'], $fields['ends_at'], $fields['notes']
                    );
                    $stmt->execute();
                    $stmt->close();
                    $notice = sprintf((string) t('admin.camp.created'), $code);
                }
            } catch (Throwable $e) {
                error_log('[Wintaskly campaign admin] ' . $e->getMessage());
                $error = t('admin.camp.err_db');
            }
        }
    }

    if ($action === 'status' && $id > 0) {
        $new = (string) ($_POST['value'] ?? '');
        if (in_array($new, ['draft','active','paused','cancelled','ended'], true)) {
            $stmt = $db->prepare("UPDATE campaigns SET status = ? WHERE id = ?");
            $stmt->bind_param('si', $new, $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.camp.status_changed');
        }
    }

    if ($action === 'delete' && $id > 0) {
        /* Les visites conservent la trace des primes versées : les effacer
           ferait disparaître des Coins de l'historique comptable. La clé
           étrangère est en SET NULL, la statistique globale reste donc
           cohérente même après suppression de la campagne. */
        $stmt = $db->prepare("DELETE FROM campaigns WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.camp.deleted');
    }
}

/* ---- Données ---------------------------------------------------------- */
$editId   = (int) ($_GET['edit']   ?? 0);
$statsId  = (int) ($_GET['stats']  ?? 0);
$editRow  = $editId  > 0 ? db_one("SELECT * FROM campaigns WHERE id = ?", [$editId], 'i')  : null;
$statsRow = $statsId > 0 ? db_one("SELECT * FROM campaigns WHERE id = ?", [$statsId], 'i') : null;

/* Liste avec agrégats. Un LEFT JOIN plutôt qu'une requête par campagne :
   une page d'administration qui fait N+1 requêtes devient inutilisable
   passé quelques dizaines de lignes. */
$rows = [];
try {
    $res = $db->query(
        "SELECT c.*,
                COUNT(v.id)                                    AS visits,
                SUM(v.converted_user_id IS NOT NULL)           AS conversions,
                SUM(v.rewarded_at IS NOT NULL)                 AS rewarded,
                ROUND(AVG(NULLIF(v.total_seconds,0)))          AS avg_seconds,
                ROUND(AVG(NULLIF(v.pages_viewed,0)), 1)        AS avg_pages
           FROM campaigns c
           LEFT JOIN campaign_visits v ON v.campaign_id = c.id
          GROUP BY c.id
          ORDER BY c.status = 'active' DESC, c.id DESC"
    );
    while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
} catch (Throwable $e) {
    $error = t('admin.camp.err_migration');
}

/* Détail visiteur, uniquement quand une campagne est ouverte : ces lignes
   peuvent être nombreuses, on ne les charge pas pour rien. */
$visits = [];
if ($statsRow) {
    try {
        $stmt = $db->prepare(
            "SELECT v.*, u.username
               FROM campaign_visits v
               LEFT JOIN users u ON u.id = v.converted_user_id
              WHERE v.campaign_id = ?
              ORDER BY v.last_seen_at DESC
              LIMIT 200"
        );
        $stmt->bind_param('i', $statsId);
        $stmt->execute();
        $visits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) { /* table absente : liste vide */ }
}

$baseUrl = rtrim((string) ($GLOBALS['WT_CONFIG']['base_url'] ?? ''), '/');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content">
      <header class="wt-admin-v2__head">
        <h1 class="wt-admin-v2__title"><?= e(t('admin.campaigns')) ?></h1>
        <p class="wt-admin-v2__lead"><?= e(t('admin.camp.lead')) ?></p>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

      <?php /* ---------- Détail d'une campagne ---------- */ ?>
      <?php if ($statsRow): ?>
        <section class="wt-admin-v2__card">
          <div class="wt-admin-v2__card-head">
            <h2><?= e(t('admin.camp.stats_of')) ?> « <?= e($statsRow['name']) ?> »</h2>
            <a class="wt-btn wt-btn--ghost wt-btn--sm" href="<?= e(wt_url('/admin/campaigns.php')) ?>">
              <?= e(t('common.back')) ?>
            </a>
          </div>

          <?php if (!$visits): ?>
            <p class="wt-admin-v2__empty"><?= e(t('admin.camp.no_visit')) ?></p>
          <?php else: ?>
            <div class="wt-camp-table">
              <table>
                <thead>
                  <tr>
                    <th><?= e(t('admin.camp.th_first')) ?></th>
                    <th><?= e(t('admin.camp.th_pages')) ?></th>
                    <th><?= e(t('admin.camp.th_time')) ?></th>
                    <th><?= e(t('admin.camp.th_member')) ?></th>
                    <th><?= e(t('admin.camp.th_reward')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($visits as $v): ?>
                    <tr>
                      <td><?= e(wt_format_datetime((string) $v['first_seen_at'], 'd/m/Y H:i')) ?></td>
                      <td><?= (int) $v['pages_viewed'] ?></td>
                      <td>
                        <?php
                          $s = (int) $v['total_seconds'];
                          echo $s >= 60 ? intdiv($s, 60) . ' min ' . ($s % 60) . ' s' : $s . ' s';
                        ?>
                      </td>
                      <td>
                        <?php if (!empty($v['username'])): ?>
                          <span class="wt-camp-yes"><?= e($v['username']) ?></span>
                        <?php else: ?>
                          <span class="wt-camp-no">—</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($v['rewarded_at'])): ?>
                          <span class="wt-camp-yes"><?= e(rtrim(rtrim(number_format((float) $v['reward_coins'], 2, ',', ' '), '0'), ',')) ?></span>
                        <?php elseif (!empty($v['converted_user_id'])): ?>
                          <span class="wt-camp-pending"><?= e(t('admin.camp.reward_pending')) ?></span>
                        <?php else: ?>
                          <span class="wt-camp-no">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="wt-admin-v2__hint"><?= e(t('admin.camp.privacy_note')) ?></p>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php /* ---------- Liste ---------- */ ?>
      <section class="wt-admin-v2__card">
        <div class="wt-admin-v2__card-head">
          <h2><?= e(t('admin.camp.list')) ?></h2>
        </div>

        <?php if (!$rows): ?>
          <p class="wt-admin-v2__empty"><?= e(t('admin.camp.empty')) ?></p>
        <?php else: ?>
          <ul class="wt-camp-list">
            <?php foreach ($rows as $c):
              $visits = (int) ($c['visits'] ?? 0);
              $conv   = (int) ($c['conversions'] ?? 0);
              $budget = (float) $c['budget_eur'];
              /* Coût par membre acquis : le chiffre qui tranche entre deux
                 emplacements. Sans conversion, on n'affiche rien plutôt
                 qu'une division par zéro déguisée. */
              $cpa    = $conv > 0 ? $budget / $conv : null;
              $rate   = $visits > 0 ? ($conv / $visits) * 100 : 0;
              $link   = $baseUrl . '/campagn/' . (int) $c['year'] . '/' . $c['code'];
            ?>
              <li class="wt-camp-item">
                <div class="wt-camp-item__head">
                  <strong><?= e($c['name']) ?></strong>
                  <span class="wt-camp-badge wt-camp-badge--<?= e($c['status']) ?>">
                    <?= e(t('admin.camp.st_' . $c['status'])) ?>
                  </span>
                  <?php if (!empty($c['site_name'])): ?>
                    <span class="wt-camp-item__site"><?= e($c['site_name']) ?></span>
                  <?php endif; ?>
                </div>

                <div class="wt-camp-link">
                  <input class="wt-input" type="text" readonly value="<?= e($link) ?>"
                         onclick="this.select()" aria-label="<?= e(t('admin.camp.link')) ?>">
                </div>

                <div class="wt-camp-kpis">
                  <span><?= e(t('admin.camp.k_visits')) ?> <strong><?= $visits ?></strong>
                    <?php if ((int) $c['expected_clicks'] > 0): ?>
                      <em>/ <?= (int) $c['expected_clicks'] ?></em>
                    <?php endif; ?>
                  </span>
                  <span><?= e(t('admin.camp.k_conv')) ?> <strong><?= $conv ?></strong>
                    <em><?= $visits > 0 ? '(' . number_format($rate, 1, ',', ' ') . ' %)' : '' ?></em>
                  </span>
                  <span><?= e(t('admin.camp.k_time')) ?> <strong><?= (int) ($c['avg_seconds'] ?? 0) ?> s</strong>
                    <?php if ((int) $c['expected_seconds'] > 0): ?>
                      <em>/ <?= (int) $c['expected_seconds'] ?> s</em>
                    <?php endif; ?>
                  </span>
                  <span><?= e(t('admin.camp.k_cpa')) ?>
                    <strong><?= $cpa !== null ? number_format($cpa, 2, ',', ' ') . ' €' : '—' ?></strong>
                  </span>
                </div>

                <div class="wt-camp-actions">
                  <a class="wt-btn wt-btn--ghost wt-btn--sm" href="?stats=<?= (int) $c['id'] ?>"><?= e(t('admin.camp.a_stats')) ?></a>
                  <a class="wt-btn wt-btn--ghost wt-btn--sm" href="?edit=<?= (int) $c['id'] ?>"><?= e(t('admin.camp.a_edit')) ?></a>
                  <?php foreach ([['active','a_activate'], ['paused','a_pause'], ['cancelled','a_cancel']] as [$st, $lbl]):
                    if ($c['status'] === $st) { continue; } ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="status">
                      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                      <input type="hidden" name="value" value="<?= e($st) ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.camp.' . $lbl)) ?></button>
                    </form>
                  <?php endforeach; ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('<?= e(t('admin.camp.del_confirm')) ?>')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.camp.a_delete')) ?></button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <?php /* ---------- Formulaire ---------- */ ?>
      <section class="wt-admin-v2__card">
        <div class="wt-admin-v2__card-head">
          <h2><?= e($editRow ? t('admin.camp.edit_title') : t('admin.camp.new_title')) ?></h2>
          <?php if ($editRow): ?>
            <a class="wt-btn wt-btn--ghost wt-btn--sm" href="<?= e(wt_url('/admin/campaigns.php')) ?>"><?= e(t('admin.camp.a_new')) ?></a>
          <?php endif; ?>
        </div>

        <form method="post" class="wt-camp-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_name')) ?></span>
            <input class="wt-input" type="text" name="name" required maxlength="120"
                   value="<?= e((string) ($editRow['name'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_site_name')) ?></span>
            <input class="wt-input" type="text" name="site_name" maxlength="120"
                   value="<?= e((string) ($editRow['site_name'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_site_url')) ?></span>
            <input class="wt-input" type="url" name="site_url" maxlength="255"
                   value="<?= e((string) ($editRow['site_url'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_placement')) ?></span>
            <input class="wt-input" type="text" name="placement" maxlength="120"
                   placeholder="<?= e(t('admin.camp.f_placement_ph')) ?>"
                   value="<?= e((string) ($editRow['placement'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_clicks')) ?></span>
            <input class="wt-input" type="number" name="expected_clicks" min="0"
                   value="<?= (int) ($editRow['expected_clicks'] ?? 0) ?>">
            <small class="wt-field__hint"><?= e(t('admin.camp.f_clicks_hint')) ?></small>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_seconds')) ?></span>
            <input class="wt-input" type="number" name="expected_seconds" min="0"
                   value="<?= (int) ($editRow['expected_seconds'] ?? 0) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_budget')) ?></span>
            <input class="wt-input" type="number" name="budget_eur" min="0" step="0.01"
                   value="<?= e(number_format((float) ($editRow['budget_eur'] ?? 0), 2, '.', '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_reward')) ?></span>
            <input class="wt-input" type="number" name="reward_coins" min="0" step="0.0001"
                   value="<?= e(number_format((float) ($editRow['reward_coins'] ?? 0), 4, '.', '')) ?>">
            <small class="wt-field__hint"><?= e(t('admin.camp.f_reward_hint')) ?></small>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_status')) ?></span>
            <select class="wt-input" name="status">
              <?php foreach (['draft','active','paused','cancelled','ended'] as $st): ?>
                <option value="<?= e($st) ?>" <?= ($editRow['status'] ?? 'draft') === $st ? 'selected' : '' ?>>
                  <?= e(t('admin.camp.st_' . $st)) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_starts')) ?></span>
            <input class="wt-input" type="datetime-local" name="starts_at"
                   value="<?= e($editRow['starts_at'] ? date('Y-m-d\TH:i', strtotime((string) $editRow['starts_at'])) : '') ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.camp.f_ends')) ?></span>
            <input class="wt-input" type="datetime-local" name="ends_at"
                   value="<?= e($editRow['ends_at'] ? date('Y-m-d\TH:i', strtotime((string) $editRow['ends_at'])) : '') ?>">
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('admin.camp.f_notes')) ?></span>
            <textarea class="wt-input" name="notes" rows="2"><?= e((string) ($editRow['notes'] ?? '')) ?></textarea>
          </label>

          <div class="wt-field--wide">
            <button class="wt-btn wt-btn--primary" type="submit">
              <?= e($editRow ? t('admin.camp.save') : t('admin.camp.create')) ?>
            </button>
          </div>
        </form>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
