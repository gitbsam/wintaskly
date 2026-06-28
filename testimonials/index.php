<?php
/**
 * Wintaskly — /testimonials  (V8 modernisé)
 *
 * Liste publique des témoignages validés + formulaire de dépôt
 * réservé aux comptes actifs.
 *
 * Améliorations V8 :
 *   - Header 2-col avec note moyenne globale + nombre de témoignages
 *   - Section "Featured" séparée (si ≥ 3 témoignages featured)
 *   - Filtre par note (Tous / 5★ / 4★+ / Featured)
 *   - Cards riches type wt-testi-v2 avec guillemet, étoiles SVG, animation cascade
 *   - Formulaire avec rating en boutons radio stylés + aperçu live
 *
 * Compat : utilise les mêmes hooks Ajax que l'ancienne version pour
 * que le JS existant (auth-form generic handler) continue de marcher.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('testi.title');
$u  = current_user();
$db = db();

/* ----- Filtre par note : ?r=all|5|4plus|featured ----- */
$filter = (string)($_GET['r'] ?? 'all');
if (!in_array($filter, ['all', '5', '4plus', 'featured'], true)) $filter = 'all';

/*
 * OPTIMISATION MÉMOIRE : au lieu de charger TOUS les témoignages (avec leurs
 * longs champs `body`) puis de filtrer en PHP avec array_filter — ce qui
 * sature la RAM —, on ne charge QUE les lignes correspondant au filtre actif,
 * directement en SQL via une clause WHERE dédiée + requête préparée.
 */
$extraWhere = match ($filter) {
    '5'        => " AND t.rating = 5",
    '4plus'    => " AND t.rating >= 4",
    'featured' => " AND t.featured = 1",
    default    => "",
};

$rows = [];
$sql = "SELECT t.id, t.rating, t.title, t.body, t.featured, t.created_at,
               u.username, u.avatar_url, u.level
          FROM testimonials t
          JOIN users u ON u.id = t.user_id
         WHERE t.status = 'approved'" . $extraWhere . "
         ORDER BY t.featured DESC, t.created_at DESC
         LIMIT 60";
if ($res = $db->query($sql)) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}
// Les lignes chargées SONT déjà les lignes visibles (filtrage fait en SQL)
$visibleRows = $rows;

/*
 * Compteurs des pills de filtre : une SEULE requête d'agrégation légère
 * (ne charge aucun `body`, juste des COUNT). Bien plus économe que de
 * compter en PHP sur des objets complets.
 */
$count5 = $count4plus = $countFeat = 0;
$globalStats = ['count' => 0, 'avg' => 0.0, 'featured_count' => 0];
if ($aggRow = $db->query(
    "SELECT COUNT(*) c,
            COALESCE(AVG(rating), 0) a,
            COALESCE(SUM(rating = 5), 0)  c5,
            COALESCE(SUM(rating >= 4), 0) c4,
            COALESCE(SUM(featured = 1), 0) cf
       FROM testimonials
      WHERE status = 'approved'"
)?->fetch_assoc()) {
    $globalStats['count']          = (int)   $aggRow['c'];
    $globalStats['avg']            = (float) $aggRow['a'];
    $globalStats['featured_count'] = (int)   $aggRow['cf'];
    $count5     = (int) $aggRow['c5'];
    $count4plus = (int) $aggRow['c4'];
    $countFeat  = (int) $aggRow['cf'];
}

/* ----- Stats utilisateur connecté : ses propres témoignages ----- */
$myStats = ['total' => 0, 'approved' => 0, 'pending' => 0];
if ($u) {
    $stmt = $db->prepare(
        "SELECT COUNT(*) total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) approved,
                SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) pending
           FROM testimonials WHERE user_id = ?"
    );
    $stmt->bind_param('i', $u['id']);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $myStats['total']    = (int)($r['total'] ?? 0);
    $myStats['approved'] = (int)($r['approved'] ?? 0);
    $myStats['pending']  = (int)($r['pending'] ?? 0);
}

