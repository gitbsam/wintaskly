<?php
/**
 * Wintaskly — Admin · Boîte à cadeaux
 *
 * Réglages du jeu, état de la grille en cours, cagnotte et jackpot.
 *
 * Le point sensible n'est pas la grille mais la CAGNOTTE : elle
 * finance les lots exceptionnels, et elle seule empêche un jackpot de
 * sortir sans être payé. Elle est donc affichée en premier.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_role('admin');

$pageTitle   = t('admin.gift.title');
$adminActive = 'gift';
$db          = db();

$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check((string) ($_POST['_csrf'] ?? ''))) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'settings') {
        foreach (['gift.enabled', 'gift.test_mode'] as $k) {
            cfg_set($k, !empty($_POST[str_replace('.', '_', $k)]) ? '1' : '0');
        }
        foreach (['gift.boxes_count', 'gift.pot_step', 'gift.jackpot_step',
                  'gift.jackpot_seed', 'gift.jackpot_min', 'gift.special_chance',
                  'gift.ads_seconds'] as $k) {
            $v = $_POST[str_replace('.', '_', $k)] ?? null;
            if ($v !== null && $v !== '') { cfg_set($k, (string) max(0, (int) $v)); }
        }
        $r = $_POST['gift_envelope_ratio'] ?? null;
        if ($r !== null && $r !== '') {
            cfg_set('gift.envelope_ratio', (string) max(0, min(100, (int) $r)) / 100);
        }
        cfg_set('gift.big_tiers', trim((string) ($_POST['gift_big_tiers'] ?? '800,1000,2000,5000')));
        cfg_set('gift.multipliers', trim((string) ($_POST['gift_multipliers'] ?? '20,50,80')));
        cfg_set('gift.ads_url', trim((string) ($_POST['gift_ads_url'] ?? '')));
        wt_admin_log('gift_settings', [], 0);
        $notice = t('admin.saved');

    } elseif ($action === 'close') {
        /* Clôture forcée de la grille en cours. La suivante sera créée
           au premier joueur, avec son propre tirage de lot exceptionnel.
           Les boîtes non ouvertes sont perdues : c'est assumé, et c'est
           pourquoi l'action demande confirmation. */
        $db->query("UPDATE gift_rounds SET status = 'done', closed_at = UTC_TIMESTAMP() WHERE status = 'active'");
        wt_admin_log('gift_close_round', [], 0);
        $notice = t('admin.gift.closed');

    } elseif ($action === 'pot') {
        /* Ajustement manuel de la cagnotte, pour amorcer le jeu sans
           attendre que des milliers de boîtes l'alimentent. */
        $v = (float) ($_POST['pot'] ?? 0);
        if ($v >= 0) {
            cfg_set('gift.prize_pot', (string) $v);
            wt_admin_log('gift_pot_set', ['v' => $v], 0);
            $notice = t('admin.gift.pot_done');
        }
    }
}

$tableOk = true;
$round   = null;
$stats   = ['boxes' => 0, 'opened' => 0, 'paid' => 0.0];
try {
    $round = db_one("SELECT * FROM gift_rounds WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    if ($round) {
        $r = db_one(
            "SELECT COUNT(*) n, SUM(opened_by IS NOT NULL) o, COALESCE(SUM(coins_paid),0) p
               FROM gift_boxes WHERE round_id = " . (int) $round['id']
        );
        $stats = ['boxes' => (int) $r['n'], 'opened' => (int) $r['o'], 'paid' => (float) $r['p']];
    }
} catch (Throwable $e) {
    $tableOk = false;
}

