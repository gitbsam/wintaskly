/**
 * Wintaskly — Compte à rebours Bingo (partagé)
 * --------------------------------------------------------------------
 * Anime tous les éléments .wt-bingo-countdown présents sur la page.
 * Chaque élément porte data-launch (timestamp UNIX en secondes).
 * À l'échéance, recharge la page pour révéler le contenu débloqué.
 *
 * Réutilisé sur : la liste des tâches (carte teaser) et les articles de
 * blog (placeholder {{BINGO_COUNTDOWN}}).
 */
(function () {
  'use strict';

  var widgets = document.querySelectorAll('.wt-bingo-countdown[data-launch]');
  if (!widgets.length) return;

  function pad(n) { return n < 10 ? '0' + n : '' + n; }

  function makeTicker(el) {
    var launch = parseInt(el.getAttribute('data-launch'), 10) * 1000;
    if (!launch) return null;

    var elD = el.querySelector('[data-cd="days"]');
    var elH = el.querySelector('[data-cd="hours"]');
    var elM = el.querySelector('[data-cd="mins"]');
    var elS = el.querySelector('[data-cd="secs"]');
    var reloaded = false;

    return function tick() {
      var diff = launch - Date.now();
      if (diff <= 0) {
        if (elD) elD.textContent = '00';
        if (elH) elH.textContent = '00';
        if (elM) elM.textContent = '00';
        if (elS) elS.textContent = '00';
        if (!reloaded) {
          reloaded = true;
          setTimeout(function () { window.location.reload(); }, 1500);
        }
        return;
      }
      var s = Math.floor(diff / 1000);
      var d = Math.floor(s / 86400); s -= d * 86400;
      var h = Math.floor(s / 3600);  s -= h * 3600;
      var m = Math.floor(s / 60);    s -= m * 60;
      if (elD) elD.textContent = pad(d);
      if (elH) elH.textContent = pad(h);
      if (elM) elM.textContent = pad(m);
      if (elS) elS.textContent = pad(s);
    };
  }

  var tickers = [];
  widgets.forEach(function (el) {
    var t = makeTicker(el);
    if (t) { t(); tickers.push(t); }
  });

  if (tickers.length) {
    setInterval(function () {
      tickers.forEach(function (t) { t(); });
    }, 1000);
  }
})();
