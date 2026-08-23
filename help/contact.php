<?php
/**
 * Wintaskly — /help/contact.php  (V8 modernisé)
 *
 * Formulaire de contact :
 *   - Utilisateurs connectés : email & username pré-remplis. Le ticket
 *     est rattaché au compte et accessible via /dashboard/messages.
 *   - Visiteurs : génération d'un token cryptographique unique, lien
 *     affiché en notification immédiate après l'envoi.
 *
 * Layout V8 : 2 colonnes desktop (formulaire à gauche, sidebar
 * "Avant de nous contacter" à droite avec raccourcis FAQ).
 *
 * Anti-abus (inchangé) :
 *   - Honeypot (champ caché "website")
 *   - Délai minimum 3 secondes entre affichage et soumission
 *   - Captcha math léger (1+2=?) côté serveur pour les invités
 *   - Rate-limit par IP côté API (5 submissions/15min/IP)
 */
declare(strict_types=1);
require __DIR__ . '/../includes/init.php';

$pageTitle = t('contact.title');
$pageDescription = t('seo.desc.contact');

/* Fil d'Ariane structuré (Schema.org) */
wt_schema_add(wt_schema_breadcrumb([
    ['name' => (string) t('site_name'), 'url' => wt_url('/')],
    ['name' => (string) t('contact.title'),    'url' => wt_url('/help/contact.php')],
]));
$u  = current_user();

/* Captcha math pour les invités */
$captcha = null;
if (!$u) {
    if (empty($_SESSION['wt_contact_captcha'])) {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION['wt_contact_captcha'] = ['a' => $a, 'b' => $b, 'r' => $a + $b];
    }
    $captcha = $_SESSION['wt_contact_captcha'];
    $_SESSION['wt_contact_form_shown_at'] = time();
}

/* Top 4 FAQ pour la sidebar — guide l'utilisateur avant d'envoyer */
$lang = $GLOBALS['WT_LANG'] ?? [];
$topFaq = [];
foreach ($lang as $k => $v) {
    if (preg_match('/^faq\.q_(.+)$/', $k, $m)) {
        $topFaq[$m[1]] = $v;
        if (count($topFaq) >= 4) break;
    }
}

include __DIR__ . '/../header.php';
?>

