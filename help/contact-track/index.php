<?php
/**
 * Wintaskly — /help/contact-track/<token>  (V8 modernisé)
 *
 * Page de suivi de ticket pour les visiteurs anonymes.
 * Accès via le lien personnalisé reçu après soumission du formulaire.
 *
 * Routage (inchangé) :
 *   - Apache + .htaccess  → rewrite `/help/contact-track/<token>` vers
 *     `index.php?token=<token>` → $_GET['token'] disponible
 *   - PHP built-in / Nginx sans rewrite → extraction depuis REQUEST_URI
 *
 * Améliorations V8 :
 *   - Card de ticket en tête avec status visuel + meta (créé/dernière réponse)
 *   - Thread en bulles différenciées (guest à gauche, admin à droite)
 *   - Timeline avec horodatages relatifs
 *   - Card de réponse plus visible
 *   - Badge "✨ Nouvelle réponse" si messages admin non lus
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';

/* ---------------------------------------------------------------------
 * Récupération du token : 2 sources possibles (Apache vs built-in).
 * --------------------------------------------------------------------- */
$token = trim((string)($_GET['token'] ?? ''));

if ($token === '') {
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    $path = rawurldecode($path);
    if (preg_match('#/help/contact-track/([a-f0-9]{32,64})/?$#i', $path, $m)) {
        $token = strtolower($m[1]);
    }
}

if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    $pageTitle = '404';
    include __DIR__ . '/../../header.php';
    ?>
    <main class="wt-main wt-track-v2">
      <div class="wt-track-v2__wrap wt-track-v2__error">
        <span class="wt-track-v2__error-icon" aria-hidden="true">🔒</span>
        <h1><?= e(t('contact.track_invalid_title')) ?></h1>
        <p class="wt-muted"><?= e(t('contact.track_invalid')) ?></p>
        <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
          ← <?= e(t('contact.title')) ?>
        </a>
      </div>
    </main>
    <?php
    include __DIR__ . '/../../footer.php';
    exit;
}

$db = db();
$stmt = $db->prepare(
    "SELECT id, guest_name, guest_email, subject, status, created_at, last_reply_at, last_reply_by
       FROM support_tickets
      WHERE guest_token = ?
      LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    http_response_code(404);
    $pageTitle = '404';
    include __DIR__ . '/../../header.php';
    ?>
    <main class="wt-main wt-track-v2">
      <div class="wt-track-v2__wrap wt-track-v2__error">
        <span class="wt-track-v2__error-icon" aria-hidden="true">🔍</span>
        <h1><?= e(t('contact.track_notfound_title')) ?></h1>
        <p class="wt-muted"><?= e(t('contact.track_invalid')) ?></p>
        <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
          ← <?= e(t('contact.title')) ?>
        </a>
      </div>
    </main>
    <?php
    include __DIR__ . '/../../footer.php';
    exit;
}

/* Compter les messages admin non lus AVANT de marquer comme lus (pour le badge) */
$stmt = $db->prepare(
    "SELECT COUNT(*) c FROM support_messages
      WHERE ticket_id = ?
        AND author_role = 'admin'
        AND read_at IS NULL"
);
$stmt->bind_param('i', $ticket['id']);
$stmt->execute();
$unreadCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Marquer les messages admin → guest comme lus (utilise un prepared
 * pour ne pas dépendre de la concatenation directe d'IDs) */
$upd = $db->prepare(
    "UPDATE support_messages
        SET read_at = UTC_TIMESTAMP()
      WHERE ticket_id = ?
        AND author_role = 'admin'
        AND read_at IS NULL"
);
$upd->bind_param('i', $ticket['id']);
$upd->execute();
$upd->close();

/* Charger le fil */
$msgs = [];
$stmt = $db->prepare(
    "SELECT id, author_role, body, created_at
       FROM support_messages
      WHERE ticket_id = ?
      ORDER BY id ASC"
);
$stmt->bind_param('i', $ticket['id']);
$stmt->execute();
$res = $stmt->get_result();
$msgs = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Helpers initiales (utilisé pour les avatars d'invité) */
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));
    return ($a . $b) ?: '?';
};

$statusKey   = (string) $ticket['status'];
$statusClass = match ($statusKey) {
    'closed'   => 'closed',
    'answered' => 'answered',
    default    => 'open',
};

