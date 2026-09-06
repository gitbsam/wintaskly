/**
 * Wintaskly — chrono du parcours de lien court.
 *
 * Purement décoratif. Le serveur revérifie le temps écoulé avec son
 * propre horodatage : supprimer ce script depuis la console débloque
 * le bouton, mais l'envoi sera refusé tant que le délai n'est pas
 * réellement passé.
 */
(function () {
  'use strict';

  var form  = document.querySelector('[data-slrun-form]');
  if (!form) { return; }

  var timer = form.querySelector('[data-slrun-timer]');
  var go    = form.querySelector('[data-slrun-go]');
  if (!timer || !go) { return; }

  var left = parseInt(timer.getAttribute('data-seconds'), 10);
  if (!isFinite(left) || left < 0) { left = 0; }

  var tpl = timer.textContent;

  function render() {
    if (left > 0) {
      timer.textContent = tpl.replace(/\d+/, String(left));
      return;
    }
    timer.textContent = timer.getAttribute('data-ready') || '';
    go.disabled = false;
    go.focus();
  }

  render();
  var id = setInterval(function () {
    left--;
    if (left <= 0) { clearInterval(id); left = 0; }
    render();
  }, 1000);

  /* Une seule soumission : un double clic consommerait le jeton deux
     fois, et le second envoi ferait passer le parcours en rejeté. */
  form.addEventListener('submit', function () {
    go.disabled = true;
  });
})();
