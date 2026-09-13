/**
 * Wintaskly — Boite a cadeaux.
 *
 * Ouvrir une boite : un onglet publicitaire s'ouvre, un chrono tourne
 * PENDANT que cette page est en arriere-plan, et le lot se revele au
 * retour. Meme principe qu'Instant Gagnant : c'est la seule facon de
 * mesurer une attention reelle sans rien demander a la page
 * publicitaire, qui est sur un autre domaine.
 */
(function () {
  'use strict';

  var racine = document.querySelector('[data-gift]');
  if (!racine) { return; }

  var mb   = document.querySelector('meta[name="wt-base"]');
  var base = (mb ? mb.getAttribute('content') : '').replace(/\/$/, '');
  var mc   = document.querySelector('meta[name="csrf-token"]');
  var csrf = mc ? mc.getAttribute('content') : '';

  var overlay = racine.querySelector('[data-gift-overlay]');
  var titre   = racine.querySelector('[data-gift-title]');
  var msg     = racine.querySelector('[data-gift-msg]');
  var chrono  = racine.querySelector('[data-gift-timer]');
  var actions = racine.querySelector('[data-gift-actions]');
  var fermer  = racine.querySelector('[data-gift-close]');
  var soldeEl = racine.querySelector('[data-gift-tickets]');

  if (!overlay) { return; }

  var lib = {
    open:  racine.getAttribute('data-label-open')  || 'Ouverture…',
    wait:  racine.getAttribute('data-label-wait')  || 'Patientez dans l’onglet ouvert.',
    back:  racine.getAttribute('data-label-back')  || 'Vous êtes revenu trop tôt.',
    boost: racine.getAttribute('data-label-boost') || 'Utiliser un ticket',
    keep:  racine.getAttribute('data-label-keep')  || 'Garder mon gain'
  };

  function poster(d) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    Object.keys(d).forEach(function (k) { fd.append(k, d[k]); });
    return fetch(base + '/api/gift_action.php', {
      method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function ouvrirOverlay() { overlay.hidden = false; document.body.style.overflow = 'hidden'; }
  function fermerOverlay() {
    overlay.hidden = true;
    document.body.style.overflow = '';
    arreter();
    /* On recharge pour que la grille reflete l'etat reel : d'autres
       joueurs ont pu ouvrir des boites pendant ce temps. */
    if (rechargerEnSortant) { window.location.reload(); }
  }

  var rechargerEnSortant = false;
  if (fermer) { fermer.addEventListener('click', fermerOverlay); }
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) { fermerOverlay(); }
  });

  function dire(t, m) {
    if (titre) { titre.textContent = t || ''; }
    if (msg)   { msg.textContent = m || ''; }
    if (actions) { actions.textContent = ''; }
  }

  /* ---------- Chrono en arriere-plan ---------- */
  var jeton = '', restant = 0, tic = null, boiteId = 0;

  function arreter() { if (tic) { clearInterval(tic); tic = null; } }

  function afficherChrono() {
    if (!chrono) { return; }
    chrono.hidden = false;
    chrono.textContent = restant + ' s';
  }

  function surVisibilite() {
    if (!jeton) { return; }
    if (document.hidden) {
      if (tic) { return; }
      tic = setInterval(function () {
        restant--;
        afficherChrono();
        if (restant <= 0) { arreter(); reveler(); }
      }, 1000);
    } else if (restant > 0) {
      /* Revenu trop tot : on fige et on propose de reprendre plutot
         que d'annuler. Le parcours precedent sera perime cote serveur
         des la prochaine ouverture. */
      arreter();
      dire('', lib.back);
      if (chrono) { chrono.hidden = false; chrono.textContent = restant + ' s'; }
      bouton(lib.boost && '', null);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'wt-btn wt-btn--primary';
      b.textContent = racine.getAttribute('data-label-retry') || 'Recommencer';
      b.addEventListener('click', function () { jeton = ''; demarrer(boiteId); });
      if (actions) { actions.appendChild(b); }
    }
  }
  document.addEventListener('visibilitychange', surVisibilite);

  function bouton(txt, onClick, cls) {
    if (!actions) { return null; }
    if (!txt) { return null; }
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'wt-btn ' + (cls || 'wt-btn--primary');
    b.textContent = txt;
    if (onClick) { b.addEventListener('click', onClick); }
    actions.appendChild(b);
    return b;
  }

  function demarrer(id) {
    boiteId = id;
    ouvrirOverlay();
    dire('', lib.open);
    if (chrono) { chrono.hidden = true; }

    poster({ action: 'open', box_id: id }).then(function (r) {
      if (!r || !r.ok) {
        dire('', (r && r.message) || 'Erreur');
        rechargerEnSortant = true;
        bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
        return;
      }
      jeton   = r.token;
      restant = r.seconds;
      afficherChrono();
      dire('', lib.wait);
      var onglet = window.open(r.url, '_blank', 'noopener');
      if (!onglet) {
        arreter();
        jeton = '';
        dire('', racine.getAttribute('data-label-popup')
             || 'Autorisez les fenêtres pour ce site, puis réessayez.');
        bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
      }
    }).catch(function () {
      dire('', 'Erreur réseau');
    });
  }

  function reveler() {
    if (chrono) { chrono.hidden = true; }
    poster({ action: 'reveal', token: jeton }).then(function (r) {
      jeton = '';
      rechargerEnSortant = true;
      if (!r || !r.ok) {
        dire('', (r && r.message) || 'Erreur');
        bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
        return;
      }

      var ico = r.kind === 'jackpot' ? '💎' : (r.kind === 'big' ? '🌟' : (r.kind === 'xp' ? '⭐' : '🪙'));
      dire(ico, r.message);
      if (soldeEl && typeof r.balance === 'number') { soldeEl.textContent = r.balance; }

      /* Le multiplicateur est propose APRES le versement du lot de
         base : si le joueur ferme maintenant, il garde son gain. */
      if (r.offer > 0) {
        var p = document.createElement('p');
        p.className = 'wt-gift-overlay__offer';
        p.textContent = (racine.getAttribute('data-label-offer') || 'Bonus proposé : +')
                      + r.offer + ' %';
        if (actions) { actions.appendChild(p); }

        bouton(lib.boost + ' (+' + r.offer + ' %)', function () {
          poster({ action: 'boost', box_id: boiteId }).then(function (b) {
            if (!b || !b.ok) {
              dire(ico, (b && b.message) || 'Erreur');
            } else {
              dire('🎉', b.message);
              if (soldeEl && typeof b.balance === 'number') { soldeEl.textContent = b.balance; }
            }
            bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
          });
        });
        bouton(lib.keep, fermerOverlay, 'wt-btn--ghost');
      } else {
        bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
      }
    }).catch(function () {
      dire('', 'Erreur réseau');
      bouton(racine.getAttribute('data-label-close') || 'Fermer', fermerOverlay, 'wt-btn--ghost');
    });
  }

  racine.querySelectorAll('[data-gift-box]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (b.disabled) { return; }
      demarrer(parseInt(b.getAttribute('data-gift-box'), 10));
    });
  });
})();