$cagnotte = (float) cfg('gift.prize_pot', 0);
$jackpot  = (float) cfg('gift.jackpot', 0);
$seuil    = (float) cfg('gift.jackpot_min', 40000);
$boxes    = max(10, (int) cfg('gift.boxes_count', 100));

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-admin-v2">
  <div class="wt-admin-v2__layout">
    <?php include __DIR__ . '/_nav.php'; ?>
    <section class="wt-admin-v2__content">

      <header class="wt-admin-v2__page-header">
        <div>
          <h1>🎁 <?= e(t('admin.gift.title')) ?></h1>
          <p class="wt-muted"><?= e(t('admin.gift.lead')) ?></p>
        </div>
      </header>

      <?php if (!$tableOk): ?>
        <div class="wt-alert wt-alert--warn"><?= e(t('admin.gift.no_table')) ?></div>
      <?php else: ?>

        <?php if ($notice): ?><div class="wt-alert wt-alert--success"><?= e($notice) ?></div><?php endif; ?>

        <?php
        /* Le jackpot ne peut sortir que si la cagnotte le couvre ET
           qu'il a franchi son seuil. Deux conditions, donc deux
           avertissements distincts : confondre les deux ferait chercher
           le probleme au mauvais endroit. */
        if ($jackpot > $cagnotte):
        ?>
          <div class="wt-alert wt-alert--warn">
            <strong><?= e(t('admin.gift.w_pot_title')) ?></strong>
            <p style="margin:.35rem 0 0;font-size:.9rem">
              <?= e(t('admin.gift.w_pot_text', [
                    'j' => number_format($jackpot, 0, ',', ' '),
                    'c' => number_format($cagnotte, 0, ',', ' '),
                  ])) ?>
            </p>
          </div>
        <?php elseif ($jackpot < $seuil): ?>
          <div class="wt-alert wt-alert--info">
            <?= e(t('admin.gift.w_seuil', [
                  'j' => number_format($jackpot, 0, ',', ' '),
                  's' => number_format($seuil, 0, ',', ' '),
                  'n' => number_format(max(0, ceil(($seuil - $jackpot) / max(1, (int) cfg('gift.jackpot_step', 5)))), 0, ',', ' '),
                ])) ?>
          </div>
        <?php endif; ?>

        <div class="wt-admin-v2__stats">
          <div><strong><?= e(number_format($cagnotte, 0, ',', ' ')) ?></strong><br>
               <small class="wt-muted"><?= e(t('admin.gift.s_pot')) ?></small></div>
          <div><strong><?= e(number_format($jackpot, 0, ',', ' ')) ?></strong><br>
               <small class="wt-muted"><?= e(t('admin.gift.s_jackpot')) ?></small></div>
          <div><strong><?= (int) $stats['opened'] ?>/<?= (int) $stats['boxes'] ?></strong><br>
               <small class="wt-muted"><?= e(t('admin.gift.s_opened')) ?></small></div>
          <div><strong><?= e(number_format($stats['paid'], 0, ',', ' ')) ?></strong><br>
               <small class="wt-muted"><?= e(t('admin.gift.s_paid')) ?></small></div>
        </div>

        <?php if ($round): ?>
          <p class="wt-muted" style="margin-top:.8rem;font-size:.9rem">
            <?= e(t('admin.gift.round_info', [
                  'id'  => (int) $round['id'],
                  'env' => number_format((float) $round['envelope'], 0, ',', ' '),
                  'sp'  => $round['special_kind'] === 'none'
                         ? t('admin.gift.sp_none')
                         : ($round['special_kind'] === 'jackpot' ? t('admin.gift.sp_jackpot') : t('admin.gift.sp_big'))
                         . ' — ' . number_format((float) $round['special_value'], 0, ',', ' ') . ' c',
                ])) ?>
          </p>
        <?php endif; ?>

        <?php
        /* Projection : une grille complete. Chaque boite ouverte est un
           parcours publicitaire, donc un affichage.
           Le versement inclut l'enveloppe ET le lot exceptionnel quand
           il y en a un : c'est le cout reel d'une grille, pas celui
           d'une grille ordinaire. */
        $env = wt_gift_envelope($boxes);
        $sp  = $round ? (float) $round['special_value'] : 0.0;
        echo wt_econ_render([
            t('admin.gift.cyc_plain', ['n' => $boxes]) => [$boxes, $env],
            t('admin.gift.cyc_special', ['n' => $boxes]) => [$boxes, $env + (float) cfg('gift.jackpot_min', 40000)],
        ], t('econ.title'));
        ?>

        <h2 style="margin-top:1.6rem"><?= e(t('admin.gift.settings_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="settings">

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="gift_boxes_count"><?= e(t('admin.gift.f_boxes')) ?></label>
              <input class="wt-input" type="number" min="10" max="500" id="gift_boxes_count"
                     name="gift_boxes_count" value="<?= $boxes ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_envelope_ratio"><?= e(t('admin.gift.f_ratio')) ?></label>
              <input class="wt-input" type="number" min="0" max="100" id="gift_envelope_ratio"
                     name="gift_envelope_ratio"
                     value="<?= (int) round((float) cfg('gift.envelope_ratio', 0.40) * 100) ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_ratio_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_ads_seconds"><?= e(t('admin.gift.f_ads_sec')) ?></label>
              <input class="wt-input" type="number" min="5" max="120" id="gift_ads_seconds"
                     name="gift_ads_seconds" value="<?= (int) cfg('gift.ads_seconds', 30) ?>">
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="gift_pot_step"><?= e(t('admin.gift.f_pot_step')) ?></label>
              <input class="wt-input" type="number" min="0" id="gift_pot_step"
                     name="gift_pot_step" value="<?= (int) cfg('gift.pot_step', 20) ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_pot_step_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_jackpot_step"><?= e(t('admin.gift.f_jp_step')) ?></label>
              <input class="wt-input" type="number" min="0" id="gift_jackpot_step"
                     name="gift_jackpot_step" value="<?= (int) cfg('gift.jackpot_step', 5) ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_jp_step_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_special_chance"><?= e(t('admin.gift.f_chance')) ?></label>
              <input class="wt-input" type="number" min="0" max="100" id="gift_special_chance"
                     name="gift_special_chance" value="<?= (int) cfg('gift.special_chance', 50) ?>">
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="gift_jackpot_seed"><?= e(t('admin.gift.f_jp_seed')) ?></label>
              <input class="wt-input" type="number" min="0" id="gift_jackpot_seed"
                     name="gift_jackpot_seed" value="<?= (int) cfg('gift.jackpot_seed', 10000) ?>">
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_jackpot_min"><?= e(t('admin.gift.f_jp_min')) ?></label>
              <input class="wt-input" type="number" min="0" id="gift_jackpot_min"
                     name="gift_jackpot_min" value="<?= (int) $seuil ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_jp_min_hint')) ?></small>
            </div>
          </div>

          <div class="wt-admin-v2__form-grid">
            <div class="wt-field">
              <label class="wt-field__label" for="gift_big_tiers"><?= e(t('admin.gift.f_tiers')) ?></label>
              <input class="wt-input" id="gift_big_tiers" name="gift_big_tiers"
                     value="<?= e((string) cfg('gift.big_tiers', '800,1000,2000,5000')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_tiers_hint')) ?></small>
            </div>
            <div class="wt-field">
              <label class="wt-field__label" for="gift_multipliers"><?= e(t('admin.gift.f_mult')) ?></label>
              <input class="wt-input" id="gift_multipliers" name="gift_multipliers"
                     value="<?= e((string) cfg('gift.multipliers', '20,50,80')) ?>">
              <small class="wt-field__hint"><?= e(t('admin.gift.f_mult_hint')) ?></small>
            </div>
          </div>

          <div class="wt-field">
            <label class="wt-field__label" for="gift_ads_url"><?= e(t('admin.gift.f_ads_url')) ?></label>
            <input class="wt-input" id="gift_ads_url" name="gift_ads_url"
                   value="<?= e((string) cfg('gift.ads_url', '')) ?>">
          </div>

          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
            <input type="checkbox" name="gift_enabled" value="1"
                   <?= ((string) cfg('gift.enabled', '1') === '1') ? 'checked' : '' ?>>
            <span><?= e(t('admin.gift.f_enabled')) ?></span>
          </label>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin-top:.5rem">
            <input type="checkbox" name="gift_test_mode" value="1"
                   <?= ((string) cfg('gift.test_mode', '1') === '1') ? 'checked' : '' ?>>
            <span><?= e(t('admin.gift.f_test')) ?></span>
          </label>

          <button class="wt-btn wt-btn--primary" type="submit" style="margin-top:.8rem">
            <?= e(t('admin.gift.save')) ?>
          </button>
        </form>

        <h2 style="margin-top:2rem"><?= e(t('admin.gift.pot_title')) ?></h2>
        <form method="post" class="wt-admin-v2__form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="pot">
          <div class="wt-field" style="max-width:260px">
            <label class="wt-field__label" for="pot"><?= e(t('admin.gift.f_pot')) ?></label>
            <input class="wt-input" type="number" min="0" id="pot" name="pot"
                   value="<?= (int) $cagnotte ?>">
            <small class="wt-field__hint"><?= e(t('admin.gift.f_pot_hint')) ?></small>
          </div>
          <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('admin.gift.pot_set')) ?></button>
        </form>

        <?php if ($round): ?>
          <form method="post" style="margin-top:1.5rem"
                onsubmit="return confirm('<?= e(t('admin.gift.confirm_close')) ?>')">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="close">
            <button class="wt-btn wt-btn--ghost" type="submit"><?= e(t('admin.gift.close')) ?></button>
            <small class="wt-field__hint" style="display:block;margin-top:.3rem">
              <?= e(t('admin.gift.close_hint')) ?>
            </small>
          </form>
        <?php endif; ?>

      <?php endif; ?>

    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
