<?php
/**
 * Wintaskly — admin/offerwall-add.php
 * ---------------------------------------------------------------------------
 * Ajout d'un mur d'offres depuis le catalogue.
 *
 * LE PARCOURS, ET POURQUOI IL EST EN DEUX TEMPS
 * ---------------------------------------------
 *   1. VÉRIFICATION — l'administrateur saisit ses clés et un exemple de
 *      postback fourni par l'outil de test du fournisseur. Le système
 *      calcule la signature attendue et la compare.
 *   2. CRÉATION — seulement si la comparaison réussit.
 *
 * Cet ordre n'est pas un confort, c'est ce qui évite la panne silencieuse.
 * Une clé fausse ne produit aucune erreur visible : les postbacks sont
 * refusés côté serveur, les membres ne sont pas crédités, et le problème
 * remonte des jours plus tard par des tickets de support. Vérifier avant
 * d'écrire déplace la découverte du problème au moment où on peut le
 * corriger en trente secondes.
 *
 * La vérification peut être passée — un fournisseur peut ne pas proposer
 * d'outil de test — mais l'avertissement est explicite.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$adminActive = 'offerwalls';
$pageTitle   = t('admin.title') . ' — ' . t('admin.ow.add_title');

$catalog  = wt_postback_providers();
$provider = (string) ($_POST['provider'] ?? $_GET['provider'] ?? '');
if (!isset($catalog[$provider])) { $provider = ''; }

$notice = null;
$error  = null;
$check  = null;   // ['expected' => …, 'received' => …, 'match' => bool]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($provider !== '') {
        $p = $catalog[$provider];

        /* ⚠️ PHP remplace les points par des underscores dans les noms de
           champs POST : `dripoffers.secret_key` arrive en
           `dripoffers_secret_key`. Sans cette conversion, la valeur saisie
           n'était jamais lue et la vérification échouait avec « renseignez
           la clé » alors qu'elle l'était. */
        $post = static function (string $key) use ($catalog): string {
            $alt = str_replace('.', '_', $key);
            return trim((string) ($_POST[$key] ?? $_POST[$alt] ?? ''));
        };

        $secret = $post($p['secret']);

        if ($action === 'verify') {
            if ($secret === '') {
                $error = t('admin.ow.err_secret');
            } else {
                /* On reconstruit la signature à partir des paramètres de
                   l'exemple. Les noms de champs viennent du catalogue :
                   impossible de se tromper d'ordre, ce qui est justement
                   l'erreur la plus fréquente en saisie manuelle. */
                $sample = [];
                foreach ($p['params'] as $param) {
                    $sample[$param] = $post('s_' . $param);
                }
                $expected = wt_postback_expected_signature($provider, $sample, $secret);
                $received = strtolower($post('received_signature'));

                $check = [
                    'expected' => $expected,
                    'received' => $received,
                    'match'    => ($received !== '' && hash_equals($expected, $received)),
                ];
            }
        }

        if ($action === 'create') {
            $values = [];
            foreach (array_keys($p['fields']) as $ck) {
                $values[$ck] = $post($ck);
            }
            $res = wt_postback_provision($provider, $values);
            if (!empty($res['ok'])) {
                $notice = sprintf((string) t('admin.ow.created'), $p['label']);
            } else {
                $error = t('admin.ow.err_' . ($res['error'] ?? 'db'));
            }
        }
    }
}

$baseUrl = rtrim((string) ($GLOBALS['WT_CONFIG']['base_url'] ?? ''), '/');

/* Murs déjà présents : on le signale plutôt que de laisser créer un doublon
   qui écraserait silencieusement la configuration existante. */
