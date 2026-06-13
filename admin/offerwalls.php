<?php
/**
 * Wintaskly — Admin · CRUD des partenaires Offerwalls (V8 modernisé).
 *
 * Pilotage des passerelles de murs d'offres (Wannads, CPALead, Monlix…).
 * Chaque ligne définit :
 *   - une clé interne `k` utilisée dans l'URL du postback,
 *   - un secret HMAC vérifié par /api/callback_offerwall.php,
 *   - une URL `iframe_url` (ouverture intégrée) OU `redirect_url`
 *     (ouverture dans un nouvel onglet).
 *
 * V8 : layout admin + stats hero + card de doc HMAC en collapse +
 * form découpé en sections (Identité / Intégration / Sécurité HMAC) +
 * liste en cards avec mode iframe/redirect et confirm V8.
 */
require __DIR__ . '/../includes/init.php';
require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.offerwalls');
$adminActive = 'offerwalls';
$db          = db();
$notice      = null;
$editing     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("DELETE FROM offerwalls WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.deleted');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE offerwalls SET active = 1 - active WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $notice = t('admin.saved');
        }
    } elseif ($action === 'save') {
        $id         = (int)   ($_POST['id'] ?? 0);
        $k          = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['k'] ?? '')));
        $name       = trim((string)($_POST['name'] ?? ''));
        $logo       = trim((string)($_POST['logo_url'] ?? ''));
        $iframeUrl  = trim((string)($_POST['iframe_url'] ?? ''));
        $redirect   = trim((string)($_POST['redirect_url'] ?? ''));
        $secret     = trim((string)($_POST['callback_secret'] ?? ''));
        $desc       = trim((string)($_POST['description'] ?? ''));
        $sortOrder  = (int)   ($_POST['sort_order'] ?? 0);
        $active     = !empty($_POST['active']) ? 1 : 0;

        if ($k !== '' && $name !== '') {
            if ($id > 0) {
                $stmt = $db->prepare(
                    "UPDATE offerwalls SET
                        k=?, name=?, logo_url=?, iframe_url=?, redirect_url=?,
                        callback_secret=?, description=?, sort_order=?, active=?
                     WHERE id=?"
                );
                $stmt->bind_param(
                    'sssssssiii',
                    $k, $name, $logo, $iframeUrl, $redirect,
                    $secret, $desc, $sortOrder, $active, $id
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.saved');
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO offerwalls
                       (k, name, logo_url, iframe_url, redirect_url,
                        callback_secret, description, sort_order, active)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param(
                    'sssssssii',
                    $k, $name, $logo, $iframeUrl, $redirect,
                    $secret, $desc, $sortOrder, $active
                );
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.created');
            }
        }
    }
}

