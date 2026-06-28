<?php
/**
 * Wintaskly — /dashboard/referrals (V8 modernisé).
 *
 * Page parrainage avec mise en valeur du partage :
 *   - Hero card avec stats du parrain (X filleuls / Y Coins gagnés)
 *   - Lien de parrainage en card avec bouton copier mis en avant
 *   - Boutons de partage X/Facebook/Telegram/WhatsApp en pills colorées
 *   - Liste des filleuls en cards avec username masqué + earned/coin
 *
 * Compat : hook data-copy-target="#wt-ref-url" pour le JS de copie V8.
 */
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle = t('ref.title');
$u  = current_user();
$db = db();

$base   = rtrim($GLOBALS['WT_CONFIG']['base_url'] ?? '', '/');
$refUrl = $base . '/auth/register.php?ref=' . urlencode($u['referral_code']);

/* Filleuls + commissions générées par chacun + total commissions */
$rows = [];
$totalEarned = 0.0;
$sql = "SELECT f.id, f.username, f.created_at,
               COALESCE(SUM(re.commission), 0) AS earned
          FROM users f
          LEFT JOIN referral_earnings re
            ON re.referee_id = f.id AND re.referrer_id = ?
         WHERE f.referrer_id = ?
         GROUP BY f.id, f.username, f.created_at
         ORDER BY earned DESC, f.id DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param('ii', $u['id'], $u['id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
    $totalEarned += (float)$row['earned'];
}
$stmt->close();

/* Anonymisation partielle : "marie_dupont" → "ma***ont" */
$shareText = rawurlencode((string) t('ref.share.text') . ' — ' . $refUrl);

$dashActive = 'referrals';
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-dash">
  <div class="wt-dash__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-dash__content wt-dash-v2__content">

      <header class="wt-dash-v2__page-header" data-reveal>
        <span class="wt-eyebrow">🤝 <?= e(t('ref.eyebrow')) ?></span>
        <h1 class="wt-dash-v2__title"><?= e(t('ref.title')) ?></h1>
        <p class="wt-muted"><?= e(t('ref.lead')) ?></p>
      </header>

      <!-- ============ HERO STATS ============ -->
      <section class="wt-ref-v2__stats" data-reveal>
        <article class="wt-ref-v2__stat" style="--idx:0">
          <span class="wt-ref-v2__stat-icon" aria-hidden="true">👥</span>
          <div>
            <small><?= e(t('ref.stat_invitees')) ?></small>
            <strong><?= (int)count($rows) ?></strong>
          </div>
        </article>
        <article class="wt-ref-v2__stat" style="--idx:1">
          <span class="wt-ref-v2__stat-icon" aria-hidden="true">💰</span>
          <div>
            <small><?= e(t('ref.stat_earned')) ?></small>
            <strong>
              <?= e(wt_format_coins((float)$totalEarned)) ?>
              <em><?= e(t('common.coins')) ?></em>
            </strong>
          </div>
        </article>
        <article class="wt-ref-v2__stat" style="--idx:2">
          <span class="wt-ref-v2__stat-icon" aria-hidden="true">📊</span>
          <div>
            <small><?= e(t('ref.stat_rate')) ?></small>
            <strong>10<em>%</em></strong>
          </div>
        </article>
      </section>

      <!-- ============ LIEN DE PARRAINAGE ============ -->
      <section class="wt-ref-v2__share-card" data-reveal>
        <h2 class="wt-dash-v2__section-title">🔗 <?= e(t('ref.your_link')) ?></h2>
        <p class="wt-muted"><?= e(t('ref.share_lead')) ?></p>

        <div class="wt-ref-v2__link">
          <input class="wt-input" type="text" readonly value="<?= e($refUrl) ?>" id="wt-ref-url">
          <button class="wt-btn wt-btn--primary" type="button" data-copy-target="#wt-ref-url"
                  data-copy-label="<?= e(t('admin.cron.copied')) ?>">
            📋 <?= e(t('dash.copy')) ?>
          </button>
        </div>

        <div class="wt-ref-v2__share-buttons">
          <a class="wt-ref-v2__share-btn wt-ref-v2__share-btn--x"
             target="_blank" rel="noopener"
             href="https://twitter.com/intent/tweet?text=<?= $shareText ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
              <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
            </svg>
            <?= e(t('ref.share.x')) ?>
          </a>
          <a class="wt-ref-v2__share-btn wt-ref-v2__share-btn--fb"
             target="_blank" rel="noopener"
             href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($refUrl) ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
            <?= e(t('ref.share.fb')) ?>
          </a>
          <a class="wt-ref-v2__share-btn wt-ref-v2__share-btn--tg"
             target="_blank" rel="noopener"
             href="https://t.me/share/url?url=<?= rawurlencode($refUrl) ?>&text=<?= rawurlencode((string) t('ref.share.text')) ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
              <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69.01-.03.01-.14-.05-.2-.06-.06-.16-.04-.23-.02-.1.02-1.69 1.07-4.77 3.16-.45.31-.86.46-1.23.45-.41-.01-1.18-.23-1.76-.41-.71-.23-1.27-.36-1.23-.76.03-.21.32-.42.88-.65 3.43-1.49 5.71-2.48 6.85-2.96 3.26-1.36 3.93-1.59 4.37-1.59.1 0 .31.02.45.12.12.08.15.2.16.28-.01.08.01.21 0 .31z"/>
            </svg>
            <?= e(t('ref.share.tg')) ?>
          </a>
          <a class="wt-ref-v2__share-btn wt-ref-v2__share-btn--wa"
             target="_blank" rel="noopener"
             href="https://wa.me/?text=<?= $shareText ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
              <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
            </svg>
            <?= e(t('ref.share.wa')) ?>
          </a>
        </div>
      </section>

      <!-- ============ LISTE DES FILLEULS ============ -->
      <section class="wt-ref-v2__invitees" data-reveal>
        <h2 class="wt-dash-v2__section-title">👥 <?= e(t('ref.list')) ?></h2>

        <?php if (!$rows): ?>
          <div class="wt-dash-v2__empty">
            <span class="wt-dash-v2__empty-icon" aria-hidden="true">🤝</span>
            <p><?= e(t('ref.empty')) ?></p>
            <small class="wt-muted"><?= e(t('ref.empty_hint')) ?></small>
          </div>
        <?php else: ?>
          <ul class="wt-ref-v2__list">
            <?php foreach ($rows as $i => $r):
              $earned = (float)$r['earned'];
            ?>
              <li class="wt-ref-v2__invitee" style="--idx:<?= (int)$i ?>">
                <div class="wt-avatar wt-avatar--md"
                     data-hash-color="<?= e($r['username']) ?>" aria-hidden="true">
                  <?= e(mb_strtoupper(mb_substr($r['username'], 0, 1)) . mb_strtoupper(mb_substr($r['username'], 1, 1))) ?>
                </div>
                <div class="wt-ref-v2__invitee-info">
                  <strong><?= e(wt_mask_username((string)$r['username'])) ?></strong>
                  <small>
                    <?= e(t('ref.col.joined')) ?>
                    <span data-fmt-time data-utc="<?= e($r['created_at']) ?>" data-format="relative">
                      <?= e(wt_format_datetime($r['created_at'])) ?>
                    </span>
                  </small>
                </div>
                <div class="wt-ref-v2__invitee-earned">
                  <strong>
                    +<?= e(wt_format_coins((float)$earned)) ?>
                  </strong>
                  <small><?= e(t('common.coins')) ?></small>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
