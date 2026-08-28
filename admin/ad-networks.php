<?php
/**
 * Wintaskly — admin/ad-networks.php
 * ---------------------------------------------------------------------------
 * Gestion des régies publicitaires.
 *
 * À QUOI SERT CET ÉCRAN
 * ---------------------
 * Les scripts d'une régie ne s'exécutent que si son domaine figure dans la
 * politique de sécurité de contenu (CSP) envoyée par le serveur. Ces
 * domaines étaient écrits en dur dans le code : ajouter un partenaire
 * imposait une modification et un redéploiement.
 *
 * Pire, en cas d'oubli, le navigateur bloque les scripts SANS RIEN AFFICHER.
 * Pas d'erreur sur la page, juste des emplacements vides et zéro revenu —
 * une panne particulièrement difficile à diagnostiquer quand on ne pense pas
 * à ouvrir la console du navigateur.
 *
 * Ici, activer une régie suffit : ses domaines rejoignent automatiquement la
 * politique.
 *
 * ⚠️ N'ACTIVEZ QUE LES RÉGIES RÉELLEMENT UTILISÉES
 * Chaque domaine autorisé est une origine de plus autorisée à exécuter du
 * code sur vos pages. Une régie active mais inutilisée n'apporte aucun
 * revenu et affaiblit la protection contre les injections.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_admin();

$db          = db();
$adminActive = 'ads';
$pageTitle   = t('admin.title') . ' — ' . t('admin.adnet.title');

$notice = null;
$error  = null;

/** Nettoie une liste de domaines saisie à la main. */
function wt_adnet_clean_domains(string $raw): string
{
    $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
    $ok    = [];
    foreach ($parts as $d) {
        $d = trim($d);
        if ($d === '') { continue; }
        /* On accepte le domaine seul et on préfixe en https : saisir
           « exemple.com » est le réflexe naturel, et refuser sans expliquer
           serait pénible. En revanche http:// est rejeté — un script chargé
           en clair sur une page sécurisée est bloqué par le navigateur de
           toute façon. */
        if (!preg_match('#^https://#i', $d)) {
            $d = 'https://' . preg_replace('#^https?://#i', '', $d);
        }
        if (preg_match('#^https://[A-Za-z0-9.*_-]+(:\d+)?$#', $d)) {
            $ok[] = $d;
        }
    }
    return implode(' ', array_unique($ok));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $key  = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) ($_POST['k'] ?? '')) ?? '');

        if ($name === '' || $key === '') {
            $error = t('admin.adnet.err_required');
        } else {
            $script  = wt_adnet_clean_domains((string) ($_POST['script_domains'] ?? ''));
            $connect = wt_adnet_clean_domains((string) ($_POST['connect_domains'] ?? ''));
            $frame   = wt_adnet_clean_domains((string) ($_POST['frame_domains'] ?? ''));
            $site    = trim((string) ($_POST['site_url'] ?? ''));
            $notes   = trim((string) ($_POST['notes'] ?? ''));
            $active  = !empty($_POST['active']) ? 1 : 0;

            if ($script === '') {
                /* Sans domaine de script, la régie ne peut rien charger :
                   l'enregistrer donnerait l'illusion d'une installation. */
                $error = t('admin.adnet.err_noscript');
            } else {
                try {
                    if ($id > 0) {
                        $stmt = $db->prepare(
                            "UPDATE ad_networks SET k=?, name=?, site_url=?, script_domains=?,
                                    connect_domains=?, frame_domains=?, notes=?, active=?
                              WHERE id=?"
                        );
                        $stmt->bind_param('sssssssii', $key, $name, $site, $script,
                                          $connect, $frame, $notes, $active, $id);
                    } else {
                        $stmt = $db->prepare(
                            "INSERT INTO ad_networks
                               (k, name, site_url, script_domains, connect_domains,
                                frame_domains, notes, active)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $stmt->bind_param('sssssssi', $key, $name, $site, $script,
                                          $connect, $frame, $notes, $active);
                    }
                    $stmt->execute();
                    $stmt->close();
                    $notice = t('admin.adnet.saved');
                } catch (Throwable $e) {
                    error_log('[Wintaskly adnet] ' . $e->getMessage());
                    $error = str_contains($e->getMessage(), 'uniq_k')
                           ? t('admin.adnet.err_dupe') : t('admin.adnet.err_db');
                }
            }
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $stmt = $db->prepare("UPDATE ad_networks SET active = 1 - active WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.adnet.saved');
    }

    if ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("DELETE FROM ad_networks WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $notice = t('admin.adnet.deleted');
    }
}

$editId  = (int) ($_GET['edit'] ?? 0);
$editRow = null;
$rows    = [];
$missing = false;
try {
    if ($editId > 0) {
        $editRow = db_one("SELECT * FROM ad_networks WHERE id = ?", [$editId], 'i');
    }
    $res = $db->query("SELECT * FROM ad_networks ORDER BY active DESC, sort_order, name");
    while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
} catch (Throwable $e) {
    $missing = true;
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content">
      <header class="wt-admin-v2__head">
        <h1 class="wt-admin-v2__title"><?= e(t('admin.adnet.title')) ?></h1>
        <p class="wt-admin-v2__lead"><?= e(t('admin.adnet.lead')) ?></p>
      </header>

      <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="wt-alert wt-alert--error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($missing): ?>
        <div class="wt-alert wt-alert--error"><?= e(t('admin.adnet.err_migration')) ?></div>
      <?php endif; ?>

      <section class="wt-admin-v2__card">
        <div class="wt-admin-v2__card-head"><h2><?= e(t('admin.adnet.list')) ?></h2></div>

        <?php if (!$rows): ?>
          <p class="wt-admin-v2__empty"><?= e(t('admin.adnet.empty')) ?></p>
        <?php else: ?>
          <ul class="wt-adnet-list">
            <?php foreach ($rows as $n): ?>
              <li class="wt-adnet-item <?= (int) $n['active'] ? 'is-on' : '' ?>">
                <div class="wt-adnet-item__main">
                  <strong><?= e($n['name']) ?></strong>
                  <span class="wt-adnet-badge <?= (int) $n['active'] ? 'is-on' : '' ?>">
                    <?= e((int) $n['active'] ? t('admin.adnet.on') : t('admin.adnet.off')) ?>
                  </span>
                  <code class="wt-adnet-domains"><?= e((string) $n['script_domains']) ?></code>
                  <?php if (!empty($n['notes'])): ?>
                    <p class="wt-adnet-notes"><?= e((string) $n['notes']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="wt-adnet-actions">
                  <a class="wt-btn wt-btn--ghost wt-btn--sm" href="?edit=<?= (int) $n['id'] ?>"><?= e(t('admin.adnet.edit')) ?></a>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit">
                      <?= e((int) $n['active'] ? t('admin.adnet.disable') : t('admin.adnet.enable')) ?>
                    </button>
                  </form>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('<?= e(t('admin.adnet.del_confirm')) ?>')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                    <button class="wt-btn wt-btn--ghost wt-btn--sm" type="submit"><?= e(t('admin.adnet.delete')) ?></button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="wt-admin-v2__hint"><?= e(t('admin.adnet.security_note')) ?></p>
        <?php endif; ?>
      </section>

      <section class="wt-admin-v2__card">
        <div class="wt-admin-v2__card-head">
          <h2><?= e($editRow ? t('admin.adnet.edit_title') : t('admin.adnet.new_title')) ?></h2>
          <?php if ($editRow): ?>
            <a class="wt-btn wt-btn--ghost wt-btn--sm" href="<?= e(wt_url('/admin/ad-networks.php')) ?>"><?= e(t('admin.adnet.new')) ?></a>
          <?php endif; ?>
        </div>

        <form method="post" class="wt-adnet-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int) ($editRow['id'] ?? 0) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_name')) ?></span>
            <input class="wt-input" type="text" name="name" required maxlength="120"
                   value="<?= e((string) ($editRow['name'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_key')) ?></span>
            <input class="wt-input" type="text" name="k" required maxlength="40"
                   value="<?= e((string) ($editRow['k'] ?? '')) ?>"
                   placeholder="adsterra">
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_script')) ?></span>
            <textarea class="wt-input wt-mono" name="script_domains" rows="2"
                      placeholder="https://*.exemple.com https://cdn.exemple.net"><?= e((string) ($editRow['script_domains'] ?? '')) ?></textarea>
            <small class="wt-field__hint"><?= e(t('admin.adnet.f_script_hint')) ?></small>
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_connect')) ?></span>
            <textarea class="wt-input wt-mono" name="connect_domains" rows="2"><?= e((string) ($editRow['connect_domains'] ?? '')) ?></textarea>
            <small class="wt-field__hint"><?= e(t('admin.adnet.f_connect_hint')) ?></small>
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_frame')) ?></span>
            <textarea class="wt-input wt-mono" name="frame_domains" rows="2"><?= e((string) ($editRow['frame_domains'] ?? '')) ?></textarea>
            <small class="wt-field__hint"><?= e(t('admin.adnet.f_frame_hint')) ?></small>
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_site')) ?></span>
            <input class="wt-input" type="url" name="site_url" maxlength="255"
                   value="<?= e((string) ($editRow['site_url'] ?? '')) ?>">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_active')) ?></span>
            <input type="checkbox" name="active" value="1"
                   <?= (int) ($editRow['active'] ?? 0) ? 'checked' : '' ?>>
          </label>

          <label class="wt-field wt-field--wide">
            <span class="wt-field__label"><?= e(t('admin.adnet.f_notes')) ?></span>
            <textarea class="wt-input" name="notes" rows="2"><?= e((string) ($editRow['notes'] ?? '')) ?></textarea>
          </label>

          <div class="wt-field--wide">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.adnet.save')) ?></button>
          </div>
        </form>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