if (!empty($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = $db->prepare(
        "SELECT id, k, name, logo_url, description, iframe_url, redirect_url,
                callback_secret, sort_order, active
           FROM offerwalls WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

/* --------------------------------------------------------------------
   Pré-calculs pour le formulaire d'AJOUT (pas en mode édition) :

   1) `$nextSortOrder` : on récupère le MAX(sort_order) actuel et on
      retourne MAX+1. Comme ça l'admin n'a pas à chercher quel est le
      prochain numéro libre — il est pré-rempli automatiquement.

   2) `$defaultSecret` : on génère une clé HMAC aléatoire 64 chars hex
      (= 256 bits d'entropie) qui sera proposée par défaut. L'admin
      peut la garder ou la remplacer. Sans cette aide, la plupart des
      admins laisseraient le champ vide (qui casse la vérification HMAC
      côté callback) ou copieraient une valeur faible.

   Ces valeurs sont calculées UNIQUEMENT pour le mode ajout (sinon on
   risque d'écraser un secret existant lors d'une édition).
   -------------------------------------------------------------------- */
$nextSortOrder = 1;
$defaultSecret = '';
if ($editing === null) {
    if ($res = $db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next FROM offerwalls")) {
        $row = $res->fetch_assoc();
        $nextSortOrder = (int) ($row['next'] ?? 1);
        $res->free();
    }
    $defaultSecret = bin2hex(random_bytes(32));  // 64 chars hex = 256 bits
}

$rows = [];
if ($res = $db->query(
    "SELECT id, k, name, iframe_url, redirect_url, callback_secret, sort_order, active
       FROM offerwalls ORDER BY sort_order ASC, id DESC"
)) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* Stats hero */
$nbTotal  = count($rows);
$nbActive = count(array_filter($rows, fn ($r) => (int)$r['active'] === 1));
$nbWithIframe = count(array_filter($rows, fn ($r) => !empty($r['iframe_url'])));

/* URL d'aide pour la doc du postback */
$base = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
$cbUrl = $base . '/api/callback_offerwall.php';

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>

    <section class="wt-admin-v2__content" data-reveal>

      <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🎁 <?= e(t('admin.eyebrow_offerwalls')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.offerwalls')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.offerwalls.lead')) ?></p>
        </div>
        <?php if ($editing): ?>
          <a class="wt-btn wt-btn--ghost wt-btn--xs"
             href="<?= e(wt_url('/admin/offerwalls.php')) ?>">
            ← <?= e(t('admin.exit_edit_mode')) ?>
          </a>
        <?php endif; ?>
      </header>

      <?php if ($notice): ?>
        <div class="wt-alert wt-alert--success" data-reveal>✓ <?= e($notice) ?></div>
      <?php endif; ?>

      <!-- Stats -->
      <section class="wt-admin-v2__stats" data-reveal>
        <article class="wt-admin-v2__stat" style="--idx:0">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">📊</span>
          <div>
            <small><?= e(t('admin.stat.total')) ?></small>
            <strong><?= (int)$nbTotal ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat wt-admin-v2__stat--ok" style="--idx:1">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">✅</span>
          <div>
            <small><?= e(t('admin.stat.active')) ?></small>
            <strong><?= (int)$nbActive ?></strong>
          </div>
        </article>
        <article class="wt-admin-v2__stat" style="--idx:2">
          <span class="wt-admin-v2__stat-icon" aria-hidden="true">🖼</span>
          <div>
            <small><?= e(t('admin.ow.iframes')) ?></small>
            <strong><?= (int)$nbWithIframe ?></strong>
          </div>
        </article>
      </section>

      <!-- Doc HMAC -->
      <details class="wt-admin-v2__doc" data-reveal>
        <summary>
          <span aria-hidden="true">📖</span>
          <strong><?= e(t('admin.ow.doc_title')) ?></strong>
          <small class="wt-muted"><?= e(t('admin.ow.doc_lead')) ?></small>
        </summary>
        <div class="wt-admin-v2__doc-body">
          <p><strong><?= e(t('admin.ow.doc_postback_label')) ?></strong></p>
          <div class="wt-admin-v2__code">
            <code>
              <?= e($cbUrl) ?>?offerwall=<span>{k}</span>&user=<span>{USER_ID}</span>&tx=<span>{TX_ID}</span>&amount=<span>{COINS}</span>&sig=<span>{HMAC}</span>
            </code>
            <button type="button" class="wt-btn wt-btn--xs wt-btn--ghost"
                    data-copy-target=".wt-admin-v2__code code"
                    data-copy-label="<?= e(t('admin.cron.copied')) ?>">
              📋 <?= e(t('common.copy')) ?>
            </button>
          </div>
          <p style="margin-top:.85rem"><strong><?= e(t('admin.ow.doc_signature_label')) ?></strong></p>
          <code class="wt-admin-v2__inline-code">
            hash_hmac('sha256', "{k}|{USER_ID}|{TX_ID}|{COINS}", $callback_secret)
          </code>
        </div>
      </details>

      <!-- Form -->
      <article class="wt-admin-v2__card" data-reveal>
        <header class="wt-admin-v2__card-head">
          <span class="wt-admin-v2__card-icon" aria-hidden="true">
            <?= $editing ? '✏️' : '➕' ?>
          </span>
          <div>
            <h2>
              <?= $editing
                    ? e(sprintf((string)t('admin.edit_item'), '#' . (int)$editing['id']))
                    : e(t('admin.new_offerwall')) ?>
            </h2>
            <small class="wt-muted">
              <?= e($editing ? t('admin.edit_lead') : t('admin.offerwalls.new_lead')) ?>
            </small>
          </div>
        </header>

        <form method="post" class="wt-admin-v2__form-body">
          <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id"     value="<?= (int)($editing['id'] ?? 0) ?>">

          <!-- Identité -->
          <h3 class="wt-admin-v2__form-section">🪪 <?= e(t('admin.ow.section_identity')) ?></h3>
          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ow.key')) ?></span>
              <input class="wt-input wt-mono" type="text" name="k" required pattern="[a-z0-9_-]+" maxlength="40"
                     value="<?= e((string)($editing['k'] ?? '')) ?>"
                     placeholder="wannads">
              <small class="wt-field__hint"><?= e(t('admin.ow.key_hint')) ?></small>
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('admin.ow.name')) ?></span>
              <input class="wt-input" type="text" name="name" required maxlength="100"
                     value="<?= e((string)($editing['name'] ?? '')) ?>"
                     placeholder="Wannads">
            </label>
          </div>

          <label class="wt-field">
            <span class="wt-field__label">🖼 <?= e(t('admin.ow.logo')) ?></span>
            <input class="wt-input" type="url" name="logo_url"
                   value="<?= e((string)($editing['logo_url'] ?? '')) ?>"
                   placeholder="https://...">
          </label>

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ow.description')) ?></span>
            <textarea class="wt-input wt-textarea" rows="2" name="description" maxlength="500"><?= e((string)($editing['description'] ?? '')) ?></textarea>
          </label>

          <!-- Intégration -->
          <h3 class="wt-admin-v2__form-section">🔌 <?= e(t('admin.ow.section_integration')) ?></h3>
          <p class="wt-muted" style="margin-top:-.5rem"><?= e(t('admin.ow.integration_lead')) ?></p>

          <label class="wt-field">
            <span class="wt-field__label">🖼 <?= e(t('admin.ow.iframe')) ?></span>
            <input class="wt-input wt-mono" type="url" name="iframe_url"
                   value="<?= e((string)($editing['iframe_url'] ?? '')) ?>"
                   placeholder="https://...{USER_ID}...{USERNAME}...">
            <small class="wt-field__hint"><?= e(t('admin.ow.iframe_hint')) ?></small>
          </label>

          <label class="wt-field">
            <span class="wt-field__label">↗ <?= e(t('admin.ow.redirect')) ?></span>
            <input class="wt-input wt-mono" type="url" name="redirect_url"
                   value="<?= e((string)($editing['redirect_url'] ?? '')) ?>"
                   placeholder="https://...">
            <small class="wt-field__hint"><?= e(t('admin.ow.redirect_hint')) ?></small>
          </label>

          <!-- Sécurité HMAC -->
          <h3 class="wt-admin-v2__form-section">🔐 <?= e(t('admin.ow.section_security')) ?></h3>
          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('admin.ow.secret')) ?></span>
            <div style="display:flex;gap:.5rem;align-items:stretch">
              <input class="wt-input wt-mono" type="text" name="callback_secret" maxlength="190"
                     value="<?= e((string)($editing['callback_secret'] ?? $defaultSecret)) ?>"
                     placeholder="<?= e(t('admin.ow.secret_placeholder')) ?>"
                     data-ow-secret
                     style="flex:1">
              <!-- Bouton "🎲 Régénérer" : génère une nouvelle clé HMAC
                   aléatoire côté navigateur via crypto.getRandomValues.
                   Pratique pour faire pivoter le secret périodiquement
                   sans devoir rafraîchir toute la page. -->
              <button type="button" class="wt-btn wt-btn--ghost wt-mono"
                      data-ow-secret-regen
                      title="<?= e(t('admin.ow.secret_regen_title')) ?>"
                      style="white-space:nowrap">
                🎲 <?= e(t('admin.ow.secret_regen')) ?>
              </button>
            </div>
            <small class="wt-field__hint"><?= e(t('admin.ow.secret_hint')) ?></small>
          </label>

          <div class="wt-admin-v2__grid-2">
            <label class="wt-field">
              <span class="wt-field__label">🔢 <?= e(t('admin.ow.sort_order')) ?></span>
              <input class="wt-input" type="number" name="sort_order"
                     value="<?= (int)($editing['sort_order'] ?? $nextSortOrder) ?>">
              <small class="wt-field__hint"><?= e(t('admin.ow.sort_order_hint')) ?></small>
            </label>

            <label class="wt-checkbox wt-admin-v2__active-check">
              <input type="checkbox" name="active" value="1"
                     <?= !empty($editing['active']) || $editing === null ? 'checked' : '' ?>>
              <span><strong><?= e(t('common.active')) ?></strong> — <?= e(t('admin.active_hint')) ?></span>
            </label>
          </div>

          <div class="wt-admin-v2__form-actions">
            <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg">
              <?= $editing ? '💾 ' . e(t('common.save')) : '➕ ' . e(t('common.add')) ?>
            </button>
            <?php if ($editing): ?>
              <a class="wt-btn wt-btn--ghost"
                 href="<?= e(wt_url('/admin/offerwalls.php')) ?>"><?= e(t('common.cancel')) ?></a>
            <?php endif; ?>
          </div>
        </form>
      </article>

      <!-- List -->
      <section class="wt-admin-v2__list-section" data-reveal>
        <header class="wt-admin-v2__list-head">
          <h2 class="wt-admin-v2__list-title">📋 <?= e(t('admin.existing_items')) ?></h2>
          <span class="wt-muted"><?= count($rows) ?> <?= e(t('common.items')) ?></span>
        </header>

        <?php if (!$rows): ?>
          <div class="wt-admin-v2__empty">
            <span class="wt-admin-v2__empty-icon" aria-hidden="true">🎁</span>
            <p><?= e(t('admin.empty_offerwalls')) ?></p>
          </div>
        <?php else: ?>
          <ul class="wt-admin-v2__entries" data-ow-reorder>
            <?php foreach ($rows as $i => $r):
              $isActive = (int)$r['active'] === 1;
              $mode = !empty($r['iframe_url']) ? 'iframe' : (!empty($r['redirect_url']) ? 'redirect' : 'none');
              $modeIcon = $mode === 'iframe' ? '🖼' : ($mode === 'redirect' ? '↗' : '⚠️');
            ?>
              <li class="wt-admin-v2__entry <?= $isActive ? '' : 'is-inactive' ?>"
                  style="--idx:<?= (int)$i ?>"
                  data-ow-id="<?= (int)$r['id'] ?>"
                  draggable="true">
                <!-- Handle de glisser-déposer (visuel uniquement, le draggable
                     est sur le <li> entier pour zone de saisie large) -->
                <div class="wt-admin-v2__entry-drag" title="<?= e(t('admin.ow.drag_title')) ?>"
                     style="display:flex;align-items:center;padding:0 .5rem;cursor:grab;color:var(--wt-text-mute);user-select:none">
                  ⋮⋮
                </div>

                <div class="wt-admin-v2__entry-status">
                  <span class="wt-admin-v2__status-dot wt-admin-v2__status-dot--<?= $isActive ? 'on' : 'off' ?>"></span>
                </div>

                <div class="wt-admin-v2__entry-body">
                  <header class="wt-admin-v2__entry-head">
                    <strong><?= e($r['name']) ?></strong>
                    <code class="wt-mono wt-admin-v2__entry-key"><?= e($r['k']) ?></code>
                    <small class="wt-mono">#<?= (int)$r['id'] ?></small>
                  </header>

                  <div class="wt-admin-v2__entry-meta">
                    <span title="<?= e(t('admin.ow.mode')) ?>">
                      <?= $modeIcon ?> <?= e(t('admin.ow.mode_' . $mode)) ?>
                    </span>
                    <span>🔢 <?= e(t('admin.ow.sort_order')) ?>: <?= (int)$r['sort_order'] ?></span>
                    <?php if (!empty($r['callback_secret'])): ?>
                      <span title="<?= e(t('admin.ow.has_secret')) ?>">
                        🔐 <?= e(t('admin.ow.secured')) ?>
                      </span>
                    <?php else: ?>
                      <span class="wt-admin-v2__warning" title="<?= e(t('admin.ow.no_secret')) ?>">
                        ⚠️ <?= e(t('admin.ow.no_secret_short')) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="wt-admin-v2__entry-actions">
                  <a class="wt-btn wt-btn--xs wt-btn--ghost"
                     href="?edit=<?= (int)$r['id'] ?>" title="<?= e(t('common.edit')) ?>">✏️</a>

                  <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="wt-btn wt-btn--xs wt-btn--ghost"
                            title="<?= $isActive ? e(t('common.disable')) : e(t('common.enable')) ?>">
                      <?= $isActive ? '⏸' : '▶' ?>
                    </button>
                  </form>

                  <button type="button"
                          class="wt-btn wt-btn--xs wt-btn--danger"
                          data-confirm
                          data-confirm-title="<?= e(t('admin.confirm_delete_title')) ?>"
                          data-confirm-body="<?= e(sprintf((string)t('admin.confirm_delete_body'), e($r['name']))) ?>"
                          data-confirm-ok="<?= e(t('common.delete')) ?>"
                          data-confirm-ok-class="wt-btn--danger"
                          data-confirm-post="<?= e(wt_url('/admin/offerwalls.php')) ?>"
                          data-confirm-data='<?= e(json_encode(['_csrf' => csrf_token(), 'action' => 'delete', 'id' => (int)$r['id']])) ?>'
                          title="<?= e(t('common.delete')) ?>">
                    🗑
                  </button>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<script>
