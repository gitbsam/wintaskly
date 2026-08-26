<?php
/**
 * Wintaskly — campagn/index.php
 * ---------------------------------------------------------------------------
 * Page d'atterrissage des campagnes d'acquisition.
 *
 * TROIS URL, UNE SEULE PAGE
 *   /campagn                  → page seule, aucune campagne
 *   /campagn/2026             → année seule, sans code
 *   /campagn/2026/AB12CD34    → campagne identifiée, suivi actif
 *
 * L'année et le code ne sont que des PARAMÈTRES : il n'y a aucune page à
 * créer d'une année sur l'autre. L'année sert à situer l'offre dans le temps
 * et à repérer un lien périmé qui circulerait encore chez un partenaire.
 *
 * ⚠️ UN CODE INCONNU N'EST PAS UNE ERREUR
 * La page s'affiche normalement, sans promettre de prime. Renvoyer une 404
 * ferait perdre un visiteur déjà acquis — alors qu'il peut parfaitement
 * s'inscrire et devenir membre sans prime. C'est le comportement le plus
 * rentable, et le plus honnête : on ne promet que ce qui sera versé.
 *
 * PAGE PUBLIQUE, DONC INDEXABLE ET MONÉTISÉE
 * Elle dépasse volontairement les 1 500 mots et porte des emplacements
 * publicitaires : elle doit rapporter par elle-même, pas seulement convertir.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

/* ---- Paramètres -------------------------------------------------------- */
$year = (int) ($_GET['y'] ?? 0);
$code = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['c'] ?? '')) ?? '';
$now  = (int) date('Y');

/* Année absente ou aberrante : on retombe sur l'année courante plutôt que
   d'afficher une valeur absurde. La fourchette évite qu'un lien trafiqué
   affiche « offre pour l'année 9999 ». */
if ($year < 2020 || $year > $now + 5) { $year = $now; }

$campaign = $code !== '' ? wt_campaign_find($code) : null;
$isLive   = wt_campaign_is_live($campaign);

/* Trois états distincts, et ils n'affichent pas la même chose :
     - offre active    → montant et conditions
     - offre terminée  → mention explicite, inscription toujours ouverte
     - aucun code      → page seule */
$offerState = $campaign ? ($isLive ? 'live' : 'expired') : 'none';

/* ---- Suivi ------------------------------------------------------------- */
/* On n'enregistre une visite que pour une campagne RÉELLE. Sans cela, chaque
   passage sur /campagn créerait une ligne orpheline, et la table se
   remplirait de données sans valeur d'analyse. */
if ($campaign) {
    wt_campaign_track($campaign, '/campagn/' . $year . '/' . $code, 0);
}

$rewardCoins = $isLive ? (float) $campaign['reward_coins'] : 0.0;
$activeDays  = max(1, (int) cfg('campaign.active_days', 10));
$minDays     = max(1, (int) cfg('campaign.active_min_days', 5));

$pageTitle       = t('camp.title');
$pageDescription = t('camp.seo_desc');

/* Lien d'inscription : on y transmet le code pour le cas où le cookie serait
   refusé. Le rattachement repose d'abord sur le cookie, mais un paramètre
   d'URL offre un second chemin. */
$joinUrl = wt_url('/auth/signup.php') . ($campaign ? '?camp=' . rawurlencode($code) : '');

/* Le balisage doit être empilé AVANT header.php, qui rend le bloc JSON-LD. */
$campFaqSchema = [];
for ($i = 1; $i <= 6; $i++) {
    $campFaqSchema[] = ['q' => (string) t('camp.q' . $i), 'a' => (string) t('camp.a' . $i)];
}
wt_schema_add(wt_schema_faq($campFaqSchema));

wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'),  'url' => wt_url('/')],
    ['name' => (string) t('camp.title'), 'url' => wt_url('/campagn/')],
]));

include __DIR__ . '/../header.php';

/** Emplacement publicitaire, rendu uniquement si une zone est configurée. */
function camp_ad(string $zoneKey, string $size): void
{
    $code = function_exists('wt_ad_zone') ? (string) wt_ad_zone($zoneKey) : '';
    if (trim($code) === '') { return; }   // pas de cadre vide
    echo '<aside class="wt-camp-ad wt-camp-ad--' . e($size) . '" aria-label="'
       . e(t('camp.ad_label')) . '">' . $code . '</aside>';
}
?>

