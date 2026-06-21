<?php
/**
 * Wintaskly — Page d'accueil.
 *
 * Tous les blocs sont activables/désactivables depuis l'admin
 * (table `homepage_blocks`, table `config` pour les stats).
 */

require __DIR__ . '/includes/init.php';

$pageTitle = t('site_name') . ' — ' . t('site_tagline');
$u         = current_user();
$db        = db();

/* -------- Blocs homepage (titres / contenus / visibilité) ----------------- */
$blocks = [];
if ($res = $db->query("SELECT k, title, content, visible FROM homepage_blocks")) {
    while ($row = $res->fetch_assoc()) {
        $blocks[$row['k']] = $row;
    }
    $res->free();
}
$blockVisible = static function (string $k) use ($blocks): bool {
    return !empty($blocks[$k]) && (int) $blocks[$k]['visible'] === 1;
};
$blockField = static function (string $k, string $field, string $default = '') use ($blocks): string {
    return isset($blocks[$k][$field]) ? (string) $blocks[$k][$field] : $default;
};

/* -------- Statistiques publiques (config + DB temps réel) -----------------

   Mode de calcul des stats, configurable via /admin/homepage.php :

   - 'real'    : uniquement les vraies données BDD (transparent, recommandé
                 quand la communauté grandit. Affiche 0 si BDD vide.)
   - 'boosted' : static + real (cumulé). Le boost statique sert de baseline
                 et les vraies données s'y ajoutent. Idéal pour garder
                 l'effet "site peuplé" tout en ayant des chiffres qui
                 évoluent vraiment.
   - 'max'     : max(static, real). Comportement historique : tant que la
                 vraie valeur est inférieure au boost, on affiche le boost.
                 Dès qu'on dépasse, on affiche le vrai chiffre.

   Default 'max' pour ne pas casser les installations existantes.
   --------------------------------------------------------------------------- */
$statsMode = (string) cfg('stats_mode', 'max');
$statsUsersBoost = (int) cfg('stats_users', '0');
$statsPaidBoost  = (int) cfg('stats_paid', '0');
$statsTodayBoost = (int) cfg('stats_tasks_today', '0');

// Valeurs réelles depuis la BDD (toujours calculées pour transparence admin)
$_ru = db_one("SELECT COUNT(*) c FROM users WHERE status='active'");
$realUsers = (int) ($_ru['c'] ?? 0);
$_rp = db_one("SELECT COALESCE(SUM(coins),0) s FROM transactions WHERE type IN ('faucet','shortlink','referral','bonus')");
$realPaid  = (int) ($_rp['s'] ?? 0);
$_rt = db_one("SELECT COUNT(*) c FROM transactions WHERE type IN ('faucet','shortlink') AND created_at >= UTC_DATE()");
$realToday = (int) ($_rt['c'] ?? 0);

// Application du mode
switch ($statsMode) {
    case 'real':
        $statsUsers = $realUsers;
        $statsPaid  = $realPaid;
        $statsToday = $realToday;
        break;
    case 'boosted':
        $statsUsers = $statsUsersBoost + $realUsers;
        $statsPaid  = $statsPaidBoost  + $realPaid;
        $statsToday = $statsTodayBoost + $realToday;
        break;
    case 'max':
    default:
        $statsUsers = max($statsUsersBoost, $realUsers);
        $statsPaid  = max($statsPaidBoost,  $realPaid);
        $statsToday = max($statsTodayBoost, $realToday);
        break;
}

/* -------- Flux des dernières récompenses (5 dernières) -------------------- */
$feed = [];
$sql = "SELECT t.type, t.coins, t.created_at, u.username
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        WHERE t.type IN ('faucet','shortlink','referral','bonus')
          AND t.coins > 0
        ORDER BY t.id DESC
        LIMIT 6";
if ($res = $db->query($sql)) {
    $feed = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
}

/* -------- Top retraits live (panneau flottant hero, desktop only) ---------
 * On affiche les 10 derniers retraits validés (status = 'completed').
 * Username tronqué pour la vie privée : "Saïd K." au lieu de "saidkamal".
 * Si la table est vide, on génère des entrées factices visuelles pour ne
 * pas avoir un panneau vide à la mise en route (mode "vitrine").       */
$topWithdrawals = [];
$sql = "SELECT w.payout_amount, w.payout_currency,
               w.created_at, w.processed_at, w.user_id,
               u.username, u.avatar_url,
               m.label AS method_label, m.k AS method_key
          FROM withdrawals w
          JOIN users u  ON u.id = w.user_id
          JOIN withdrawal_methods m ON m.id = w.method_id
         WHERE w.status = 'completed'
         ORDER BY w.processed_at DESC, w.id DESC
         LIMIT 10";
if ($res = $db->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        // Tronquer le username : premiers caractères + initiale du dernier mot
        $name = (string) $row['username'];
        if (strlen($name) > 3) {
            $row['display_name'] = substr($name, 0, 1) . str_repeat('•', min(3, strlen($name) - 2)) . substr($name, -1);
        } else {
            $row['display_name'] = $name;
        }
        // Normalisation des champs pour la suite (compatibilité avec le rendu)
        $row['amount']       = (float) $row['payout_amount'];
        $row['completed_at'] = $row['processed_at'] ?: $row['created_at'];
        $topWithdrawals[] = $row;
    }
    $res->free();
}

include __DIR__ . '/header.php';
?>

