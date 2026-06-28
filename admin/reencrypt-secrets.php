<?php
/**
 * Wintaskly — Admin · Re-chiffrement en masse des secrets
 * ----------------------------------------------------------------------
 * Outil one-shot : parcourt tous les secrets stockés en clair (anciens
 * enregistrements d'avant le chiffrement) et les re-chiffre avec la clé
 * maître (config encryption_key). Les secrets DÉJÀ chiffrés (préfixe
 * enc:v1:) sont ignorés → le script est idempotent (relançable sans risque).
 *
 * Secrets traités :
 *   - withdrawal_methods.api_credentials (JSON)
 *   - shortlinks.api_token, shortlinks.callback_key
 *   - offerwalls.callback_secret
 *   - config['ads.adsterra_api_token']
 *
 * ⚠️ PRÉREQUIS : exécuter d'abord migration_secret_columns_size.sql
 * (les secrets chiffrés sont plus longs que les colonnes d'origine).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$db      = db();
$notice  = null;
$error   = null;
$report  = [];

// Vérifie que le chiffrement est disponible
$cryptoOk = function_exists('wt_encrypt') && function_exists('wt_decrypt') && function_exists('wt_is_encrypted');

/*
 * Petit utilitaire : re-chiffre une valeur si elle est en clair.
 * Retourne [nouvelleValeur|null, 'encrypted'|'skipped'|'empty'].
 *   - null en première position = rien à écrire (déjà chiffré ou vide)
 */
