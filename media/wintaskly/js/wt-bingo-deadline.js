/**
 * Wintaskly — compte a rebours du tirage du jour (bingo).
 *
 * La bascule de journee se fait a minuit UTC, pas a l'heure locale du
 * joueur. Sans ce decompte, un joueur a La Reunion (UTC+4) croit avoir
 * jusqu'a minuit chez lui alors qu'il lui reste quatre heures de moins.
 */
(function () {
  'use strict';

  var bloc = document.querySelector('[data-bingo-deadline]');
  if (!bloc) { return; }

  var cible = parseInt(bloc.getAttribute('data-bingo-deadline'), 10);
  var sortie = bloc.querySelector('[data-bingo-countdown]');
  if (!sortie || !isFinite(cible)) { return; }

  function deux(n) { return n < 10 ? '0' + n : String(n); }

  function maj() {
    var reste = cible - Math.floor(Date.now() / 1000);
    if (reste <= 0) {
      /* On ne recharge pas la page : le joueur pourrait etre en train de
         cocher. On lui dit simplement que la fenetre est fermee. */
      sortie.textContent = '00:00:00';
      bloc.classList.add('is-over');
      clearInterval(id);
      return;
    }
    sortie.textContent = deux(Math.floor(reste / 3600)) + ':' +
                         deux(Math.floor((reste % 3600) / 60)) + ':' +
                         deux(reste % 60);
    /* Sous une heure, on passe en alerte : c'est le moment ou le rappel
       sert vraiment a quelque chose. */
    if (reste < 3600) { bloc.classList.add('is-soon'); }
  }

  maj();
  var id = setInterval(maj, 1000);
})();
