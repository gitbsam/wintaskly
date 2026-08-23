<?php
/**
 * Wintaskly — /tasks/offerwalls/open.php  (V8 modernisé)
 *
 * Affiche un offerwall partenaire dans une iframe responsive.
 * Le placeholder {USER_ID} dans iframe_url est remplacé par l'ID
 * de l'utilisateur courant (postback nominatif côté provider).
 *
 * Améliorations V8 :
 *   - Header riche avec logo + nom + description partenaire
 *   - Bouton retour propre (plus de inline-style)
 *   - Bandeau info "tu seras crédité automatiquement par postback"
 *   - Iframe avec frame discret + loader placeholder
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
require_auth();

$u  = current_user();
$db = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . wt_url('/tasks/offerwalls/'));
    exit;
}

$stmt = $db->prepare(
    "SELECT id, k, name, logo_url, description, iframe_url
       FROM offerwalls WHERE id = ? AND active = 1 LIMIT 1"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$ow = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Mur introuvable ou inactif : contrôle en premier, avant toute lecture
   de $ow — sinon un identifiant invalide déclencherait une erreur PHP. */
if (!$ow) {
    header('Location: ' . wt_url('/tasks/offerwalls/'));
    exit;
}

/* CPX Research — l'URL ne peut PAS être stockée en base : elle contient un
   hachage calculé à partir de l'identifiant de l'utilisateur connecté
   (md5(id-secret)). Une URL figée serait la même pour tout le monde, donc
   inutilisable comme preuve d'identité, et chacun verrait le profil du
   premier inscrit. On la construit donc ici. */
$isCpx = ($ow['k'] ?? '') === (string) cfg('cpx.offerwall_key', 'cpx');

if ($isCpx) {
    $appId  = trim((string) cfg('cpx.app_id', ''));
    $secret = trim((string) cfg('cpx.secure_hash', ''));
    if ($appId === '' || $secret === '') {
        /* Réglages incomplets : on renvoie à la liste plutôt que d'afficher
           un mur qui échouerait côté CPX avec un message obscur. */
        header('Location: ' . wt_url('/tasks/offerwalls/?err=notconfigured'));
        exit;
    }

    $uidStr = (string) $u['id'];
    $params = [
        'app_id'      => $appId,
        'ext_user_id' => $uidStr,
        'secure_hash' => md5($uidStr . '-' . $secret),
        'username'    => (string) $u['username'],
        'email'       => (string) ($u['email'] ?? ''),
        'subid_1'     => '',
        'subid_2'     => '',
    ];
    /* message_id : transmis par CPX lors du retour après un sondage. Sans
       lui, l'utilisateur ne voit jamais le message de réussite ou d'échec. */
    if (!empty($_GET['message_id'])) {
        $params['message_id'] = (string) $_GET['message_id'];
    }
    $iframeUrl = 'https://offers.cpx-research.com/index.php?' . http_build_query($params);

} else {
    if (empty($ow['iframe_url'])) {
        header('Location: ' . wt_url('/tasks/offerwalls/'));
        exit;
    }
    $iframeUrl = str_replace(
        ['{USER_ID}', '{USERNAME}'],
        [(string) $u['id'], rawurlencode($u['username'])],
        $ow['iframe_url']
    );
}


$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));
    return ($a . $b) ?: '?';
};

$pageTitle = t('ow.title') . ' — ' . $ow['name'];
include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-ow-v2 wt-ow-v2--open">
  <div class="wt-ow-v2__wrap wt-ow-v2__wrap--full">

    <!-- ====== HEADER avec logo + nom + back ====== -->
    <header class="wt-ow-v2__open-head" data-reveal>
      <a class="wt-ow-v2__open-back" href="<?= e(wt_url('/tasks/offerwalls/')) ?>">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="15 18 9 12 15 6"/>
        </svg>
        <?= e(t('ow.iframe.back')) ?>
      </a>

      <div class="wt-ow-v2__open-brand">
        <div class="wt-ow-v2__open-logo">
          <?php if (!empty($ow['logo_url'])): ?>
            <img src="<?= e($ow['logo_url']) ?>" alt="" decoding="async">
          <?php else: ?>
            <span aria-hidden="true"><?= e($initials($ow['name'])) ?></span>
          <?php endif; ?>
        </div>
        <div class="wt-ow-v2__open-meta">
          <h1 class="wt-ow-v2__open-name"><?= e($ow['name']) ?></h1>
          <?php if (!empty($ow['description'])): ?>
            <p class="wt-ow-v2__open-desc"><?= e($ow['description']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <!-- ====== BANDEAU INFO POSTBACK ====== -->
    <div class="wt-ow-v2__open-banner" data-reveal>
      <span class="wt-ow-v2__open-banner-icon" aria-hidden="true">ℹ️</span>
      <div>
        <strong><?= e(t('ow.open.banner_title')) ?></strong>
        <small><?= e(t('ow.open.banner_text')) ?></small>
      </div>
    </div>

    <!-- ====== IFRAME ====== -->
    <div class="wt-ow-v2__iframe-wrap" data-reveal>
      <div class="wt-ow-v2__iframe-loader" aria-hidden="true">
        <span class="wt-ow-v2__iframe-spinner"></span>
        <span><?= e(t('ow.open.loading')) ?></span>
      </div>
      <iframe class="wt-ow-v2__iframe"
              src="<?= e($iframeUrl) ?>"
              title="<?= e($ow['name']) ?>"
              allow="fullscreen"
              sandbox="allow-scripts allow-forms allow-same-origin allow-popups allow-popups-to-escape-sandbox"
              referrerpolicy="no-referrer-when-downgrade"
              onload="this.previousElementSibling.style.display='none'"></iframe>
    </div>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
