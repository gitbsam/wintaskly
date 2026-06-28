<?php
/**
 * Wintaskly — Admin · File des tickets de support.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
$adminUser   = require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.tickets');
$adminActive = 'tickets';
$db          = db();
$notice      = null;
$error       = null;

/* ----- Actions ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');
    $tid    = (int)   ($_POST['ticket_id'] ?? 0);

    if ($tid > 0) {
        // Charger le ticket pour savoir si user_id ou guest
        $stmt = $db->prepare(
            "SELECT id, user_id, guest_email, guest_name, guest_token
               FROM support_tickets WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $tk = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($tk) {
            if ($action === 'reply') {
                $body = trim((string)($_POST['body'] ?? ''));
                if ($body === '' || wt_strlen($body) > 5000) {
                    $error = t('contact.invalid_body');
                } else {
                    $stmt = $db->prepare(
                        "INSERT INTO support_messages
                           (ticket_id, author_role, author_id, body)
                         VALUES (?, 'admin', ?, ?)"
                    );
                    $stmt->bind_param('iis', $tid, $adminUser['id'], $body);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $db->prepare(
                        "UPDATE support_tickets
                            SET last_reply_by='admin', last_reply_at=UTC_TIMESTAMP(), status='answered'
                          WHERE id = ?"
                    );
                    $stmt->bind_param('i', $tid);
                    $stmt->execute();
                    $stmt->close();
                    // Notifie l'utilisateur connecté…
                    if (!empty($tk['user_id'])) {
                        wt_notify(
                            (int) $tk['user_id'],
                            'support_reply',
                            (string) t('admin.tickets.notif_reply'),
                            null,
                            wt_url('/dashboard/messages.php?tab=tickets&ticket=' . $tid)
                        );
                    }
                    // …ou envoie un e-mail à l'invité avec son lien de suivi
                    if (!empty($tk['guest_email']) && !empty($tk['guest_token'])) {
                        wt_mail($tk['guest_email'], 'security_alert', [
                            'username' => $tk['guest_name'] ?? '',
                            'link'     => wt_url('/help/contact-track/' . $tk['guest_token']),
                            'body'     => (string) t('admin.tickets.mail_body'),
                        ]);
                    }
                    $notice = t('admin.saved');
                }
            } elseif ($action === 'close') {
                $stmt = $db->prepare("UPDATE support_tickets SET status = 'closed' WHERE id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.saved');
            } elseif ($action === 'reopen') {
                $stmt = $db->prepare("UPDATE support_tickets SET status = 'open' WHERE id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.saved');
            } elseif ($action === 'delete') {
                $stmt = $db->prepare("DELETE FROM support_tickets WHERE id = ?");
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();
                $notice = t('admin.deleted');
            }
        }
    }
}

/* ----- Vue détail / liste ----- */
$openTicket = null;
$openMsgs   = [];
if (!empty($_GET['ticket'])) {
    $tid = (int) $_GET['ticket'];
    $stmt = $db->prepare(
        "SELECT t.*, u.username, u.email AS user_email
           FROM support_tickets t
           LEFT JOIN users u ON u.id = t.user_id
          WHERE t.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $tid);
    $stmt->execute();
    $openTicket = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($openTicket) {
        // Marquer messages "user/guest" comme lus côté admin
        $stmt = $db->prepare(
            "UPDATE support_messages
                SET read_at = UTC_TIMESTAMP()
              WHERE ticket_id = ?
                AND author_role IN ('user','guest')
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

/* Liste si pas de détail */
$tickets = [];
$counts  = ['open' => 0, 'answered' => 0, 'closed' => 0];
if (!$openTicket) {
    $sql = "SELECT t.id, t.subject, t.status, t.created_at, t.last_reply_at, t.last_reply_by,
                   t.user_id, t.guest_email, t.guest_name,
                   u.username,
                   (SELECT COUNT(*) FROM support_messages sm
                     WHERE sm.ticket_id = t.id
                       AND sm.author_role IN ('user','guest')
                       AND sm.read_at IS NULL) AS unread_in
              FROM support_tickets t
              LEFT JOIN users u ON u.id = t.user_id
             ORDER BY (t.status='open') DESC, t.last_reply_at DESC, t.id DESC
             LIMIT 100";
    if ($res = $db->query($sql)) {
        $tickets = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    if ($res = $db->query("SELECT status, COUNT(*) c FROM support_tickets GROUP BY status")) {
        while ($r = $res->fetch_assoc()) $counts[$r['status']] = (int) $r['c'];
        $res->free();
    }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">🎫 <?= e(t('admin.eyebrow_tickets')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.tickets')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.tickets.lead')) ?></p>
        </div>
      </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

    <?php if ($openTicket): ?>
      <p style="margin-bottom:1rem">
        <a class="wt-btn wt-btn--ghost wt-btn--xs" href="<?= e(wt_url('/admin/tickets.php')) ?>">← Liste</a>
      </p>

      <header class="wt-page__header">
        <h2 class="wt-section__title"><?= e($openTicket['subject']) ?></h2>
        <p class="wt-muted">
          <?php if (!empty($openTicket['user_id'])): ?>
            User : <strong><?= e($openTicket['username']) ?></strong> (<?= e($openTicket['user_email']) ?>)
          <?php else: ?>
            Invité : <strong><?= e($openTicket['guest_name']) ?></strong> (<?= e($openTicket['guest_email']) ?>)
          <?php endif; ?>
          ·
          Statut :
          <span class="wt-badge wt-badge--<?= e($openTicket['status'] === 'closed' ? 'refused' : ($openTicket['status'] === 'answered' ? 'completed' : 'pending')) ?>">
            <?= e(t('ticket.status.' . $openTicket['status'])) ?>
          </span>
        </p>
      </header>

      <section class="wt-thread">
        <?php foreach ($openMsgs as $m): ?>
          <article class="wt-thread__msg wt-thread__msg--<?= e($m['author_role']) ?>">
            <header class="wt-thread__head">
              <strong>
                <?= $m['author_role']==='admin' ? 'Vous (admin)' :
                    ($m['author_role']==='user' ? e($openTicket['username']) :
                     e($openTicket['guest_name'])) ?>
              </strong>
              <span class="wt-muted" style="font-size:.78rem">
                <span data-fmt-time data-utc="<?= e($m['created_at']) ?>">
                  <?= e(wt_format_datetime($m['created_at'])) ?>
                </span>
              </span>
            </header>
            <div class="wt-thread__body"><?= nl2br(e($m['body'])) ?></div>
          </article>
        <?php endforeach; ?>
      </section>

      <section style="margin-top:1.5rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <?php if ($openTicket['status'] !== 'closed'): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action"    value="close">
            <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
            <button class="wt-btn wt-btn--xs wt-btn--ghost">Fermer le ticket</button>
          </form>
        <?php else: ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action"    value="reopen">
            <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
            <button class="wt-btn wt-btn--xs wt-btn--ghost">Réouvrir</button>
          </form>
        <?php endif; ?>
        <form method="post" style="display:inline"
              onsubmit="return confirm('<?= e(t('admin.confirm_delete')) ?>')">
          <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action"    value="delete">
          <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
          <button class="wt-btn wt-btn--xs wt-btn--danger">Supprimer</button>
        </form>
      </section>

      <?php if ($openTicket['status'] !== 'closed'): ?>
        <section style="margin-top:1.5rem">
          <h3 class="wt-section__title">Répondre</h3>
          <form method="post" class="wt-form">
            <input type="hidden" name="_csrf"     value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action"    value="reply">
            <input type="hidden" name="ticket_id" value="<?= (int)$openTicket['id'] ?>">
            <label class="wt-field wt-field--wide">
              <span class="wt-field__label"><?= e(t('contact.message')) ?></span>
              <textarea class="wt-input wt-textarea" name="body" rows="6" required maxlength="5000"></textarea>
            </label>
            <button class="wt-btn wt-btn--primary">Envoyer la réponse</button>
          </form>
        </section>
      <?php endif; ?>

    <?php else: ?>

      <div class="wt-admin__tabs" style="margin:1rem 0">
        <a class="wt-btn wt-btn--xs wt-btn--ghost is-active" href="?">
          Tous (<?= array_sum($counts) ?>)
        </a>
        <?php foreach ($counts as $st => $c): ?>
          <span class="wt-muted" style="margin-left:.5rem">
            <?= e($st) ?> : <strong><?= (int) $c ?></strong>
          </span>
        <?php endforeach; ?>
      </div>

      <div class="wt-table-wrap">
        <table class="wt-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Auteur</th>
              <th>Sujet</th>
              <th>Statut</th>
              <th>Dernière activité</th>
              <th class="wt-table__actions"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="6" class="wt-muted"><?= e(t('common.empty')) ?></td></tr>
          <?php else: foreach ($tickets as $t):
            $badge = $t['status']==='closed' ? 'refused' : ($t['status']==='answered' ? 'completed' : 'pending');
            $author = !empty($t['user_id'])
                    ? ($t['username'] ?? '—')
                    : (($t['guest_name'] ?? '—') . ' (invité)');
          ?>
            <tr style="<?= (int)$t['unread_in']>0 ? 'font-weight:600' : '' ?>">
              <td><?= (int)$t['id'] ?></td>
              <td><?= e($author) ?></td>
              <td><?= e($t['subject']) ?></td>
              <td>
                <span class="wt-badge wt-badge--<?= e($badge) ?>"><?= e(t('ticket.status.' . $t['status'])) ?></span>
                <?php if ((int)$t['unread_in'] > 0): ?>
                  <span class="wt-pill-badge"><?= (int)$t['unread_in'] ?></span>
                <?php endif; ?>
              </td>
              <td>
                <span data-fmt-time data-utc="<?= e($t['last_reply_at'] ?? $t['created_at']) ?>">
                  <?= e(wt_format_datetime($t['last_reply_at'] ?? $t['created_at'])) ?>
                </span>
              </td>
              <td class="wt-table__actions">
                <a class="wt-btn wt-btn--xs" href="?ticket=<?= (int)$t['id'] ?>">Ouvrir</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
