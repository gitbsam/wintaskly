<?php
/**
 * Wintaskly — Vérification 2FA TOTP (V8 modernisé).
 *
 * S'affiche après un POST réussi vers /api/auth_login.php qui a
 * renvoyé { ok:true, two_factor_required:true }. La session contient
 * `pending_2fa_uid` mais PAS encore `uid`.
 *
 * Compat : hooks [data-otp-root], [data-otp-hidden], data-auth-form
 * (le JS d'autotab des cases existe déjà).
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

if (empty($_SESSION['pending_2fa_uid'])) {
    header('Location: ' . wt_url('/auth/login.php'));
    exit;
}

$pageTitle = t('auth.2fa.title');

/* Méthodes disponibles pour ce compte et méthode courante.
   Calculées à la connexion (api/auth_login.php) et conservées en session :
   on ne réinterroge pas la base à chaque affichage, et surtout on ne laisse
   pas le client choisir une méthode qui ne lui est pas ouverte. */
$twofaMethods = (array) ($_SESSION['pending_2fa_methods'] ?? ['totp']);
$twofaCurrent = (string) ($_SESSION['pending_2fa_method'] ?? ($twofaMethods[0] ?? 'totp'));
$backupOn     = (string) cfg('2fa.backup_enabled', '1') === '1';
/* Page de formulaire d'authentification : aucune valeur en recherche.
   Sans noindex, ce sont des pages de ~55 mots que Google peut indexer et
   comptabiliser comme contenu pauvre du site — forgot-password.php est
   même explicitement autorisée au crawl dans robots.txt. Connexion et
   inscription étaient déjà traitées : on harmonise. Les liens restent
   suivis (follow). */