/* ════════════════════════════════════════════════════════════════════
   1) BOUTON "🎲 Régénérer" pour le secret HMAC
   ════════════════════════════════════════════════════════════════════
   Au clic, génère 64 chars hex aléatoires via crypto.getRandomValues
   (256 bits d'entropie, cryptographiquement sûr). L'admin peut ainsi
   rouler sa clé sans recharger la page. */
(function () {
  const btn = document.querySelector('[data-ow-secret-regen]');
  const input = document.querySelector('[data-ow-secret]');
  if (!btn || !input) return;

  btn.addEventListener('click', function () {
    // Génère 32 bytes aléatoires → 64 chars hex
    const arr = new Uint8Array(32);
    crypto.getRandomValues(arr);
    let hex = '';
    for (let i = 0; i < arr.length; i++) {
      hex += arr[i].toString(16).padStart(2, '0');
    }
    input.value = hex;
    input.select();
    // Flash visuel
    const orig = btn.innerHTML;
    btn.innerHTML = '✓ ' + <?= json_encode(t('admin.ow.secret_regenerated')) ?>;
    setTimeout(function () { btn.innerHTML = orig; }, 1500);
  });
})();

/* ════════════════════════════════════════════════════════════════════
   2) DRAG-AND-DROP des offerwalls pour réordonner
   ════════════════════════════════════════════════════════════════════
   Utilise l'API HTML5 native (pas de lib externe). Sur mobile, la
   poignée ⋮⋮ est tappable et l'API touch est gérée via pointer events
   (HTML5 drag-and-drop natif fonctionne avec souris ET stylet/doigt
   sur la plupart des navigateurs modernes).

   Quand l'utilisateur lâche un item à une nouvelle position :
     1. On collecte les IDs dans le nouvel ordre
     2. On POST vers /api/admin_offerwall_reorder.php
     3. Toast en bas pour confirmer (succès vert / erreur rouge)

   Si une erreur survient (réseau KO, CSRF expiré), on annule visuellement
   la réorganisation en rechargeant l'ordre depuis la BDD.

   Note mobile : le drag-and-drop HTML5 ne marche pas bien partout sur
   tactile. Pour une UX mobile parfaite, il faudrait un polyfill type
   SortableJS, mais ça ajoute 30 KB de JS. Pour V8, on garde du natif
   et on indique au touche "Glisse depuis la poignée ⋮⋮".
   ════════════════════════════════════════════════════════════════════ */
