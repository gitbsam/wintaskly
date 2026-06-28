/**
 * Wintaskly — Configuration 2FA (TOTP) côté client.
 * --------------------------------------------------------------------
 *   - Génère le QR code depuis l'URI otpauth (via qrcode.min.js).
 *   - Soumet l'activation (secret + code) à /api/auth_2fa_setup.php.
 *   - Soumet la désactivation.
 *
 * Le secret est généré côté serveur et n'est enregistré qu'après
 * validation d'un code — voir api/auth_2fa_setup.php.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/auth_2fa_setup.php';

  // Construit l'URL absolue en respectant un éventuel sous-dossier
  function apiUrl() {
    var base = window.location.pathname.replace(/\/dashboard\/.*$/, '');
    return base + ENDPOINT;
  }

  function showMsg(el, text, ok) {
    if (!el) return;
    el.hidden = false;
    el.textContent = text;
    el.classList.toggle('wt-form__msg--error', !ok);
    el.classList.toggle('wt-form__msg--ok', ok);
  }

  /* ---- 1. Génération du QR code ---- */
  var qrBox = document.querySelector('[data-2fa-qr]');
  if (qrBox && window.QRCode) {
    var uri = qrBox.getAttribute('data-uri');
    if (uri) {
      qrBox.innerHTML = '';
      try {
        new QRCode(qrBox, {
          text: uri,
          width: 200,
          height: 200,
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {
        qrBox.innerHTML = '<p class="wt-muted">QR indisponible — utilise la clé manuelle ci-dessous.</p>';
      }
    }
  }

  /* ---- 2. Activation ---- */
  var enableForm = document.querySelector('[data-2fa-enable-form]');
  if (enableForm) {
    enableForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = enableForm.querySelector('[data-2fa-msg]');
      var btn = enableForm.querySelector('button[type="submit"]');
      var fd = new FormData(enableForm);
      fd.append('action', 'enable');

      if (btn) { btn.disabled = true; }
      fetch(apiUrl(), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            showMsg(msg, data.message || 'OK', true);
            if (data.redirect) {
              setTimeout(function () { window.location.href = data.redirect; }, 800);
            }
          } else {
            showMsg(msg, data.error || 'Erreur', false);
            if (btn) { btn.disabled = false; }
          }
        })
        .catch(function () {
          showMsg(msg, 'Erreur réseau', false);
          if (btn) { btn.disabled = false; }
        });
    });
  }

  /* ---- 3. Désactivation ---- */
  var disableBtn = document.querySelector('[data-2fa-disable]');
  if (disableBtn) {
    disableBtn.addEventListener('click', function () {
      var msg = document.querySelector('[data-2fa-msg]');
      var fd = new FormData();
      fd.append('action', 'disable');
      fd.append('_csrf', disableBtn.getAttribute('data-csrf') || '');

      disableBtn.disabled = true;
      fetch(apiUrl(), { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            showMsg(msg, data.message || 'OK', true);
            if (data.redirect) {
              setTimeout(function () { window.location.href = data.redirect; }, 800);
            }
          } else {
            showMsg(msg, data.error || 'Erreur', false);
            disableBtn.disabled = false;
          }
        })
        .catch(function () {
          showMsg(msg, 'Erreur réseau', false);
          disableBtn.disabled = false;
        });
    });
  }
})();
