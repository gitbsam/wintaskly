<?php
/**
 * Wintaskly — Admin · Diffusion de messages & réglages TTL.
 *
 * Permet d'envoyer un message à :
 *   - un utilisateur précis (par username/email) ;
 *   - tous les utilisateurs actifs ;
 *   - tous les administrateurs.
 *
 * Affiche également les paramètres de TTL et permet de les ajuster.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
$adminUser   = require_admin();

$pageTitle   = t('admin.title') . ' — ' . t('admin.messages');
$adminActive = 'messages';
$db          = db();
$notice      = null;
$error       = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'send') {
        $target  = (string)($_POST['target']  ?? 'user');
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = trim((string)($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            $error = t('common.error');
        } else {
            $ids = [];
            if ($target === 'user') {
                $needle = trim((string)($_POST['needle'] ?? ''));
                if ($needle === '') { $error = 'Indique un utilisateur.'; }
                else {
                    $stmt = $db->prepare(
                        "SELECT id FROM users
                          WHERE username = ? OR email = ?
                          LIMIT 1"
                    );
                    $stmt->bind_param('ss', $needle, $needle);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($r) $ids[] = (int) $r['id'];
                    else    $error = 'Utilisateur introuvable.';
                }
            } elseif ($target === 'all_users') {
                if ($res = $db->query("SELECT id FROM users WHERE status='active'")) {
                    while ($r = $res->fetch_assoc()) $ids[] = (int) $r['id'];
                    $res->free();
                }
            } elseif ($target === 'admins') {
                if ($res = $db->query("SELECT id FROM users WHERE role='admin'")) {
                    while ($r = $res->fetch_assoc()) $ids[] = (int) $r['id'];
                    $res->free();
                }
            }

            if (!$error && $ids) {
                foreach ($ids as $uid) {
                    wt_create_message($uid, $subject, $body, 'admin');
                    wt_notify($uid, 'admin_message', $subject, null,
                              wt_url('/dashboard/messages.php'));
                }
                $notice = sprintf('Message envoyé à %d destinataire(s).', count($ids));
            }
        }
    } elseif ($action === 'ttl_save') {
        // Wrapper s[] pour contourner le bug PHP qui convertit "." en "_"
        $postValues = $_POST['s'] ?? [];

        $keys = [
            'ttl.message_read_days',
            'ttl.message_unread_days',
            'ttl.notif_read_days',
            'ttl.notif_unread_days',
            'ttl.cleanup_probability',
            'testimonials.show_on_home',
            'testimonials.home_limit',
        ];
        foreach ($keys as $k) {
            if (!array_key_exists($k, $postValues)) continue;
            $v = (string) $postValues[$k];
            $stmt = $db->prepare(
                "INSERT INTO config (k, v) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v)"
            );
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
            $stmt->close();
        }
        $notice = t('admin.saved');
    } elseif ($action === 'ttl_run_now') {
        $stats = wt_ttl_cleanup();
        $notice = 'Nettoyage TTL : ' . json_encode($stats);
    }
}

/* Lecture des paramètres actuels — bypass du cache statique */
$current = [];
if ($res = $db->query("SELECT k, v FROM config WHERE k LIKE 'ttl.%' OR k LIKE 'testimonials.%'")) {
    while ($r = $res->fetch_assoc()) $current[$r['k']] = $r['v'];
    $res->free();
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
  <section class="wt-admin-v2__content" data-reveal>
    <header class="wt-admin-v2__page-header">
        <div>
          <span class="wt-eyebrow">📣 <?= e(t('admin.eyebrow_messages')) ?></span>
          <h1 class="wt-admin-v2__title"><?= e(t('admin.messages')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.messages.lead')) ?></p>
        </div>
      </header>

    <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error):  ?><div class="wt-alert wt-alert--error"><?= e($error)   ?></div><?php endif; ?>

    <h2 class="wt-section__title" style="margin-top:1rem">Diffusion</h2>
    <form method="post" class="wt-form wt-form--grid">
      <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="send">

      <label class="wt-field">
        <span class="wt-field__label">Destinataires</span>
        <select class="wt-input" name="target">
          <option value="user">Un utilisateur</option>
          <option value="all_users">Tous les utilisateurs actifs</option>
          <option value="admins">Tous les administrateurs</option>
        </select>
      </label>

      <label class="wt-field">
        <span class="wt-field__label">Username / email (si "Un utilisateur")</span>
        <input class="wt-input" type="text" name="needle">
      </label>

      <label class="wt-field wt-field--wide">
        <span class="wt-field__label">Sujet</span>
        <input class="wt-input" type="text" name="subject" required maxlength="180">
      </label>

      <label class="wt-field wt-field--wide">
        <span class="wt-field__label">Message</span>
        <textarea class="wt-input wt-textarea" name="body" rows="6" required maxlength="10000"></textarea>
      </label>

      <div class="wt-form__actions">
        <button class="wt-btn wt-btn--primary">Envoyer</button>
      </div>
    </form>

    <hr style="margin:2rem 0;border-color:var(--wt-border)">

    <h2 class="wt-section__title">Paramètres TTL & Témoignages</h2>
    <form method="post" class="wt-form wt-form--grid">
      <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="ttl_save">

      <label class="wt-field">
        <span class="wt-field__label">Messages lus — TTL (jours)</span>
        <input class="wt-input" type="number" min="1" name="ttl.message_read_days"
               value="<?= e($current['ttl.message_read_days'] ?? '30') ?>">
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Messages non lus — TTL (jours)</span>
        <input class="wt-input" type="number" min="1" name="ttl.message_unread_days"
               value="<?= e($current['ttl.message_unread_days'] ?? '90') ?>">
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Notifs lues — TTL (jours)</span>
        <input class="wt-input" type="number" min="1" name="ttl.notif_read_days"
               value="<?= e($current['ttl.notif_read_days'] ?? '30') ?>">
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Notifs non lues — TTL (jours)</span>
        <input class="wt-input" type="number" min="1" name="ttl.notif_unread_days"
               value="<?= e($current['ttl.notif_unread_days'] ?? '90') ?>">
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Probabilité cleanup par requête (0..1)</span>
        <input class="wt-input" type="number" step="0.001" min="0" max="1"
               name="ttl.cleanup_probability"
               value="<?= e($current['ttl.cleanup_probability'] ?? '0.02') ?>">
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Témoignages : afficher en accueil</span>
        <select class="wt-input" name="testimonials.show_on_home">
          <option value="1" <?= ($current['testimonials.show_on_home'] ?? '1')==='1'?'selected':'' ?>>Oui</option>
          <option value="0" <?= ($current['testimonials.show_on_home'] ?? '1')==='0'?'selected':'' ?>>Non</option>
        </select>
      </label>
      <label class="wt-field">
        <span class="wt-field__label">Témoignages : nombre en accueil</span>
        <input class="wt-input" type="number" min="1" max="20"
               name="testimonials.home_limit"
               value="<?= e($current['testimonials.home_limit'] ?? '8') ?>">
      </label>

      <div class="wt-form__actions">
        <button class="wt-btn wt-btn--primary">Enregistrer</button>
      </div>
    </form>

    <form method="post" style="margin-top:.75rem">
      <input type="hidden" name="_csrf"  value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="ttl_run_now">
      <button class="wt-btn wt-btn--ghost wt-btn--xs">Lancer le nettoyage TTL maintenant</button>
    </form>
  </section>
</div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
