/**
 * Wintaskly — Conversion de fuseau pour les champs datetime (générique).
 * --------------------------------------------------------------------
 * Le serveur stocke et lit les dates en UTC, mais l'admin les saisit dans
 * SON fuseau local (plus naturel). Ce script fait le pont pour TOUT champ
 * datetime-local marqué des attributs adéquats — réutilisable partout
 * (lancement Bingo, programmation d'articles, etc.).
 *
 * Convention (sur un <input type="datetime-local">) :
 *   - data-dt-local            : marque le champ visible à convertir
 *   - data-utc="YYYY-MM-DDTHH:MM" : valeur UTC source (pour l'affichage)
 *   - data-dt-target="<name>"  : name du champ caché qui recevra l'UTC
 *
 * Le champ caché (<input type="hidden" name="<name>">) reçoit la valeur UTC
 * à la soumission. Au chargement, le champ visible affiche l'heure locale.
 */
(function () {
  'use strict';

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function toLocalInputValue(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
         + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function toUtcInputValue(d) {
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate())
         + 'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
  }

  var fields = document.querySelectorAll('[data-dt-local]');
  if (!fields.length) {
    return;
  }

  fields.forEach(function (localInput) {
    var targetName = localInput.getAttribute('data-dt-target');
    if (!targetName) { return; }
    var hidden = document.querySelector('input[type="hidden"][name="' + targetName + '"]');
    if (!hidden) { return; }

    /* 1. Affichage : UTC → heure locale */
    var utcRaw = localInput.getAttribute('data-utc') || '';
    if (utcRaw !== '') {
      var d = new Date(utcRaw + 'Z'); // 'Z' force l'interprétation UTC
      if (!isNaN(d.getTime())) {
        localInput.value = toLocalInputValue(d);
      }
    }

    /* 2. Soumission : heure locale → UTC dans le champ caché */
    var form = localInput.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        var val = localInput.value;
        if (val === '') { hidden.value = ''; return; }
        var dd = new Date(val); // interprété en heure locale
        if (!isNaN(dd.getTime())) {
          hidden.value = toUtcInputValue(dd);
        }
      });
    }

    /* 3. Aperçu live UTC sous le champ (si un .wt-field__hint existe) */
    var hint = localInput.parentElement
      ? localInput.parentElement.querySelector('.wt-field__hint')
      : null;
    if (hint) {
      var baseHint = hint.textContent;
      var preview = function () {
        var v = localInput.value;
        if (v === '') { hint.textContent = baseHint; return; }
        var pd = new Date(v);
        if (!isNaN(pd.getTime())) {
          hint.textContent = baseHint + ' — UTC : ' + toUtcInputValue(pd).replace('T', ' ');
        }
      };
      localInput.addEventListener('change', preview);
      localInput.addEventListener('input', preview);
      preview();
    }
  });
})();