$reencrypt = static function (?string $value): array {
    $value = (string) $value;
    if ($value === '') {
        return [null, 'empty'];
    }
    if (wt_is_encrypted($value)) {
        return [null, 'skipped']; // déjà chiffré
    }
    return [wt_encrypt($value), 'encrypted'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && csrf_check($_POST['_csrf'] ?? null)
    && ($_POST['action'] ?? '') === 'reencrypt') {

    if (!$cryptoOk) {
        $error = 'Le module de chiffrement n\'est pas disponible.';
    } else {
        $stats = [
            'withdrawal_methods' => ['encrypted' => 0, 'skipped' => 0, 'empty' => 0],
            'shortlinks'         => ['encrypted' => 0, 'skipped' => 0, 'empty' => 0],
            'offerwalls'         => ['encrypted' => 0, 'skipped' => 0, 'empty' => 0],
            'config'             => ['encrypted' => 0, 'skipped' => 0, 'empty' => 0],
        ];

        try {
            // ---- 1) withdrawal_methods.api_credentials ----
            $rows = $db->query("SELECT id, api_credentials FROM withdrawal_methods");
            if ($rows) {
                $upd = $db->prepare("UPDATE withdrawal_methods SET api_credentials = ? WHERE id = ?");
                while ($r = $rows->fetch_assoc()) {
                    [$new, $state] = $reencrypt($r['api_credentials']);
                    $stats['withdrawal_methods'][$state]++;
                    if ($new !== null) {
                        $id = (int) $r['id'];
                        $upd->bind_param('si', $new, $id);
                        $upd->execute();
                    }
                }
                $upd->close();
            }

            // ---- 2) shortlinks.api_token + callback_key ----
            $rows = $db->query("SELECT id, api_token, callback_key FROM shortlinks");
            if ($rows) {
                $upd = $db->prepare("UPDATE shortlinks SET api_token = ?, callback_key = ? WHERE id = ?");
                while ($r = $rows->fetch_assoc()) {
                    [$newTok, $st1] = $reencrypt($r['api_token']);
                    [$newKey, $st2] = $reencrypt($r['callback_key']);
                    // Compte chaque secret traité
                    foreach ([$st1, $st2] as $st) { $stats['shortlinks'][$st]++; }
                    if ($newTok !== null || $newKey !== null) {
                        $tok = $newTok ?? (string) $r['api_token'];
                        $key = $newKey ?? (string) $r['callback_key'];
                        $id  = (int) $r['id'];
                        $upd->bind_param('ssi', $tok, $key, $id);
                        $upd->execute();
                    }
                }
                $upd->close();
            }

            // ---- 3) offerwalls.callback_secret ----
            $rows = $db->query("SELECT id, callback_secret FROM offerwalls");
            if ($rows) {
                $upd = $db->prepare("UPDATE offerwalls SET callback_secret = ? WHERE id = ?");
                while ($r = $rows->fetch_assoc()) {
                    [$new, $state] = $reencrypt($r['callback_secret']);
                    $stats['offerwalls'][$state]++;
                    if ($new !== null) {
                        $id = (int) $r['id'];
                        $upd->bind_param('si', $new, $id);
                        $upd->execute();
                    }
                }
                $upd->close();
            }

            // ---- 4) config['ads.adsterra_api_token'] ----
            $tokRow = $db->query("SELECT v FROM config WHERE k = 'ads.adsterra_api_token' LIMIT 1");
            if ($tokRow && ($cfgRow = $tokRow->fetch_assoc())) {
                [$new, $state] = $reencrypt($cfgRow['v']);
                $stats['config'][$state]++;
                if ($new !== null) {
                    $upd = $db->prepare("UPDATE config SET v = ? WHERE k = 'ads.adsterra_api_token'");
                    $upd->bind_param('s', $new);
                    $upd->execute();
                    $upd->close();
                }
            }

            // Construit le rapport
            $totalEnc = 0;
            foreach ($stats as $t => $s) { $totalEnc += $s['encrypted']; }
            $report = $stats;
            $notice = sprintf('Re-chiffrement terminé : %d secret(s) nouvellement chiffré(s).', $totalEnc);

        } catch (Throwable $ex) {
            $error = 'Erreur durant le re-chiffrement : ' . $ex->getMessage();
            error_log('[Wintaskly reencrypt] ' . $ex->getMessage());
        }
    }
}

/* ---- Scan préalable : combien de secrets en clair actuellement ? ---- */
$pending = ['total' => 0, 'detail' => []];
if ($cryptoOk) {
    $scan = static function (mysqli $db, string $sql, array $cols) use (&$pending): void {
        if ($res = $db->query($sql)) {
            while ($r = $res->fetch_assoc()) {
                foreach ($cols as $c) {
                    $v = (string) ($r[$c] ?? '');
                    if ($v !== '' && !wt_is_encrypted($v)) {
                        $pending['total']++;
                    }
                }
            }
            $res->free();
        }
    };
    $scan($db, "SELECT api_credentials FROM withdrawal_methods", ['api_credentials']);
    $scan($db, "SELECT api_token, callback_key FROM shortlinks", ['api_token', 'callback_key']);
    $scan($db, "SELECT callback_secret FROM offerwalls", ['callback_secret']);
    $scan($db, "SELECT v FROM config WHERE k='ads.adsterra_api_token'", ['v']);
}

$pageTitle = 'Re-chiffrement des secrets';
$adminActive = 'reencrypt';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content">
  <div class="wt-admin-v2__wrap">

    <header class="wt-admin-v2__head">
      <div>
        <h1 class="wt-admin-v2__title">🔐 Re-chiffrement des secrets</h1>
        <p class="wt-muted">Chiffre en masse les secrets API encore stockés en clair.</p>
      </div>
    </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$cryptoOk): ?>
      <div class="wt-alert wt-alert--error">
        Le module de chiffrement (includes/crypto.php) n'est pas chargé. Vérifie ton installation.
      </div>
    <?php else: ?>

      <section class="wt-card wt-card--padded" style="margin-bottom:1.5rem">
        <h2 style="margin-top:0">État actuel</h2>
        <?php if ($pending['total'] === 0): ?>
          <p>✅ <strong>Tous les secrets sont déjà chiffrés.</strong> Rien à faire.</p>
        <?php else: ?>
          <p>⚠️ <strong><?= (int) $pending['total'] ?></strong> secret(s) encore en clair détecté(s).</p>
          <p class="wt-muted" style="font-size:.9rem">
            ⚠️ Prérequis : avoir exécuté <code>migration_secret_columns_size.sql</code>
            (les secrets chiffrés sont plus longs). Pense aussi à définir
            <code>encryption_key</code> dans <code>config.php</code> avant de lancer.
          </p>
          <form method="post" data-reencrypt-form style="margin-top:1rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="reencrypt">
            <button class="wt-btn wt-btn--primary" data-reencrypt-btn>
              🔐 Chiffrer les <?= (int) $pending['total'] ?> secret(s) en clair
            </button>
          </form>
        <?php endif; ?>
      </section>

      <?php if ($report): ?>
        <section class="wt-card wt-card--padded">
          <h2 style="margin-top:0">Rapport</h2>
          <div class="wt-table-wrap">
            <table class="wt-table">
              <thead>
                <tr><th>Source</th><th>Chiffrés</th><th>Déjà OK</th><th>Vides</th></tr>
              </thead>
              <tbody>
                <?php
                  $labels = [
                      'withdrawal_methods' => 'Méthodes de paiement',
                      'shortlinks'         => 'Shortlinks (token + clé)',
                      'offerwalls'         => 'Offerwalls (secret)',
                      'config'             => 'Token Adsterra',
                  ];
                  foreach ($report as $src => $s):
                ?>
                  <tr>
                    <td><?= e($labels[$src] ?? $src) ?></td>
                    <td><strong><?= (int) $s['encrypted'] ?></strong></td>
                    <td><?= (int) $s['skipped'] ?></td>
                    <td><?= (int) $s['empty'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

<script>
/* Confirmation avant le re-chiffrement (action sensible et globale) */
(function () {
  'use strict';
  var form = document.querySelector('[data-reencrypt-form]');
  if (!form) { return; }
  form.addEventListener('submit', function (e) {
    var ok = window.confirm(
      'Re-chiffrement des secrets\n\n'
      + 'Cette opération va chiffrer tous les secrets actuellement en clair.\n'
      + 'Assure-toi d\'avoir :\n'
      + '  1. Exécuté migration_secret_columns_size.sql\n'
      + '  2. Défini encryption_key dans config.php\n\n'
      + 'Continuer ?'
    );
    if (!ok) { e.preventDefault(); }
  });
})();
</script>

  </div>
  </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
