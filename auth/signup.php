<?php
/**
 * Wintaskly — Inscription (V8 modernisé).
 *
 * Formulaire Ajax avec préservation des protections existantes :
 *   - 2 honeypots cachés en CSS
 *   - Jauge de force du mot de passe (JS)
 *   - Acceptation des CGU obligatoire
 *   - Capture du code parrainage depuis URL/cookie
 *
 * V8 :
 *   - Layout 2-col avec panneau d'accueil
 *   - Affichage du code parrain si présent (pill verte + nom du parrain
 *     si on peut le résoudre)
 *   - "Ce que tu obtiens" : 3 puces visuelles
 *   - Bonus inscription mis en valeur si bonus configuré
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (current_user()) {
    header('Location: ' . wt_url('/dashboard/'));
    exit;
}

/* Capture du ref-code */
if (!empty($_GET['ref'])) {
    $ref = trim((string) $_GET['ref']);
    if ($ref !== '' && strlen($ref) <= 16) {
        setcookie('wt_ref', $ref, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => !empty($GLOBALS['WT_CONFIG']['cookie_secure']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['wt_ref'] = $ref;
    }
}
$refCode = trim((string) ($_COOKIE['wt_ref'] ?? ''));

/* Résout le username du parrain pour humaniser l'expérience */
$refUsername = null;
if ($refCode !== '') {
    try {
        $db = db();
        $stmt = $db->prepare(
            "SELECT username FROM users WHERE referral_code = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param('s', $refCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $refUsername = $row['username'] ?? null;
    } catch (Throwable $e) {
        // Si la résolution échoue, on continue silencieusement (pas critique)
        error_log('[Wintaskly signup] Resolve referrer failed: ' . $e->getMessage());
        $refUsername = null;
    }
}

/* Bonus d'inscription configuré ? */
$welcomeBonus = (float) cfg('signup.welcome_bonus', '0');

$pageTitle = t('auth.register');
include __DIR__ . '/../header.php';
?>

<script>
(function () {
  var h = document.documentElement;
  h.dataset.strengthWeak   = <?= json_encode(t('auth.strength.weak')) ?>;
  h.dataset.strengthFair   = <?= json_encode(t('auth.strength.fair')) ?>;
  h.dataset.strengthGood   = <?= json_encode(t('auth.strength.good')) ?>;
  h.dataset.strengthStrong = <?= json_encode(t('auth.strength.strong')) ?>;
})();
</script>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <!-- ============ COLONNE FORM ============ -->
    <section class="wt-auth-v2__form-col">
      <header class="wt-auth-v2__head">
        <span class="wt-eyebrow">🎉 <?= e(t('auth.eyebrow_signup')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.register')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.lead_signup')) ?></p>

        <?php if ($refCode !== ''): ?>
          <div class="wt-auth-v2__ref-pill">
            <span class="wt-auth-v2__ref-icon" aria-hidden="true">🎁</span>
            <div>
              <strong>
                <?= $refUsername
                      ? e(sprintf((string)t('auth.ref.invited_by'), $refUsername))
                      : e(t('auth.ref.with_code')) ?>
              </strong>
              <small><?= e(sprintf((string)t('auth.ref.code_is'), $refCode)) ?></small>
            </div>
          </div>
        <?php endif; ?>
      </header>

      <div class="wt-alert wt-alert--error is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_signup.php')) ?>"
            novalidate>
        <input type="hidden" name="_csrf"    value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="ref_code" value="<?= e($refCode) ?>">

        <!-- Honeypots — invisibles humains via CSS .wt-honey -->
        <div class="wt-honey" aria-hidden="true">
          <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
          <label>Phone<input type="text" name="phone_number" tabindex="-1" autocomplete="off"></label>
        </div>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.username')) ?></span>
          <input class="wt-input" type="text" name="username"
                 autocomplete="username"
                 pattern="[a-zA-Z0-9_.\-]{3,40}"
                 placeholder="<?= e(t('auth.username_placeholder')) ?>"
                 required>
          <small class="wt-field__hint"><?= e(t('auth.username_hint')) ?></small>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.email')) ?></span>
          <input class="wt-input" type="email" name="email"
                 autocomplete="email"
                 placeholder="exemple@email.com"
                 required>
        </label>

        <label class="wt-field">
          <span class="wt-field__label"><?= e(t('auth.password')) ?></span>
          <div class="wt-input-wrap wt-input-wrap--password">
            <input class="wt-input" type="password" name="password"
                 autocomplete="new-password" required minlength="8"
                 data-strength-input>
            <button type="button" class="wt-input-eye" data-toggle-pw
                    aria-label="<?= e(t('auth.toggle_pw')) ?>" tabindex="-1">
              <svg class="wt-input-eye__off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="wt-input-eye__on is-hidden" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.69 21.69 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.7 21.7 0 0 1-3.17 4.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/></svg>
            </button>
          </div>
          <div class="wt-strength" data-strength-bar>
            <div class="wt-strength__fill" data-strength-fill></div>
          </div>
          <small class="wt-field__hint" data-strength-label>
            <?= e(t('auth.strength.weak')) ?>
          </small>
        </label>

        <label class="wt-checkbox wt-auth-v2__terms">
          <input type="checkbox" name="accept_terms" value="1" required>
          <span>
            <?= e(t('auth.accept_pre')) ?>
            <a href="<?= e(wt_url('/legal/cgu.php')) ?>" target="_blank"><?= e(t('auth.accept_cgu')) ?></a>
            <?= e(t('auth.accept_and')) ?>
            <a href="<?= e(wt_url('/legal/privacy.php')) ?>" target="_blank"><?= e(t('auth.accept_privacy')) ?></a>.
          </span>
        </label>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">🚀 <?= e(t('auth.submit_reg')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>

      <p class="wt-auth-v2__alt">
        <?= e(t('auth.has_account')) ?>
        <a href="<?= e(wt_url('/auth/login.php')) ?>"><?= e(t('auth.login')) ?> →</a>
      </p>
    </section>

    <!-- ============ COLONNE MARKETING ============ -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--signup">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">✨ <?= e(t('auth.side.start')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.side.welcome_new')) ?></h2>
      </header>

      <?php if ($welcomeBonus > 0): ?>
        <div class="wt-auth-v2__bonus">
          <span class="wt-auth-v2__bonus-icon" aria-hidden="true">🎁</span>
          <div>
            <strong>+<?= e(rtrim(rtrim(number_format($welcomeBonus, 2, '.', ''), '0'), '.')) ?> <?= e(t('common.coins')) ?></strong>
            <small><?= e(t('auth.side.welcome_bonus')) ?></small>
          </div>
        </div>
      <?php endif; ?>

      <ul class="wt-auth-v2__benefits">
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">💧</span>
          <div>
            <strong><?= e(t('auth.benefit_faucet_title')) ?></strong>
            <small><?= e(t('auth.benefit_faucet_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">🤝</span>
          <div>
            <strong><?= e(t('auth.benefit_referral_title')) ?></strong>
            <small><?= e(t('auth.benefit_referral_text')) ?></small>
          </div>
        </li>
        <li>
          <span class="wt-auth-v2__benefit-icon" aria-hidden="true">🏆</span>
          <div>
            <strong><?= e(t('auth.benefit_leaderboard_title')) ?></strong>
            <small><?= e(t('auth.benefit_leaderboard_text')) ?></small>
          </div>
        </li>
      </ul>

      <p class="wt-auth-v2__side-foot">
        🔒 <?= e(t('auth.side.no_card')) ?>
      </p>
    </aside>

  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
