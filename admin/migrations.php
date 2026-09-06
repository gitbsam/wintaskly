<?php
/**
 * Wintaskly — Admin · Migrations SQL
 *
 * Applique les fichiers de sql/ sans passer par phpMyAdmin, et garde la
 * trace de ce qui a déjà tourné dans `applied_migrations`.
 *
 * PRUDENCE — cette page exécute du SQL. Trois garde-fous :
 *
 *   1. Le nom de fichier n'est jamais utilisé tel quel. Il est comparé à
 *      la liste des fichiers réellement présents dans sql/ ; tout ce qui
 *      ne s'y trouve pas est rejeté. Aucune traversée de répertoire
 *      possible, même en trafiquant le formulaire.
 *   2. Une migration déjà enregistrée n'est jamais rejouée. Beaucoup de
 *      ces fichiers ne sont pas idempotents (INSERT sans IGNORE, ALTER
 *      sans test d'existence) : les relancer casserait des données.
 *   3. Sur une installation existante, la table d'historique est vide
 *      alors que la plupart des migrations ont déjà tourné. D'où
 *      l'action « marquer comme appliquée » : elle enregistre sans
 *      exécuter. À utiliser pour rattraper l'historique, jamais pour
 *      sauter une migration réellement nécessaire.
 *
 * schema.sql est traité à part : il est idempotent par construction
 * (CREATE TABLE IF NOT EXISTS, INSERT IGNORE, ALTER conditionnels) et
 * peut donc être rejoué à volonté.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = 'Migrations SQL — Wintaskly';
$adminActive = 'migrations';
$db          = db();
$sqlDir      = dirname(__DIR__) . '/sql';

$notice = null;
$error  = null;
$report = [];   // Détail de la dernière exécution

/* ------------------------------------------------------------------
 * Inventaire des fichiers présents sur le disque
 * ------------------------------------------------------------------ */
$files = [];
foreach (glob($sqlDir . '/*.sql') ?: [] as $path) {
    $files[basename($path)] = [
        'name'  => basename($path),
        'size'  => (int) filesize($path),
        'mtime' => (int) filemtime($path),
    ];
}
ksort($files);

/* ------------------------------------------------------------------
 * Historique déjà enregistré
 * ------------------------------------------------------------------ */
$applied = [];
try {
    if ($res = $db->query("SELECT filename, applied_at, applied_by, version FROM applied_migrations")) {
        while ($r = $res->fetch_assoc()) { $applied[$r['filename']] = $r; }
        $res->free();
    }
} catch (Throwable $e) {
    $error = "La table applied_migrations est absente. Réimportez sql/schema.sql d'abord.";
}

/**
 * Exécute un fichier SQL multi-instructions.
 *
 * On passe par multi_query plutôt que par un découpage sur « ; » :
 * schema.sql contient des blocs PREPARE/EXECUTE et des chaînes qui
 * contiennent des points-virgules, qu'un découpage naïf couperait au
 * mauvais endroit.
 *
 * @return array{ok:bool, statements:int, error:string|null}
 */
function wt_run_sql_file(mysqli $db, string $path): array
{
    $sql = @file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return ['ok' => false, 'statements' => 0, 'error' => 'Fichier vide ou illisible'];
    }

    $count = 0;
    try {
        if (!$db->multi_query($sql)) {
            return ['ok' => false, 'statements' => 0, 'error' => $db->error];
        }
        /* Il faut vider chaque jeu de résultats, sinon la connexion reste
           bloquée pour les requêtes suivantes (« commands out of sync »). */
        do {
            $count++;
            if ($res = $db->store_result()) { $res->free(); }
            if (!$db->more_results()) { break; }
        } while ($db->next_result());

        /* next_result() renvoie false aussi bien à la fin normale qu'en
           cas d'erreur : c'est errno qui tranche. */
        if ($db->errno) {
            return ['ok' => false, 'statements' => $count, 'error' => $db->error];
        }
        return ['ok' => true, 'statements' => $count, 'error' => null];
    } catch (Throwable $e) {
        /* On draine ce qui reste pour ne pas laisser la connexion dans un
           état inutilisable pour la suite de la page. */
        while ($db->more_results() && $db->next_result()) {
            if ($res = $db->store_result()) { $res->free(); }
        }
        return ['ok' => false, 'statements' => $count, 'error' => $e->getMessage()];
    }
}

