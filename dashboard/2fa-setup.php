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

/* ---------------------------------------------------------------------
 * Traitement des formulaires (avant tout rendu, pour pouvoir rediriger)
 * ------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['_csrf'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    $db = db();

    if ($action === 'save_methods') {
        $wanted = (array) ($_POST['methods'] ?? []);
        $admin  = wt_2fa_admin_methods();
        /* On n'active que ce que l'admin autorise ET dont le canal est
           opérationnel : une case cochée ne suffit pas à contourner le
           garde-fou côté plateforme. */
        $email = in_array('email', $wanted, true) && in_array('email', $admin, true) ? 1 : 0;
        $sms   = in_array('sms',   $wanted, true) && in_array('sms',   $admin, true) ? 1 : 0;

        // Le SMS exige un numéro vérifié : sans lui, on refuse silencieusement
        if ($sms === 1 && empty($u['twofa_phone_verified_at'])) {
            $sms = 0;
        }

        $pref = (string) ($_POST['preferred'] ?? '');
        if (!in_array($pref, ['totp', 'email', 'sms'], true)) { $pref = ''; }

        $stmt = $db->prepare(
            "UPDATE users SET twofa_email_enabled = ?, twofa_sms_enabled = ?,
                    twofa_preferred = NULLIF(?, '') WHERE id = ?"
        );
        $stmt->bind_param('iisi', $email, $sms, $pref, $uid);
        $stmt->execute();
        $stmt->close();

        /* Première activation d'une méthode 2FA sans code de secours :
           on en génère immédiatement. Laisser un compte protégé mais sans
           recours, c'est fabriquer un futur ticket « je ne peux plus me
           connecter ». */
        if (($email || $sms) && (string) cfg('2fa.backup_enabled', '1') === '1'
            && wt_2fa_backup_remaining($uid) === 0) {
            $_SESSION['fresh_backup_codes'] = wt_2fa_generate_backup_codes($uid);
        }
        header('Location: ' . wt_url('/dashboard/2fa-setup.php'));
        exit;
    }

    if ($action === 'gen_backup' && (string) cfg('2fa.backup_enabled', '1') === '1') {
        $_SESSION['fresh_backup_codes'] = wt_2fa_generate_backup_codes($uid);
        header('Location: ' . wt_url('/dashboard/2fa-setup.php'));
        exit;
    }
}

// Rechargé après un éventuel enregistrement
$u = current_user();

$totpEnabled = (int) ($u['totp_enabled'] ?? 0) === 1;

/* Méthodes disponibles, codes de secours et risque d'enfermement.
   Le tout est calculé une fois ici pour éviter de requêter dans le rendu. */
$adminMethods   = wt_2fa_admin_methods();
$activeMethods  = wt_2fa_user_methods($u);
$backupEnabled  = (string) cfg('2fa.backup_enabled', '1') === '1';
$backupLeft     = $backupEnabled ? wt_2fa_backup_remaining((int) $u['id']) : 0;
$lockRisk       = wt_2fa_lockout_risk($u);
/* Codes fraîchement générés : affichés une seule fois, jamais reconsultables. */
$freshCodes     = $_SESSION['fresh_backup_codes'] ?? null;
unset($_SESSION['fresh_backup_codes']);
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
                 aria-label="<?= e(t('a11y.field_2fa_code')) ?>"
                 pattern="\d{6}" maxlength="6" placeholder="000000" required
                 style="text-align:center;font-size:1.4rem;letter-spacing:6px;max-width:200px">
          <div style="margin-top:1rem">
            <button class="wt-btn wt-btn--primary" type="submit"><?= e(t('tfa_setup.activate_btn')) ?></button>
          </div>
          <p class="wt-form__msg" data-2fa-msg hidden></p>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($lockRisk['risk']): ?>
      <?php /* Avertissement AVANT que l'utilisateur ne se retrouve enfermé
               dehors : c'est le moment utile, pas après. */ ?>
      <div class="wt-alert wt-alert--warning wt-mt-3" role="alert">
        ⚠️ <?= e(t('auth.2fa.risk_' . ($lockRisk['reason'] === 'no_backup_codes' ? 'no_backup' : 'single'))) ?>
      </div>
    <?php endif; ?>

    <?php if ($freshCodes): ?>
      <section class="wt-card wt-card--padded wt-mt-3" data-reveal>
        <h2 class="wt-h3">🗝️ <?= e(t('auth.2fa.backup_title')) ?></h2>
        <p class="wt-muted"><?= e(t('auth.2fa.backup_lead')) ?></p>
        <ul class="wt-backup-codes">
          <?php foreach ($freshCodes as $c): ?>
            <li><code><?= e($c) ?></code></li>
          <?php endforeach; ?>
        </ul>
        <button type="button" class="wt-btn wt-btn--ghost" data-copy-backup>
          📋 <?= e(t('dash.copy')) ?>
        </button>
      </section>
    <?php endif; ?>

    <?php if (count($adminMethods) > 1 || $backupEnabled): ?>
      <section class="wt-card wt-card--padded wt-mt-3" data-reveal>
        <h2 class="wt-h3"><?= e(t('auth.2fa.methods_title')) ?></h2>
        <p class="wt-muted"><?= e(t('auth.2fa.methods_lead')) ?></p>

        <form method="post" action="<?= e(wt_url('/dashboard/2fa-setup.php')) ?>" class="wt-2fa-methods">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_methods">

          <?php foreach ($adminMethods as $m):
            // Le TOTP se gère par le QR code ci-dessus, pas par une case
            if ($m === 'totp') { continue; }
            $on = in_array($m, $activeMethods, true);
          ?>
            <label class="wt-2fa-method">
              <input type="checkbox" name="methods[]" value="<?= e($m) ?>" <?= $on ? 'checked' : '' ?>>
              <span>
                <strong><?= e(t('auth.2fa.method_' . $m)) ?></strong>
                <?php if ($m === 'sms'): ?>
                  <small><?= e(t('auth.2fa.sms_needs_phone')) ?></small>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>

          <?php if (count($activeMethods) > 1): ?>
            <label class="wt-field wt-mt-2">
              <span class="wt-field__label"><?= e(t('auth.2fa.preferred_label')) ?></span>
              <select class="wt-input" name="preferred">
                <?php foreach ($activeMethods as $m): ?>
                  <option value="<?= e($m) ?>" <?= ($u['twofa_preferred'] ?? '') === $m ? 'selected' : '' ?>>
                    <?= e(t('auth.2fa.method_' . $m)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          <?php endif; ?>

          <button type="submit" class="wt-btn wt-btn--primary wt-mt-2"><?= e(t('common.save')) ?></button>
        </form>

        <?php if ($backupEnabled): ?>
          <hr class="wt-sep">
          <p><?= e(sprintf((string) t('auth.2fa.backup_remaining'), $backupLeft)) ?></p>
          <form method="post" action="<?= e(wt_url('/dashboard/2fa-setup.php')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="gen_backup">
            <button type="submit" class="wt-btn wt-btn--ghost">
              🗝️ <?= e(t('auth.2fa.backup_regenerate')) ?>
            </button>
          </form>
          <p class="wt-muted" style="font-size:.82rem;margin-top:.5rem">
            <?= e(t('auth.2fa.backup_regen_notice')) ?>
          </p>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </section>
</div>

<!-- Lib QR code légère (génération côté client : le secret ne transite par aucun tiers) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= e(wt_url('/media/wintaskly/js/wt-2fa-setup.js')) ?>?v=<?= e(WT_VERSION) ?>"></script>

<?php include __DIR__ . '/../footer.php'; ?>
