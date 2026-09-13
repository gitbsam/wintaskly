/**
 * Wintaskly — Instant Gagnant.
 *
 * Le chrono ne tourne QUE pendant que cette page est en arriere-plan.
 * C'est la seule facon de mesurer une attention reelle sans rien
 * demander a la page publicitaire : celle-ci est sur un autre domaine
 * et notre code ne peut rien y observer.
 *
 * Le decompte affiche n'est qu'un affichage. Le serveur recalcule le
 * temps ecoule a partir de l'horodatage d'ouverture : vider le chrono
 * depuis la console ne donne rien.
 */
(function () {
  'use strict';

  var cartes = document.querySelectorAll('[data-instant-game]');
  if (!cartes.length) { return; }

  var csrf = document.querySelector('meta[name="csrf-token"]');
  csrf = csrf ? csrf.getAttribute('content') : '';

  /* L'URL de base est portee par une balise meta, comme pour le reste
     du site : le site peut etre installe dans un sous-dossier. */
  var mb = document.querySelector('meta[name="wt-base"]');
  var base = (mb ? mb.getAttribute('content') : '').replace(/\/$/, '');

  function poster(donnees) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    Object.keys(donnees).forEach(function (k) { fd.append(k, donnees[k]); });
    return fetch(base + '/api/instant_action.php', {
      method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function deux(n) { return n < 10 ? '0' + n : String(n); }

  cartes.forEach(function (carte) {
    var gameId  = carte.getAttribute('data-instant-game');
    var btn     = carte.querySelector('[data-instant-verify]');
    var reset   = carte.querySelector('[data-instant-reset]');
    var chrono  = carte.querySelector('[data-instant-timer]');
    var msg     = carte.querySelector('[data-instant-msg]');
    var jouer   = carte.querySelector('[data-instant-play]');
    var mise    = carte.querySelector('[data-instant-stake]');

    function dire(texte, type) {
      if (!msg) { return; }
      msg.textContent = texte;
      msg.hidden = false;
      msg.className = 'wt-instant-card__msg' +
        (type ? ' wt-instant-card__msg--' + type : '');
    }

    /* ---------------- Voie publicitaire ---------------- */
    if (btn) {
      var jeton = '', requis = 0, restant = 0, tic = null, onglet = null;

      function arreter() {
        if (tic) { clearInterval(tic); tic = null; }
      }

      function afficher() {
        if (!chrono) { return; }
        chrono.hidden = false;
        chrono.textContent = deux(Math.floor(restant / 60)) + ':' + deux(restant % 60);
      }

      /* Etat « interrompu » : l'utilisateur est revenu trop tot. Le
         bouton passe au rouge et la reinitialisation apparait. */
      function interrompre() {
        arreter();
        btn.classList.add('is-stopped');
        btn.classList.remove('is-ready');
        btn.disabled = true;
        if (reset) { reset.hidden = false; }
        dire(carte.getAttribute('data-msg-stopped') ||
             'Vous êtes revenu trop tôt. Réinitialisez pour reprendre.', 'warn');
      }

      function pret() {
        arreter();
        btn.classList.remove('is-stopped');
        btn.classList.add('is-ready');
        btn.disabled = false;
        btn.textContent = carte.getAttribute('data-label-reveal') || 'Voir le résultat';
        if (reset) { reset.hidden = true; }
        if (chrono) { chrono.hidden = true; }
        dire(carte.getAttribute('data-msg-ready') || 'Temps accompli.', 'ok');
      }

      /* Le coeur du mecanisme : on ne decompte que lorsque la page est
         masquee, c'est-a-dire quand l'utilisateur regarde l'onglet
         publicitaire. Revenir ici met fin au decompte. */
      function surVisibilite() {
        if (!jeton) { return; }
        if (document.hidden) {
          if (tic) { return; }
          tic = setInterval(function () {
            restant--;
            afficher();
            if (restant <= 0) { pret(); }
          }, 1000);
        } else {
          if (restant > 0) { interrompre(); }
        }
      }

      document.addEventListener('visibilitychange', surVisibilite);

      function demarrer() {
        btn.disabled = true;
        poster({ action: 'start', game_id: gameId }).then(function (r) {
          if (!r || !r.ok) {
            btn.disabled = false;
            dire((r && r.message) || 'Erreur', 'err');
            return;
          }
          jeton   = r.token;
          requis  = r.seconds;
          restant = r.seconds;
          afficher();
          /* L'ouverture doit suivre le clic sans detour : un
             window.open() appele depuis une promesse est bloque par les
             navigateurs mobiles. On ouvre donc immediatement apres la
             reponse, dans le meme fil. */
          onglet = window.open(r.url, '_blank', 'noopener');
          if (!onglet) {
            dire(carte.getAttribute('data-msg-popup') ||
                 'Autorisez les fenêtres pour ce site, puis réessayez.', 'err');
            btn.disabled = false;
            jeton = '';
            return;
          }
          dire(carte.getAttribute('data-msg-wait') ||
               'Patientez dans l’onglet ouvert.', '');
        }).catch(function () {
          btn.disabled = false;
          dire('Erreur réseau', 'err');
        });
      }

      function reveler() {
        btn.disabled = true;
        poster({ action: 'finish', game_id: gameId, token: jeton }).then(function (r) {
          jeton = '';
          if (!r || !r.ok) {
            dire((r && r.message) || 'Erreur', 'err');
            btn.classList.remove('is-ready');
            btn.disabled = false;
            btn.textContent = carte.getAttribute('data-label-verify') || 'Vérifier';
            return;
          }
          dire(r.message, r.won ? 'ok' : 'warn');
          btn.classList.remove('is-ready');
          btn.textContent = carte.getAttribute('data-label-verify') || 'Vérifier';
          btn.disabled = false;
          if (window.WT && window.WT.toast) {
            window.WT.toast(r.message, r.won ? 'ok' : 'warn', 5000);
          }
        }).catch(function () {
          dire('Erreur réseau', 'err');
          btn.disabled = false;
        });
      }

      btn.addEventListener('click', function () {
        if (btn.classList.contains('is-ready')) { reveler(); }
        else { demarrer(); }
      });

      if (reset) {
        reset.addEventListener('click', function () {
          /* On repart d'un parcours neuf : le precedent sera perime
             cote serveur des l'ouverture du suivant. */
          jeton = ''; restant = 0;
          arreter();
          btn.classList.remove('is-stopped');
          btn.disabled = false;
          btn.textContent = carte.getAttribute('data-label-verify') || 'Vérifier';
          reset.hidden = true;
          if (chrono) { chrono.hidden = true; }
          if (msg) { msg.hidden = true; }
        });
      }
    }

    /* ---------------- Voie tickets ---------------- */
    if (jouer) {
      jouer.addEventListener('click', function () {
        jouer.disabled = true;
        poster({ action: 'play', game_id: gameId, stake: mise ? mise.value : 1 })
          .then(function (r) {
            jouer.disabled = false;
            if (!r || !r.ok) { dire((r && r.message) || 'Erreur', 'err'); return; }
            dire(r.message, r.wins > 0 ? 'ok' : 'warn');
            if (window.WT && window.WT.toast) {
              window.WT.toast(r.message, r.wins > 0 ? 'ok' : 'warn', 5000);
            }
            /* Le solde a change : on retire les mises devenues
               impossibles plutot que de laisser choisir une option
               qui sera refusee. */
            if (mise && typeof r.balance === 'number') {
              Array.prototype.slice.call(mise.options).forEach(function (o) {
                o.hidden = parseInt(o.value, 10) > r.balance;
              });
            }
          })
          .catch(function () {
            jouer.disabled = false;
            dire('Erreur réseau', 'err');
          });
      });
    }
  });
})();
