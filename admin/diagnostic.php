<?php
/**
 * Wintaskly — Admin · Diagnostic BDD
 *
 * Page minimaliste qui te dit en un coup d'œil :
 *   - L'état de chaque table importante (existe/manquante)
 *   - Les colonnes présentes dans `shortlinks` (et celles attendues)
 *   - Si une page admin plante, l'erreur EXACTE (lecture error.log LWS)
 *   - La version Wintaskly installée
 *
 * Utile quand une page admin retourne un 500 mystérieux après une mise
 * à jour : tu vois immédiatement quelle migration appliquer.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = 'Diagnostic BDD — Wintaskly';
$adminActive = '';
$db          = db();

// ============================================================
// 1) Inventaire des tables
// ============================================================
$expectedTables = [
    'users', 'transactions', 'config', 'shortlinks', 'offerwalls',
    'faucet_claims', 'ptc_ads', 'withdrawals', 'withdrawal_methods',
    'auth_tokens', 'messages', 'notifications', 'support_tickets',
    'support_messages', 'cron_runs', 'update_checks', 'applied_migrations',
    'admin_actions',
];

/* Note : certains noms de tables prêtent à confusion —
   - La table des méthodes de retrait s'appelle `withdrawal_methods`
     (et NON payment_methods, même si la page admin s'appelle
     payment_methods.php).
   - La table des tickets support s'appelle `support_tickets`
     (et NON tickets). */
$existingTables = [];
if ($res = $db->query("SHOW TABLES")) {
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        $existingTables[$row[0]] = true;
    }
    $res->free();
}

// ============================================================
// 2) Colonnes attendues sur shortlinks (avec leur migration source)
// ============================================================
$shortlinksExpected = [
    'id'                      => ['core',  'PK'],
    'name'                    => ['core',  ''],
    'provider'                => ['core',  ''],
    'destination_url'         => ['core',  ''],
    'reward_coins'            => ['core',  ''],
    'reward_xp'               => ['core',  ''],
    'cooldown_hours'          => ['core',  ''],
    'gateway_seconds'         => ['core',  ''],
    'active'                  => ['core',  ''],
    'created_at'              => ['core',  ''],
    'mode'                    => ['migration_shortlinks_api.sql',  'API'],
    'api_endpoint'            => ['migration_shortlinks_api.sql',  'API'],
    'api_token'               => ['migration_shortlinks_api.sql',  'API'],
    'callback_key'            => ['migration_shortlinks_api.sql',  'API'],
    'provider_rate_amount'    => ['migration_shortlinks_rate.sql', 'RATE'],
    'provider_rate_currency'  => ['migration_shortlinks_rate.sql', 'RATE'],
    'provider_rate_per_views' => ['migration_shortlinks_rate.sql', 'RATE'],
];

$shortlinksCols = [];
if (isset($existingTables['shortlinks'])) {
    if ($res = $db->query("SHOW COLUMNS FROM shortlinks")) {
        while ($r = $res->fetch_assoc()) {
            $shortlinksCols[$r['Field']] = $r['Type'];
        }
        $res->free();
    }
}