<main class="wt-main wt-home">

  <!-- ===================== HERO ============================================ -->
  <?php if ($blockVisible('hero')): ?>
  <section class="wt-hero" data-reveal>
    <!-- Orbs décoratifs (couleur accent + accent2 mélangées en fond) -->
    <div class="wt-hero__orbs" aria-hidden="true">
      <span class="wt-orb wt-orb--a"></span>
      <span class="wt-orb wt-orb--b"></span>
      <span class="wt-orb wt-orb--c"></span>
    </div>

    <div class="wt-hero__layout">

      <!-- ============ COLONNE GAUCHE : pitch + CTA ============ -->
      <div class="wt-hero__inner">
        <span class="wt-eyebrow">
          <span class="wt-eyebrow__pulse" aria-hidden="true"></span>
          Get-Paid-To · 100% en ligne
        </span>

        <h1 class="wt-hero__title">
          <?= e($blockField('hero', 'title', t('site_tagline'))) ?>
        </h1>
        <p class="wt-hero__lead">
          <?= e($blockField('hero', 'content', t('faucet.intro'))) ?>
        </p>

        <!-- CTA primaires -->
        <div class="wt-hero__cta">
          <?php if ($u): ?>
            <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e(wt_url('/dashboard/')) ?>">
              <?= e(t('home.cta.dashboard')) ?>
            </a>
            <a class="wt-btn wt-btn--ghost wt-btn--lg" href="<?= e(wt_url('/tasks/faucet/')) ?>">
              <?= e(t('nav.faucet')) ?> →
            </a>
          <?php else: ?>
            <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e(wt_url('/auth/signup.php')) ?>">
              <?= e(t('home.cta.register')) ?>
            </a>
            <a class="wt-btn wt-btn--ghost wt-btn--lg" href="<?= e(wt_url('/auth/login.php')) ?>">
              <?= e(t('nav.login')) ?>
            </a>
          <?php endif; ?>
        </div>

        <!-- Trust line — chiffres clés sur une ligne (existant remixé) -->
        <div class="wt-hero__trust">
          <div class="wt-hero__trust-item">
            <strong data-countup="<?= e((string)$statsUsers) ?>">0</strong>
            <span><?= e(t('home.stats.users')) ?></span>
          </div>
          <div class="wt-hero__trust-sep" aria-hidden="true"></div>
          <div class="wt-hero__trust-item">
            <strong data-countup="<?= e((string)$statsPaid) ?>">0</strong>
            <span><?= e(t('home.stats.paid')) ?></span>
          </div>
          <div class="wt-hero__trust-sep" aria-hidden="true"></div>
          <div class="wt-hero__trust-item">
            <strong data-countup="<?= e((string)$statsToday) ?>">0</strong>
            <span><?= e(t('home.stats.today')) ?></span>
          </div>
        </div>
      </div>

      <!-- ============ COLONNE DROITE : sidebar top retraits live ============
       * Visible UNIQUEMENT en desktop (data-nav="desktop").
       * En mobile l'utilisateur voit cette info plus bas dans le feed.
       * ----------------------------------------------------------------- -->
      <aside class="wt-hero__sidebar" data-nav="desktop" aria-label="<?= e(t('home.live.title')) ?>">
        <header class="wt-hero__sidebar-head">
          <span class="wt-hero__live-dot" aria-hidden="true"></span>
          <div>
            <strong><?= e(t('home.live.title')) ?></strong>
            <small class="wt-muted"><?= e(t('home.live.sub')) ?></small>
          </div>
        </header>

        <?php if (!empty($topWithdrawals)): ?>
          <ul class="wt-hero__withdraw-list" data-withdraw-list>
            <?php foreach ($topWithdrawals as $i => $w): ?>
              <li class="wt-hero__withdraw-item" style="--idx:<?= $i ?>">
                <div class="wt-avatar wt-avatar--xs">
                  <?= wt_avatar_inner($w) ?>
                </div>
                <div class="wt-hero__withdraw-info">
                  <strong><?= e($w['display_name']) ?></strong>
                  <small class="wt-muted">
                    <?= e($w['method_label']) ?> ·
                    <time data-fmt-time data-utc="<?= e($w['created_at']) ?>"
                          data-format="relative"><?= e(wt_format_datetime($w['created_at'])) ?></time>
                  </small>
                </div>
                <span class="wt-hero__withdraw-amount">
                  +<?= e(number_format((float)$w['amount'], 2, ',', ' ')) ?> €
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <!-- Mode vitrine : 4 entrées factices pour ne pas avoir un panel vide -->
          <ul class="wt-hero__withdraw-list">
            <?php
            $demo = [
                ['S••d K.', 'PayPal', '12.50', '3'],
                ['M••a R.', 'Orange Money', '8.75', '11'],
                ['P••o T.', 'Wise', '24.00', '27'],
                ['L••a B.', 'PayPal', '5.20', '42'],
            ];
            foreach ($demo as $i => $d):
            ?>
              <li class="wt-hero__withdraw-item" style="--idx:<?= $i ?>">
                <div class="wt-avatar wt-avatar--xs"><?= e(substr($d[0], 0, 1)) ?></div>
                <div class="wt-hero__withdraw-info">
                  <strong><?= e($d[0]) ?></strong>
                  <small class="wt-muted"><?= e($d[1]) ?> · il y a <?= e($d[3]) ?> min</small>
                </div>
                <span class="wt-hero__withdraw-amount">+<?= e($d[2]) ?> €</span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="wt-hero__withdraw-empty">
            <small class="wt-muted">
              <?= e(t('home.live.empty')) ?>
            </small>
          </p>
        <?php endif; ?>

        <footer class="wt-hero__sidebar-foot">
          <a href="<?= e(wt_url('/leaderboard/')) ?>" class="wt-hero__sidebar-link">
            <?= e(t('home.live.cta')) ?> →
          </a>
        </footer>
      </aside>

    </div>
  </section>
  <?php endif; ?>

  <?php $_ad = wt_ad_zone('home_hero_bottom'); if ($_ad !== ''): ?>
    <div class="wt-ad-zone wt-ad-zone--home" style="max-width:1240px;margin:0 auto 1.5rem;padding:0 1rem;text-align:center"><?= $_ad ?></div>
  <?php endif; ?>

  <!-- ===================== TRUST BAR STICKY (V8) =================
   * Bande fine qui devient sticky en haut au scroll quand elle dépasse
   * le viewport. Rappel des 3 KPI du hero + indicateur "live".
   * Auto-cachée si JS désactivé (data-trustbar visible uniquement
   * après init du IntersectionObserver côté JS).
   ========================================================== -->
  <div class="wt-trustbar" data-trustbar>
    <div class="wt-trustbar__inner">
      <span class="wt-trustbar__pulse" aria-hidden="true"></span>
      <span class="wt-trustbar__item">
        <strong><?= number_format($statsUsers, 0, '.', ' ') ?></strong>
        <small><?= e(t('home.stats.users')) ?></small>
      </span>
      <span class="wt-trustbar__sep" aria-hidden="true">·</span>
      <span class="wt-trustbar__item">
        <strong><?= number_format($statsPaid, 0, '.', ' ') ?></strong>
        <small><?= e(t('home.stats.paid')) ?></small>
      </span>
      <span class="wt-trustbar__sep" aria-hidden="true">·</span>
      <span class="wt-trustbar__item">
        <strong><?= number_format($statsToday, 0, '.', ' ') ?></strong>
        <small><?= e(t('home.stats.today')) ?></small>
      </span>
      <a class="wt-trustbar__cta" href="<?= e($u ? wt_url('/dashboard/') : wt_url('/auth/signup.php')) ?>">
        <?= e($u ? t('home.cta.dashboard') : t('home.cta.register')) ?> →
      </a>
    </div>
  </div>

  <!-- ===================== TASKS SHOWCASE (V8) =================
   * 4 cards marketing présentant chaque module : icône SVG distinctive,
   * mini-pitch, récompense typique (lue depuis config), durée moyenne,
   * CTA cliquable vers le module. Card "POPULAIRE" mise en avant.
   *
   * Données :
   *   - Faucet     : reward fixe depuis cfg('faucet_reward_coins')
   *   - Shortlinks : moyenne des reward_coins actifs depuis la DB
   *   - PTC        : moyenne idem
   *   - Offerwalls : range typique (en dur, varie par provider)
   * ========================================================== -->
  <?php
    /* Récupère les vraies récompenses pour des chiffres crédibles */
    $faucetReward = (float) cfg('faucet_reward_coins', '25');
    $faucetCD     = (int)   cfg('faucet_cooldown_seconds', '10800');

    $slAvg = (float) ($db->query(
        "SELECT COALESCE(AVG(reward_coins), 5) a FROM shortlinks WHERE active = 1"
    )->fetch_assoc()['a'] ?? 5);

    $ptcAvg = (float) ($db->query(
        "SELECT COALESCE(AVG(reward_coins), 2) a FROM ptc_ads WHERE active = 1"
    )->fetch_assoc()['a'] ?? 2);

    $owCount = (int) ($db->query(
        "SELECT COUNT(*) c FROM offerwalls WHERE active = 1"
    )->fetch_assoc()['c'] ?? 0);

    /* Format compact : "25" plutôt que "25.00" si pas de décimales utiles */
    $fmt = static function (float $n): string {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    };

    /* Cooldown faucet en format humain : 10800s → "3h" */
    $cdHuman = $faucetCD >= 3600
        ? floor($faucetCD / 3600) . 'h'
        : floor($faucetCD / 60) . ' min';

    $tasks = [
        [
            'k'       => 'faucet',
            'href'    => wt_url('/tasks/faucet/'),
            'icon'    => 'M12 2v6m0 0 4-4m-4 4-4-4M5 12a7 7 0 1 0 14 0M5 12H2m20 0h-3', // gouttelette stylisée
            'title'   => t('nav.faucet'),
            'pitch'   => t('home.tasks.faucet.pitch'),
            'reward'  => $fmt($faucetReward),
            'period'  => '/ ' . $cdHuman,
            'duration'=> '10 s',
            'popular' => true,
        ],
        [
            'k'       => 'shortlinks',
            'href'    => wt_url('/tasks/shortlinks/'),
            'icon'    => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
            'title'   => t('nav.shortlinks'),
            'pitch'   => t('home.tasks.shortlinks.pitch'),
            'reward'  => $fmt($slAvg),
            'period'  => '/ ' . t('home.tasks.per_link'),
            'duration'=> '15 s',
            'popular' => false,
        ],
        [
            'k'       => 'ptc',
            'href'    => wt_url('/tasks/ptc/'),
            'icon'    => 'M2 7v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2zm8 3 5 3-5 3z',
            'title'   => t('nav.ptc'),
            'pitch'   => t('home.tasks.ptc.pitch'),
            'reward'  => $fmt($ptcAvg),
            'period'  => '/ ' . t('home.tasks.per_ad'),
            'duration'=> '30 s',
            'popular' => false,
        ],
        [
            'k'       => 'offerwalls',
            'href'    => wt_url('/tasks/offerwalls/'),
            'icon'    => 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
            'title'   => t('nav.offerwalls'),
            'pitch'   => t('home.tasks.offerwalls.pitch'),
            'reward'  => '1—50',
            'period'  => '/ ' . t('home.tasks.per_offer'),
            'duration'=> '2-10 min',
            'popular' => false,
            'note'    => $owCount > 0
                ? sprintf((string) t('home.tasks.offerwalls.providers'), $owCount)
                : null,
        ],
    ];
  ?>

  <section class="wt-showcase" data-reveal>
    <div class="wt-showcase__head">
      <span class="wt-eyebrow"><?= e(t('home.tasks.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.tasks.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.tasks.lead')) ?></p>
    </div>

    <div class="wt-showcase__grid">
      <?php foreach ($tasks as $i => $t): ?>
        <a class="wt-task-card wt-task-card--<?= e($t['k']) ?> wt-task-card--showcase<?= !empty($t['popular']) ? ' is-popular' : '' ?>"
           href="<?= e($t['href']) ?>"
           style="--idx:<?= (int)$i ?>">

          <?php if (!empty($t['popular'])): ?>
            <span class="wt-task-card__ribbon"><?= e(t('home.tasks.popular')) ?></span>
          <?php endif; ?>

          <span class="wt-task-card__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="26" height="26" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="<?= e($t['icon']) ?>" />
            </svg>
          </span>

          <h3 class="wt-task-card__title"><?= e($t['title']) ?></h3>
          <p class="wt-task-card__desc"><?= e($t['pitch']) ?></p>

          <div class="wt-task-card__pricing">
            <span class="wt-task-card__price">+<?= e($t['reward']) ?></span>
            <span class="wt-task-card__price-unit"><?= e(t('common.coins')) ?> <?= e($t['period']) ?></span>
          </div>

          <ul class="wt-task-card__meta">
            <li>
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              ≈ <?= e($t['duration']) ?>
            </li>
            <?php if (!empty($t['note'])): ?>
              <li class="wt-task-card__note"><?= e($t['note']) ?></li>
            <?php endif; ?>
          </ul>

          <span class="wt-task-card__arrow" aria-hidden="true">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ===================== STATS =========================================
   * Note V8 : la section "Stats" historique est désactivée par défaut
   * car les 3 mêmes chiffres apparaissent désormais dans la trust-line
   * du hero. Si tu veux la réactiver (autre style, autres KPI…), enlève
   * le `false &&` ci-dessous et le bloc s'affichera selon homepage_blocks.
   * --------------------------------------------------------------- -->
  <?php if (false && $blockVisible('stats')): ?>
  <section class="wt-stats" data-reveal>
    <h2 class="wt-section__title"><?= e($blockField('stats', 'title', 'Une plateforme qui paye')) ?></h2>
    <div class="wt-stats__grid">
      <div class="wt-stat">
        <div class="wt-stat__value" data-countup="<?= e((string)$statsUsers) ?>">0</div>
        <div class="wt-stat__label"><?= e(t('home.stats.users')) ?></div>
      </div>
      <div class="wt-stat">
        <div class="wt-stat__value" data-countup="<?= e((string)$statsPaid) ?>">0</div>
        <div class="wt-stat__label"><?= e(t('home.stats.paid')) ?></div>
      </div>
      <div class="wt-stat">
        <div class="wt-stat__value" data-countup="<?= e((string)$statsToday) ?>">0</div>
        <div class="wt-stat__label"><?= e(t('home.stats.today')) ?></div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===================== ACTIVITY FEED v2 (V8) ===============
   * Flux des dernières récompenses, format "phrase naturelle" :
   *   [avatar] Saïd a réclamé +0.50 Coins via Faucet · il y a 2 min
   *
   * - Avatars colorés par hash du username (déterministe, sans image)
   * - Icône d'action distinctive par type
   * - Animation slide-in en cascade au reveal initial
   * - Auto-refresh toutes les 30s via /api/home_feed.php
   * - Pause quand l'onglet est masqué (économie batterie)
   ========================================================== -->
  <?php
    /* Mapping type → icône SVG (path d) + libellé verbe naturel */
    $feedActions = [
        'faucet'    => ['verb' => t('home.feed.verb.faucet'),    'icon' => 'M12 2v6m0 0 4-4m-4 4-4-4M5 12a7 7 0 1 0 14 0M5 12H2m20 0h-3'],
        'shortlink' => ['verb' => t('home.feed.verb.shortlink'), 'icon' => 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'],
        'ptc'       => ['verb' => t('home.feed.verb.ptc'),       'icon' => 'M2 7v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2zm8 3 5 3-5 3z'],
        'offerwall' => ['verb' => t('home.feed.verb.offerwall'), 'icon' => 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5'],
        'referral'  => ['verb' => t('home.feed.verb.referral'),  'icon' => 'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 11A4 4 0 1 0 8.5 3a4 4 0 0 0 0 8zm9-3v6m3-3h-6'],
        'bonus'     => ['verb' => t('home.feed.verb.bonus'),     'icon' => 'M20 12V8H6a2 2 0 0 1 0-4h12v4M4 6v12c0 1.1.9 2 2 2h14v-4M18 12a2 2 0 0 0 0 4h4v-4Z'],
    ];

    /* Initiales pour l'avatar (réutilisé du hero) */
    $feedInitials = static function (string $name): string {
        $parts = preg_split('/[\s._-]+/', trim($name)) ?: [];
        $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
        $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));
        return ($a . $b) ?: '?';
    };
    /* Username masqué (privacy) — même règle que le hero */
    $feedMask = static function (string $name): string {
        $parts = preg_split('/[\s._-]+/', trim($name)) ?: [$name];
        $first = mb_strlen($parts[0]) > 12 ? mb_substr($parts[0], 0, 10) . '…' : $parts[0];
        return $first . (isset($parts[1]) ? ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '');
    };
  ?>

  <section class="wt-feed-v2" data-reveal>
    <div class="wt-feed-v2__head">
      <span class="wt-eyebrow">
        <span class="wt-eyebrow__pulse" aria-hidden="true"></span>
        <?= e(t('home.feed.live')) ?>
      </span>
      <h2 class="wt-section__title"><?= e(t('home.feed.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.feed.lead')) ?></p>
    </div>

    <?php if (empty($feed)): ?>
      <p class="wt-feed-v2__empty wt-muted"><?= e(t('home.feed.empty')) ?></p>
    <?php else: ?>
      <ul class="wt-feed-v2__list" data-feed-list>
        <?php foreach ($feed as $i => $f):
            $action = $feedActions[$f['type']] ?? ['verb' => $f['type'], 'icon' => 'M12 2v20m-10-10h20'];
        ?>
          <li class="wt-feed-v2__item" style="--idx:<?= (int)$i ?>" data-user="<?= e($f['username']) ?>">
            <div class="wt-feed-v2__avatar wt-avatar wt-avatar--xs"
                 data-hash-color="<?= e($f['username']) ?>"
                 aria-hidden="true"><?= e($feedInitials($f['username'])) ?></div>

            <div class="wt-feed-v2__body">
              <p class="wt-feed-v2__line">
                <strong><?= e($feedMask($f['username'])) ?></strong>
                <span class="wt-feed-v2__verb"><?= e($action['verb']) ?></span>
                <span class="wt-feed-v2__coins wt-feed-v2__coins--<?= e($f['type']) ?>">
                  +<?= e(rtrim(rtrim(number_format((float)$f['coins'], 2, '.', ''), '0'), '.')) ?>
                  <small><?= e(t('common.coins')) ?></small>
                </span>
              </p>
              <small class="wt-feed-v2__meta">
                <span class="wt-feed-v2__type-icon wt-feed-v2__type-icon--<?= e($f['type']) ?>" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="<?= e($action['icon']) ?>"/>
                  </svg>
                </span>
                <span><?= e(ucfirst($f['type'])) ?></span>
                <span class="wt-feed-v2__sep" aria-hidden="true">·</span>
                <time data-fmt-time
                      data-utc="<?= e($f['created_at']) ?>"
                      data-format="relative"><?= e(wt_format_datetime($f['created_at'])) ?></time>
              </small>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- ===================== OLD FEED (désactivé V8) ============== -->
  <?php if (false): ?>
  <section class="wt-feed" data-reveal>
    <h2 class="wt-section__title"><?= e(t('home.feed.title')) ?></h2>
    <?php if (!$feed): ?>
      <p class="wt-muted"><?= e(t('home.feed.empty')) ?></p>
    <?php else: ?>
      <ul class="wt-feed__list">
        <?php foreach ($feed as $f): ?>
          <li class="wt-feed__item">
            <span class="wt-feed__user"><?= e($f['username']) ?></span>
            <span class="wt-feed__type wt-feed__type--<?= e($f['type']) ?>">
              <?= e(ucfirst($f['type'])) ?>
            </span>
            <span class="wt-feed__coins">+<?= e(rtrim(rtrim(number_format((float)$f['coins'], 2, '.', ''), '0'), '.')) ?> <?= e(t('common.coins')) ?></span>
            <span class="wt-feed__time"
                  data-fmt-time
                  data-utc="<?= e($f['created_at']) ?>"><?= e(wt_format_datetime($f['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ===================== LEADERBOARD PREVIEW (V8) ============
   * Top 5 du mois en cours en format podium horizontal :
   *  - rank 2 (gauche), rank 1 (centre plus grand), rank 3 (droite)
   *  - rank 4 & 5 plus petits en dessous
   *  - médailles 🥇🥈🥉, montant Coins du mois, badge niveau
   *  - CTA vers /leaderboard/
   *
   * Source : wt_lb_get_top() (cache 15 min en table leaderboard_cache).
   * Si moins de 3 entrées : on n'affiche pas la section (pas de podium dégénéré).
   ========================================================== -->
  <?php
    $lbTop = wt_lb_get_top();
    if (count($lbTop) >= 3):
        // Réorganisation pour le podium : [rank2, rank1, rank3, rank4, rank5]
        $rank1 = $lbTop[0] ?? null;
        $rank2 = $lbTop[1] ?? null;
        $rank3 = $lbTop[2] ?? null;
        $rank4 = $lbTop[3] ?? null;
        $rank5 = $lbTop[4] ?? null;

        /* Reuse des helpers existants */
        $lbInitials = static function (string $name): string {
            $parts = preg_split('/[\s._-]+/', trim($name)) ?: [];
            $a = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
            $b = mb_strtoupper(mb_substr($parts[1] ?? '', 0, 1));
            return ($a . $b) ?: '?';
        };
        $lbMask = static function (string $name): string {
            $parts = preg_split('/[\s._-]+/', trim($name)) ?: [$name];
            $first = mb_strlen($parts[0]) > 12 ? mb_substr($parts[0], 0, 10) . '…' : $parts[0];
            return $first . (isset($parts[1]) ? ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '');
        };
        $fmtCoins = static function (float $n): string {
            return rtrim(rtrim(number_format($n, 2, '.', ' '), '0'), '.');
        };
  ?>
  <section class="wt-lbprev" data-reveal>
    <div class="wt-lbprev__head">
      <span class="wt-eyebrow">🏆 <?= e(t('home.lb.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.lb.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.lb.lead')) ?></p>
    </div>

    <!-- Podium horizontal 2-1-3 -->
    <div class="wt-lbprev__podium">

      <!-- Rank 2 (gauche) -->
      <article class="wt-lbprev__step wt-lbprev__step--2">
        <div class="wt-lbprev__medal" aria-hidden="true">🥈</div>
        <div class="wt-lbprev__avatar wt-avatar wt-avatar--md"
             data-hash-color="<?= e($rank2['username']) ?>"
             aria-hidden="true"><?= e($lbInitials($rank2['username'])) ?></div>
        <strong class="wt-lbprev__name"><?= e($lbMask($rank2['username'])) ?></strong>
        <span class="wt-lbprev__level">Lv <?= (int)$rank2['level'] ?></span>
        <div class="wt-lbprev__coins">
          <span class="wt-lbprev__amount"><?= e($fmtCoins((float)$rank2['coins_month'])) ?></span>
          <small><?= e(t('common.coins')) ?></small>
        </div>
        <div class="wt-lbprev__plinth wt-lbprev__plinth--2"><span>2</span></div>
      </article>

      <!-- Rank 1 (centre, plus grand) -->
      <article class="wt-lbprev__step wt-lbprev__step--1">
        <div class="wt-lbprev__crown" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
            <path d="M5 16 3 6l4.5 4L12 4l4.5 6L21 6l-2 10H5zm0 3h14v2H5v-2z"/>
          </svg>
        </div>
        <div class="wt-lbprev__medal" aria-hidden="true">🥇</div>
        <div class="wt-lbprev__avatar wt-avatar wt-avatar--lg"
             data-hash-color="<?= e($rank1['username']) ?>"
             aria-hidden="true"><?= e($lbInitials($rank1['username'])) ?></div>
        <strong class="wt-lbprev__name"><?= e($lbMask($rank1['username'])) ?></strong>
        <span class="wt-lbprev__level">Lv <?= (int)$rank1['level'] ?></span>
        <div class="wt-lbprev__coins">
          <span class="wt-lbprev__amount"><?= e($fmtCoins((float)$rank1['coins_month'])) ?></span>
          <small><?= e(t('common.coins')) ?></small>
        </div>
        <div class="wt-lbprev__plinth wt-lbprev__plinth--1"><span>1</span></div>
      </article>

      <!-- Rank 3 (droite) -->
      <article class="wt-lbprev__step wt-lbprev__step--3">
        <div class="wt-lbprev__medal" aria-hidden="true">🥉</div>
        <div class="wt-lbprev__avatar wt-avatar wt-avatar--md"
             data-hash-color="<?= e($rank3['username']) ?>"
             aria-hidden="true"><?= e($lbInitials($rank3['username'])) ?></div>
        <strong class="wt-lbprev__name"><?= e($lbMask($rank3['username'])) ?></strong>
        <span class="wt-lbprev__level">Lv <?= (int)$rank3['level'] ?></span>
        <div class="wt-lbprev__coins">
          <span class="wt-lbprev__amount"><?= e($fmtCoins((float)$rank3['coins_month'])) ?></span>
          <small><?= e(t('common.coins')) ?></small>
        </div>
        <div class="wt-lbprev__plinth wt-lbprev__plinth--3"><span>3</span></div>
      </article>
    </div>

    <!-- Lignes 4 & 5 sous le podium (si présentes) -->
    <?php if ($rank4 || $rank5): ?>
      <ul class="wt-lbprev__runners">
        <?php foreach ([$rank4, $rank5] as $r): if (!$r) continue; ?>
          <li class="wt-lbprev__runner">
            <span class="wt-lbprev__runner-rank">#<?= (int)$r['rank'] ?></span>
            <div class="wt-lbprev__avatar wt-avatar wt-avatar--xs"
                 data-hash-color="<?= e($r['username']) ?>"
                 aria-hidden="true"><?= e($lbInitials($r['username'])) ?></div>
            <strong class="wt-lbprev__runner-name"><?= e($lbMask($r['username'])) ?></strong>
            <span class="wt-lbprev__runner-level">Lv <?= (int)$r['level'] ?></span>
            <span class="wt-lbprev__runner-coins">
              <?= e($fmtCoins((float)$r['coins_month'])) ?>
              <small><?= e(t('common.coins')) ?></small>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="wt-lbprev__foot">
      <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/leaderboard/')) ?>">
        <?= e(t('home.lb.cta')) ?> →
      </a>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===================== TESTIMONIALS FEATURED (V8) ============
   * Affiche 3 témoignages "featured" approuvés. Si moins de 3 entries,
   * on cache la section entièrement (pas de placeholder bidon).
   * Layout 3-col desktop / 1-col mobile. Chaque card a un dégradé subtil
   * et une "quote mark" décorative en arrière-plan.
   ========================================================== -->
  <?php
    $testimonials = [];
    $sql = "SELECT t.rating, t.title, t.body, t.created_at,
                   u.username, u.level
              FROM testimonials t
              JOIN users u ON u.id = t.user_id
             WHERE t.status = 'approved' AND t.featured = 1
             ORDER BY t.created_at DESC
             LIMIT 3";
    if ($res = $db->query($sql)) {
        $testimonials = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    if (count($testimonials) >= 3):
        /* Réutilise les helpers initials/mask déjà définis plus haut dans
         * le fichier ; on les redéfinit pas (PHP les a en mémoire). */
  ?>
  <section class="wt-testi" data-reveal>
    <div class="wt-testi__head">
      <span class="wt-eyebrow">⭐ <?= e(t('home.testi.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.testi.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.testi.lead')) ?></p>
    </div>

    <div class="wt-testi__grid">
      <?php foreach ($testimonials as $i => $t): ?>
        <article class="wt-testi__card" style="--idx:<?= (int)$i ?>">
          <span class="wt-testi__quote-mark" aria-hidden="true">"</span>

          <!-- Étoiles : on en remplit "rating" sur 5 -->
          <div class="wt-testi__stars" aria-label="<?= (int)$t['rating'] ?> / 5">
            <?php for ($s = 1; $s <= 5; $s++): ?>
              <svg viewBox="0 0 24 24" width="16" height="16"
                   class="wt-testi__star <?= $s <= (int)$t['rating'] ? 'is-on' : '' ?>"
                   fill="currentColor" aria-hidden="true">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
              </svg>
            <?php endfor; ?>
          </div>

          <h3 class="wt-testi__title"><?= e($t['title']) ?></h3>
          <p class="wt-testi__body"><?= e($t['body']) ?></p>

          <footer class="wt-testi__author">
            <div class="wt-testi__avatar wt-avatar wt-avatar--sm"
                 data-hash-color="<?= e($t['username']) ?>"
                 aria-hidden="true"><?= e($lbInitials($t['username'])) ?></div>
            <div class="wt-testi__author-info">
              <strong><?= e($lbMask($t['username'])) ?></strong>
              <small>Lv <?= (int)$t['level'] ?> · <time data-fmt-time data-utc="<?= e($t['created_at']) ?>"><?= e(wt_format_datetime($t['created_at'], 'M Y')) ?></time></small>
            </div>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="wt-testi__foot">
      <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/testimonials/')) ?>">
        <?= e(t('home.testi.cta')) ?> →
      </a>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===================== HOW IT WORKS v2 (V8) ==================
   * Timeline 3 étapes avec icône SVG par étape, ligne de connexion
   * animée (gradient qui se "remplit" au scroll via IntersectionObserver),
   * et badges illustratifs sous chaque étape.
   *
   * Layout :
   *  - Desktop : timeline horizontale, 3 étapes côte à côte avec ligne
   *  - Mobile  : timeline verticale, étapes empilées avec ligne à gauche
   ========================================================== -->
  <?php if ($blockVisible('how')):
    $howSteps = [
        [
            'num'   => '01',
            't'     => t('home.how.step1.t'),
            'd'     => t('home.how.step1.d'),
            'icon'  => 'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 11A4 4 0 1 0 8.5 3a4 4 0 0 0 0 8zm9-3v6m3-3h-6', // user-plus (signup)
            'cta'   => $u ? null : ['href' => wt_url('/auth/signup.php'), 'label' => t('nav.register')],
        ],
        [
            'num'   => '02',
            't'     => t('home.how.step2.t'),
            'd'     => t('home.how.step2.d'),
            'icon'  => 'M22 11.08V12a10 10 0 1 1-5.93-9.14M22 4 12 14.01l-3-3', // check-shield (réaliser tâches)
            'cta'   => null,
        ],
        [
            'num'   => '03',
            't'     => t('home.how.step3.t'),
            'd'     => t('home.how.step3.d'),
            'icon'  => 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4Z', // wallet (encaisser)
            'cta'   => null,
        ],
    ];
  ?>
  <section class="wt-how-v2" data-reveal>
    <div class="wt-how-v2__head">
      <span class="wt-eyebrow">🚀 <?= e(t('home.how.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e($blockField('how', 'title', t('home.how.title'))) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.how.lead')) ?></p>
    </div>

    <ol class="wt-how-v2__timeline">
      <?php foreach ($howSteps as $i => $s): ?>
        <li class="wt-how-v2__step" style="--idx:<?= (int)$i ?>">
          <!-- Pastille numérotée + icône -->
          <div class="wt-how-v2__bullet" aria-hidden="true">
            <span class="wt-how-v2__icon">
              <svg viewBox="0 0 24 24" width="24" height="24" fill="none"
                   stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round">
                <path d="<?= e($s['icon']) ?>"/>
              </svg>
            </span>
            <span class="wt-how-v2__num"><?= e($s['num']) ?></span>
          </div>

          <div class="wt-how-v2__body">
            <h3 class="wt-how-v2__title"><?= e($s['t']) ?></h3>
            <p class="wt-how-v2__desc"><?= e($s['d']) ?></p>
            <?php if (!empty($s['cta'])): ?>
              <a class="wt-how-v2__cta" href="<?= e($s['cta']['href']) ?>">
                <?= e($s['cta']['label']) ?> →
              </a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <!-- ===================== PAYMENT METHODS (V8) ==================
   * Bandeau des méthodes de retrait actives, avec seuil minimum en
   * tooltip et lien direct vers /dashboard/withdraw si l'utilisateur
   * est connecté, sinon vers /auth/signup pour pousser l'inscription.
   ========================================================== -->
  <?php
    $payMethods = [];
    if ($res = $db->query("SELECT k, label, currency, min_coins, coins_per_unit
                             FROM withdrawal_methods
                            WHERE active = 1
                            ORDER BY sort_order ASC")) {
        $payMethods = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
    }
    if (!empty($payMethods)):
        $payHref = $u ? wt_url('/dashboard/withdraw.php') : wt_url('/auth/signup.php');

        /* Mapping clé interne → emoji visuel (rapide et universel) */
        $payIcons = [
            'paypal'    => '💳',
            'wise'      => '🏦',
            'crypto'    => '₿',
            'btc'       => '₿',
            'eth'       => '⟠',
            'usdt'      => '₮',
            'orange'    => '📱',
            'mpesa'     => '📱',
            'mtn'       => '📱',
            'card'      => '💳',
            'bank'      => '🏦',
        ];
        $getPayIcon = static function (string $k) use ($payIcons): string {
            foreach ($payIcons as $needle => $emoji) {
                if (stripos($k, $needle) !== false) return $emoji;
            }
            return '💸';
        };
  ?>
  <section class="wt-paymethods" data-reveal>
    <div class="wt-paymethods__head">
      <span class="wt-eyebrow">💸 <?= e(t('home.pay.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.pay.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.pay.lead')) ?></p>
    </div>

    <ul class="wt-paymethods__list">
      <?php foreach ($payMethods as $i => $m):
          $minUnit = (float)$m['min_coins'] / max(1, (float)$m['coins_per_unit']);
          $minFmt  = rtrim(rtrim(number_format($minUnit, 2, '.', ' '), '0'), '.');
      ?>
        <li class="wt-paymethods__item" style="--idx:<?= (int)$i ?>">
          <a class="wt-paymethods__chip" href="<?= e($payHref) ?>"
             title="<?= e(sprintf((string)t('home.pay.tooltip'), $minFmt, $m['currency'])) ?>">
            <span class="wt-paymethods__icon" aria-hidden="true"><?= $getPayIcon($m['k']) ?></span>
            <span class="wt-paymethods__label">
              <strong><?= e($m['label']) ?></strong>
              <small><?= e(sprintf((string)t('home.pay.min'), $minFmt, $m['currency'])) ?></small>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <!-- ===================== FAQ ACCORDION (V8) ====================
   * 5 questions clés en accordion. Une seule réponse ouverte à la fois.
   * Le HTML <details> natif gère l'état (pas de JS sauf pour la
   * "one-open-at-a-time" via le hook plus bas).
   ========================================================== -->
  <?php
    $faqItems = [
        ['q' => t('home.faq.q1'), 'a' => t('home.faq.a1'), 'open' => true],
        ['q' => t('home.faq.q2'), 'a' => t('home.faq.a2'), 'open' => false],
        ['q' => t('home.faq.q3'), 'a' => t('home.faq.a3'), 'open' => false],
        ['q' => t('home.faq.q4'), 'a' => t('home.faq.a4'), 'open' => false],
        ['q' => t('home.faq.q5'), 'a' => t('home.faq.a5'), 'open' => false],
    ];
  ?>
  <section class="wt-faq" data-reveal>
    <div class="wt-faq__head">
      <span class="wt-eyebrow">❓ <?= e(t('home.faq.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.faq.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.faq.lead')) ?></p>
    </div>

    <div class="wt-faq__list" data-faq-list>
      <?php foreach ($faqItems as $i => $item): ?>
        <details class="wt-faq__item"
                 style="--idx:<?= (int)$i ?>"
                 <?= !empty($item['open']) ? 'open' : '' ?>>
          <summary class="wt-faq__q">
            <span class="wt-faq__q-text"><?= e($item['q']) ?></span>
            <span class="wt-faq__chevron" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                   stroke="currentColor" stroke-width="2"
                   stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </span>
          </summary>
          <div class="wt-faq__a"><?= e($item['a']) ?></div>
        </details>
      <?php endforeach; ?>
    </div>

    <p class="wt-faq__foot">
      <?= e(t('home.faq.more_prefix')) ?>
      <a href="<?= e(wt_url('/help/faq.php')) ?>"><?= e(t('home.faq.more_link')) ?></a>
      <?= e(t('home.faq.more_suffix')) ?>
      <a href="<?= e(wt_url('/help/contact.php')) ?>"><?= e(t('home.faq.contact_link')) ?></a>.
    </p>
  </section>

  <?php
  /* ===================== DERNIERS ARTICLES DU BLOG =====================
   * Affiche les 3 derniers articles publiés. Section masquée s'il n'y a
   * aucun article, ou si le blog est désactivé. Apporte du contenu frais
   * et des liens internes (bon pour le SEO et la découverte). */
  $homeBlogPosts = (function_exists('wt_blog_enabled') && wt_blog_enabled())
      ? wt_blog_posts(3, 0)
      : [];
  if (!empty($homeBlogPosts)):
  ?>
  <section class="wt-home-blog" data-reveal>
    <div class="wt-home-blog__head">
      <span class="wt-eyebrow">📰 <?= e(t('home.blog.eyebrow')) ?></span>
      <h2 class="wt-section__title"><?= e(t('home.blog.title')) ?></h2>
      <p class="wt-section__lead"><?= e(t('home.blog.lead')) ?></p>
    </div>

    <div class="wt-home-blog__grid">
      <?php foreach ($homeBlogPosts as $post): ?>
        <article class="wt-home-blog__card">
          <a href="<?= e(wt_url('/blog/' . $post['slug'])) ?>" class="wt-home-blog__link">
            <div class="wt-home-blog__cover">
              <span class="wt-home-blog__emoji"><?= e($post['cover_emoji'] ?: '📄') ?></span>
            </div>
            <div class="wt-home-blog__body">
              <?php if (!empty($post['category_name'])): ?>
                <span class="wt-home-blog__cat"><?= e($post['category_name']) ?></span>
              <?php endif; ?>
              <h3 class="wt-home-blog__title"><?= e($post['title']) ?></h3>
              <?php if (!empty($post['excerpt'])): ?>
                <p class="wt-home-blog__excerpt"><?= e($post['excerpt']) ?></p>
              <?php endif; ?>
              <span class="wt-home-blog__meta">⏱️ <?= (int)$post['reading_minutes'] ?> <?= e(t('blog.min_read')) ?></span>
            </div>
          </a>
        </article>
      <?php endforeach; ?>
    </div>

    <p class="wt-home-blog__foot">
      <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/blog')) ?>">
        <?= e(t('home.blog.see_all')) ?> →
      </a>
    </p>
  </section>
  <?php endif; ?>

  <!-- ===================== FINAL CTA (V8) =========================
   * Masqué si l'utilisateur est déjà connecté (rien à pousser).
   * Sinon : bandeau full-bleed avec gradient + gros bouton conversion.
   ========================================================== -->
  <?php if (!$u): ?>
  <section class="wt-finalcta" data-reveal>
    <div class="wt-finalcta__inner">
      <div class="wt-finalcta__halo" aria-hidden="true"></div>
      <h2 class="wt-finalcta__title"><?= e(t('home.finalcta.title')) ?></h2>
      <p class="wt-finalcta__lead"><?= e(t('home.finalcta.lead')) ?></p>
      <div class="wt-finalcta__cta">
        <a class="wt-btn wt-btn--primary wt-btn--lg" href="<?= e(wt_url('/auth/signup.php')) ?>">
          <?= e(t('home.finalcta.primary')) ?>
        </a>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/help/faq.php')) ?>">
          <?= e(t('home.finalcta.secondary')) ?>
        </a>
      </div>
      <small class="wt-finalcta__trust">
        <?= e(t('home.finalcta.trust')) ?>
      </small>
    </div>
  </section>
  <?php endif; ?>

</main>

<?php include __DIR__ . '/footer.php'; ?>
