<?php
/**
 * Wintaskly — /dashboard/messages.php (V8 modernisé).
 *
 * Boîte de réception unifiée :
 *   - Onglet « Messages » : table `messages` (admin → user, système)
 *   - Onglet « Tickets »  : `support_tickets` de l'utilisateur, avec
 *     leur fil `support_messages`.
 *
 * Améliorations V8 :
 *   - Tabs en pills (au lieu d'underline)
 *   - Inbox : cards avec icône type + preview de message
 *   - Tickets list : cards avec status dot + meta (date, count)
 *   - Thread : bulles différenciées (user à droite, admin à gauche)
 *     cohérent avec /help/contact-track/
 *
 * Compat : hooks bulk-delete et data-msg-toggle préservés.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle = t('msg.title');
$u  = current_user();
$db = db();

$tab = $_GET['tab'] ?? 'inbox';
if (!in_array($tab, ['inbox', 'tickets'], true)) $tab = 'inbox';

/* Charger un ticket spécifique si ?ticket=… */
$openTicket = null;
$openMsgs   = [];
if (!empty($_GET['ticket']) && $tab === 'tickets') {
    $tid = (int) $_GET['ticket'];
    $stmt = $db->prepare(
        "SELECT id, subject, status, last_reply_at, created_at
           FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1"
    );
    $stmt->bind_param('ii', $tid, $u['id']);
    $stmt->execute();
    $openTicket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($openTicket) {
        $stmt = $db->prepare(
            "UPDATE support_messages
                SET read_at = UTC_TIMESTAMP()
              WHERE ticket_id = ?
                AND author_role = 'admin'
                AND read_at IS NULL"
        );
        $stmt->bind_param('i', $openTicket['id']);
        $stmt->execute();
        $stmt->close();
        $stmt = $db->prepare(
            "SELECT id, author_role, body, created_at
               FROM support_messages
              WHERE ticket_id = ?
              ORDER BY id ASC"
        );
        $stmt->bind_param('i', $openTicket['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $openMsgs = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

/* Messages (inbox) */
$messages = [];
if ($tab === 'inbox') {
    $stmt = $db->prepare(
        "SELECT id, sender_role, subject, body, read_at, created_at
           FROM messages
          WHERE user_id = ?
            AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
          ORDER BY id DESC
          LIMIT 100"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

/* Tickets list avec compteur de messages admin non lus */
$tickets = [];
if ($tab === 'tickets') {
    $sql = "SELECT t.*, (
              SELECT COUNT(*) FROM support_messages sm
               WHERE sm.ticket_id = t.id
                 AND sm.author_role = 'admin'
                 AND sm.read_at IS NULL
            ) AS unread_admin,
            (SELECT COUNT(*) FROM support_messages sm2
              WHERE sm2.ticket_id = t.id) AS total_msgs
              FROM support_tickets t
             WHERE t.user_id = ?
             ORDER BY t.last_reply_at DESC, t.id DESC
             LIMIT 60";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $tickets = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$dashActive = 'messages';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-dash__content wt-dash-v2__content">

      <!-- ============ HEADER + TABS ============ -->
      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">📬 <?= e(t('msg.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('msg.title')) ?></h1>
        <p class="wt-muted"><?= e(t('msg.lead')) ?></p>

        <nav class="wt-dash-v2__tabs" aria-label="Tabs">
          <a class="wt-dash-v2__tab <?= $tab==='inbox'?'is-active':'' ?>"
             href="?tab=inbox">
            📥 <?= e(t('msg.tab_inbox')) ?>
          </a>
          <a class="wt-dash-v2__tab <?= $tab==='tickets'?'is-active':'' ?>"
             href="?tab=tickets">
            🎫 <?= e(t('msg.tab_tickets')) ?>
          </a>
        </nav>
      </header>

      <?php if ($tab === 'inbox'): ?>

        <?php if (!$messages): ?>
          <div class="wt-dash-v2__empty" data-reveal>
            <span class="wt-dash-v2__empty-icon" aria-hidden="true">📭</span>
            <p><?= e(t('msg.empty')) ?></p>
          </div>
        <?php else: ?>

          <!-- Bulk toolbar -->
          <div class="wt-dash-v2__bulk" data-bulk-toolbar data-reveal>
            <label class="wt-checkbox">
              <input type="checkbox" data-bulk-toggle-all>
              <span><?= e(t('common.select_all')) ?></span>
            </label>
            <button type="button" class="wt-btn wt-btn--xs wt-btn--danger" data-bulk-delete
                    data-endpoint="<?= e(wt_url('/api/message_delete.php')) ?>"
                    data-csrf="<?= e(csrf_token()) ?>">
              🗑 <?= e(t('common.delete_selected')) ?>
            </button>
          </div>

          <ul class="wt-dash-v2__msglist" data-reveal>
            <?php foreach ($messages as $i => $m):
              $unread = $m['read_at'] === null;
              // Icône selon sender role
              $icon = match ($m['sender_role'] ?? 'system') {
                  'admin'  => '👤',
                  'system' => '🤖',
                  default  => '✉️',
              };
            ?>
              <li class="wt-dash-v2__msg <?= $unread ? 'is-unread' : '' ?>"
                  data-msg-id="<?= (int)$m['id'] ?>"
                  style="--idx:<?= (int)$i ?>">
                <label class="wt-checkbox wt-dash-v2__msg-check">
                  <input type="checkbox" data-bulk-item value="<?= (int)$m['id'] ?>">
                  <span class="sr-only"><?= e(t('common.select')) ?></span>
                </label>
                <details data-msg-toggle data-id="<?= (int)$m['id'] ?>" class="wt-dash-v2__msg-details">
                  <summary class="wt-dash-v2__msg-summary">
                    <span class="wt-dash-v2__msg-icon" aria-hidden="true"><?= $icon ?></span>
                    <div class="wt-dash-v2__msg-meta">
                      <strong><?= e($m['subject']) ?></strong>
                      <small>
                        <span data-fmt-time data-utc="<?= e($m['created_at']) ?>" data-format="relative">
                          <?= e(wt_format_datetime($m['created_at'])) ?>
                        </span>
                      </small>
                    </div>
                    <?php if ($unread): ?>
                      <span class="wt-dash-v2__msg-dot" aria-hidden="true"></span>
                    <?php endif; ?>
                  </summary>
                  <div class="wt-dash-v2__msg-body"><?= nl2br(e($m['body'])) ?></div>
                </details>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      <?php else: /* tickets */ ?>

        <?php if ($openTicket): ?>
          <!-- ============ THREAD DU TICKET OUVERT ============ -->
          <a class="wt-dash-v2__back" href="?tab=tickets" data-reveal>
            ← <?= e(t('common.back')) ?>
          </a>

          <header class="wt-dash-v2__ticket-header" data-reveal>
            <h2 class="wt-dash-v2__section-title"><?= e($openTicket['subject']) ?></h2>
            <div class="wt-dash-v2__ticket-status">
              <span class="wt-dash-v2__status-dot wt-dash-v2__status-dot--<?= e(
                $openTicket['status'] === 'closed'   ? 'closed' :
                ($openTicket['status'] === 'answered' ? 'answered' : 'open')
              ) ?>"></span>
              <span><?= e(t('ticket.status.' . $openTicket['status'])) ?></span>
            </div>
          </header>

          <section class="wt-dash-v2__thread" data-reveal>
            <?php foreach ($openMsgs as $i => $m):
              $isAdmin = $m['author_role'] === 'admin';
            ?>
              <article class="wt-dash-v2__bubble wt-dash-v2__bubble--<?= $isAdmin ? 'admin' : 'me' ?>"
                       style="--idx:<?= (int)$i ?>">
                <div class="wt-dash-v2__bubble-avatar"
                     <?= !$isAdmin ? 'data-hash-color="' . e($u['username']) . '"' : '' ?>>
                  <?= $isAdmin ? 'WT' : e(mb_strtoupper(mb_substr($u['username'], 0, 1)) . mb_strtoupper(mb_substr($u['username'], 1, 1))) ?>
                </div>
                <div class="wt-dash-v2__bubble-content">
                  <header class="wt-dash-v2__bubble-head">
                    <strong>
                      <?= $isAdmin
                            ? e(t('ticket.role.admin'))
                            : e($u['username']) ?>
                    </strong>
                    <?php if ($isAdmin): ?>
                      <span class="wt-dash-v2__bubble-badge">✓ <?= e(t('ticket.role.admin_team')) ?></span>
                    <?php endif; ?>
                    <time data-fmt-time data-utc="<?= e($m['created_at']) ?>" data-format="relative">
                      <?= e(wt_format_datetime($m['created_at'])) ?>
                    </time>
                  </header>
                  <div class="wt-dash-v2__bubble-body"><?= nl2br(e($m['body'])) ?></div>
                </div>
              </article>
            <?php endforeach; ?>
          </section>

          <?php if ($openTicket['status'] !== 'closed'): ?>
            <section class="wt-dash-v2__reply" data-reveal>
              <h3 class="wt-dash-v2__section-title">✍️ <?= e(t('contact.reply')) ?></h3>
              <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
              <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>
              <form class="wt-form"
                    data-auth-form
                    data-endpoint="<?= e(wt_url('/api/contact_reply.php')) ?>">
                <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
                <label class="wt-field">
                  <span class="wt-field__label"><?= e(t('contact.message')) ?></span>
                  <textarea class="wt-input wt-textarea" name="body" rows="5"
                            required maxlength="5000"
                            placeholder="<?= e(t('contact.reply_placeholder')) ?>"></textarea>
                </label>
                <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg" data-submit-btn>
                  <span class="wt-btn__label">✈️ <?= e(t('contact.send_reply')) ?></span>
                  <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
                </button>
              </form>
            </section>
          <?php else: ?>
            <div class="wt-dash-v2__closed-notice" data-reveal>
              🔒 <?= e(t('contact.track_closed_text')) ?>
              <a class="wt-btn wt-btn--primary wt-btn--xs" href="<?= e(wt_url('/help/contact.php')) ?>">
                ✉️ <?= e(t('contact.track_new_ticket')) ?>
              </a>
            </div>
          <?php endif; ?>

        <?php elseif (!$tickets): ?>
          <div class="wt-dash-v2__empty" data-reveal>
            <span class="wt-dash-v2__empty-icon" aria-hidden="true">🎫</span>
            <p><?= e(t('msg.no_tickets')) ?></p>
            <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
              ✉️ <?= e(t('contact.title')) ?>
            </a>
          </div>
        <?php else: ?>
          <ul class="wt-dash-v2__tickets" data-reveal>
            <?php foreach ($tickets as $i => $t):
              $unread = (int)$t['unread_admin'] > 0;
              $statusClass = $t['status'] === 'closed'   ? 'closed' :
                            ($t['status'] === 'answered' ? 'answered' : 'open');
            ?>
              <li class="wt-dash-v2__ticket <?= $unread ? 'is-unread' : '' ?>"
                  style="--idx:<?= (int)$i ?>">
                <a href="?tab=tickets&ticket=<?= (int)$t['id'] ?>"
                   class="wt-dash-v2__ticket-link">
                  <span class="wt-dash-v2__status-dot wt-dash-v2__status-dot--<?= e($statusClass) ?>"></span>
                  <div class="wt-dash-v2__ticket-body">
                    <div class="wt-dash-v2__ticket-row">
                      <strong><?= e($t['subject']) ?></strong>
                      <?php if ($unread): ?>
                        <span class="wt-pill-badge wt-pill-badge--inline">
                          <?= e(wt_badge_count((int)$t['unread_admin'])) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                    <small class="wt-muted">
                      <?= (int)$t['total_msgs'] ?> <?= e(t('contact.messages_count')) ?>
                      · <span data-fmt-time data-utc="<?= e($t['last_reply_at'] ?? $t['created_at']) ?>" data-format="relative">
                        <?= e(wt_format_datetime($t['last_reply_at'] ?? $t['created_at'])) ?>
                      </span>
                    </small>
                  </div>
                  <span class="wt-dash-v2__ticket-arrow" aria-hidden="true">→</span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