<main class="wt-main wt-contact-v2">
  <div class="wt-contact-v2__wrap">

    <!-- ====== HEADER ====== -->
    <header class="wt-contact-v2__header" data-reveal>
      <span class="wt-eyebrow">✉️ <?= e(t('help.eyebrow')) ?></span>
      <h1 class="wt-contact-v2__title"><?= e(t('contact.title')) ?></h1>
      <p class="wt-contact-v2__lead"><?= e(t('contact.lead')) ?></p>
    </header>

    <!-- ====== LAYOUT 2-COL ====== -->
    <div class="wt-contact-v2__grid">

      <!-- ====== Colonne formulaire ====== -->
      <section class="wt-contact-v2__form-section" data-reveal>
        <div class="wt-alert wt-alert--success is-hidden" data-auth-success></div>
        <div class="wt-alert wt-alert--error   is-hidden" data-auth-error></div>

        <!-- Success guest (token de suivi) — caché par défaut -->
        <?php if (!$u): ?>
          <div class="wt-contact-v2__success is-hidden" data-guest-success>
            <div class="wt-contact-v2__success-icon" aria-hidden="true">✉️</div>
            <h2><?= e(t('contact.guest_success_title')) ?></h2>
            <p><?= e(t('contact.guest_success_lead')) ?></p>

            <div class="wt-contact-v2__success-link">
              <label class="wt-field__label"><?= e(t('contact.your_track_link')) ?></label>
              <div class="wt-contact-v2__copy">
                <input type="text" readonly data-track-input value=""
                       class="wt-input" aria-label="<?= e(t('contact.your_track_link')) ?>">
                <button type="button" class="wt-btn wt-btn--primary wt-btn--xs"
                        data-copy-target="[data-track-input]"
                        data-copy-label="<?= e(t('admin.cron.copied')) ?>">
                  📋 <?= e(t('common.copy')) ?>
                </button>
              </div>
              <p class="wt-contact-v2__security-notice">
                🔐 <?= e(t('contact.security_notice')) ?>
              </p>
            </div>

            <div class="wt-contact-v2__success-actions">
              <a class="wt-btn wt-btn--ghost" data-track-link href="#" target="_blank" rel="noopener">
                ↗ <?= e(t('contact.open_tracking')) ?>
              </a>
              <a class="wt-btn wt-btn--ghost" href="<?= e(wt_url('/auth/signup.php')) ?>">
                <?= e(t('common.create_account')) ?>
              </a>
            </div>
          </div>
        <?php endif; ?>

        <!-- Card formulaire principal -->
        <div class="wt-contact-v2__card" data-guest-form>
          <?php if ($u): ?>
            <div class="wt-contact-v2__logged-as">
              <div class="wt-avatar wt-avatar--sm"
                   data-hash-color="<?= e($u['username']) ?>"
                   aria-hidden="true"><?= wt_avatar_inner($u) ?></div>
              <div>
                <small><?= e(t('contact.logged_as')) ?></small>
                <strong><?= e($u['username']) ?></strong>
                <em><?= e($u['email']) ?></em>
              </div>
            </div>
          <?php endif; ?>

          <form class="wt-form wt-contact-v2__form"
                data-auth-form
                data-endpoint="<?= e(wt_url('/api/contact_submit.php')) ?>"
                data-keep-form
                data-is-guest="<?= $u ? '0' : '1' ?>"
                novalidate>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

            <?php if (!$u): ?>
              <div class="wt-contact-v2__row-2">
                <label class="wt-field">
                  <span class="wt-field__label"><?= e(t('contact.name')) ?></span>
                  <input class="wt-input" type="text" name="name" required maxlength="120"
                         placeholder="<?= e(t('contact.name_placeholder')) ?>">
                </label>
                <label class="wt-field">
                  <span class="wt-field__label"><?= e(t('auth.email')) ?></span>
                  <input class="wt-input" type="email" name="email" required maxlength="190"
                         placeholder="exemple@email.com">
                </label>
              </div>
            <?php endif; ?>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('contact.subject')) ?></span>
              <input class="wt-input" type="text" name="subject" required maxlength="180"
                     placeholder="<?= e(t('contact.subject_placeholder')) ?>">
            </label>

            <label class="wt-field">
              <span class="wt-field__label"><?= e(t('contact.message')) ?></span>
              <textarea class="wt-input wt-textarea" name="body" rows="6" required
                        maxlength="5000" data-contact-body
                        placeholder="<?= e(t('contact.message_placeholder')) ?>"></textarea>
              <small class="wt-contact-v2__counter">
                <span data-contact-counter>0</span> / 5000
              </small>
            </label>

            <?php if (!$u && $captcha): ?>
              <div class="wt-contact-v2__captcha">
                <label class="wt-field">
                  <span class="wt-field__label">
                    🤖 <?= e(t('contact.captcha_label')) ?>
                  </span>
                  <div class="wt-contact-v2__captcha-row">
                    <span class="wt-contact-v2__captcha-q">
                      <?= (int)$captcha['a'] ?> + <?= (int)$captcha['b'] ?> =
                    </span>
                    <input class="wt-input" type="number" name="captcha"
                           required min="0" max="99" inputmode="numeric"
                           autocomplete="off">
                  </div>
                </label>
              </div>
            <?php endif; ?>

            <!-- Honeypot (caché des humains via CSS) -->
            <div class="wt-honey" aria-hidden="true">
              <label>Web<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <button type="submit" class="wt-btn wt-btn--primary wt-btn--lg wt-btn--block" data-submit-btn>
              <span class="wt-btn__label">✈️ <?= e(t('contact.submit')) ?></span>
              <span class="wt-btn__spinner is-hidden" aria-hidden="true"></span>
            </button>

            <p class="wt-contact-v2__note">
              ℹ️ <?= e(t('contact.response_time')) ?>
            </p>
          </form>
        </div>
      </section>

      <!-- ====== Colonne sidebar : "Avant de nous contacter" ====== -->
      <aside class="wt-contact-v2__sidebar" data-reveal>
        <div class="wt-contact-v2__panel">
          <h2 class="wt-contact-v2__panel-title">
            💡 <?= e(t('contact.before_title')) ?>
          </h2>
          <p class="wt-contact-v2__panel-lead">
            <?= e(t('contact.before_lead')) ?>
          </p>

          <?php if ($topFaq): ?>
            <ul class="wt-contact-v2__faq-list">
              <?php foreach ($topFaq as $slug => $q): ?>
                <li>
                  <a href="<?= e(wt_url('/help/faq.php#q-' . urlencode($slug))) ?>">
                    <span><?= e($q) ?></span>
                    <span class="wt-contact-v2__faq-arrow" aria-hidden="true">→</span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <a class="wt-btn wt-btn--ghost wt-btn--block"
             href="<?= e(wt_url('/help/faq.php')) ?>">
            📚 <?= e(t('contact.see_full_faq')) ?>
          </a>
        </div>

        <!-- Info anti-abus / SLA -->
        <div class="wt-contact-v2__panel wt-contact-v2__panel--info">
          <h3><?= e(t('contact.sla_title')) ?></h3>
          <p><?= e(t('contact.sla_text')) ?></p>
        </div>

        <?php
          /* Coordonnées et horaires.
             L'adresse est lue depuis la configuration d'envoi plutôt
             qu'écrite en dur : elle reste ainsi cohérente avec celle qui
             apparaît dans vos e-mails, et une modification en admin se
             répercute ici automatiquement. Si aucune adresse n'est
             configurée, le bloc n'affiche pas de ligne vide. */
          $_cEmail = trim((string) cfg('email.from_address', ''));
        ?>
        <div class="wt-contact-v2__aside-card">
          <h3><?= e(t('contact.reach_title')) ?></h3>
          <ul class="wt-contact-v2__reach">
            <?php if ($_cEmail !== ''): ?>
              <li>
                <?= wt_icon('mail', ['size' => 15, 'class' => 'wt-ico--muted']) ?>
                <a href="mailto:<?= e($_cEmail) ?>"><?= e($_cEmail) ?></a>
              </li>
            <?php endif; ?>
            <li>
              <?= wt_icon('clock', ['size' => 15, 'class' => 'wt-ico--muted']) ?>
              <?= e(t('contact.hours')) ?>
            </li>
            <li>
              <?= wt_icon('globe', ['size' => 15, 'class' => 'wt-ico--muted']) ?>
              <?= e(t('contact.lang_note')) ?>
            </li>
          </ul>
          <p class="wt-contact-v2__reach-note"><?= e(t('contact.reach_note')) ?></p>
        </div>

        <div class="wt-contact-v2__aside-card">
          <h3><?= e(t('contact.links_title')) ?></h3>
          <ul class="wt-contact-v2__links">
            <li><a href="<?= e(wt_url('/help/faq.php')) ?>"><?= e(t('contact.link_faq')) ?></a></li>
            <li><a href="<?= e(wt_url('/help/')) ?>"><?= e(t('contact.link_help')) ?></a></li>
            <li><a href="<?= e(wt_url('/help/antifraud.php')) ?>"><?= e(t('contact.link_antifraud')) ?></a></li>
            <li><a href="<?= e(wt_url('/about/partners.php')) ?>"><?= e(t('contact.link_partners')) ?></a></li>
            <li><a href="<?= e(wt_url('/legal/privacy.php')) ?>"><?= e(t('contact.link_privacy')) ?></a></li>
          </ul>
        </div>
      </aside>

    </div>

    <!-- Contenu d'accompagnement : la page se limitait au formulaire
         (156 mots). Ces informations réduisent les allers-retours et
         donnent à la page la substance qu'un simple champ de saisie
         n'apporte pas. -->
    <section class="wt-contact-help" data-reveal>
      <h2 class="wt-contact-help__title"><?= e(t('contact.help_title')) ?></h2>

      <div class="wt-contact-help__cols">
        <div class="wt-contact-help__col">
          <h3><?= e(t('contact.help_what')) ?></h3>
          <ul>
            <li><?= e(t('contact.help_what_1')) ?></li>
            <li><?= e(t('contact.help_what_2')) ?></li>
            <li><?= e(t('contact.help_what_3')) ?></li>
          </ul>
        </div>
        <div class="wt-contact-help__col">
          <h3><?= e(t('contact.help_faster')) ?></h3>
          <ul>
            <li><?= e(t('contact.help_faster_1')) ?></li>
            <li><?= e(t('contact.help_faster_2')) ?></li>
            <li><?= e(t('contact.help_faster_3')) ?></li>
          </ul>
        </div>
      </div>

      <p class="wt-contact-help__note"><?= e(t('contact.help_security')) ?></p>
      <p class="wt-contact-help__note">
        <?= e(t('contact.help_faq_first')) ?>
        <a href="<?= e(wt_url('/help/faq.php')) ?>"><?= e(t('contact.help_faq_link')) ?></a>
      </p>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../footer.php'; ?>