// ============================================================
// 3) Migrations appliquées (si la table existe)
// ============================================================
$appliedMigrations = [];
if (isset($existingTables['applied_migrations'])) {
    if ($res = $db->query("SELECT filename, version, applied_at FROM applied_migrations ORDER BY applied_at ASC")) {
        $appliedMigrations = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
}

// ============================================================
// 4) Tentative de lecture du dernier error.log
// ============================================================
$errorLog = null;
$logPaths = [
    '/var/www/wintaskly.com/log/error.log',  // LWS standard
    ini_get('error_log'),
    __DIR__ . '/../error_log',
];
foreach ($logPaths as $path) {
    if ($path && is_readable($path)) {
        // Lit les 50 dernières lignes
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            $errorLog = array_slice($lines, -50);
            break;
        }
    }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🩺 Diagnostic système</span>
          <h1 class="wt-admin-v2__title">État de la BDD</h1>
          <p class="wt-muted">Vue d'ensemble de l'état des tables et migrations.</p>
        </div>
      </header>

      <!-- Version Wintaskly -->
      <div class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2>📦 Version installée</h2>
        <p style="font-family:var(--wt-font-mono);font-size:1.3rem;color:var(--wt-accent)">
          v<?= e(defined('WT_VERSION') ? WT_VERSION : 'unknown') ?>
        </p>
        <p class="wt-muted" style="font-size:.85rem">
          🕐 Fichier diagnostic.php daté du :
          <strong style="font-family:var(--wt-font-mono)"><?= e(date('Y-m-d H:i:s', filemtime(__FILE__))) ?></strong>
        </p>
        <p class="wt-muted" style="font-size:.8rem;opacity:.6">
          Si cette date ne change pas après un upload, c'est que le fichier n'a pas été remplacé
          (cache Varnish LWS ou upload incomplet).
        </p>
      </div>

      <!-- Tables -->
      <div class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2>🗄 Tables BDD</h2>
        <p class="wt-muted">Total tables existantes : <strong><?= count($existingTables) ?></strong></p>
        <table class="wt-table" style="margin-top:1rem">
          <thead>
            <tr><th>Table</th><th>État</th></tr>
          </thead>
          <tbody>
            <?php foreach ($expectedTables as $t):
              $exists = isset($existingTables[$t]);
            ?>
              <tr>
                <td class="wt-mono"><?= e($t) ?></td>
                <td><?= $exists
                    ? '<span style="color:#22c55e">✓ existe</span>'
                    : '<span style="color:#ef4444">✗ MANQUANTE</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Colonnes shortlinks -->
      <div class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2>🔗 Colonnes de la table <code>shortlinks</code></h2>
        <?php if (!isset($existingTables['shortlinks'])): ?>
          <div class="wt-alert wt-alert--error">
            ❌ La table <code>shortlinks</code> n'existe même pas ! Schéma BDD corrompu — réinstallation conseillée.
          </div>
        <?php else: ?>
          <table class="wt-table" style="margin-top:1rem">
            <thead>
              <tr>
                <th>Colonne</th>
                <th>État</th>
                <th>Type BDD</th>
                <th>Source / Migration</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $missingMigrations = [];
              foreach ($shortlinksExpected as $col => [$source, $tag]):
                $exists = isset($shortlinksCols[$col]);
                if (!$exists && $source !== 'core') {
                    $missingMigrations[$source] = true;
                }
              ?>
                <tr>
                  <td class="wt-mono"><strong><?= e($col) ?></strong></td>
                  <td><?= $exists
                      ? '<span style="color:#22c55e">✓</span>'
                      : '<span style="color:#ef4444">✗ MANQUANTE</span>' ?></td>
                  <td class="wt-mono" style="opacity:.7;font-size:.85rem">
                    <?= $exists ? e($shortlinksCols[$col]) : '—' ?>
                  </td>
                  <td>
                    <?php if ($source === 'core'): ?>
                      <span style="opacity:.5">schema.sql</span>
                    <?php else: ?>
                      <code style="font-size:.8rem"><?= e($source) ?></code>
                      <?php if ($tag): ?>
                        <span style="background:var(--wt-bg-soft);padding:.1rem .4rem;border-radius:4px;font-size:.7rem;margin-left:.3rem">
                          <?= e($tag) ?>
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (!empty($missingMigrations)): ?>
            <div class="wt-alert wt-alert--warn" style="margin-top:1rem">
              <strong>⚠ Migrations à appliquer en urgence :</strong>
              <ul style="margin:.5rem 0 0;line-height:1.8">
                <?php foreach (array_keys($missingMigrations) as $mig): ?>
                  <li><code><?= e($mig) ?></code></li>
                <?php endforeach; ?>
              </ul>
              <p style="margin-top:.75rem">
                Va dans phpMyAdmin → BDD → onglet SQL → copie-colle le contenu de chaque fichier <code>sql/<i>migration</i>.sql</code>.
              </p>
            </div>
          <?php else: ?>
            <div class="wt-alert wt-alert--success" style="margin-top:1rem">
              ✅ Toutes les colonnes attendues sont présentes !
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Migrations historique -->
      <?php if (!empty($appliedMigrations)): ?>
      <div class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2>📜 Historique des migrations appliquées</h2>
        <table class="wt-table" style="margin-top:1rem">
          <thead><tr><th>Migration</th><th>Version</th><th>Appliquée le</th></tr></thead>
          <tbody>
            <?php foreach ($appliedMigrations as $m): ?>
              <tr>
                <td class="wt-mono"><?= e($m['filename']) ?></td>
                <td class="wt-mono"><?= e($m['version'] ?? '—') ?></td>
                <td><?= e($m['applied_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <!-- error.log -->
      <details>
        <summary style="cursor:pointer;padding:.75rem 1rem;background:var(--wt-bg-soft);border-radius:8px">
          📋 Dernières erreurs PHP (50 lignes du error.log)
        </summary>
        <div class="wt-card wt-card--padded" style="margin-top:.75rem">
          <?php if ($errorLog): ?>
            <pre style="font-size:.78rem;line-height:1.5;background:#0a0e1a;color:#e8eaf0;padding:1rem;border-radius:8px;overflow-x:auto;max-height:500px"><?php
              echo e(implode("\n", $errorLog));
            ?></pre>
          <?php else: ?>
            <p class="wt-muted">⚠ error.log non lisible. Cherche manuellement dans :
              <code>/var/www/wintaskly.com/log/error.log</code> via cPanel LWS.</p>
          <?php endif; ?>
        </div>
      </details>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
