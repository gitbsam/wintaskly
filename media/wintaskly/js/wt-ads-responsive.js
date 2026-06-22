/**
 * Wintaskly — Mise à l'échelle responsive des pubs (A-ADS et autres)
 * --------------------------------------------------------------------
 * Les iframes publicitaires ont une taille fixe (728x90, 300x250...).
 * Ce script calcule un facteur d'échelle pour qu'elles tiennent dans la
 * largeur disponible sur mobile, sans déformation ni débordement.
 *
 * Fonctionnement :
 *   1. Pour chaque .wt-ad-scale, on lit la largeur/hauteur native de la
 *      pub interne (iframe ou conteneur #frame).
 *   2. Si la largeur native dépasse la largeur dispo, on calcule un ratio
 *      (dispo / native) et on le pose en variable CSS --ad-scale.
 *   3. On recalcule au redimensionnement de la fenêtre.
 *
 * La pub reste nette (vraie taille rendue, juste transformée à l'échelle).
 */
(function () {
  'use strict';

  function detectNativeSize(inner) {
    // Cherche l'iframe (cas A-ADS) ou un élément à taille fixe
    var ifr = inner.querySelector('iframe');
    var w = 0, h = 0;

    if (ifr) {
      // Largeur/hauteur depuis l'attribut style, width/height, ou data
      w = parseInt(ifr.getAttribute('width'), 10) ||
          parseInt((ifr.style.width || '').replace('px', ''), 10) || 0;
      h = parseInt(ifr.getAttribute('height'), 10) ||
          parseInt((ifr.style.height || '').replace('px', ''), 10) || 0;

      // A-ADS encode parfois la taille dans l'URL (?size=728x90)
      if ((!w || !h) && ifr.src) {
        var m = ifr.src.match(/size=(\d+)x(\d+)/);
        if (m) { w = w || parseInt(m[1], 10); h = h || parseInt(m[2], 10); }
      }
    }

    // Repli : mesurer le conteneur interne lui-même
    if (!w) { w = inner.scrollWidth || inner.offsetWidth || 0; }
    if (!h) { h = inner.scrollHeight || inner.offsetHeight || 0; }

    return { w: w, h: h };
  }

  function scaleOne(wrap) {
    var inner = wrap.querySelector('.wt-ad-scale__inner');
    if (!inner) return;

    var size = detectNativeSize(inner);
    if (!size.w || !size.h) return;

    // Largeur disponible = largeur du wrapper
    var avail = wrap.clientWidth || wrap.offsetWidth || 0;
    if (!avail) return;

    // Échelle : 1 si ça tient, sinon on réduit proportionnellement
    var scale = avail < size.w ? (avail / size.w) : 1;

    inner.style.setProperty('--ad-w', size.w + 'px');
    inner.style.setProperty('--ad-h', size.h + 'px');
    inner.style.setProperty('--ad-scale', String(scale));
  }

  function scaleAll() {
    var wraps = document.querySelectorAll('.wt-ad-scale');
    for (var i = 0; i < wraps.length; i++) { scaleOne(wraps[i]); }
  }

  // Calcul initial (et re-calcul après chargement des iframes)
  scaleAll();
  window.addEventListener('load', scaleAll);

  // Recalcul au redimensionnement (debounce léger)
  var t = null;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(scaleAll, 150);
  });

  // Si les pubs se chargent en différé, on re-tente quelques fois
  var tries = 0;
  var poll = setInterval(function () {
    tries++;
    scaleAll();
    if (tries >= 5) { clearInterval(poll); }
  }, 700);
})();