$fmtAvg = static fn (float $v): string => number_format($v, 1, '.', '');

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-testi-v2">
  <div class="wt-testi-v2__wrap">

    <!-- ====== HEADER 2-col : intro + note moyenne ====== -->
    <header class="wt-testi-v2__header" data-reveal>
      <div class="wt-testi-v2__intro">
        <span class="wt-eyebrow">💬 <?= e(t('testi.eyebrow')) ?></span>
        <h1 class="wt-testi-v2__title"><?= e(t('testi.title')) ?></h1>
        <p class="wt-testi-v2__lead"><?= e(t('testi.lead')) ?></p>
      </div>

      <?php if ($globalStats['count'] > 0): ?>
        <aside class="wt-testi-v2__score" aria-label="<?= e(t('testi.avg_label')) ?>">
          <div class="wt-testi-v2__score-num"><?= e($fmtAvg($globalStats['avg'])) ?></div>
          <div class="wt-testi-v2__score-stars" aria-hidden="true">
            <?php
              $whole = (int) floor($globalStats['avg']);
              $half  = ($globalStats['avg'] - $whole) >= 0.5;
              for ($i = 1; $i <= 5; $i++):
                $cls = $i <= $whole ? 'is-on'
                     : (($i === $whole + 1 && $half) ? 'is-half' : '');
            ?>
              <svg viewBox="0 0 24 24" width="20" height="20"
                   class="wt-testi-v2__score-star <?= $cls ?>"
                   fill="currentColor" aria-hidden="true">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
              </svg>
            <?php endfor; ?>
          </div>
          <div class="wt-testi-v2__score-meta">
            <?= e(sprintf((string)t('testi.based_on'), $globalStats['count'])) ?>
          </div>
        </aside>
      <?php endif; ?>
    </header>

    <!-- ====== FILTRES ====== -->
    <?php if ($globalStats['count'] > 0): ?>
      <nav class="wt-testi-v2__filters" data-reveal aria-label="<?= e(t('testi.filter_label')) ?>">
        <a class="wt-testi-v2__filter <?= $filter === 'all' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/testimonials/?r=all')) ?>">
          <?= e(t('testi.filter_all')) ?>
          <span class="wt-testi-v2__filter-count"><?= (int)count($rows) ?></span>
        </a>
        <a class="wt-testi-v2__filter <?= $filter === '5' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/testimonials/?r=5')) ?>">
          ★★★★★
          <span class="wt-testi-v2__filter-count wt-testi-v2__filter-count--gold"><?= (int)$count5 ?></span>
        </a>
        <a class="wt-testi-v2__filter <?= $filter === '4plus' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/testimonials/?r=4plus')) ?>">
          ★★★★+
          <span class="wt-testi-v2__filter-count"><?= (int)$count4plus ?></span>
        </a>
        <a class="wt-testi-v2__filter <?= $filter === 'featured' ? 'is-active' : '' ?>"
           href="<?= e(wt_url('/testimonials/?r=featured')) ?>">
          ⭐ <?= e(t('testi.filter_featured')) ?>
          <span class="wt-testi-v2__filter-count wt-testi-v2__filter-count--featured"><?= (int)$countFeat ?></span>
        </a>
      </nav>
    <?php endif; ?>

    <!-- ====== GRILLE PRINCIPALE ====== -->
    <?php if (!$rows): ?>
      <div class="wt-testi-v2__empty" data-reveal>
        <span class="wt-testi-v2__empty-icon" aria-hidden="true">💬</span>
        <h2><?= e(t('testi.empty_title')) ?></h2>
        <p><?= e(t('testi.empty')) ?></p>
      </div>
    <?php elseif (empty($visibleRows)): ?>
      <div class="wt-testi-v2__empty" data-reveal>
        <span class="wt-testi-v2__empty-icon" aria-hidden="true">🤷</span>
        <h2><?= e(t('common.filter_empty_title')) ?></h2>
        <p><?= e(t('common.filter_empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/testimonials/?r=all')) ?>">
          <?= e(t('testi.see_all')) ?>
        </a>
      </div>
    <?php else: ?>
      <section class="wt-testi-v2__grid" data-reveal>
        <?php foreach (array_values($visibleRows) as $i => $r):
              $rating   = max(1, min(5, (int)$r['rating']));
              $isFeat   = !empty($r['featured']);
        ?>
          <article class="wt-testi-v2__card <?= $isFeat ? 'is-featured' : '' ?>"
                   style="--idx:<?= (int)$i ?>">
            <span class="wt-testi-v2__quote" aria-hidden="true">"</span>

            <?php if ($isFeat): ?>
              <span class="wt-testi-v2__featured-badge" aria-label="<?= e(t('testi.featured_badge')) ?>">
                ⭐ <?= e(t('testi.featured_badge')) ?>
              </span>
            <?php endif; ?>

            <div class="wt-testi-v2__stars" aria-label="<?= $rating ?>/5">
              <?php for ($s = 1; $s <= 5; $s++): ?>
                <svg viewBox="0 0 24 24" width="16" height="16"
                     class="wt-testi-v2__star <?= $s <= $rating ? 'is-on' : '' ?>"
                     fill="currentColor" aria-hidden="true">
                  <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
              <?php endfor; ?>
            </div>

            <h3 class="wt-testi-v2__card-title"><?= e($r['title']) ?></h3>
            <p class="wt-testi-v2__card-body"><?= nl2br(e($r['body'])) ?></p>

            <footer class="wt-testi-v2__card-foot">
              <div class="wt-avatar wt-avatar--sm"
                   data-hash-color="<?= e($r['username']) ?>"
                   aria-hidden="true"><?= e(wt_avatar_inner($r)) ?></div>
              <div class="wt-testi-v2__card-author">
                <strong><?= e($r['username']) ?></strong>
                <small>
                  Lv <?= (int)$r['level'] ?> ·
                  <time data-fmt-time data-utc="<?= e($r['created_at']) ?>"
                        data-format="relative"><?= e(wt_format_datetime($r['created_at'], 'd/m/Y')) ?></time>
                </small>
              </div>
            </footer>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <!-- ====== FORMULAIRE DE DÉPÔT ====== -->
    <section class="wt-testi-v2__form-section" data-reveal>
      <header class="wt-testi-v2__form-head">
        <span class="wt-eyebrow">✍️ <?= e(t('testi.write_eyebrow')) ?></span>
        <h2 class="wt-section__title"><?= e(t('testi.write')) ?></h2>
        <p class="wt-section__lead"><?= e(t('testi.write_lead')) ?></p>
      </header>

      <?php if (!$u): ?>
        <div class="wt-testi-v2__form-locked">
          <span class="wt-testi-v2__form-locked-icon" aria-hidden="true">🔒</span>
          <h3><?= e(t('testi.must_login_title')) ?></h3>
          <p><?= e(t('testi.must_login')) ?></p>
          <div class="wt-testi-v2__form-locked-cta">
            <a class="wt-btn wt-btn--primary" href="<?= e(wt_url('/auth/login.php')) ?>"><?= e(t('nav.login')) ?></a>
            <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/auth/signup.php')) ?>"><?= e(t('nav.register')) ?></a>
          </div>
        </div>
      <?php elseif (($u['status'] ?? '') !== 'active'): ?>
        <div class="wt-testi-v2__form-locked">
          <span class="wt-testi-v2__form-locked-icon" aria-hidden="true">⏳</span>
          <h3><?= e(t('testi.must_active_title')) ?></h3>
          <p><?= e(t('testi.must_active')) ?></p>
        </div>
      <?php else: ?>
        <div class="wt-testi-v2__form-card">

          <!-- Mes stats personnelles -->
          <?php if ($myStats['total'] > 0): ?>
            <div class="wt-testi-v2__mystats">
              <div>
                <strong><?= (int)$myStats['total'] ?></strong>
                <small><?= e(t('testi.my_total')) ?></small>
              </div>
              <div>
                <strong class="wt-testi-v2__mystats-ok"><?= (int)$myStats['approved'] ?></strong>
                <small><?= e(t('testi.my_approved')) ?></small>
              </div>
              <?php if ($myStats['pending'] > 0): ?>
                <div>
                  <strong class="wt-testi-v2__mystats-wait"><?= (int)$myStats['pending'] ?></strong>
                  <small><?= e(t('testi.my_pending')) ?></small>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
          <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

          <form class="wt-form wt-testi-v2__form"
                data-auth-form
                data-endpoint="<?= e(wt_url('/api/testimonial_submit.php')) ?>"
                data-keep-form>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

            <!-- Rating en boutons radio stylés -->
            <fieldset class="wt-testi-v2__rating-field">
              <legend class="wt-field__label"><?= e(t('testi.rating')) ?></legend>
              <div class="wt-testi-v2__rating-group" data-rating-group>
                <?php
                  /*
                   * Ordre 1 → 5 pour une navigation clavier logique (avant,
                   * c'était 5 → 1 pour un vieux hack CSS `~` devenu inutile :
                   * le style repose désormais sur :has(input:checked) et sur
                   * la classe is-on calculée par PHP, indépendants de l'ordre).
                   */
                  for ($i = 1; $i <= 5; $i++):
                ?>
                  <label class="wt-testi-v2__rating-radio">
                    <input type="radio" name="rating" value="<?= $i ?>" <?= $i === 5 ? 'checked' : '' ?>>
                    <span class="wt-testi-v2__rating-stars" aria-hidden="true">
                      <?php for ($s = 1; $s <= 5; $s++): ?>
                        <svg viewBox="0 0 24 24" width="22" height="22"
                             class="wt-testi-v2__rating-star <?= $s <= $i ? 'is-on' : '' ?>"
                             fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                      <?php endfor; ?>
                    </span>
                    <span class="wt-testi-v2__rating-label"><?= $i ?>/5</span>
                  </label>
                <?php endfor; ?>
              </div>
            </fieldset>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('testi.label_title')) ?></span>
              <input class="wt-input" type="text" name="title" required maxlength="120"
                     data-testi-title placeholder="<?= e(t('testi.title_placeholder')) ?>">
            </label>

            <label class="wt-field wt-field--wide">
              <span class="wt-field__label"><?= e(t('testi.comment')) ?></span>
              <textarea class="wt-input wt-textarea" name="body" rows="5" required
                        maxlength="2000" data-testi-body
                        placeholder="<?= e(t('testi.body_placeholder')) ?>"></textarea>
              <small class="wt-testi-v2__counter">
                <span data-testi-counter>0</span> / 2000
              </small>
            </label>

            <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg" data-submit-btn>
              <span class="wt-btn__label"><?= e(t('testi.submit')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>

            <p class="wt-testi-v2__note">
              ℹ️ <?= e(t('testi.moderation_note')) ?>
            </p>
          </form>
        </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