$pageNoindex = true;
include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-auth-v2" data-reveal>
  <div class="wt-auth-v2__wrap">

    <section class="wt-auth-v2__form-col">
      <div class="wt-auth-v2__shield-illust" aria-hidden="true">
        <svg viewBox="0 0 120 120" width="120" height="120">
          <defs>
            <linearGradient id="gradShield" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%"  stop-color="var(--wt-accent)"/>
              <stop offset="100%" stop-color="var(--wt-accent2)"/>
            </linearGradient>
          </defs>
          <path d="M60 12 L 100 28 L 100 60 C 100 84 80 100 60 108 C 40 100 20 84 20 60 L 20 28 Z"
                fill="url(#gradShield)" opacity=".15"/>
          <path d="M60 12 L 100 28 L 100 60 C 100 84 80 100 60 108 C 40 100 20 84 20 60 L 20 28 Z"
                fill="none" stroke="url(#gradShield)" stroke-width="3"/>
          <path d="M42 60 L 55 73 L 80 48" fill="none" stroke="url(#gradShield)"
                stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>

      <header class="wt-auth-v2__head wt-auth-v2__head--centered">
        <span class="wt-eyebrow">🔐 <?= e(t('auth.eyebrow_2fa')) ?></span>
        <h1 class="wt-auth-v2__title"><?= e(t('auth.2fa.title')) ?></h1>
        <p class="wt-auth-v2__lead"><?= e(t('auth.2fa.intro')) ?></p>
      </header>

      <div class="wt-alert wt-alert--error is-hidden" data-auth-error></div>

      <form class="wt-form wt-auth-v2__form"
            data-auth-form
            data-endpoint="<?= e(wt_url('/api/auth_verify_2fa.php')) ?>"
            novalidate>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="code"   data-otp-hidden value="">
        <input type="hidden" name="method" data-2fa-method value="<?= e($twofaCurrent) ?>">

        <?php /* Saisie TOTP : 6 cases numériques, format imposé par le
                 standard des applications d'authentification. */ ?>
        <div class="wt-otp wt-auth-v2__otp" data-otp-root
             <?= $twofaCurrent === 'totp' ? '' : 'hidden' ?>>
          <?php for ($i = 0; $i < 6; $i++): ?>
            <input class="wt-otp__cell"
                   type="text"
                   inputmode="numeric"
                   pattern="[0-9]"
                   maxlength="1"
                   autocomplete="one-time-code"
                   aria-label="<?= e(sprintf((string) t('auth.2fa.digit_aria'), $i + 1)) ?>"
                   <?= $i === 0 && $twofaCurrent === 'totp' ? 'autofocus' : '' ?>>
          <?php endfor; ?>
        </div>

        <?php /* Saisie libre : e-mail (7 alphanumériques), SMS (8 chiffres)
                 et codes de secours (10 caractères) n'ont pas le même
                 format — un champ unique évite d'imposer une grille qui ne
                 correspondrait à aucun d'eux. */ ?>
        <label class="wt-field" data-2fa-textfield <?= $twofaCurrent === 'totp' ? 'hidden' : '' ?>>
          <span class="wt-field__label" data-2fa-textlabel><?= e(t('auth.2fa.enter_code')) ?></span>
          <input class="wt-input wt-input--code" type="text"
                 data-2fa-textinput
                 inputmode="text" autocomplete="one-time-code"
                 spellcheck="false" autocapitalize="characters">
        </label>

        <p class="wt-auth-v2__sent is-hidden" data-2fa-sent></p>

        <?php if (in_array($twofaCurrent, ['email', 'sms'], true)): ?>
          <button type="button" class="wt-btn wt-btn--ghost wt-btn--block wt-mt-2"
                  data-2fa-send="<?= e($twofaCurrent) ?>">
            📨 <?= e(t('auth.2fa.send_code')) ?>
          </button>
        <?php endif; ?>

        <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
          <span class="wt-btn__label">→ <?= e(t('auth.2fa.submit')) ?></span>
          <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
        </button>
      </form>

      <?php
        // Autres méthodes proposables : celles du compte + les codes de secours
        $others = array_values(array_diff($twofaMethods, [$twofaCurrent]));
        if ($backupOn && $twofaCurrent !== 'backup') { $others[] = 'backup'; }
      ?>
      <?php if ($others): ?>
        <div class="wt-2fa-switch">
          <span class="wt-2fa-switch__label"><?= e(t('auth.2fa.use_another')) ?></span>
          <?php foreach ($others as $m): ?>
            <button type="button" class="wt-2fa-switch__btn" data-2fa-switch="<?= e($m) ?>">
              <?= e(t('auth.2fa.method_' . $m)) ?>
              <?php if ($m === 'backup'): ?>
                <small><?= e(t('auth.2fa.backup_hint_login')) ?></small>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <p class="wt-auth-v2__alt">
        <a href="<?= e(wt_url('/auth/login.php')) ?>">← <?= e(t('common.back')) ?></a>
      </p>
    </section>

    <!-- Panneau "Pourquoi 2FA ?" -->
    <aside class="wt-auth-v2__side wt-auth-v2__side--2fa">
      <div class="wt-auth-v2__side-bg" aria-hidden="true"></div>

      <header class="wt-auth-v2__side-head">
        <span class="wt-auth-v2__side-eyebrow">🛡️ <?= e(t('auth.2fa.side_eyebrow')) ?></span>
        <h2 class="wt-auth-v2__side-title"><?= e(t('auth.2fa.side_title')) ?></h2>
      </header>

      <ul class="wt-auth-v2__tips">
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">📱</span>
          <span><?= e(t('auth.2fa.tip1')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">⏱</span>
          <span><?= e(t('auth.2fa.tip2')) ?></span>
        </li>
        <li>
          <span class="wt-auth-v2__tip-icon" aria-hidden="true">🔒</span>
          <span><?= e(t('auth.2fa.tip3')) ?></span>
        </li>
      </ul>

      <p class="wt-auth-v2__side-foot">
        ℹ️ <?= e(t('auth.2fa.side_foot')) ?>
      </p>
    </aside>

  </div>
</main>

<?php /* Libellés passés au script : ils doivent suivre la langue active,
         pas être figés dans le JavaScript. */ ?>
<script>
  window.WT_2FA_LABELS = <?= json_encode([
      'totp'   => t('auth.2fa.label_totp'),
      'email'  => t('auth.2fa.label_email'),
      'sms'    => t('auth.2fa.label_sms'),
      'backup' => t('auth.2fa.label_backup'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="<?= e(wt_url('/media/wintaskly/js/wt-2fa-login.js')) ?>?v=<?= e(WT_VERSION) ?>" defer></script>

<?php include __DIR__ . '/../footer.php'; ?>
