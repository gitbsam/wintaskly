/**
 * Wintaskly — encart publicitaire flottant (wt-adpop)
 *
 * Ouvre l'encart après le délai déclaré sur le conteneur, puis le
 * supprime du document quand l'utilisateur ferme.
 *
 * « Supprimer » et non « masquer » : un panneau simplement caché
 * continue d'exister, et les scripts de régie qu'il contient
 * continuent de tourner, de compter des impressions et parfois de
 * jouer du son. remove() coupe court. L'encart revient au prochain
 * chargement de page, puisque le HTML est régénéré côté serveur.
 */
(function () {
  'use strict';

  var pop = document.querySelector('[data-ad-overlay]');
  if (!pop) { return; }

  var delay = parseInt(pop.getAttribute('data-delay'), 10);
  if (!isFinite(delay) || delay < 0) { delay = 10000; }

  var timer = null;
  var opened = false;

  function destroy() {
    if (timer) { clearTimeout(timer); timer = null; }
    document.removeEventListener('keydown', onKey);
    if (opened) { document.documentElement.classList.remove('wt-adpop-open'); }
    if (pop && pop.parentNode) { pop.parentNode.removeChild(pop); }
    pop = null;
  }

  function onKey(e) {
    /* Échap ferme, comme n'importe quelle boîte de dialogue. Un encart
       qu'on ne peut fermer qu'à la souris est une plaie au clavier. */
    if (e.key === 'Escape' || e.key === 'Esc') { destroy(); }
  }

  function open() {
    timer = null;

    /* Le cadre ne s'ouvre que s'il a effectivement quelque chose à
       montrer. Une régie peut très bien ne rien renvoyer (pas
       d'inventaire, requête bloquée) : dans ce cas le serveur a
       pourtant écrit le conteneur, et on se retrouverait avec un
       rectangle vide à fermer. */
    var body = pop.querySelector('.wt-adpop__body');
    if (!body || body.offsetHeight < 20) { destroy(); return; }

    pop.hidden = false;
    opened = true;
    /* Empêche le défilement de l'arrière-plan tant que l'encart est
       ouvert : sur mobile, faire défiler la page derrière un panneau
       fixe donne une impression de page cassée. */
    document.documentElement.classList.add('wt-adpop-open');

    var closeBtn = pop.querySelector('.wt-adpop__close');
    if (closeBtn) { closeBtn.focus(); }
  }

  pop.addEventListener('click', function (e) {
    if (e.target.closest('[data-adpop-dismiss]')) {
      e.preventDefault();
      destroy();
    }
  });

  document.addEventListener('keydown', onKey);

  /* Le décompte ne démarre pas tant que l'onglet est en arrière-plan :
     sinon l'encart s'ouvre pendant l'absence de l'utilisateur, qui
     retrouve un panneau déjà là sans comprendre d'où il sort — et
     l'impression est comptée sans avoir été vue. */
  function start() {
    if (timer !== null || !pop) { return; }
    timer = setTimeout(open, delay);
  }

  if (document.visibilityState === 'hidden') {
    document.addEventListener('visibilitychange', function once() {
      if (document.visibilityState === 'visible') {
        document.removeEventListener('visibilitychange', once);
        start();
      }
    });
  } else {
    start();
  }
})();
