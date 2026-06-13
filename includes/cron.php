<?php
/**
 * Wintaskly — includes/cron.php
 *
 * Système d'exécution de tâches planifiées.
 *
 * Concept :
 *   - Chaque tâche est enregistrée via `wt_cron_register($key, $callable,
 *     $every_seconds = 3600)` avec une fréquence minimale.
 *   - Quand le cron est déclenché (via /api/cron.php ou CLI), il lit la
 *     dernière exécution réussie de chaque tâche dans `cron_runs` et
 *     l'exécute uniquement si l'écart >= every_seconds.
 *   - Chaque run est tracé en BDD : status (running/success/error/
 *     skipped), summary, error éventuel.
 *
 * Sécurité :
 *   - Le déclenchement web requiert le token `cfg('cron.token')`.
 *   - Le CLI passe sans token.
 *   - Le lock global anti-concurrence évite que deux déclenchements
 *     parallèles n'exécutent la même tâche en double.
 *
 * Ajout d'une tâche : créer un fichier dans `cron/tasks/`, ou appeler
 *   wt_cron_register() depuis n'importe où avant `wt_cron_run()`.
 */
declare(strict_types=1);

/* Registry global des tâches : [key => ['cb' => callable, 'every' => int]] */
$GLOBALS['wt_cron_tasks'] = $GLOBALS['wt_cron_tasks'] ?? [];

/**
 * Enregistre une tâche planifiée.
 *
 * @param string   $key            Identifiant unique (ex: 'leaderboard_archive')
 * @param callable $callable       Fonction qui exécute la tâche, doit retourner
 *                                 un string résumé (ou throw en cas d'erreur)
 * @param int      $every_seconds  Fréquence minimale entre 2 runs (en secondes)
 */
function wt_cron_register(string $key, callable $callable, int $every_seconds = 3600): void
{
    $GLOBALS['wt_cron_tasks'][$key] = [
        'cb'    => $callable,
        'every' => max(1, $every_seconds),
    ];
}

/**
 * Récupère le timestamp UTC du dernier run "success" pour une tâche.
 * Retourne 0 si la tâche n'a jamais tourné avec succès.
 */
function wt_cron_last_success(string $task): int
{
    $db = db();
    $stmt = $db->prepare(
        "SELECT UNIX_TIMESTAMP(finished_at) ts
           FROM cron_runs
          WHERE task = ? AND status = 'success'
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('s', $task);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['ts'] ?? 0);
}

/**
 * Lance l'exécution de toutes les tâches dont l'écart depuis le dernier
 * run dépasse leur `every_seconds`.
 *
 * @param  bool $force  Si true, ignore les délais et lance tout.
 * @return array<string, array{status:string, summary:?string, error:?string}>
 */
function wt_cron_run(bool $force = false): array
{
    $report = [];
    $now    = time();
    $db     = db();

    foreach ($GLOBALS['wt_cron_tasks'] as $key => $task) {
        $lastTs = wt_cron_last_success($key);
        $due    = $force || ($now - $lastTs) >= $task['every'];

        if (!$due) {
            $report[$key] = [
                'status'  => 'skipped',
                'summary' => 'Pas encore dû (dernier run il y a ' . ($now - $lastTs) . 's)',
                'error'   => null,
            ];
            continue;
        }

        // Insert run "running"
        $stmt = $db->prepare(
            "INSERT INTO cron_runs (task, status) VALUES (?, 'running')"
        );
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $runId = $db->insert_id;
        $stmt->close();

        try {
            $summary = (string) ($task['cb'])();
            $upd = $db->prepare(
                "UPDATE cron_runs SET finished_at = NOW(), status = 'success', summary = ?
                  WHERE id = ?"
            );
            $upd->bind_param('si', $summary, $runId);
            $upd->execute();
            $upd->close();

            $report[$key] = ['status' => 'success', 'summary' => $summary, 'error' => null];
        } catch (\Throwable $e) {
            $err = $e->getMessage();
            $upd = $db->prepare(
                "UPDATE cron_runs SET finished_at = NOW(), status = 'error', error = ?
                  WHERE id = ?"
            );
            $upd->bind_param('si', $err, $runId);
            $upd->execute();
            $upd->close();

            $report[$key] = ['status' => 'error', 'summary' => null, 'error' => $err];
        }
    }

    // Met à jour le marqueur global "dernier passage du cron"
    $summary = json_encode($report, JSON_UNESCAPED_UNICODE);
    cfg_set('cron.last_run_at', date('Y-m-d H:i:s'));
    cfg_set('cron.last_run_summary', $summary);

    return $report;
}

/**
 * V8 — Lance UNE seule tâche par sa clé (force=true ignore le délai).
 * Pratique pour le bouton "Exécuter cette tâche" de l'interface admin.
 *
 * @param  string $key      Identifiant de la tâche (cf. wt_cron_register)
 * @param  bool   $force    Si true, ignore le délai every_seconds
 * @return array{status:string,summary:?string,error:?string}
 * @throws RuntimeException Si la tâche n'existe pas dans le registry
 */
function wt_cron_run_one(string $key, bool $force = true): array
{
    if (!isset($GLOBALS['wt_cron_tasks'][$key])) {
        throw new RuntimeException("Tâche inconnue : {$key}");
    }
    $task   = $GLOBALS['wt_cron_tasks'][$key];
    $lastTs = wt_cron_last_success($key);
    $due    = $force || (time() - $lastTs) >= $task['every'];

    if (!$due) {
        return ['status' => 'skipped', 'summary' => 'Pas dû', 'error' => null];
    }

    $db = db();
    $stmt = $db->prepare("INSERT INTO cron_runs (task, status) VALUES (?, 'running')");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $runId = $db->insert_id;
    $stmt->close();

    try {
        $summary = (string) ($task['cb'])();
        $upd = $db->prepare(
            "UPDATE cron_runs SET finished_at = NOW(), status = 'success', summary = ?
              WHERE id = ?"
        );
        $upd->bind_param('si', $summary, $runId);
        $upd->execute();
        $upd->close();
        return ['status' => 'success', 'summary' => $summary, 'error' => null];
    } catch (\Throwable $e) {
        $err = $e->getMessage();
        $upd = $db->prepare(
            "UPDATE cron_runs SET finished_at = NOW(), status = 'error', error = ?
              WHERE id = ?"
        );
        $upd->bind_param('si', $err, $runId);
        $upd->execute();
        $upd->close();
        return ['status' => 'error', 'summary' => null, 'error' => $err];
    }
}

/**
 * Génère un nouveau token cron sécurisé et le stocke en config.
 * Utilisé au premier déploiement ou via /admin/cron.php (rotation).
 */
function wt_cron_rotate_token(): string
{
    $tok = bin2hex(random_bytes(24));  // 48 chars
    cfg_set('cron.token', $tok);
    return $tok;
}

/**
 * Charge tous les fichiers de tâches dans cron/tasks/*.php.
 * Chaque fichier doit appeler wt_cron_register() à son chargement.
 */
function wt_cron_load_tasks(): void
{
    $dir = __DIR__ . '/../cron/tasks';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        require_once $file;
    }
}
