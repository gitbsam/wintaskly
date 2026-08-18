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
$pageDescription = t('seo.desc.help');

/* Fil d'Ariane structuré : Google l'affiche sous le lien dans les résultats
   et il clarifie la place de la page dans le site. */
wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'), 'url' => wt_url('/')],
    ['name' => (string) t('help.title'), 'url' => wt_url('/help/')],
]));
$u  = current_user();
$db = db();

/* Quelques questions "populaires" sélectionnées depuis l'i18n.
 * On prend les 5 premières clés faq.q_* (l'ordre d'apparition dans
 * le fichier i18n est l'ordre de pertinence éditoriale). */
$lang = $GLOBALS['WT_LANG'] ?? [];
/* Guides et tutoriels du blog, groupés par thème.
 *
 * Le centre d'aide se limitait à trois cartes de redirection (FAQ, contact,
 * suivi) : une page de navigation, pas de contenu. Or c'est la page que les
 * visiteurs — et les évaluateurs — consultent pour juger si le site aide
 * réellement ses utilisateurs. On y expose donc les guides existants, ce
 * qui donne à la fois du contenu et du maillage interne vers le blog. */
/* Base de connaissances organisée PAR QUESTION, pas par catégorie.
 *
 * Le hub listait les guides selon la taxonomie du blog — Guides, Finance,
 * Astuces, Crypto. C'est une classification éditoriale, utile pour lire,
 * inutile pour chercher : quelqu'un dont une offre n'est pas créditée ne
 * se demande pas dans quelle catégorie ranger son problème.
 *
 * On associe donc chaque intention à des articles précis. Un article peut
 * apparaître sous plusieurs thèmes — c'est voulu : il répond à plusieurs
 * questions. Les articles non encore publiés (date future) sont écartés
 * automatiquement, le hub suit donc le calendrier de publication.
 */
$helpTopics = [
    ['k' => 'tasks', 'slugs' => [
        'comprendre-optimiser-chaque-type-de-tache',
        'faucet-pourquoi-un-delai-entre-reclamations',
        'ptc-ce-qui-se-passe-pendant-le-compteur',
        'shortlinks-comprendre-pages-de-passage',
        'bien-choisir-ses-offres-partenaires',
    ]],
    ['k' => 'notcredited', 'slugs' => [
        'offre-refusee-offerwall-que-faire',
        'bloqueur-publicite-pourquoi-gains-bloques',
        'lire-ses-propres-chiffres-tableau-de-bord',
    ]],
    ['k' => 'withdraw', 'slugs' => [
        'retraits-conversion-moyens-de-paiement',
        'pourquoi-un-retrait-peut-etre-refuse',
        'combien-de-temps-met-un-paiement-crypto',
        'choisir-sa-methode-de-retrait-selon-son-pays',
        'micro-portefeuille-a-quoi-ca-sert',
    ]],
    ['k' => 'account', 'slugs' => [
        'securiser-son-compte-et-ses-gains',
        'double-authentification-expliquee-simplement',
        'que-faire-si-votre-compte-est-compromis',
        'gestionnaire-mots-de-passe-pourquoi-comment',
    ]],
    ['k' => 'fraud', 'slugs' => [
        'reconnaitre-email-phishing-indices-concrets',
        'signaux-alerte-plateforme-micro-gains-douteuse',
        'arnaques-crypto-les-schemas-a-connaitre',
    ]],
    ['k' => 'understand', 'slugs' => [
        'guide-complet-micro-gains-en-ligne',
        'pourquoi-ces-plateformes-peuvent-vous-payer',
        'combien-peut-on-vraiment-gagner-micro-taches',
        'parrainage-ce-quil-est-vraiment-et-ses-limites',
    ]],
];