$existing = [];
try {
    $r = db()->query("SELECT k, active FROM offerwalls");
    while ($r && ($x = $r->fetch_assoc())) { $existing[$x['k']] = (int) $x['active']; }
} catch (Throwable $e) { /* liste vide */ }

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content">
      <header class="wt-admin-v2__head">
        <h1 class="wt-admin-v2__title"><?= e(t('admin.ow.add_title')) ?></h1>
        <p class="wt-admin-v2__lead"><?= e(t('admin.ow.add_lead')) ?></p>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>

      <?php if ($provider === ''): ?>
        <section class="wt-admin-v2__card">
          <div class="wt-admin-v2__card-head"><h2><?= e(t('admin.ow.pick')) ?></h2></div>
          <ul class="wt-owadd-list">
            <?php foreach ($catalog as $key => $p): ?>
              <li>
                <div>
                  <strong><?= e($p['label']) ?></strong>
                  <code class="wt-owadd-formula"><?= e($p['formula']) ?></code>
                  <?php if (isset($existing[$key])): ?>
                    <span class="wt-owadd-exists">
                      <?= e($existing[$key] ? t('admin.ow.already_active') : t('admin.ow.already_inactive')) ?>
                    </span>
                  <?php endif; ?>
                </div>
                <a class="wt-btn wt-btn--ghost wt-btn--sm" href="?provider=<?= e($key) ?>">
                  <?= e(isset($existing[$key]) ? t('admin.ow.reconfigure') : t('admin.ow.configure')) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php else: ?>
        <?php $p = $catalog[$provider]; ?>

        <section class="wt-admin-v2__card">
          <div class="wt-admin-v2__card-head">
            <h2><?= e($p['label']) ?></h2>
            <a class="wt-btn wt-btn--ghost wt-btn--sm" href="?"><?= e(t('common.back')) ?></a>
          </div>

          <?php /* URL à déclarer chez le fournisseur — affichées en premier
                   car sans elles, rien ne fonctionnera quelles que soient
                   les clés saisies. */ ?>
          <h3 class="wt-owadd-h"><?= e(t('admin.ow.urls')) ?></h3>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ow.url_postback')) ?></span>
            <input class="wt-input wt-owadd-url" type="text" readonly onclick="this.select()"
                   value="<?= e($baseUrl . $p['url'] . '?' . $p['postback_query']) ?>">
          </label>
          <?php if (!empty($p['redirect'])): ?>
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ow.url_redirect')) ?></span>
              <input class="wt-input wt-owadd-url" type="text" readonly onclick="this.select()"
                     value="<?= e($baseUrl . $p['redirect']) ?>">
            </label>
          <?php endif; ?>

          <hr class="wt-owadd-sep">

          <h3 class="wt-owadd-h"><?= e(t('admin.ow.step1')) ?></h3>
          <p class="wt-owadd-p"><?= e(sprintf((string) t('admin.ow.step1_lead'), $p['formula'])) ?></p>

          <form method="post" class="wt-owadd-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="provider" value="<?= e($provider) ?>">
            <input type="hidden" name="action" value="verify">

            <?php foreach ($p['fields'] as $ck => $meta): ?>
              <label class="wt-field">
                <span class="wt-field__label"><?= e($meta['label']) ?></span>
                <input class="wt-input" type="<?= e($meta['type']) ?>" name="<?= e($ck) ?>"
                       value="<?= e((string) ($_POST[$ck] ?? $_POST[str_replace('.', '_', $ck)] ?? cfg($ck, ''))) ?>"
                       autocomplete="off">
              </label>
            <?php endforeach; ?>

            <?php foreach ($p['params'] as $param): ?>
              <label class="wt-field">
                <span class="wt-field__label"><?= e($param) ?></span>
                <input class="wt-input" type="text" name="s_<?= e($param) ?>"
                       value="<?= e((string) ($_POST['s_' . $param] ?? '')) ?>"
                       placeholder="<?= e(t('admin.ow.sample_ph')) ?>">
              </label>
            <?php endforeach; ?>

            <label class="wt-field wt-field--wide">
              <span class="wt-field__label"><?= e(t('admin.ow.received_sig')) ?></span>
              <input class="wt-input" type="text" name="received_signature" maxlength="64"
                     value="<?= e((string) ($_POST['received_signature'] ?? '')) ?>"
                     placeholder="<?= e(t('admin.ow.received_sig_ph')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.ow.received_sig_hint')) ?></small>
            </label>

            <div class="wt-field--wide">
              <button class="wt-btn wt-btn--ghost" type="submit"><?= e(t('admin.ow.verify')) ?></button>
            </div>
          </form>

          <?php if ($check !== null): ?>
            <div class="wt-owadd-result <?= $check['match'] ? 'is-ok' : 'is-ko' ?>">
              <strong><?= e($check['match'] ? t('admin.ow.match') : t('admin.ow.nomatch')) ?></strong>
              <p><?= e(t('admin.ow.expected')) ?> <code><?= e($check['expected']) ?></code></p>
              <?php if ($check['received'] !== ''): ?>
                <p><?= e(t('admin.ow.received')) ?> <code><?= e($check['received']) ?></code></p>
              <?php endif; ?>
              <?php if (!$check['match']): ?>
                <p class="wt-owadd-tip"><?= e(t('admin.ow.nomatch_tip')) ?></p>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <hr class="wt-owadd-sep">

          <h3 class="wt-owadd-h"><?= e(t('admin.ow.step2')) ?></h3>
          <p class="wt-owadd-p"><?= e(t('admin.ow.step2_lead')) ?></p>

          <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="provider" value="<?= e($provider) ?>">
            <input type="hidden" name="action" value="create">
            <?php foreach (array_keys($p['fields']) as $ck): ?>
              <input type="hidden" name="<?= e($ck) ?>" value="<?= e((string) ($_POST[$ck] ?? $_POST[str_replace('.', '_', $ck)] ?? cfg($ck, ''))) ?>">
            <?php endforeach; ?>
            <button class="wt-btn wt-btn--primary" type="submit"
                    <?= ($check !== null && !$check['match']) ? 'onclick="return confirm(\'' . e(t('admin.ow.create_anyway')) . '\')"' : '' ?>>
              <?= e(t('admin.ow.create')) ?>
            </button>
            <p class="wt-owadd-p"><?= e(t('admin.ow.create_note')) ?></p>
          </form>
        </section>
      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