/* ------------------------------------------------------------------
 * Traitement
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $target = (string) ($_POST['file'] ?? '');

    /* Le nom reçu doit correspondre à un fichier réellement listé. C'est
       ce contrôle, et non un filtrage de caractères, qui rend impossible
       toute lecture hors de sql/. */
    if (!isset($files[$target])) {
        $error = 'Fichier inconnu.';
    } elseif ($action === 'mark') {
        if (isset($applied[$target])) {
            $notice = 'Cette migration est déjà enregistrée.';
        } else {
            $stmt = $db->prepare(
                "INSERT INTO applied_migrations (filename, applied_by, version, notes)
                 VALUES (?, ?, ?, 'Marquée manuellement, sans exécution')"
            );
            $who = (string) (current_user()['username'] ?? 'admin');
            $ver = WT_VERSION;
            $stmt->bind_param('sss', $target, $who, $ver);
            $stmt->execute();
            $stmt->close();
            wt_admin_log('migration_mark', ['file' => $target]);
            $notice = $target . ' — enregistrée sans exécution.';
            $applied[$target] = ['filename' => $target, 'applied_at' => 'maintenant',
                                 'applied_by' => $who, 'version' => $ver];
        }
    } elseif ($action === 'run') {
        $isSchema = ($target === 'schema.sql');

        if (!$isSchema && isset($applied[$target])) {
            $error = 'Déjà appliquée le ' . $applied[$target]['applied_at']
                   . '. Rejouer une migration non idempotente peut corrompre des données.';
        } else {
            @set_time_limit(120);
            $res = wt_run_sql_file($db, $sqlDir . '/' . $target);
            $report = ['file' => $target] + $res;

            if ($res['ok']) {
                if (!isset($applied[$target])) {
                    $stmt = $db->prepare(
                        "INSERT INTO applied_migrations (filename, applied_by, version, notes)
                         VALUES (?, ?, ?, ?)"
                    );
                    $who   = (string) (current_user()['username'] ?? 'admin');
                    $ver   = WT_VERSION;
                    $notes = $res['statements'] . ' instruction(s)';
                    $stmt->bind_param('ssss', $target, $who, $ver, $notes);
                    $stmt->execute();
                    $stmt->close();
                    $applied[$target] = ['filename' => $target, 'applied_at' => 'maintenant',
                                         'applied_by' => $who, 'version' => $ver];
                }
                wt_admin_log('migration_run', ['file' => $target, 'statements' => $res['statements']]);
                $notice = $target . ' — appliquée (' . $res['statements'] . ' instruction(s)).';
            } else {
                wt_admin_log('migration_fail', ['file' => $target, 'error' => $res['error']]);
                $error = $target . ' — échec : ' . $res['error'];
            }
        }
    }
}

/* Regroupement pour l'affichage : le socle, les seeds, puis le reste. */
$groups = ['Socle' => [], 'Migrations' => [], 'Contenu (seed)' => []];
foreach ($files as $name => $f) {
    if ($name === 'schema.sql')            { $groups['Socle'][$name] = $f; }
    elseif (str_starts_with($name, 'seed')) { $groups['Contenu (seed)'][$name] = $f; }
    else                                    { $groups['Migrations'][$name] = $f; }
}

$pending = 0;
foreach ($files as $name => $f) {
    if ($name !== 'schema.sql' && !isset($applied[$name])) { $pending++; }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <h1>🗃 Migrations SQL</h1>
        <p class="wt-muted">
          <?= count($files) ?> fichier(s) dans <code>sql/</code> —
          <?= count($applied) ?> enregistrée(s), <?= (int) $pending ?> sans trace.
        </p>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

      <div class="wt-alert wt-alert--warn">
        <strong>Avant d'appliquer quoi que ce soit, sauvegardez la base.</strong>
        Une migration ne s'annule pas. Sur une installation déjà en service,
        la plupart de ces fichiers ont déjà tourné sans être enregistrés :
        utilisez « Marquer » pour rattraper l'historique, et n'appliquez
        réellement que ce dont vous avez besoin.
      </div>

      <?php if ($report && !empty($report['error'])): ?>
        <div class="wt-alert wt-alert--error">
          <strong><?= e($report['file']) ?></strong> — arrêt après
          <?= (int) $report['statements'] ?> instruction(s).<br>
          <code><?= e((string) $report['error']) ?></code>
        </div>
      <?php endif; ?>

      <?php foreach ($groups as $groupName => $groupFiles): ?>
        <?php if (!$groupFiles) { continue; } ?>
        <h2><?= e($groupName) ?> <span class="wt-muted">(<?= count($groupFiles) ?>)</span></h2>
        <table class="wt-admin-v2__table">
          <thead>
            <tr><th>Fichier</th><th>Taille</th><th>État</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($groupFiles as $name => $f): ?>
              <?php $isApplied = isset($applied[$name]); $isSchema = ($name === 'schema.sql'); ?>
              <tr>
                <td><code><?= e($name) ?></code></td>
                <td class="wt-muted"><?= e(number_format($f['size'] / 1024, 1, ',', ' ')) ?> Ko</td>
                <td>
                  <?php if ($isSchema): ?>
                    <span class="wt-muted">Idempotent — rejouable</span>
                  <?php elseif ($isApplied): ?>
                    ✅ <?= e((string) $applied[$name]['applied_at']) ?>
                    <?php if (!empty($applied[$name]['applied_by'])): ?>
                      <small class="wt-muted">par <?= e((string) $applied[$name]['applied_by']) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="wt-muted">aucune trace</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!$isApplied || $isSchema): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Exécuter <?= e($name) ?> ? Cette action ne s\'annule pas.')">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="run">
                      <input type="hidden" name="file" value="<?= e($name) ?>">
                      <button class="wt-btn wt-btn--primary wt-btn--sm" type="submit">Appliquer</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$isApplied && !$isSchema): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="mark">
                      <input type="hidden" name="file" value="<?= e($name) ?>">
                      <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"
                              title="Enregistre sans exécuter — pour rattraper l'historique">Marquer</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
