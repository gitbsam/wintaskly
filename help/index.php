<?php
/**
 * Wintaskly — /help/  (V8 — Hub support)
 *
 * Page d'accueil du support. Présente 3 chemins :
 *   1) Consulter la FAQ
 *   2) Contacter l'équipe
 *   3) Suivre un ticket existant (par token pour les guests, ou
 *      lien vers /dashboard/messages pour les connectés)
 *
 * Affiche également les 5 questions les plus probables ("populaires"
 * basé sur la liste i18n) et, pour l'utilisateur connecté, ses 3
 * derniers tickets ouverts (raccourci direct).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('help.title');
$u  = current_user();
$db = db();

/* Quelques questions "populaires" sélectionnées depuis l'i18n.
 * On prend les 5 premières clés faq.q_* (l'ordre d'apparition dans
 * le fichier i18n est l'ordre de pertinence éditoriale). */
$lang = $GLOBALS['WT_LANG'] ?? [];
$popular = [];
foreach ($lang as $k => $v) {
    if (preg_match('/^faq\.q_(.+)$/', $k, $m)) {
        $popular[$m[1]] = $v;
        if (count($popular) >= 5) break;
    }
}

/* Tickets récents du user connecté (max 3) */
$myTickets = [];
if ($u) {
    $stmt = $db->prepare(
        "SELECT id, subject, status, last_reply_at, created_at
           FROM support_tickets
          WHERE user_id = ?
          ORDER BY id DESC LIMIT 3"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $myTickets = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-help-v2">
  <div class="wt-help-v2__wrap">

    <!-- ====== HEADER ====== -->
    <header class="wt-help-v2__header" data-reveal>
      <span class="wt-eyebrow">🛟 <?= e(t('help.eyebrow')) ?></span>
      <h1 class="wt-help-v2__title"><?= e(t('help.title')) ?></h1>
      <p class="wt-help-v2__lead"><?= e(t('help.lead')) ?></p>

      <!-- Mini-search : envoie directement vers /help/faq.php?q=... -->
      <form class="wt-help-v2__search" action="<?= e(wt_url('/help/faq.php')) ?>" method="get" role="search">
        <label class="wt-help-v2__search-label" for="help-search">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
               stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <line x1="21" y1="21" x2="16.5" y2="16.5"/>
          </svg>
        </label>
        <input id="help-search" type="search"
               name="q"
               placeholder="<?= e(t('help.search_placeholder')) ?>"
               autocomplete="off"
               class="wt-help-v2__search-input">
        <button type="submit" class="wt-btn wt-btn--primary wt-btn--xs">
          <?= e(t('help.search_btn')) ?>
        </button>
      </form>
    </header>

    <!-- ====== 3 CARDS PRINCIPALES ====== -->
    <section class="wt-help-v2__grid" data-reveal>
      <a class="wt-help-v2__card wt-help-v2__card--faq" href="<?= e(wt_url('/help/faq.php')) ?>" style="--idx:0">
        <div class="wt-help-v2__card-icon" aria-hidden="true">📚</div>
        <h2><?= e(t('help.card_faq')) ?></h2>
        <p><?= e(t('help.card_faq_lead')) ?></p>
        <span class="wt-help-v2__card-arrow" aria-hidden="true">→</span>
      </a>

      <a class="wt-help-v2__card wt-help-v2__card--contact" href="<?= e(wt_url('/help/contact.php')) ?>" style="--idx:1">
        <div class="wt-help-v2__card-icon" aria-hidden="true">✉️</div>
        <h2><?= e(t('help.card_contact')) ?></h2>
        <p><?= e(t('help.card_contact_lead')) ?></p>
        <span class="wt-help-v2__card-arrow" aria-hidden="true">→</span>
      </a>

      <a class="wt-help-v2__card wt-help-v2__card--track"
         href="<?= e($u ? wt_url('/dashboard/messages.php') : wt_url('/help/contact.php')) ?>"
         style="--idx:2">
        <div class="wt-help-v2__card-icon" aria-hidden="true">📨</div>
        <h2><?= e(t('help.card_track')) ?></h2>
        <p>
          <?= e($u ? t('help.card_track_lead_user') : t('help.card_track_lead_guest')) ?>
        </p>
        <span class="wt-help-v2__card-arrow" aria-hidden="true">→</span>
      </a>
    </section>

    <!-- ====== MES TICKETS RÉCENTS (utilisateur connecté avec ≥1 ticket) ====== -->
    <?php if ($myTickets): ?>
      <section class="wt-help-v2__my-tickets" data-reveal>
        <header class="wt-help-v2__my-tickets-head">
          <h2 class="wt-section__title"><?= e(t('help.my_tickets')) ?></h2>
          <a class="wt-btn wt-btn--xs wt-btn--ghost"
             href="<?= e(wt_url('/dashboard/messages.php')) ?>">
            <?= e(t('help.see_all_tickets')) ?> →
          </a>
        </header>

        <ul class="wt-help-v2__tickets">
          <?php foreach ($myTickets as $i => $t):
              $statusKey = (string)$t['status'];
              $statusClass = match ($statusKey) {
                  'closed'   => 'closed',
                  'answered' => 'answered',
                  default    => 'open',
              };
          ?>
            <li class="wt-help-v2__ticket" style="--idx:<?= (int)$i ?>">
              <div class="wt-help-v2__ticket-info">
                <strong><?= e($t['subject']) ?></strong>
                <small>
                  <?= e(t('common.created')) ?>:
                  <time data-fmt-time data-utc="<?= e($t['created_at']) ?>" data-format="relative">
                    <?= e(wt_format_datetime($t['created_at'])) ?>
                  </time>
                </small>
              </div>
              <span class="wt-help-v2__ticket-status wt-help-v2__ticket-status--<?= e($statusClass) ?>">
                <?= e(t('ticket.status.' . $statusKey)) ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <!-- ====== QUESTIONS POPULAIRES ====== -->
    <?php if ($popular): ?>
      <section class="wt-help-v2__popular" data-reveal>
        <header class="wt-help-v2__popular-head">
          <span class="wt-eyebrow">⭐ <?= e(t('help.popular_eyebrow')) ?></span>
          <h2 class="wt-section__title"><?= e(t('help.popular_title')) ?></h2>
        </header>

        <ul class="wt-help-v2__popular-list">
          <?php foreach ($popular as $slug => $q): ?>
            <li>
              <a href="<?= e(wt_url('/help/faq.php#q-' . urlencode($slug))) ?>">
                <span class="wt-help-v2__popular-q"><?= e($q) ?></span>
                <span class="wt-help-v2__popular-arrow" aria-hidden="true">→</span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