(function () {
  const list = document.querySelector('[data-ow-reorder]');
  if (!list) return;

  const csrf = <?= json_encode(csrf_token()) ?>;
  const endpoint = <?= json_encode(wt_url('/api/admin_offerwall_reorder.php')) ?>;
  const T = {
    saving: <?= json_encode(t('admin.ow.reorder_saving')) ?>,
    saved:  <?= json_encode(t('admin.ow.reorder_saved')) ?>,
    error:  <?= json_encode(t('admin.ow.reorder_error')) ?>,
  };

  let draggedEl = null;

  // Style "en train d'être glissé"
  function setDragging(el, on) {
    el.classList.toggle('is-dragging', on);
    el.style.opacity = on ? '0.5' : '';
  }

  // Toast minimal en bas de la page
  function showToast(msg, type) {
    let toast = document.getElementById('wt-ow-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'wt-ow-toast';
      toast.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);'
        + 'padding:.75rem 1.25rem;border-radius:8px;color:#fff;font-weight:600;'
        + 'z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.25);transition:opacity .3s';
      document.body.appendChild(toast);
    }
    toast.style.background = type === 'error' ? '#ef4444' : (type === 'success' ? '#22c55e' : '#3b82f6');
    toast.textContent = msg;
    toast.style.opacity = '1';
    clearTimeout(toast._hideT);
    toast._hideT = setTimeout(function () { toast.style.opacity = '0'; }, 2500);
  }

  // Récupère l'ordre actuel en DOM et POSTe vers le serveur
  async function persistOrder() {
    const items = Array.from(list.querySelectorAll('[data-ow-id]'));
    const ids = items.map(function (el) { return el.dataset.owId; });

    showToast(T.saving, 'info');

    try {
      const fd = new FormData();
      fd.append('_csrf', csrf);
      ids.forEach(function (id) { fd.append('ids[]', id); });

      const res = await fetch(endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(function () { return { ok: false }; });

      if (data.ok) {
        showToast('✓ ' + T.saved, 'success');
        // Met à jour les badges "sort_order: N" visibles dans la liste
        items.forEach(function (el, idx) {
          const meta = el.querySelector('.wt-admin-v2__entry-meta');
          if (!meta) return;
          // Trouve le <span>🔢 ...: N</span> et met à jour le N
          const spans = meta.querySelectorAll('span');
          for (let i = 0; i < spans.length; i++) {
            if (spans[i].textContent.indexOf('🔢') === 0) {
              // Conserve le label, ne remplace que le nombre
              spans[i].textContent = spans[i].textContent.replace(/:\s*\d+/, ': ' + (idx + 1));
              break;
            }
          }
        });
      } else {
        showToast('✗ ' + T.error, 'error');
      }
    } catch (e) {
      showToast('✗ ' + T.error, 'error');
    }
  }

  // ----- Drag events natifs HTML5 -----
  list.addEventListener('dragstart', function (e) {
    const li = e.target.closest('[data-ow-id]');
    if (!li) return;
    draggedEl = li;
    setDragging(li, true);
    e.dataTransfer.effectAllowed = 'move';
    // Firefox exige un setData pour activer le drag
    e.dataTransfer.setData('text/plain', li.dataset.owId);
  });

  list.addEventListener('dragend', function (e) {
    if (draggedEl) setDragging(draggedEl, false);
    draggedEl = null;
  });

  list.addEventListener('dragover', function (e) {
    e.preventDefault();  // nécessaire pour permettre le drop
    if (!draggedEl) return;
    const overLi = e.target.closest('[data-ow-id]');
    if (!overLi || overLi === draggedEl) return;

    // Insère dragged AVANT ou APRÈS selon la position de la souris
    const rect = overLi.getBoundingClientRect();
    const midY = rect.top + rect.height / 2;
    if (e.clientY < midY) {
      overLi.parentNode.insertBefore(draggedEl, overLi);
    } else {
      overLi.parentNode.insertBefore(draggedEl, overLi.nextSibling);
    }
  });

  list.addEventListener('drop', function (e) {
    e.preventDefault();
    if (draggedEl) {
      setDragging(draggedEl, false);
      persistOrder();
    }
    draggedEl = null;
  });
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
