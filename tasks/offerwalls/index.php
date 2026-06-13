<?php
/**
 * Wintaskly — /tasks/offerwalls/index.php  (V8 modernisé)
 *
 * Liste des partenaires Offerwalls + historique des conversions
 * de l'utilisateur connecté.
 *
 * Améliorations V8 :
 *   - Header 2-col avec récap user (gains OW today/total + conversions)
 *   - Cards riches par partenaire (logo, name, desc, badge "haut payout")
 *   - Historique en cards (au lieu de table), filtrable par status
 *   - Accent module : vert (--success)
 *   - Footer bonus parrainage (cohérence cross-modules)
 */
declare(strict_types=1);
require __DIR__ . '/../../includes/init.php';
require_auth();

$pageTitle = t('ow.title');
$u  = current_user();
$db = db();

/* Liste des partenaires actifs */
$rows = [];
if ($res = $db->query(
    "SELECT id, name, logo_url, description, iframe_url, redirect_url
       FROM offerwalls WHERE active = 1 ORDER BY sort_order ASC, id ASC"
)) {
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* Filtre historique : ?fh=all|pending|credited|rejected */
$fh = (string)($_GET['fh'] ?? 'all');
if (!in_array($fh, ['all', 'pending', 'credited', 'rejected'], true)) $fh = 'all';

/* Historique complet, on filtrera côté PHP (volume faible : 20 max) */
$history = [];
$stmt = $db->prepare(
    "SELECT t.coins, t.status, t.created_at, o.name AS ow_name, o.logo_url
       FROM offerwall_transactions t
       JOIN offerwalls o ON o.id = t.offerwall_id
      WHERE t.user_id = ?
      ORDER BY t.id DESC
      LIMIT 20"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$res = $stmt->get_result();
$history = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Compteurs par status pour les filtres */
$cntAll      = count($history);
$cntPending  = count(array_filter($history, static fn ($h) => $h['status'] === 'pending'));
$cntCredited = count(array_filter($history, static fn ($h) => $h['status'] === 'credited'));
$cntRejected = count(array_filter($history, static fn ($h) => $h['status'] === 'rejected'));

$visibleHistory = $fh === 'all'
    ? $history
    : array_filter($history, static fn ($h) => $h['status'] === $fh);

/* Récap utilisateur — gains offerwalls (somme transactions.coins>0) */
$owStats = ['today' => 0.0, 'total' => 0.0, 'count_lifetime' => 0];
$stmt = $db->prepare(
    "SELECT COALESCE(SUM(CASE WHEN DATE(created_at) = UTC_DATE() THEN coins ELSE 0 END), 0) today,
            COALESCE(SUM(coins), 0) total,
            COUNT(*) cnt
       FROM transactions
      WHERE user_id = ? AND type = 'offerwall' AND coins > 0"
);
$stmt->bind_param('i', $u['id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$owStats['today']          = (float)($row['today'] ?? 0);
$owStats['total']          = (float)($row['total'] ?? 0);
$owStats['count_lifetime'] = (int)  ($row['cnt'] ?? 0);

$fmt = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

/* Initiales pour fallback logo */
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));
    return ($a . $b) ?: '?';
};

include __DIR__ . '/../../header.php';
?>

<main class="wt-main wt-ow-v2">
  <div class="wt-ow-v2__wrap">

    <!-- ====== HEADER 2-col ====== -->
    <header class="wt-ow-v2__header" data-reveal>
      <div class="wt-ow-v2__intro">
        <span class="wt-eyebrow">🎯 <?= e(t('ow.eyebrow')) ?></span>
        <h1 class="wt-ow-v2__title"><?= e(t('ow.title')) ?></h1>
        <p class="wt-ow-v2__lead"><?= e(t('ow.intro')) ?></p>
      </div>

      <aside class="wt-ow-v2__recap">
        <div class="wt-ow-v2__recap-item">
          <small><?= e(t('shortlinks.today')) ?></small>
          <strong>+<?= e($fmt($owStats['today'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
        <div class="wt-ow-v2__recap-item">
          <small><?= e(t('ow.completed')) ?></small>
          <strong><?= (int)$owStats['count_lifetime'] ?></strong>
          <em><?= e(t('ow.offers')) ?></em>
        </div>
        <div class="wt-ow-v2__recap-item">
          <small><?= e(t('shortlinks.lifetime')) ?></small>
          <strong>+<?= e($fmt($owStats['total'])) ?></strong>
          <em><?= e(t('common.coins')) ?></em>
        </div>
      </aside>
    </header>

    <!-- ====== GRILLE PARTENAIRES ====== -->
    <?php if (!$rows): ?>
      <div class="wt-ow-v2__empty" data-reveal>
        <span class="wt-ow-v2__empty-icon" aria-hidden="true">🎯</span>
        <h2><?= e(t('ow.empty_title')) ?></h2>
        <p><?= e(t('ow.empty')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/tasks/')) ?>">
          ← <?= e(t('shortlinks.back_to_hub')) ?>
        </a>
      </div>
    <?php else: ?>
      <section class="wt-ow-v2__grid" data-reveal>
        <?php foreach ($rows as $i => $ow):
            $href = !empty($ow['iframe_url'])
                  ? wt_url('/tasks/offerwalls/open.php?id=' . (int)$ow['id'])
                  : (!empty($ow['redirect_url']) ? $ow['redirect_url'] : '#');
            $newTab = empty($ow['iframe_url']) && !empty($ow['redirect_url']);
        ?>
          <a class="wt-ow-v2__card"
             style="--idx:<?= (int)$i ?>"
             href="<?= e($href) ?>"
             <?= $newTab ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>

            <header class="wt-ow-v2__card-head">
              <div class="wt-ow-v2__card-logo">
                <?php if (!empty($ow['logo_url'])): ?>
                  <img src="<?= e($ow['logo_url']) ?>" alt="" loading="lazy" decoding="async">
                <?php else: ?>
                  <span aria-hidden="true"><?= e($initials($ow['name'])) ?></span>
                <?php endif; ?>
              </div>
              <span class="wt-ow-v2__pill">
                ⭐ <?= e(t('ow.high_payout')) ?>
              </span>
            </header>

            <h3 class="wt-ow-v2__card-name"><?= e($ow['name']) ?></h3>
            <?php if (!empty($ow['description'])): ?>
              <p class="wt-ow-v2__card-desc"><?= e($ow['description']) ?></p>
            <?php endif; ?>

            <ul class="wt-ow-v2__card-meta">
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?= e(t('ow.meta_validated')) ?>
              </li>
              <li>
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s-8-4.5-8-11.5a8 8 0 0 1 16 0c0 7-8 11.5-8 11.5z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php if ($newTab): ?>
                  <?= e(t('ow.meta_external')) ?>
                <?php else: ?>
                  <?= e(t('ow.meta_internal')) ?>
                <?php endif; ?>
              </li>
            </ul>

            <span class="wt-btn wt-btn--primary wt-btn--block wt-ow-v2__card-cta">
              <?= e(t('ow.open')) ?> →
            </span>
          </a>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <!-- ====== HISTORIQUE CONVERSIONS ====== -->
    <?php if ($cntAll > 0): ?>
      <section class="wt-ow-v2__history" data-reveal>
        <header class="wt-ow-v2__history-head">
          <h2 class="wt-section__title"><?= e(t('ow.history')) ?></h2>
          <p class="wt-section__lead"><?= e(t('ow.history_lead')) ?></p>
        </header>

        <!-- Filtres status -->
        <nav class="wt-ow-v2__filters" aria-label="<?= e(t('ow.filter_label')) ?>">
          <a class="wt-ow-v2__filter <?= $fh === 'all' ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/tasks/offerwalls/?fh=all')) ?>">
            <?= e(t('shortlinks.filter_all')) ?>
            <span class="wt-ow-v2__filter-count"><?= (int)$cntAll ?></span>
          </a>
          <a class="wt-ow-v2__filter <?= $fh === 'pending' ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/tasks/offerwalls/?fh=pending')) ?>">
            ⏳ <?= e(t('ow.status_pending')) ?>
            <span class="wt-ow-v2__filter-count wt-ow-v2__filter-count--pending"><?= (int)$cntPending ?></span>
          </a>
          <a class="wt-ow-v2__filter <?= $fh === 'credited' ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/tasks/offerwalls/?fh=credited')) ?>">
            ✓ <?= e(t('ow.status_credited')) ?>
            <span class="wt-ow-v2__filter-count wt-ow-v2__filter-count--credited"><?= (int)$cntCredited ?></span>
          </a>
          <a class="wt-ow-v2__filter <?= $fh === 'rejected' ? 'is-active' : '' ?>"
             href="<?= e(wt_url('/tasks/offerwalls/?fh=rejected')) ?>">
            ✗ <?= e(t('ow.status_rejected')) ?>
            <span class="wt-ow-v2__filter-count wt-ow-v2__filter-count--rejected"><?= (int)$cntRejected ?></span>
          </a>
        </nav>

        <?php if (empty($visibleHistory)): ?>
          <div class="wt-ow-v2__history-empty">
            <p class="wt-muted"><?= e(t('shortlinks.filter_empty')) ?></p>
          </div>
        <?php else: ?>
          <ul class="wt-ow-v2__history-list">
            <?php foreach ($visibleHistory as $i => $h):
                $statusClass = match ($h['status']) {
                    'credited' => 'credited',
                    'rejected' => 'rejected',
                    default    => 'pending',
                };
                $statusIcon = match ($h['status']) {
                    'credited' => '✓',
                    'rejected' => '✗',
                    default    => '⏳',
                };
                $statusLabel = match ($h['status']) {
                    'credited' => t('ow.status_credited'),
                    'rejected' => t('ow.status_rejected'),
                    default    => t('ow.status_pending'),
                };
            ?>
              <li class="wt-ow-v2__history-item wt-ow-v2__history-item--<?= e($statusClass) ?>"
                  style="--idx:<?= (int)$i ?>">
                <div class="wt-ow-v2__history-logo">
                  <?php if (!empty($h['logo_url'])): ?>
                    <img src="<?= e($h['logo_url']) ?>" alt="" loading="lazy">
                  <?php else: ?>
                    <span aria-hidden="true"><?= e($initials($h['ow_name'])) ?></span>
                  <?php endif; ?>
                </div>
                <div class="wt-ow-v2__history-info">
                  <strong><?= e($h['ow_name']) ?></strong>
                  <small>
                    <time data-fmt-time data-utc="<?= e($h['created_at']) ?>"
                          data-format="relative"><?= e(wt_format_datetime($h['created_at'])) ?></time>
                  </small>
                </div>
                <span class="wt-ow-v2__history-amount">
                  <?php if ((float)$h['coins'] > 0): ?>+<?php endif; ?><?= e($fmt((float)$h['coins'])) ?>
                  <small><?= e(t('common.coins')) ?></small>
                </span>
                <span class="wt-ow-v2__history-status wt-ow-v2__history-status--<?= e($statusClass) ?>">
                  <?= e($statusIcon) ?> <?= e($statusLabel) ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- ====== FOOTER BONUS ====== -->
    <p class="wt-ow-v2__bonus">
      <?= e(t('faucet.referral_bonus')) ?>
      <a href="<?= e(wt_url('/dashboard/referrals.php')) ?>"><?= e(t('faucet.referral_link')) ?> →</a>
    </p>

  </div>
</main>

<?php include __DIR__ . '/../../footer.php'; ?>