$pageTitle = t('contact.track_title');
include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-track-v2">
  <div class="wt-track-v2__wrap">

    <!-- ====== HEADER ====== -->
    <header class="wt-track-v2__header" data-reveal>
      <a class="wt-track-v2__back" href="<?= e(wt_url('/help/')) ?>">
        ← <?= e(t('contact.track_back')) ?>
      </a>

      <div class="wt-track-v2__title-row">
        <span class="wt-eyebrow">📨 <?= e(t('contact.track_eyebrow')) ?></span>
        <?php if ($unreadCount > 0): ?>
          <span class="wt-track-v2__new-badge" aria-label="<?= e(t('contact.track_new_replies')) ?>">
            ✨ <?= e(sprintf((string) t('contact.track_unread'), $unreadCount)) ?>
          </span>
        <?php endif; ?>
      </div>

      <h1 class="wt-track-v2__title"><?= e($ticket['subject']) ?></h1>
    </header>

    <!-- ====== TICKET CARD : status + meta ====== -->
    <section class="wt-track-v2__ticket-card" data-reveal>
      <div class="wt-track-v2__ticket-status">
        <span class="wt-track-v2__status-dot wt-track-v2__status-dot--<?= e($statusClass) ?>" aria-hidden="true"></span>
        <span class="wt-track-v2__status-label wt-track-v2__status-label--<?= e($statusClass) ?>">
          <?= e(t('ticket.status.' . $statusKey)) ?>
        </span>
      </div>

      <ul class="wt-track-v2__ticket-meta">
        <li>
          <small><?= e(t('common.created')) ?></small>
          <strong>
            <time data-fmt-time data-utc="<?= e($ticket['created_at']) ?>" data-format="relative">
              <?= e(wt_format_datetime($ticket['created_at'])) ?>
            </time>
          </strong>
        </li>
        <?php if (!empty($ticket['last_reply_at'])): ?>
          <li>
            <small><?= e(t('contact.last_reply')) ?></small>
            <strong>
              <time data-fmt-time data-utc="<?= e($ticket['last_reply_at']) ?>" data-format="relative">
                <?= e(wt_format_datetime($ticket['last_reply_at'])) ?>
              </time>
              <?php if (!empty($ticket['last_reply_by'])): ?>
                <span class="wt-track-v2__ticket-by">
                  · <?= e(t('contact.by_' . $ticket['last_reply_by'])) ?>
                </span>
              <?php endif; ?>
            </strong>
          </li>
        <?php endif; ?>
        <li>
          <small><?= e(t('contact.messages_count')) ?></small>
          <strong><?= count($msgs) ?></strong>
        </li>
      </ul>
    </section>

    <!-- ====== THREAD ====== -->
    <section class="wt-track-v2__thread" data-reveal>
      <?php foreach ($msgs as $i => $m):
          $isAdmin = $m['author_role'] === 'admin';
          $authorName = $isAdmin
                      ? (string) t('ticket.role.admin')
                      : ($ticket['guest_name'] ?: (string) t('ticket.role.guest'));
          $initialsTxt = $isAdmin ? 'WT' : $initials($authorName);
      ?>
        <article class="wt-track-v2__msg wt-track-v2__msg--<?= $isAdmin ? 'admin' : 'guest' ?>"
                 style="--idx:<?= (int)$i ?>">
          <div class="wt-track-v2__msg-avatar"
               <?= !$isAdmin ? 'data-hash-color="' . e($authorName) . '"' : '' ?>
               aria-hidden="true">
            <?= e($initialsTxt) ?>
          </div>
          <div class="wt-track-v2__msg-content">
            <header class="wt-track-v2__msg-head">
              <strong><?= e($authorName) ?></strong>
              <?php if ($isAdmin): ?>
                <span class="wt-track-v2__msg-badge">✓ <?= e(t('ticket.role.admin_team')) ?></span>
              <?php endif; ?>
              <time data-fmt-time data-utc="<?= e($m['created_at']) ?>" data-format="relative">
                <?= e(wt_format_datetime($m['created_at'])) ?>
              </time>
            </header>
            <div class="wt-track-v2__msg-bubble">
              <?= nl2br(e($m['body'])) ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- ====== FORMULAIRE DE RÉPONSE / TICKET CLOS ====== -->
    <?php if ($ticket['status'] === 'closed'): ?>
      <section class="wt-track-v2__closed" data-reveal>
        <span class="wt-track-v2__closed-icon" aria-hidden="true">🔒</span>
        <h2><?= e(t('contact.track_closed_title')) ?></h2>
        <p><?= e(t('contact.track_closed_text')) ?></p>
        <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
          ✉️ <?= e(t('contact.track_new_ticket')) ?>
        </a>
      </section>
    <?php else: ?>
      <section class="wt-track-v2__reply" data-reveal>
        <h2 class="wt-track-v2__reply-title">
          ✍️ <?= e(t('contact.reply')) ?>
        </h2>

        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <form class="wt-form wt-track-v2__reply-form"
              data-auth-form
              data-endpoint="<?= e(wt_url('/api/contact_reply.php')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="token" value="<?= e($token) ?>">

          <label class="wt-field">
            <span class="wt-field__label"><?= e(t('contact.message')) ?></span>
            <textarea class="wt-input wt-textarea" name="body" rows="5" required
                      maxlength="5000"
                      placeholder="<?= e(t('contact.reply_placeholder')) ?>"></textarea>
          </label>

          <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg" data-submit-btn>
            <span class="wt-btn__label">✈️ <?= e(t('contact.send_reply')) ?></span>
            <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
          </button>

          <p class="wt-track-v2__reply-note">
            🔒 <?= e(t('contact.reply_security_note')) ?>
          </p>
        </form>
      </section>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
