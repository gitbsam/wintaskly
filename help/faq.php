<?php
/**
 * Wintaskly — /help/faq.php  (V8 modernisé)
 *
 * Foire aux questions enrichie :
 *   - Recherche en direct (JS, filtre client)
 *   - Groupement par sections (préfixes des slugs : "account_", "earn_",
 *     "withdraw_", etc.). Si aucun préfixe, tout va dans "general".
 *   - Ancres cliquables (#q-slug) pour partager une réponse précise
 *   - Empty state propre quand la recherche ne trouve rien
 *   - Auto-ouverture de la question correspondant à l'ancre courante
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('faq.title');

/* Récupération des Q/R depuis l'i18n */
$lang = $GLOBALS['WT_LANG'] ?? [];
$qa = [];
foreach ($lang as $k => $v) {
    if (preg_match('/^faq\.q_(.+)$/', $k, $m)) {
        $slug = $m[1];
        $qa[$slug] = [
            'q' => $v,
            'a' => $lang['faq.a_' . $slug] ?? '',
        ];
    }
}

/* Grouper par section :
 *   - Le slug "account_email" → section "account"
 *   - Le slug "general" (pas de _) → section "general"
 *   - "*" sans match → "general"
 *
 * Les libellés des sections sont dans l'i18n sous faq.section_<key>.
 * Si la clé n'existe pas, on tombe sur faq.section_general (fallback).
 */
$sections = [];
foreach ($qa as $slug => $item) {
    $section = 'general';
    if (preg_match('/^([a-z]+)_/', $slug, $m)) {
        $section = $m[1];
    }
    $sections[$section][$slug] = $item;
}
ksort($sections);

/* Pré-remplissage du champ de recherche depuis le hub */
$preq = trim((string)($_GET['q'] ?? ''));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-faq-v2">
  <div class="wt-faq-v2__wrap">

    <!-- ====== HEADER ====== -->
    <header class="wt-faq-v2__header" data-reveal>
      <span class="wt-eyebrow">📚 <?= e(t('help.eyebrow')) ?></span>
      <h1 class="wt-faq-v2__title"><?= e(t('faq.title')) ?></h1>
      <p class="wt-faq-v2__lead"><?= e(t('faq.lead')) ?></p>
    </header>

    <!-- ====== BARRE DE RECHERCHE ====== -->
    <section class="wt-faq-v2__search-wrap" data-reveal>
      <label class="wt-faq-v2__search">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
             stroke="currentColor" stroke-width="2" aria-hidden="true">
          <circle cx="11" cy="11" r="7"/>
          <line x1="21" y1="21" x2="16.5" y2="16.5"/>
        </svg>
        <input type="search"
               data-faq-search
               value="<?= e($preq) ?>"
               placeholder="<?= e(t('faq.search_placeholder')) ?>"
               autocomplete="off"
               aria-label="<?= e(t('faq.search_placeholder')) ?>">
        <button type="button" class="wt-faq-v2__clear is-hidden"
                data-faq-clear
                aria-label="<?= e(t('faq.search_clear')) ?>">×</button>
      </label>
      <p class="wt-faq-v2__count wt-muted" data-faq-count></p>
    </section>

    <!-- ====== SECTIONS DE QUESTIONS ====== -->
    <?php foreach ($sections as $sectionKey => $items):
      $label = (string)(t('faq.section_' . $sectionKey) ?: t('faq.section_general'));
      $icon  = match ($sectionKey) {
          'account'  => '👤',
          'earn'     => '💰',
          'withdraw' => '💸',
          'security' => '🔒',
          'tasks'    => '🎯',
          'referral' => '🤝',
          'tech'     => '⚙️',
          default    => '💡',
      };
    ?>
      <section class="wt-faq-v2__section" data-reveal data-faq-section="<?= e($sectionKey) ?>">
        <header class="wt-faq-v2__section-head">
          <span class="wt-faq-v2__section-icon" aria-hidden="true"><?= $icon ?></span>
          <h2 class="wt-faq-v2__section-title"><?= e($label) ?></h2>
          <span class="wt-faq-v2__section-count wt-muted"><?= count($items) ?></span>
        </header>

        <ul class="wt-faq-v2__list">
          <?php foreach ($items as $slug => $item):
            // Index global pour le délai d'animation (sortable)
            $globalIdx = array_search($slug, array_keys($qa));
          ?>
            <li class="wt-faq-v2__item-wrap" data-faq-item-wrap>
              <details class="wt-faq-v2__item"
                       id="q-<?= e($slug) ?>"
                       data-faq-item
                       data-faq-slug="<?= e($slug) ?>"
                       style="--idx:<?= (int)$globalIdx ?>">
                <summary class="wt-faq-v2__q">
                  <span class="wt-faq-v2__q-text"><?= e($item['q']) ?></span>
                  <span class="wt-faq-v2__q-icons" aria-hidden="true">
                    <a class="wt-faq-v2__q-anchor"
                       href="#q-<?= e($slug) ?>"
                       title="<?= e(t('faq.anchor_copy')) ?>"
                       data-faq-anchor
                       onclick="event.stopPropagation()">🔗</a>
                    <svg class="wt-faq-v2__q-chevron" viewBox="0 0 24 24" width="18" height="18"
                         fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                      <polyline points="6 9 12 15 18 9"/>
                    </svg>
                  </span>
                </summary>
                <div class="wt-faq-v2__a"><?= nl2br(e($item['a'])) ?></div>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>

    <!-- ====== EMPTY STATE (caché initialement, affiché si recherche vide) ====== -->
    <div class="wt-faq-v2__empty is-hidden" data-faq-empty>
      <span class="wt-faq-v2__empty-icon" aria-hidden="true">🤷</span>
      <h2><?= e(t('faq.search_empty_title')) ?></h2>
      <p><?= e(t('faq.search_empty_text')) ?></p>
      <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
        ✉️ <?= e(t('faq.contact_us')) ?>
      </a>
    </div>

    <!-- ====== FOOTER CTA ====== -->
    <footer class="wt-faq-v2__cta" data-reveal>
      <span class="wt-faq-v2__cta-icon" aria-hidden="true">💬</span>
      <div class="wt-faq-v2__cta-text">
        <strong><?= e(t('faq.cta_title')) ?></strong>
        <small><?= e(t('faq.cta_lead')) ?></small>
      </div>
      <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/help/contact.php')) ?>">
        <?= e(t('faq.cta_contact')) ?> →
      </a>
    </footer>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