/* Résolution en une seule requête : on ne veut pas d'un appel par article. */
$helpArticles = [];
try {
    $all = [];
    foreach ($helpTopics as $t) { $all = array_merge($all, $t['slugs']); }
    $all = array_values(array_unique($all));
    if ($all) {
        $ph  = implode(',', array_fill(0, count($all), '?'));
        $stmt = db()->prepare(
            "SELECT slug, title, reading_minutes, cover_emoji
               FROM blog_posts
              WHERE slug IN ($ph)
                AND status = 'published'
                AND published_at <= UTC_TIMESTAMP()"
        );
        $stmt->bind_param(str_repeat('s', count($all)), ...$all);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $helpArticles[$row['slug']] = $row;
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('[Wintaskly help] ' . $e->getMessage());
}

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

    <?php if ($helpArticles): ?>
      <section class="wt-help-kb" data-reveal>
        <header class="wt-help-kb__head">
          <h2 class="wt-help-kb__title"><?= e(t('help.kb_title')) ?></h2>
          <p class="wt-help-kb__lead"><?= e(t('help.kb_lead')) ?></p>
        </header>

        <?php foreach ($helpTopics as $topic):
          $items = [];
          foreach ($topic['slugs'] as $sl) {
              if (isset($helpArticles[$sl])) { $items[] = $helpArticles[$sl]; }
          }
          if (!$items) { continue; }   // thème sans article publié : masqué
        ?>
          <div class="wt-help-kb__topic">
            <h3 class="wt-help-kb__topic-title"><?= e(t('help.kb_' . $topic['k'])) ?></h3>
            <p class="wt-help-kb__topic-desc"><?= e(t('help.kb_' . $topic['k'] . '_d')) ?></p>
            <ul class="wt-help-kb__list">
              <?php foreach ($items as $a): ?>
                <li>
                  <a href="<?= e(wt_url('/blog/' . $a['slug'])) ?>">
                    <span class="wt-help-kb__emoji" aria-hidden="true"><?= e($a['cover_emoji'] ?: '📄') ?></span>
                    <span class="wt-help-kb__label"><?= e($a['title']) ?></span>
                    <span class="wt-help-kb__min"><?= (int) $a['reading_minutes'] ?> <?= e(t('blog.min_read')) ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>

        <p class="wt-help-kb__foot">
          <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/blog')) ?>">
            <?= e(t('help.guides_all')) ?> →
          </a>
        </p>
      </section>
    <?php endif; ?>

    <!-- ====== BIEN UTILISER LE SUPPORT ======
         Contenu utile : réduit les allers-retours en expliquant quoi
         vérifier avant d'ouvrir un ticket, et quoi y mettre. -->
    <section class="wt-help-explain" data-reveal>
      <h2 class="wt-help-explain__title"><?= e(t('help.guide_title')) ?></h2>
      <p class="wt-help-explain__lead"><?= e(t('help.guide_lead')) ?></p>

      <div class="wt-help-explain__steps">
        <div class="wt-help-explain__step">
          <span class="wt-help-explain__num">1</span>
          <div>
            <strong><?= e(t('help.guide_s1_t')) ?></strong>
            <p><?= e(t('help.guide_s1_d')) ?></p>
          </div>
        </div>
        <div class="wt-help-explain__step">
          <span class="wt-help-explain__num">2</span>
          <div>
            <strong><?= e(t('help.guide_s2_t')) ?></strong>
            <p><?= e(t('help.guide_s2_d')) ?></p>
          </div>
        </div>
        <div class="wt-help-explain__step">
          <span class="wt-help-explain__num">3</span>
          <div>
            <strong><?= e(t('help.guide_s3_t')) ?></strong>
            <p><?= e(t('help.guide_s3_d')) ?></p>
          </div>
        </div>
      </div>

      <p class="wt-help-explain__note"><?= e(t('help.guide_delay')) ?></p>
      <p class="wt-help-explain__note"><?= e(t('help.guide_scam')) ?></p>
    </section>

    <!-- Délais et périmètre du support : cadre les attentes et réduit
         les relances inutiles. -->
    <section class="wt-help-explain" data-reveal>
      <h2 class="wt-help-explain__title"><?= e(t('help.scope_title')) ?></h2>
      <p class="wt-help-explain__lead"><?= e(t('help.scope_lead')) ?></p>

      <div class="wt-lb-explain__cols">
        <div class="wt-lb-explain__col">
          <h3>✅ <?= e(t('help.scope_we')) ?></h3>
          <ul>
            <li><?= e(t('help.scope_we_1')) ?></li>
            <li><?= e(t('help.scope_we_2')) ?></li>
            <li><?= e(t('help.scope_we_3')) ?></li>
          </ul>
        </div>
        <div class="wt-lb-explain__col">
          <h3>➖ <?= e(t('help.scope_not')) ?></h3>
          <ul>
            <li><?= e(t('help.scope_not_1')) ?></li>
            <li><?= e(t('help.scope_not_2')) ?></li>
            <li><?= e(t('help.scope_not_3')) ?></li>
          </ul>
        </div>
      </div>

      <p class="wt-help-explain__note"><?= e(t('help.scope_delay')) ?></p>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