<main class="wt-main wt-camp">
  <div class="wt-camp__wrap">

    <header class="wt-camp__hero" data-reveal>
      <h1 class="wt-camp__title"><?= e(t('camp.title')) ?></h1>
      <p class="wt-camp__lead"><?= e(t('camp.hero_lead')) ?></p>

      <?php if ($offerState === 'live'): ?>
        <div class="wt-camp__offer">
          <span class="wt-camp__offer-k"><?= e(t('camp.offer_title')) ?></span>
          <strong class="wt-camp__offer-v">
            <?= e(sprintf((string) t('camp.offer_amount'),
                  rtrim(rtrim(number_format($rewardCoins, 2, ',', ' '), '0'), ','))) ?>
          </strong>
          <p class="wt-camp__offer-cond">
            <?= e(sprintf((string) t('camp.offer_cond'), $minDays, $activeDays)) ?>
          </p>
        </div>
      <?php elseif ($offerState === 'expired'): ?>
        <p class="wt-camp__notice"><?= e(t('camp.offer_expired')) ?></p>
      <?php else: ?>
        <p class="wt-camp__notice"><?= e(t('camp.offer_none')) ?></p>
      <?php endif; ?>

      <div class="wt-camp__cta">
        <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e($joinUrl) ?>">
          <?= e(t('camp.cta_join')) ?>
        </a>
        <a class="wt-btn wt-btn--ghost" href="#fonctionnement"><?= e(t('camp.cta_learn')) ?></a>
      </div>
    </header>

    <div class="wt-camp__cols">
      <article class="wt-camp__main">

        <section id="fonctionnement">
          <h2><?= e(t('camp.h_what')) ?></h2>
          <p><?= e(t('camp.p_what1')) ?></p>
          <p><?= e(t('camp.p_what2')) ?></p>
          <p><?= e(t('camp.p_what3')) ?></p>
        </section>

        <?php camp_ad('campaign_top', '728'); ?>

        <section>
          <h2><?= e(t('camp.h_money')) ?></h2>
          <p><?= e(t('camp.p_money1')) ?></p>
          <p><?= e(t('camp.p_money2')) ?></p>
          <p><?= e(t('camp.p_money3')) ?></p>
          <p><?= e(t('camp.p_money4')) ?></p>
        </section>

        <section>
          <h2><?= e(t('camp.h_tasks')) ?></h2>
          <div class="wt-camp__tasks">
            <?php foreach ([1, 2, 3, 4] as $n): ?>
              <div class="wt-camp__task">
                <h3><?= e(t('camp.t' . $n . '_title')) ?></h3>
                <p><?= e(t('camp.t' . $n . '_desc')) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="wt-camp__cta wt-camp__cta--inline">
          <a class="wt-btn wt-btn--primary" href="<?= e($joinUrl) ?>"><?= e(t('camp.cta_join')) ?></a>
        </div>

        <?php camp_ad('campaign_mid', '728'); ?>

        <section>
          <h2><?= e(t('camp.h_expect')) ?></h2>
          <p><?= e(t('camp.p_expect1')) ?></p>
          <p><?= e(t('camp.p_expect2')) ?></p>
          <p><?= e(t('camp.p_expect3')) ?></p>
          <p><?= e(t('camp.p_expect4')) ?></p>
        </section>

        <section>
          <h2><?= e(t('camp.h_trust')) ?></h2>
          <div class="wt-camp__trust">
            <?php foreach ([1, 2, 3, 4] as $n): ?>
              <div class="wt-camp__trust-item">
                <h3><?= e(t('camp.tr' . $n . '_t')) ?></h3>
                <p><?= e(t('camp.tr' . $n . '_d')) ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section>
          <h2><?= e(t('camp.h_security')) ?></h2>
          <p><?= e(t('camp.p_sec1')) ?></p>
          <p><?= e(t('camp.p_sec2')) ?></p>
        </section>

        <?php if ($offerState === 'live'): ?>
          <section class="wt-camp__why">
            <h2><?= e(t('camp.offer_title')) ?></h2>
            <p><?= e(sprintf((string) t('camp.offer_cond'), $minDays, $activeDays)) ?></p>
            <p><?= e(t('camp.offer_why')) ?></p>
          </section>
        <?php endif; ?>

        <section>
          <h2><?= e(t('camp.h_who')) ?></h2>
          <p><?= e(t('camp.p_who1')) ?></p>
          <p><?= e(t('camp.p_who2')) ?></p>
          <p><?= e(t('camp.p_who3')) ?></p>
          <p><?= e(t('camp.p_who4')) ?></p>
        </section>

        <?php
          /* FAQ balisée en FAQPage : Google exige que les questions marquées
             soient réellement visibles sur la page, ce qui est le cas ici. */
          $campFaq = [];
          for ($i = 1; $i <= 6; $i++) {
              $campFaq[] = ['q' => (string) t('camp.q' . $i), 'a' => (string) t('camp.a' . $i)];
          }
        ?>
        <section>
          <h2><?= e(t('camp.h_faq')) ?></h2>
          <div class="wt-camp__faq">
            <?php foreach ($campFaq as $qa): ?>
              <details class="wt-camp__qa">
                <summary><?= e($qa['q']) ?></summary>
                <p><?= e($qa['a']) ?></p>
              </details>
            <?php endforeach; ?>
          </div>
        </section>

        <section>
          <h2><?= e(t('camp.h_start')) ?></h2>
          <ol class="wt-camp__steps">
            <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
              <li><?= e(t('camp.s' . $n)) ?></li>
            <?php endforeach; ?>
          </ol>
        </section>

        <section class="wt-camp__final">
          <h2><?= e(t('camp.h_final')) ?></h2>
          <p><?= e(t('camp.p_final')) ?></p>
          <div class="wt-camp__cta">
            <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e($joinUrl) ?>">
              <?= e(t('camp.cta_join2')) ?>
            </a>
          </div>
        </section>

        <?php camp_ad('campaign_bottom', '728'); ?>

        <?php if ($campaign): ?>
          <p class="wt-camp__yearnote"><?= e(sprintf((string) t('camp.year_note'), $year)) ?></p>
        <?php endif; ?>
      </article>

      <aside class="wt-camp__aside">
        <div class="wt-camp__charter">
          <h2><?= e(t('camp.aside_title')) ?></h2>
          <ul>
            <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
              <li><?= e(t('camp.aside_' . $n)) ?></li>
            <?php endforeach; ?>
          </ul>
          <a href="<?= e(wt_url('/about/editorial.php')) ?>"><?= e(t('camp.aside_more')) ?> →</a>
        </div>

        <?php camp_ad('campaign_side', '300'); ?>

        <div class="wt-camp__aside-cta">
          <a class="wt-btn wt-btn--primary" href="<?= e($joinUrl) ?>"><?= e(t('camp.cta_join')) ?></a>
        </div>
      </aside>
    </div>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
