/**
 * Wintaskly — Bingo (page joueur)
 * --------------------------------------------------------------------
 * Gère les interactions de la page /tasks/bingo :
 *   - Validation manuelle d'un numéro (clic sur une case "drawable")
 *   - Activation / achat d'un carton
 *   - Réclamation d'un carton plein
 *
 * Toutes les actions passent par /api/bingo_action.php (POST + CSRF).
 * La logique métier (argent, concurrence) est côté serveur ; ce script
 * ne fait que déclencher et refléter le résultat.
 */
(function () {
  'use strict';

  var CFG = window.WT_BINGO;
  if (!CFG) return;

  /** Envoie une action au serveur. */
  function post(params) {
    var body = new URLSearchParams();
    body.set('_csrf', CFG.csrf);
    Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
    return fetch(CFG.apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  /** Petit toast non bloquant. */
  function toast(msg, kind) {
    var t = document.createElement('div');
    t.className = 'wt-bingo-toast wt-bingo-toast--' + (kind || 'info');
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-visible'); });
    setTimeout(function () {
      t.classList.remove('is-visible');
      setTimeout(function () { t.remove(); }, 300);
    }, 2600);
  }

  /** Désactive temporairement un bouton pendant l'action. */
  function busy(btn, on) {
    if (!btn) return;
    btn.disabled = on;
    btn.classList.toggle('is-loading', on);
  }

  // --- Délégation d'événements sur toute la page ---
  document.addEventListener('click', function (e) {

    // 1) Validation d'un numéro (case drawable)
    var cell = e.target.closest('.wt-bingo-cell.is-drawable');
    if (cell && !cell.disabled) {
      var card = cell.closest('[data-card-id]');
      var cardId = card ? card.getAttribute('data-card-id') : null;
      var number = cell.getAttribute('data-number');
      if (!cardId || !number) return;

      busy(cell, true);
      post({ action: 'mark', card_id: cardId, number: number })
        .then(function (res) {
          if (res.ok) {
            cell.classList.remove('is-drawable');
            cell.classList.add('is-marked');
            cell.disabled = true;
            // Met à jour le compteur du carton
            var counter = card.querySelector('.wt-bingo-card__count');
            if (counter) {
              var parts = counter.textContent.split('/');
              var n = parseInt(parts[0], 10) + 1;
              counter.textContent = n + '/25';
            }
            if (res.full) {
              // Carton complet → recharge pour afficher le bouton Réclamer
              toast(CFG.i18n.activated || '✓', 'success');
              setTimeout(function () { window.location.reload(); }, 800);
            }
          } else {
            toast(res.message || CFG.i18n.err, 'error');
            busy(cell, false);
          }
        })
        .catch(function () { toast(CFG.i18n.err, 'error'); busy(cell, false); });
      return;
    }

    // 2) Boutons d'action (activate / claim)
    var btn = e.target.closest('[data-action]');
    if (!btn) return;
    var action = btn.getAttribute('data-action');
    var bCardId = btn.getAttribute('data-card-id');
    if (!bCardId) return;

    if (action === 'activate') {
      busy(btn, true);
      post({ action: 'activate', card_id: bCardId })
        .then(function (res) {
          if (res.ok) {
            toast(res.free ? (CFG.i18n.activated) : (CFG.i18n.bought), 'success');
            if (res.reload) {
              setTimeout(function () { window.location.reload(); }, 700);
            }
          } else {
            toast(res.message || CFG.i18n.err, 'error');
            busy(btn, false);
          }
        })
        .catch(function () { toast(CFG.i18n.err, 'error'); busy(btn, false); });
      return;
    }

    if (action === 'claim') {
      busy(btn, true);
      post({ action: 'claim', card_id: bCardId })
        .then(function (res) {
          if (res.ok) {
            toast(CFG.i18n.claimed, 'success');
            if (res.reload) {
              setTimeout(function () { window.location.reload(); }, 900);
            }
          } else {
            toast(res.message || CFG.i18n.err, 'error');
            busy(btn, false);
          }
        })
        .catch(function () { toast(CFG.i18n.err, 'error'); busy(btn, false); });
      return;
    }
  });
})();
