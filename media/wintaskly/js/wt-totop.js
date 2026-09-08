/**
 * Wintaskly — bouton de remontee.
 *
 * Apparait apres un defilement significatif, disparait en haut de page.
 * Le seuil est exprime en hauteurs d'ecran plutot qu'en pixels : sur un
 * telephone, 600 px representent presque un ecran entier, alors que sur
 * un grand moniteur ce n'est qu'un tiers. Un seuil fixe apparaitrait
 * trop tot sur l'un et trop tard sur l'autre.
 */
(function () {
  'use strict';

  var btn = document.querySelector('[data-totop]');
  if (!btn) { return; }

  var seuil = function () { return window.innerHeight * 1.5; };
  var visible = false;

  function maj() {
    var doit = window.scrollY > seuil();
    if (doit === visible) { return; }   // on ne touche au DOM qu'au changement
    visible = doit;
    btn.hidden = !doit;
  }

  /* requestAnimationFrame plutot qu'un appel direct : l'evenement de
     defilement se declenche des dizaines de fois par seconde, et lire
     scrollY a chaque fois provoque des recalculs de mise en page. */
  var enCours = false;
  window.addEventListener('scroll', function () {
    if (enCours) { return; }
    enCours = true;
    requestAnimationFrame(function () { maj(); enCours = false; });
  }, { passive: true });

  btn.addEventListener('click', function () {
    /* On respecte le reglage systeme : une animation de defilement peut
       provoquer un malaise chez les personnes sensibles au mouvement. */
    var reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduit ? 'auto' : 'smooth' });
    /* Le focus repart en haut, sinon la navigation au clavier reste
       bloquee en bas de page apres le clic. */
    var cible = document.querySelector('main') || document.body;
    cible.setAttribute('tabindex', '-1');
    cible.focus({ preventScroll: true });
  });

  maj();
})();
