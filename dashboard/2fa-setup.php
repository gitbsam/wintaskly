<?php
/**
 * Wintaskly — /dashboard/2fa-setup.php
 *
 * Configuration de la 2FA par application (TOTP — Google Authenticator,
 * Authy, etc.). Trois cas :
 *   1. ?disable=1 + TOTP déjà actif → écran de confirmation de désactivation
 *   2. TOTP déjà actif (sans disable) → info + lien désactiver
 *   3. TOTP inactif → génération d'un secret + QR code + champ de validation
 *
 * Le secret est généré côté serveur, affiché une seule fois (QR + clé
 * manuelle), et n'est ENREGISTRÉ qu'après validation d'un code à 6 chiffres
 * (preuve que l'app est bien configurée). Cf. api/auth_2fa_setup.php.
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';
require_auth();

$pageTitle = t('tfa_setup.title');
$u   = current_user();
$uid = (int) $u['id'];

$totpEnabled = (int) ($u['totp_enabled'] ?? 0) === 1;
$wantDisable = !empty($_GET['disable']);

// Identifiant affiché dans l'app d'authentification (email de préférence)
$accountLabel = (string) ($u['email'] ?? $u['username'] ?? 'user');

// Génère un secret en attente (seulement si on est en phase d'activation)
$pendingSecret = '';
$otpauthUri    = '';
if (!$totpEnabled) {
    $pendingSecret = auth_totp_generate_secret();
    $otpauthUri    = auth_totp_provisioning_uri($pendingSecret, $accountLabel, 'Wintaskly');
}

$dashActive = 'settings';
include __DIR__ . '/../header.php';
?>

<div class="wt-dash wt-dash-v2">
  <?php include __DIR__ . '/_nav.php'; ?>

  <section class="wt-dash__content wt-dash-v2__content">
    <header class="wt-dash-v2__head" data-reveal>
      <a class="wt-dash-v2__back" href="<?= e(wt_url('/dashboard/settings.php')) ?>">← <?= e(t('common.back')) ?></a>
      <h1 class="wt-dash-v2__title">🔐 <?= e(t('tfa_setup.title')) ?></h1>
    </header>

    <?php if ($totpEnabled && $wantDisable): ?>
      <!-- CAS 1 : confirmation de désactivation -->
      <section class="wt-card wt-card--padded" data-reveal>
        <h2 style="margin-top:0"><?= e(t('tfa_setup.disable_title')) ?></h2>
        <p class="wt-muted"><?= e(t('tfa_setup.disable_warning')) ?></p>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
          <button class="wt-btn wt-btn--danger" data-2fa-disable
                  data-csrf="<?= e(csrf_token()) ?>"><?= e(t('tfa_setup.disable_confirm')) ?></button>
          <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/dashboard/settings.php')) ?>"><?= e(t('common.cancel')) ?></a>
        </div>
        <p class="wt-form__msg" data-2fa-msg hidden></p>
      </section>

    <?php elseif ($totpEnabled): ?>
      <!-- CAS 2 : déjà activé -->
      <section class="wt-card wt-card--padded" data-reveal>
        <div class="wt-settings__row-status wt-settings__row-status--on" style="display:inline-flex;margin-bottom:1rem">
          ✅ <?= e(t('common.enabled')) ?>
        </div>
        <p><?= e(t('tfa_setup.already_on')) ?></p>
        <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/dashboard/2fa-setup.php?disable=1')) ?>">
          <?= e(t('common.disable')) ?>
        </a>
      </section>

    <?php else: ?>
      <!-- CAS 3 : activation (QR code + validation) -->
      <section class="wt-card wt-card--padded" data-reveal>
        <h2 style="margin-top:0"><?= e(t('tfa_setup.step1_title')) ?></h2>
        <p class="wt-muted"><?= e(t('tfa_setup.step1_desc')) ?></p>

        <div class="wt-2fa-qr" data-2fa-qr data-uri="<?= e($otpauthUri) ?>" style="margin:1.25rem 0;display:flex;justify-content:center">
          <!-- Le QR code est rendu ici par qrcode.min.js -->
          <div class="wt-2fa-qr__loading"><?= e(t('tfa_setup.qr_loading')) ?></div>
        </div>

        <p class="wt-muted" style="font-size:.85rem"><?= e(t('tfa_setup.manual_key')) ?></p>
        <code class="wt-2fa-secret" style="display:block;text-align:center;font-size:1.1rem;letter-spacing:2px;padding:.75rem;background:var(--wt-bg-soft,#1a2235);border-radius:8px;word-break:break-all"><?= e($pendingSecret) ?></code>

        <hr style="margin:1.5rem 0;border:none;border-top:1px solid var(--wt-border,#2a3346)">

        <h2><?= e(t('tfa_setup.step2_title')) ?></h2>
        <p class="wt-muted"><?= e(t('tfa_setup.step2_desc')) ?></p>

        <form data-2fa-enable-form style="margin-top:1rem">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="secret" value="<?= e($pendingSecret) ?>">
          <input class="wt-input wt-2fa-code-input"
                 type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                 pattern="\d{6}" maxlength="6" placeholder="000000" required
                 style="text-align:center;font-size:1.4rem;letter-spacing:6px;max-width:200px">
          <div style="margin-top:1rem">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('tfa_setup.activate_btn')) ?></button>
          </div>
          <p class="wt-form__msg" data-2fa-msg hidden></p>
        </form>
      </section>
    <?php endif; ?>
  </section>
</div>

<!-- Lib QR code légère (génération côté client : le secret ne transite par aucun tiers) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= e(wt_url('/media/wintaskly/js/wt-2fa-setup.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>

<?php include __DIR__ . '/../footer.php'; ?>
