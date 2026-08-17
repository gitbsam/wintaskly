/* =====================================================================
 *  Wintaskly — Bascule entre méthodes 2FA pendant la connexion
 * =====================================================================
 *  Trois responsabilités :
 *    1. changer de méthode sans recharger la page ;
 *    2. adapter la saisie au format attendu (6 cases pour le TOTP, champ
 *       libre pour les autres) ;
 *    3. demander l'envoi d'un code e-mail ou SMS.
 *
 *  Le champ caché `method` accompagne la soumission : c'est lui qui indique
 *  au serveur quel vérificateur appliquer. Le serveur revalide de son côté —
 *  ce script ne fait qu'ajuster l'interface, il n'accorde aucun droit.
 * ===================================================================== */
(function () {
  'use strict';

  var form = document.querySelector('[data-auth-form]');
  if (!form) return;

  var hiddenMethod = form.querySelector('[data-2fa-method]');
  var hiddenCode   = form.querySelector('[data-otp-hidden]');
  var otpRoot      = form.querySelector('[data-otp-root]');
  var textField    = form.querySelector('[data-2fa-textfield]');
  var textInput    = form.querySelector('[data-2fa-textinput]');
  var textLabel    = form.querySelector('[data-2fa-textlabel]');
  var sentMsg      = form.querySelector('[data-2fa-sent]');
  var csrf         = form.querySelector('input[name="_csrf"]');

  /* Libellés et contraintes de saisie par méthode. Les longueurs
     correspondent aux formats générés côté serveur : 8 chiffres en SMS,
     7 caractères en e-mail, 10 (plus un tiret de lisibilité) en secours. */
  var L = window.WT_2FA_LABELS || {};
  var PROFILES = {
    totp:   { label: L.totp   || '', mode: 'numeric', max: 6  },
    email:  { label: L.email  || '', mode: 'text',    max: 7  },
    sms:    { label: L.sms    || '', mode: 'numeric', max: 8  },
    backup: { label: L.backup || '', mode: 'text',    max: 12 }
  };

  function setMethod(method) {
    var p = PROFILES[method] || PROFILES.totp;
    if (hiddenMethod) hiddenMethod.value = method;

    var isTotp = (method === 'totp');
    if (otpRoot)   otpRoot.hidden   = !isTotp;
    if (textField) textField.hidden = isTotp;

    if (!isTotp && textInput) {
      textInput.value = '';
      textInput.setAttribute('inputmode', p.mode);
      textInput.setAttribute('maxlength', String(p.max));
      if (textLabel) textLabel.textContent = p.label;
      textInput.focus();
    } else if (isTotp && otpRoot) {
      var first = otpRoot.querySelector('.wt-otp__cell');
      if (first) { first.focus(); }
    }

    // Le bouton d'envoi n'a de sens que pour les canaux qui envoient
    var sendBtn = form.querySelector('[data-2fa-send]');
    if (sendBtn) {
      sendBtn.hidden = (method !== 'email' && method !== 'sms');
      sendBtn.setAttribute('data-2fa-send', method);
    }
    if (sentMsg) { sentMsg.classList.add('is-hidden'); }

    // Met à jour les boutons de bascule : la méthode courante disparaît
    document.querySelectorAll('[data-2fa-switch]').forEach(function (b) {
      b.hidden = (b.getAttribute('data-2fa-switch') === method);
    });
  }

  /* Le champ libre alimente le même champ caché que les cases TOTP :
     le serveur reçoit toujours `code`, quelle que soit la saisie. */
  if (textInput && hiddenCode) {
    textInput.addEventListener('input', function () {
      hiddenCode.value = this.value.trim();
    });
  }

  document.querySelectorAll('[data-2fa-switch]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      setMethod(this.getAttribute('data-2fa-switch'));
    });
  });

  // Envoi d'un code e-mail / SMS
  var sendBtn = form.querySelector('[data-2fa-send]');
  if (sendBtn) {
    sendBtn.addEventListener('click', function () {
      var method = this.getAttribute('data-2fa-send');
      var btn = this;
      btn.disabled = true;

      var body = new URLSearchParams();
      body.append('method', method);
      if (csrf) body.append('_csrf', csrf.value);

      fetch(form.getAttribute('data-endpoint').replace('auth_verify_2fa', 'auth_2fa_send'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (sentMsg) {
            sentMsg.textContent = d.ok
              ? (d.message || '') + (d.target ? ' (' + d.target + ')' : '')
              : (d.error || '');
            sentMsg.classList.remove('is-hidden');
            sentMsg.classList.toggle('is-error', !d.ok);
          }
          // Anti-double-clic : le serveur impose déjà 60 s, on aligne l'UI
          setTimeout(function () { btn.disabled = false; }, 60000);
        })
        .catch(function () { btn.disabled = false; });
    });
  }

  // État initial cohérent avec ce que le serveur a choisi
  if (hiddenMethod) { setMethod(hiddenMethod.value || 'totp'); }
})();
