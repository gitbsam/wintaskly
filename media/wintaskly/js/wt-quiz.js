/**
 * Wintaskly — WintQuiz.
 *
 * La page fonctionne deja sans ce script : le formulaire est un POST
 * classique. Ce fichier evite le rechargement, rien de plus. S'il ne se
 * charge pas, le jeu reste entierement jouable.
 */
(function () {
  'use strict';

  var racine = document.querySelector('[data-quiz]');
  if (!racine) { return; }

  var mb   = document.querySelector('meta[name="wt-base"]');
  var base = (mb ? mb.getAttribute('content') : '').replace(/\/$/, '');
  var mc   = document.querySelector('meta[name="csrf-token"]');
  var csrf = mc ? mc.getAttribute('content') : '';
  var delai = parseInt(racine.getAttribute('data-wait'), 10) || 0;

  function poster(donnees) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    Object.keys(donnees).forEach(function (k) { fd.append(k, donnees[k]); });
    return fetch(base + '/api/quiz_action.php', {
      method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function (r) { return r.json(); });
  }

  function deux(n) { return n < 10 ? '0' + n : String(n); }
  function hms(s) {
    return deux(Math.floor(s / 3600)) + ':' + deux(Math.floor((s % 3600) / 60)) + ':' + deux(s % 60);
  }

  /* ---------- Compte a rebours entre deux parties ---------- */
  var bloc = document.querySelector('[data-quiz-next]');
  if (bloc) {
    var cible = parseInt(bloc.getAttribute('data-quiz-next'), 10) || 0;
    var sortie = bloc.querySelector('[data-quiz-countdown]');
    var id = setInterval(function () {
      var reste = cible - Math.floor(Date.now() / 1000);
      if (reste <= 0) {
        clearInterval(id);
        /* La partie redevient possible : on recharge pour repartir
           d'un etat propre plutot que de reconstruire la page. */
        window.location.reload();
        return;
      }
      if (sortie) { sortie.textContent = hms(reste); }
    }, 1000);
    return;
  }

  /* ---------- Partie ---------- */
  var carte = racine.querySelector('[data-quiz-question]');
  if (!carte) { return; }

  var form   = carte.querySelector('[data-quiz-form]');
  var submit = carte.querySelector('[data-quiz-submit]');
  var texte  = carte.querySelector('[data-quiz-text]');
  var cat    = carte.querySelector('[data-quiz-cat]');
  var dots   = carte.querySelector('[data-quiz-dots]');
  var bar    = document.querySelector('[data-quiz-bar]');
  var pct    = document.querySelector('[data-quiz-pct]');
  var asked  = document.querySelector('[data-quiz-asked]');

  if (!form) { return; }

  /* Zone de resultat inseree apres la carte, masquee au depart. */
  var res = document.createElement('section');
  res.className = 'wt-quiz-card wt-quiz-card--result';
  res.hidden = true;
  carte.parentNode.insertBefore(res, carte.nextSibling);

  var corr = document.querySelector('[data-quiz-correct]');

  /* La barre suit la CAMPAGNE, les points suivent la partie en cours.
     Deux compteurs distincts : les confondre donnerait une barre qui
     repart a zero a chaque partie gagnee. */
  function majCampagne(c) {
    if (!c) { return; }
    if (bar)  { bar.style.width = c.pct + '%'; }
    if (pct)  { pct.textContent = c.pct + ' %'; }
    if (corr) { corr.textContent = c.correct; }
  }

  function majProgression(d) {
    if (!d) { return; }
    if (asked !== null && typeof d.asked === 'number') { asked.textContent = d.asked; }
    if (dots) {
      var pts = dots.querySelectorAll('.wt-quiz-dot');
      for (var i = 0; i < pts.length; i++) {
        pts[i].classList.toggle('is-on', i < d.count);
      }
    }
  }

  function afficherQuestion(q) {
    if (texte) { texte.textContent = q.question; }
    if (cat)   { cat.textContent = q.category || ''; }
    ['a', 'b', 'c', 'd'].forEach(function (l) {
      var el = carte.querySelector('[data-quiz-opt="' + l + '"]');
      if (el) { el.textContent = q[l]; }
    });
    var coches = form.querySelectorAll('input[name="given"]');
    for (var i = 0; i < coches.length; i++) { coches[i].checked = false; }
    res.hidden = true;
    carte.hidden = false;
    if (submit) { submit.disabled = false; }
  }

  function suivante() {
    poster({ action: 'question' }).then(function (r) {
      if (r && r.ok) {
        majProgression({ count: r.count, goal: r.goal, asked: r.asked });
        afficherQuestion(r.question);
      } else if (r && r.error === 'cooldown') {
        window.location.reload();
      } else {
        res.innerHTML = '<p class="wt-quiz-result__explain"></p>';
        res.querySelector('p').textContent = (r && r.message) || 'Erreur';
      }
    }).catch(function () { window.location.reload(); });
  }

  function afficherResultat(r) {
    carte.hidden = true;
    res.hidden = false;

    var ico = r.finished ? '🏆' : (r.correct ? '✅' : '❌');
    var cls = r.correct || r.finished ? 'wt-quiz-result__title--ok' : 'wt-quiz-result__title--bad';

    /* Construction par noeuds et non par innerHTML : le texte des
       questions vient de la base et pourrait contenir des chevrons. */
    res.textContent = '';
    var d1 = document.createElement('div');
    d1.className = 'wt-quiz-result__ico'; d1.textContent = ico;
    var h = document.createElement('h2');
    h.className = 'wt-quiz-result__title ' + cls; h.textContent = r.message;
    res.appendChild(d1); res.appendChild(h);

    if (!r.correct && r.good) {
      var p = document.createElement('p');
      p.className = 'wt-quiz-result__good';
      p.textContent = (racine.getAttribute('data-label-good') || 'Bonne réponse : ') + r.good.toUpperCase();
      res.appendChild(p);
      if (r.explain) {
        var pe = document.createElement('p');
        pe.className = 'wt-quiz-result__explain'; pe.textContent = r.explain;
        res.appendChild(pe);
      }
    }

    if (r.finished) {
      var a = document.createElement('a');
      a.className = 'wt-btn wt-btn--primary';
      a.href = base + '/tasks/';
      a.textContent = racine.getAttribute('data-label-back') || 'Retour aux tâches';
      res.appendChild(a);
      return;
    }

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'wt-btn wt-btn--primary';
    res.appendChild(btn);

    var attente = typeof r.wait === 'number' ? r.wait : delai;
    var label = racine.getAttribute('data-label-next') || 'Question suivante';

    function maj() {
      if (attente > 0) {
        btn.disabled = true;
        btn.textContent = label + ' (' + attente + ')';
        attente--;
        setTimeout(maj, 1000);
      } else {
        btn.disabled = false;
        btn.textContent = label;
      }
    }
    maj();
    btn.addEventListener('click', function () { suivante(); });
  }

  form.addEventListener('submit', function (ev) {
    var choisi = form.querySelector('input[name="given"]:checked');
    if (!choisi) { return; }          /* required s'en charge */
    ev.preventDefault();
    if (submit) { submit.disabled = true; }

    poster({ action: 'answer', given: choisi.value }).then(function (r) {
      if (!r || !r.ok) {
        if (submit) { submit.disabled = false; }
        window.location.reload();
        return;
      }
      majProgression({ count: r.count, goal: r.goal });
      majCampagne(r.camp);
      afficherResultat(r);
    }).catch(function () { window.location.reload(); });
  });
})();
