/* =====================================================================
   wintaskly.js — Logique applicative globale (Ajax Vanilla)
   ===================================================================== */
(function () {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const META = (n) => document.querySelector('meta[name="' + n + '"]')?.content || '';
  const BASE = META('wt-base');
  const CSRF = META('csrf-token');

  // ---------------------------------------------------------------------
  // Helper Ajax générique
  // ---------------------------------------------------------------------
  async function api(path, data = {}, opts = {}) {
    const fd = new FormData();
    // Standard projet : tous les POST utilisent '_csrf' (avec underscore).
    fd.append('_csrf', CSRF);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(BASE + path, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      ...opts,
    });
    let json = null;
    try { json = await res.json(); } catch (e) {}
    if (!res.ok) {
      const msg = (json && json.message) || 'Erreur réseau';
      throw Object.assign(new Error(msg), { response: json, status: res.status });
    }
    return json || {};
  }

  // ---------------------------------------------------------------------
  // FAUCET — Étape 1 : Carrefour (compte à rebours + bouton start)
  // ---------------------------------------------------------------------
  const faucetStart = $('[data-faucet-start]');
  const faucetCountdown = $('[data-faucet-countdown]');

  function fmtRemaining(ms) {
    if (ms <= 0) return null;
    const s = Math.floor(ms / 1000);
    const hh = String(Math.floor(s / 3600)).padStart(2, '0');
    const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
    const ss = String(s % 60).padStart(2, '0');
    return { hh, mm, ss };
  }

  function tickCountdown() {
    if (!faucetCountdown) return;
    const endIso = faucetCountdown.getAttribute('data-end-iso');
    if (!endIso) return;
    const remain = new Date(endIso).getTime() - Date.now();
    const parts = fmtRemaining(remain);
    if (!parts) {
      faucetCountdown.classList.add('wt-hidden');
      if (faucetStart) faucetStart.classList.remove('wt-hidden');
      return;
    }
    faucetCountdown.querySelector('[data-h]').textContent = parts.hh;
    faucetCountdown.querySelector('[data-m]').textContent = parts.mm;
    faucetCountdown.querySelector('[data-s]').textContent = parts.ss;

    /* V8 — Met à jour le cercle SVG de progression si présent.
     * dasharray = circonférence (2π × r = 2π × 62 ≈ 389.56)
     * Le pourcentage restant détermine l'offset. */
    const ring = faucetCountdown.querySelector('[data-progress-circle]');
    if (ring) {
      const cooldownSec = parseInt(faucetCountdown.getAttribute('data-cooldown-seconds'), 10) || 10800;
      const remainSec   = Math.max(0, remain / 1000);
      const ratio       = Math.min(1, Math.max(0, remainSec / cooldownSec));
      const circumf     = 389.56;
      ring.style.strokeDashoffset = (circumf * ratio).toFixed(2);
    }
  }
  if (faucetCountdown) {
    tickCountdown();
    setInterval(tickCountdown, 1000);
  }

  // Démarrage du faucet : appel API, redirection vers /tasks/faucet/transition.php
  if (faucetStart) {
    faucetStart.addEventListener('click', async () => {
      faucetStart.disabled = true;
      try {
        const r = await api('/api/faucet_start.php');
        if (r.ok && r.next) {
          window.location.href = r.next;
        } else {
          window.WT.toast(r.message || 'Erreur', 'err');
          faucetStart.disabled = false;
        }
      } catch (e) {
        window.WT.toast(e.message || 'Erreur', 'err');
        faucetStart.disabled = false;
      }
    });
  }

  // ---------------------------------------------------------------------
  // FAUCET — Étape 2 : page de transition (compte à rebours puis bouton)
  // ---------------------------------------------------------------------
  const tCount = $('[data-transition-count]');
  const tBtn   = $('[data-transition-continue]');
  if (tCount && tBtn) {
    const total = parseInt(tCount.getAttribute('data-seconds'), 10) || 12;
    let secs    = total;
    /* V8 : si on a un markup séparé num/bar, on les pilote, sinon
     * fallback sur l'ancien comportement (text dans tCount). */
    const numEl = tCount.querySelector('[data-transition-num]') || tCount;
    const barEl = tCount.querySelector('[data-transition-bar]');
    const CIRC  = 326.7; // 2π × 52

    function paint() {
      numEl.textContent = Math.max(0, secs);
      if (barEl) {
        const ratio = Math.max(0, secs / total);
        barEl.style.strokeDashoffset = (CIRC * (1 - ratio)).toFixed(2);
      }
    }

    paint();
    tBtn.classList.add('wt-hidden');
    const itv = setInterval(() => {
      secs--;
      paint();
      tCount.classList.remove('is-ticking');
      void tCount.offsetWidth;
      tCount.classList.add('is-ticking');
      if (secs <= 0) {
        clearInterval(itv);
        tCount.classList.add('wt-hidden');
        tBtn.classList.remove('wt-hidden');
      }
    }, 1000);
  }

  // ---------------------------------------------------------------------
  // FAUCET — Étape 3 : captcha + checkbox + honeypot + Ajax
  // ---------------------------------------------------------------------
  const verifyForm = $('[data-faucet-verify-form]');
  if (verifyForm) {
    const target   = verifyForm.getAttribute('data-target-slug');
    /* V8 : on accepte les deux noms de classe pour rester rétrocompat */
    const icons    = $$('.wt-captcha-icon, .wt-faucet-v2__captcha-icon', verifyForm);
    const cb       = $('[data-not-robot]', verifyForm);
    const submit   = $('[data-claim-btn]', verifyForm);
    const reset    = $('[data-captcha-reset]', verifyForm);
    let pickedSlug = null;
    let pickedAt   = 0;     // timestamp ms du clic correct

    function updateSubmitVisibility() {
      const ok = pickedSlug === target && cb && cb.checked;
      if (ok) {
        submit.classList.remove('wt-hidden');
      } else {
        submit.classList.add('wt-hidden');
      }
    }
    function clearSelection() {
      pickedSlug = null;
      icons.forEach(i => i.classList.remove('is-selected'));
      updateSubmitVisibility();
    }

    icons.forEach(icon => {
      icon.addEventListener('click', () => {
        icons.forEach(i => i.classList.remove('is-selected'));
        icon.classList.add('is-selected');
        pickedSlug = icon.getAttribute('data-slug');
        pickedAt   = Date.now();
        updateSubmitVisibility();
      });
    });

    if (cb) cb.addEventListener('change', updateSubmitVisibility);
    if (reset) reset.addEventListener('click', clearSelection);

    submit.classList.add('wt-hidden');

    verifyForm.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      submit.disabled = true;

      // Honeypot : si rempli → on n'envoie pas et on logge côté serveur
      const hpA = verifyForm.querySelector('input[name="website"]');
      const hpB = verifyForm.querySelector('input[name="address2"]');

      try {
        const r = await api('/api/faucet_validate.php', {
          token: verifyForm.getAttribute('data-token'),
          picked: pickedSlug || '',
          not_robot: cb && cb.checked ? '1' : '0',
          website: hpA ? hpA.value : '',
          address2: hpB ? hpB.value : '',
          client_pick_ts: pickedAt || 0,
        });

        if (r.ok) {
          // Effet de fête puis redirection
          const coin = $('.wt-faucet-coin');
          if (coin) coin.classList.add('wt-celebrate');
          window.WT.toast(r.message || 'Bravo !', 'ok');
          setTimeout(() => { window.location.href = r.next || BASE + '/tasks/faucet/'; }, 1200);
        } else {
          window.WT.toast(r.message || 'Refusé', 'err');
          submit.disabled = false;
          if (r.redirect) {
            setTimeout(() => { window.location.href = r.redirect; }, 1500);
          }
        }
      } catch (e) {
        window.WT.toast(e.message || 'Erreur', 'err');
        if (e.response && e.response.redirect) {
          setTimeout(() => { window.location.href = e.response.redirect; }, 1500);
        } else {
          submit.disabled = false;
        }
      }
    });
  }

  // ---------------------------------------------------------------------
  // PROGRESS BARS (XP)
  // ---------------------------------------------------------------------
  $$('[data-progress]').forEach(bar => {
    const pct = Math.max(0, Math.min(100, parseFloat(bar.getAttribute('data-progress'))));
    requestAnimationFrame(() => {
      bar.style.width = pct + '%';
    });
  });
})();

/* =====================================================================
 *  PTC (Paid To Click) — frontend logic
 *
 *  Comportement attendu :
 *    1) L'utilisateur clique sur "Visiter l'annonce" sur la liste PTC.
 *    2) On appelle /api/ptc_start.php pour acquérir un verrou côté serveur.
 *    3) Si OK, on ouvre l'URL partenaire dans un nouvel onglet et on
 *       lance le chrono sur l'onglet Wintaskly courant.
 *    4) Le titre de l'onglet Wintaskly est mis à jour en temps réel
 *       (⏳ (12s) Wintaskly → ✅ [Prêt !] Wintaskly).
 *    5) Si l'onglet partenaire est fermé avant la fin → annulation
 *       (toast + appel API ptc_cancel).
 *    6) À la fin du chrono → modale captcha (mini-captcha 3 icônes).
 *    7) Validation Ajax → crédit + déverrouillage.
 *
 *  Verrou multi-onglets :
 *    - sessionStorage.wt_ptc_running = "1" pendant toute la durée
 *    - tout clic sur une autre carte PTC est instantanément bloqué
 * ===================================================================== */
(function () {
  'use strict';

  const list = document.querySelector('[data-ptc-list]');
  const banner = document.querySelector('[data-ptc-banner]');
  if (!list && !banner) return;

  const META = (n) => document.querySelector('meta[name="' + n + '"]')?.content || '';
  const BASE = META('wt-base');

  const T = {
    tab_closed:    document.documentElement.dataset.ptcTabClosed    || 'You closed the partner tab too early!',
    running:       document.documentElement.dataset.ptcRunning      || 'A PTC task is already running.',
    title_running: document.documentElement.dataset.ptcTitleRunning || '⏳ ({s}s) {site}',
    title_ready:   document.documentElement.dataset.ptcTitleReady   || '✅ [Ready!] {site}',
    success:       document.documentElement.dataset.ptcSuccess      || 'Reward credited 🎉',
  };

  const SITE_TITLE = (META('wt-site-name') || 'Wintaskly');
  const ORIGINAL_TITLE = document.title;

  // ----- Multi-tab lock ------------------------------------------------
  const LOCK_KEY = 'wt_ptc_running';

  function setRunning(on) {
    try {
      if (on) sessionStorage.setItem(LOCK_KEY, '1');
      else    sessionStorage.removeItem(LOCK_KEY);
    } catch (_) { /* private mode */ }
    document.querySelectorAll('[data-ptc-card]').forEach((card) => {
      if (on) card.classList.add('is-disabled');
      else    card.classList.remove('is-disabled');
    });
  }

  // Au chargement, si le storage dit "en cours" mais qu'on est sur la liste,
  // on grise les cartes par sécurité (le user peut avoir rafraîchi).
  try {
    if (sessionStorage.getItem(LOCK_KEY) === '1') setRunning(true);
  } catch (_) {}

  // ----- API helper ----------------------------------------------------
  async function api(endpoint, payload) {
    const csrf = META('csrf-token');
    const fd = new FormData();
    fd.append('_csrf', csrf);
    if (payload) {
      for (const k in payload) fd.append(k, payload[k]);
    }
    const r = await fetch(BASE + endpoint, { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }

  // ----- Modal validation ---------------------------------------------
  function buildModal(token, icons, target) {
    let modal = document.getElementById('wt-ptc-modal');
    if (modal) modal.remove();

    modal = document.createElement('div');
    modal.id = 'wt-ptc-modal';
    modal.className = 'wt-modal';

    const titleTxt = document.documentElement.dataset.ptcModalTitle || 'Validate your reward';
    const introTxt = document.documentElement.dataset.ptcModalIntro || 'Click on the requested symbol.';
    const captchaTxt = (document.documentElement.dataset.ptcCaptcha || 'Click on "{target}"')
                        .replace('{target}', target);

    modal.innerHTML =
      '<div class="wt-modal__backdrop" data-modal-close></div>' +
      '<div class="wt-modal__panel" role="dialog" aria-modal="true">' +
        '<h2 class="wt-modal__title">' + titleTxt + '</h2>' +
        '<p class="wt-modal__intro">' + introTxt + '</p>' +
        '<p><strong>' + captchaTxt + '</strong></p>' +
        '<div class="wt-mini-captcha" data-captcha-grid></div>' +
      '</div>';
    document.body.appendChild(modal);

    const grid = modal.querySelector('[data-captcha-grid]');
    icons.forEach((ic) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'wt-mini-captcha__btn';
      btn.setAttribute('data-slug', ic.slug);
      btn.setAttribute('aria-label', ic.label || ic.slug);
      btn.innerHTML = ic.svg;
      btn.addEventListener('click', async () => {
        // Bloque tous les boutons pendant la requête
        grid.querySelectorAll('button').forEach((b) => b.disabled = true);
        const res = await api('/api/ptc_validate.php', { token: token, slug: ic.slug });
        if (res && res.ok) {
          modal.classList.remove('is-open');
          setRunning(false);
          document.title = ORIGINAL_TITLE;
          if (window.WT && window.WT.toast) window.WT.toast(T.success, 'ok', 3000);
          setTimeout(() => location.reload(), 1500);
        } else {
          if (window.WT && window.WT.toast) window.WT.toast(res?.error || 'Erreur', 'err', 3000);
          // Réactive pour permettre une nouvelle tentative
          grid.querySelectorAll('button').forEach((b) => b.disabled = false);
        }
      });
      grid.appendChild(btn);
    });

    requestAnimationFrame(() => modal.classList.add('is-open'));
  }

  // ----- Chrono runner -------------------------------------------------
  let chronoState = null; // { token, win, seconds, timerId, watcherId, ended }

  function stopChrono(reason) {
    if (!chronoState) return;
    clearInterval(chronoState.timerId);
    clearInterval(chronoState.watcherId);
    if (reason === 'cancel') {
      api('/api/ptc_cancel.php', { token: chronoState.token }).catch(() => {});
      document.title = ORIGINAL_TITLE;
      setRunning(false);
      if (banner) {
        banner.classList.add('is-cancelled');
        const num = banner.querySelector('[data-ptc-banner-num]');
        if (num) num.textContent = '✖';
      }
      if (window.WT && window.WT.toast) window.WT.toast(T.tab_closed, 'err', 4000);
    }
    chronoState = null;
  }

  function startChrono(payload, preOpenedWin) {
    const { token, url, duration_seconds, captcha } = payload;

    // Utilise la fenêtre déjà ouverte (pattern "about:blank trick") si
    // elle est fournie. Fallback : tente de l'ouvrir maintenant (cas où
    // startChrono serait appelé sans pré-ouverture).
    let win = preOpenedWin || null;
    if (!win) {
      win = window.open(url, '_blank', 'noreferrer');
      if (!win) {
        if (window.WT && window.WT.toast) {
          window.WT.toast('Popup bloquée — autorise les pop-ups pour ce site.', 'err', 4000);
        }
        setRunning(false);
        return;
      }
    }
    setRunning(true);

    // 2) Affiche le bandeau chrono
    let bnr = banner;
    if (!bnr) {
      bnr = document.createElement('div');
      bnr.className = 'wt-ptc-chrono';
      bnr.setAttribute('data-ptc-banner', '');
      bnr.innerHTML =
        '<span class="wt-ptc-chrono__num" data-ptc-banner-num>' + duration_seconds + '</span>' +
        '<span class="wt-ptc-chrono__label">⏳ Garde l’onglet partenaire ouvert…</span>';
      const main = document.querySelector('main') || document.body;
      main.insertBefore(bnr, main.firstChild);
    }
    const numEl = bnr.querySelector('[data-ptc-banner-num]');

    // 3) State machine
    chronoState = {
      token: token,
      win: win,
      seconds: duration_seconds,
      timerId: null,
      watcherId: null,
      ended: false,
    };

    function tick() {
      if (!chronoState) return;
      chronoState.seconds--;
      if (numEl) numEl.textContent = String(chronoState.seconds);
      document.title = T.title_running.replace('{s}', chronoState.seconds).replace('{site}', SITE_TITLE);

      if (chronoState.seconds <= 0) {
        clearInterval(chronoState.timerId);
        chronoState.ended = true;
        document.title = T.title_ready.replace('{site}', SITE_TITLE);
        if (bnr) { bnr.classList.add('is-done'); if (numEl) numEl.textContent = '✓'; }
        // On laisse la fenêtre partenaire — modal de validation
        buildModal(token, captcha.icons, captcha.target);
      }
    }

    // ----- Surveillance de l'onglet partenaire -----
    function watch() {
      if (!chronoState) return;
      // win.closed est lisible même après une redirection cross-origin
      if (chronoState.win && chronoState.win.closed && !chronoState.ended) {
        stopChrono('cancel');
      }
    }

    document.title = T.title_running.replace('{s}', duration_seconds).replace('{site}', SITE_TITLE);
    chronoState.timerId   = setInterval(tick, 1000);
    chronoState.watcherId = setInterval(watch, 500);
  }

  // ----- Click handler on PTC cards -----------------------------------
  //
  // 🎯 IMPORTANT — pattern "about:blank trick" pour popup mobile.
  //
  // Sur mobile Chrome/Safari, window.open() doit être appelé DIRECTEMENT
  // dans le handler du user gesture (sync, pas après un await), sinon le
  // navigateur considère que ce n'est pas une interaction utilisateur
  // directe et bloque le popup.
  //
  // Or, ici on a besoin d'attendre le /api/ptc_start.php pour connaître
  // l'URL partenaire à ouvrir. Solution standard du web :
  //   1) ouvrir IMMÉDIATEMENT une fenêtre vide (about:blank) — dans le
  //      user gesture, donc autorisée
  //   2) attendre le résultat de l'API (await)
  //   3) une fois la réponse reçue, charger l'URL dans la fenêtre déjà
  //      ouverte via win.location = url
  //
  // Bonus : la fenêtre s'ouvre quasi-instantanément (perçu plus rapide
  // par l'utilisateur), et on garde la référence pour win.closed.
  // ─────────────────────────────────────────────────────────────────
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ptc-start]');
    if (!btn) return;
    e.preventDefault();

    // 1) Check du lock multi-onglets AVANT toute action
    try {
      if (sessionStorage.getItem(LOCK_KEY) === '1') {
        if (window.WT && window.WT.toast) window.WT.toast(T.running, 'err', 3000);
        return;
      }
    } catch (_) {}

    // 2) Ouvre IMMÉDIATEMENT un onglet vide (dans le user gesture)
    //    — sans noopener (sinon win = null), sans noreferrer pour éviter
    //    les bugs Safari, on s'en occupera lors de la redirection.
    const win = window.open('about:blank', '_blank');
    if (!win) {
      // Popup bloquée par le navigateur — on prévient l'utilisateur
      if (window.WT && window.WT.toast) {
        window.WT.toast('Popup bloquée — autorise les pop-ups pour ce site et réessaie.', 'err', 5000);
      }
      return;
    }

    // 3) Affiche un message "Chargement..." dans la fenêtre ouverte
    //    pendant qu'on appelle l'API
    try {
      win.document.write(
        '<!doctype html><html><head><title>Chargement…</title>' +
        '<style>body{margin:0;display:flex;align-items:center;justify-content:center;' +
        'min-height:100vh;background:#0a0e1a;color:#e8eaf0;font-family:system-ui,sans-serif;' +
        'font-size:1.1rem}</style></head>' +
        '<body><div>⏳ Préparation de l\'annonce…</div></body></html>'
      );
    } catch (_) { /* cross-origin = OK, on ignore */ }

    btn.disabled = true;
    const ptcId = btn.getAttribute('data-ptc-id');

    // 4) Appel API (await — possible MAINTENANT car la fenêtre est ouverte)
    let res;
    try {
      res = await api('/api/ptc_start.php', { ptc_id: ptcId });
    } catch (err) {
      btn.disabled = false;
      try { win.close(); } catch (_) {}
      if (window.WT && window.WT.toast) window.WT.toast('Erreur réseau', 'err', 3000);
      return;
    }

    if (!res || !res.ok) {
      btn.disabled = false;
      try { win.close(); } catch (_) {}
      if (window.WT && window.WT.toast) window.WT.toast((res && res.error) || 'Erreur', 'err', 3000);
      return;
    }

    // 5) Charge l'URL partenaire dans la fenêtre déjà ouverte
    try {
      win.location.href = res.url;
    } catch (_) {
      // si win.location plante (rare), on tente un fallback
      try { win.location = res.url; } catch (_) {}
    }

    // 6) Lance le chrono en passant la référence à la fenêtre déjà ouverte
    startChrono(res, win);
  });

  // ----- Withdrawal real-time conversion ------------------------------
  const wdForm = document.querySelector('[data-wd-form]');
  if (wdForm) {
    const amount  = wdForm.querySelector('[name="coins_amount"]');
    const out     = wdForm.querySelector('[data-wd-payout]');
    const methods = wdForm.querySelectorAll('input[name="method_id"]');

    function refresh() {
      const radio = wdForm.querySelector('input[name="method_id"]:checked');
      if (!radio || !amount || !out) return;
      const ratio    = parseFloat(radio.getAttribute('data-ratio') || '10000');
      const currency = radio.getAttribute('data-currency') || 'USD';
      const coins    = parseFloat(amount.value || '0');
      const payout   = (coins / ratio);
      out.textContent = (isFinite(payout) ? payout.toFixed(4) : '0') + ' ' + currency;

      // Active highlight
      wdForm.querySelectorAll('[data-wd-method]').forEach((m) => m.classList.remove('is-active'));
      const card = radio.closest('[data-wd-method]');
      if (card) card.classList.add('is-active');
    }

    methods.forEach((r) => r.addEventListener('change', refresh));
    if (amount) amount.addEventListener('input', refresh);
    refresh();
  }
})();

/* =====================================================================
 *  V3 — Hub d'authentification (modules frontend)
 *  ---------------------------------------------------------------------
 *  • Gestionnaire Ajax générique pour les formulaires [data-auth-form]
 *  • Jauge de force du mot de passe ([data-strength-input/bar/fill/label])
 *  • Saisie OTP segmentée 6 cases ([data-otp-root]) avec auto-focus +
 *    coller multi-chiffres + soumission auto à la 6e case
 *  • Bouton « Renvoyer l'email » ([data-resend-btn]) avec compte
 *    à rebours visuel de 60 s
 * ===================================================================== */
(function () {
  'use strict';

  // -----------------------------------------------------------------
  // Utilitaire : récupère le token CSRF (méta ou champ caché)
  // -----------------------------------------------------------------
  function getCsrf(form) {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    const f = form && form.querySelector('input[name="_csrf"]');
    return f ? f.value : '';
  }

  // ===================================================================
  // 1) Gestionnaire Ajax pour [data-auth-form]
  //    - Empêche le rechargement de page
  //    - Affiche les erreurs dans [data-auth-error]
  //    - Affiche les succès dans [data-auth-success] (si dispo)
  //    - Désactive le bouton et montre un spinner
  //    - Suit la redirection demandée par le serveur
  // ===================================================================
  document.querySelectorAll('[data-auth-form]').forEach((form) => {
    const url       = form.getAttribute('data-endpoint');
    const keepForm  = form.hasAttribute('data-keep-form');
    const errBox    = document.querySelector('[data-auth-error]');
    const okBox     = document.querySelector('[data-auth-success]');
    const btn       = form.querySelector('[data-submit-btn]');
    const btnLabel  = btn && btn.querySelector('.wt-btn__label');
    const btnSpin   = btn && btn.querySelector('.wt-btn__spinner');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (errBox) { errBox.textContent = ''; errBox.classList.add('is-hidden'); }
      if (okBox)  { okBox.textContent  = ''; okBox.classList.add('is-hidden'); }

      if (btn) {
        btn.disabled = true;
        if (btnSpin) btnSpin.classList.remove('is-hidden');
        if (btnLabel) btnLabel.classList.add('is-loading');
      }

      try {
        const fd = new FormData(form);
        const res = await fetch(url, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'fetch' },
        });
        const data = await res.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));

        if (data && data.ok) {
          // ===== Cas spécial : guest contact submit avec track_url =====
          // L'API renvoie { ok:true, message, track_url, ticket_id }.
          // On affiche un bloc dédié avec le lien copiable, on masque
          // le formulaire pour empêcher une double-soumission.
          if (data.track_url) {
            const guestSuccess = document.querySelector('[data-guest-success]');
            if (guestSuccess) {
              const input = guestSuccess.querySelector('[data-track-input]');
              const link  = guestSuccess.querySelector('[data-track-link]');
              if (input) input.value = data.track_url;
              if (link)  link.href  = data.track_url;
              guestSuccess.classList.remove('is-hidden');
              guestSuccess.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            // On masque le formulaire pour éviter les double-envois
            form.classList.add('is-hidden');
            // On affiche tout de même le message global (cumul avec le bloc)
            if (okBox && data.message) {
              okBox.textContent = data.message;
              okBox.classList.remove('is-hidden');
            }
            return;
          }

          if (okBox && data.message) {
            okBox.textContent = data.message;
            okBox.classList.remove('is-hidden');
          }
          if (!keepForm && data.redirect) {
            // Petite pause pour que le message s'affiche
            setTimeout(() => { window.location.href = data.redirect; }, okBox ? 500 : 0);
            return;
          }
          if (!keepForm) {
            form.reset();
          }
          // Cas : redirect vers le dashboard après envoi user connecté
          if (keepForm && data.redirect) {
            setTimeout(() => { window.location.href = data.redirect; }, 1200);
          }
        } else {
          if (errBox) {
            let msg = (data && data.error) || 'Erreur inconnue';
            if (data && data.cooldown) {
              msg += ' (' + data.cooldown + 's)';
            }
            errBox.textContent = msg;
            errBox.classList.remove('is-hidden');
            errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          // Cas spécial : captcha invalide → recharger la page pour avoir un nouveau
          if (data && data.captcha_new) {
            setTimeout(() => { window.location.reload(); }, 2500);
          }
          // Cas spécial : email pas vérifié → invitation à aller sur verify-email
          if (data && data.redirect && data.error) {
            setTimeout(() => { window.location.href = data.redirect; }, 1800);
          }
        }
      } catch (err) {
        if (errBox) {
          errBox.textContent = 'Erreur réseau, réessaie.';
          errBox.classList.remove('is-hidden');
        }
      } finally {
        if (btn) {
          btn.disabled = false;
          if (btnSpin) btnSpin.classList.add('is-hidden');
          if (btnLabel) btnLabel.classList.remove('is-loading');
        }
      }
    });
  });

  // ===================================================================
  // 2) Jauge de force du mot de passe
  //    Évalue grossièrement la complexité : longueur + variété
  //    de classes de caractères. 4 niveaux : weak / fair / good / strong.
  // ===================================================================
  function scorePassword(pwd) {
    if (!pwd) return 0;
    let score = 0;
    const len = pwd.length;
    score += Math.min(len * 4, 40);

    let classes = 0;
    if (/[a-z]/.test(pwd)) classes++;
    if (/[A-Z]/.test(pwd)) classes++;
    if (/[0-9]/.test(pwd)) classes++;
    if (/[^A-Za-z0-9]/.test(pwd)) classes++;
    score += (classes - 1) * 15;

    // Pénalités
    if (/^(.)\1+$/.test(pwd))            score -= 30;     // caractère unique
    if (/^(0123|1234|abcd|qwer)/i.test(pwd)) score -= 20; // suites

    return Math.max(0, Math.min(score, 100));
  }

  function labelForScore(s) {
    const t = document.documentElement.dataset;
    if (s >= 75) return { c: 'strong', t: t.strengthStrong || 'Solide'  };
    if (s >= 50) return { c: 'good',   t: t.strengthGood   || 'Bon'     };
    if (s >= 25) return { c: 'fair',   t: t.strengthFair   || 'Moyen'   };
    return                { c: 'weak',   t: t.strengthWeak   || 'Faible'  };
  }

  document.querySelectorAll('[data-strength-input]').forEach((input) => {
    const field = input.closest('.wt-field') || input.parentElement;
    const fill  = field.querySelector('[data-strength-fill]');
    const label = field.querySelector('[data-strength-label]');

    input.addEventListener('input', () => {
      const s   = scorePassword(input.value);
      const lvl = labelForScore(s);
      if (fill) {
        fill.style.width = s + '%';
        fill.dataset.level = lvl.c;
      }
      if (label) {
        label.textContent = lvl.t;
        label.dataset.level = lvl.c;
      }
    });
  });

  // ===================================================================
  // 3) OTP segmenté ([data-otp-root])
  //    - Tabule automatiquement à la cellule suivante après saisie
  //    - Backspace remonte sur la cellule précédente vide
  //    - Coller un code à N chiffres répartit les chiffres
  //    - À la 6e case remplie, soumet le formulaire parent
  // ===================================================================
  document.querySelectorAll('[data-otp-root]').forEach((root) => {
    const cells  = Array.from(root.querySelectorAll('.wt-otp__cell'));
    const hidden = root.parentElement
                       ? root.parentElement.querySelector('[data-otp-hidden]')
                       : null;
    const form   = root.closest('form');

    function syncHidden() {
      const v = cells.map((c) => c.value || '').join('');
      if (hidden) hidden.value = v;
      return v;
    }

    cells.forEach((cell, i) => {
      cell.addEventListener('input', () => {
        // Garde uniquement les chiffres
        cell.value = (cell.value || '').replace(/[^0-9]/g, '').slice(0, 1);
        if (cell.value && i < cells.length - 1) {
          cells[i + 1].focus();
        }
        const v = syncHidden();
        if (v.length === cells.length && form) {
          // Soumission auto à la 6e case
          form.requestSubmit
            ? form.requestSubmit()
            : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      });

      cell.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !cell.value && i > 0) {
          cells[i - 1].focus();
          cells[i - 1].value = '';
          syncHidden();
        } else if (e.key === 'ArrowLeft' && i > 0) {
          cells[i - 1].focus();
        } else if (e.key === 'ArrowRight' && i < cells.length - 1) {
          cells[i + 1].focus();
        }
      });

      cell.addEventListener('paste', (e) => {
        const txt = (e.clipboardData || window.clipboardData).getData('text') || '';
        const digits = txt.replace(/\D+/g, '').slice(0, cells.length);
        if (!digits) return;
        e.preventDefault();
        for (let j = 0; j < digits.length; j++) {
          cells[j].value = digits[j];
        }
        cells[Math.min(digits.length, cells.length) - 1].focus();
        const v = syncHidden();
        if (v.length === cells.length && form) {
          form.requestSubmit
            ? form.requestSubmit()
            : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      });
    });
  });

  // ===================================================================
  // 4) Bouton « Renvoyer l'email » avec compte à rebours 60 s
  // ===================================================================
  document.querySelectorAll('[data-resend-btn]').forEach((btn) => {
    const url     = btn.getAttribute('data-endpoint');
    const csrf    = btn.getAttribute('data-csrf') || '';
    const label   = btn.querySelector('.wt-btn__label');
    const okBox   = document.querySelector('[data-resend-success]');
    const errBox  = document.querySelector('[data-resend-error]');
    const baseTxt = (label ? label.textContent : btn.textContent).trim();

    function startCountdown(seconds) {
      btn.disabled = true;
      let s = seconds;
      const update = () => {
        if (label) label.textContent = baseTxt + ' (' + s + 's)';
        else       btn.textContent   = baseTxt + ' (' + s + 's)';
      };
      update();
      const id = setInterval(() => {
        s--;
        if (s <= 0) {
          clearInterval(id);
          btn.disabled = false;
          if (label) label.textContent = baseTxt;
          else       btn.textContent   = baseTxt;
        } else {
          update();
        }
      }, 1000);
    }

    btn.addEventListener('click', async () => {
      if (okBox)  okBox.classList.add('is-hidden');
      if (errBox) errBox.classList.add('is-hidden');
      btn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('_csrf', csrf);
        const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));

        if (data.ok) {
          if (okBox) {
            okBox.textContent = document.documentElement.dataset.resendOk
                              || 'Email renvoyé. Vérifie ta boîte de réception.';
            okBox.classList.remove('is-hidden');
          }
          startCountdown(data.cooldown || 60);
        } else {
          if (errBox) {
            errBox.textContent = data.error || 'Erreur';
            errBox.classList.remove('is-hidden');
          }
          if (data.cooldown) startCountdown(data.cooldown);
          else btn.disabled = false;
        }
      } catch (e) {
        if (errBox) {
          errBox.textContent = 'Erreur réseau, réessaie.';
          errBox.classList.remove('is-hidden');
        }
        btn.disabled = false;
      }
    });
  });
})();

/* =====================================================================
 *  V4 — Modules UI : profil, drawer mobile, FAQ accordion, bulk delete,
 *  badges polling
 * ===================================================================== */

/* --- 1) Avatar dropdown ----------------------------------------------- */
(function () {
  const root = document.querySelector('[data-profile-menu]');
  if (!root) return;
  const toggle = root.querySelector('[data-profile-toggle]');
  const menu   = root.querySelector('[data-profile-dropdown]');
  if (!toggle || !menu) return;

  function close() {
    root.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
  }
  function open() {
    root.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    root.classList.contains('is-open') ? close() : open();
  });
  document.addEventListener('click', (e) => {
    if (!root.contains(e.target)) close();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });
})();

/* --- 2) Mobile drawer (droite → gauche) ------------------------------ */
(function () {
  const drawer   = document.getElementById('wt-drawer');
  const backdrop = document.querySelector('[data-drawer-backdrop]');
  const triggers = document.querySelectorAll('[data-drawer-toggle]');
  const closers  = document.querySelectorAll('[data-drawer-close]');
  if (!drawer || !backdrop) return;

  // Mémorise la position de scroll pour la restaurer à la fermeture
  let savedScrollY = 0;

  function lockBodyScroll() {
    savedScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top = '-' + savedScrollY + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.classList.add('wt-no-scroll');
  }

  function unlockBodyScroll() {
    document.body.classList.remove('wt-no-scroll');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    // Restaure la position de scroll exacte (sinon l'utilisateur revient en haut)
    window.scrollTo(0, savedScrollY);
  }

  function setOpen(open) {
    drawer.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (open) lockBodyScroll();
    else      unlockBodyScroll();
    triggers.forEach((t) => {
      t.classList.toggle('is-active', open);
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  triggers.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault();
    setOpen(!drawer.classList.contains('is-open'));
  }));
  closers.forEach((c) => c.addEventListener('click', () => setOpen(false)));
  backdrop.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer.classList.contains('is-open')) setOpen(false);
  });
})();

/* --- 2b) Sidebar drawer (gauche → droite, pour /admin/* et /dashboard/*)
   ---------------------------------------------------------------------
   La sidebar (admin/_nav.php, dashboard/_nav.php) devient un drawer
   sur écrans < 960px, ouvert par le bouton ⋯ du header.

   Hooks DOM :
     - #wt-sidebar-drawer        : l'aside lui-même (admin ou dashboard)
     - [data-sidebar-toggle]     : bouton ⋯ dans le header
     - [data-sidebar-backdrop]   : overlay semi-transparent
     - [data-sidebar-close]      : (optionnel) bouton X dans la sidebar

   Comportement :
     - Tap bouton ⋯ → ouvre/ferme le drawer
     - Tap backdrop → ferme
     - Touche Escape → ferme
     - Tap sur lien interne de la sidebar → ferme automatiquement
       (l'utilisateur navigue vers la page choisie)
*/
(function () {
  const sidebar  = document.getElementById('wt-sidebar-drawer');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const toggles  = document.querySelectorAll('[data-sidebar-toggle]');
  const closers  = document.querySelectorAll('[data-sidebar-close]');
  if (!sidebar || !backdrop || !toggles.length) return;

  let savedScrollY = 0;

  function lockBodyScroll() {
    savedScrollY = window.scrollY || window.pageYOffset || 0;
    document.body.style.position = 'fixed';
    document.body.style.top = '-' + savedScrollY + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.classList.add('has-sidebar-open');
  }

  function unlockBodyScroll() {
    document.body.classList.remove('has-sidebar-open');
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    window.scrollTo(0, savedScrollY);
  }

  function setOpen(open) {
    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-open', open);
    sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
    if (open) lockBodyScroll();
    else      unlockBodyScroll();
    toggles.forEach((t) => {
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  toggles.forEach((t) => t.addEventListener('click', (e) => {
    e.preventDefault();
    setOpen(!sidebar.classList.contains('is-open'));
  }));
  closers.forEach((c) => c.addEventListener('click', () => setOpen(false)));
  backdrop.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false);
  });

  // Ferme automatiquement quand on clique sur un lien interne de la sidebar
  // (sauf liens externes ou avec target="_blank")
  sidebar.querySelectorAll('a[href]').forEach((link) => {
    link.addEventListener('click', () => {
      const isExternal = link.hostname && link.hostname !== window.location.hostname;
      const opensNewTab = link.target === '_blank';
      if (!isExternal && !opensNewTab) {
        setOpen(false);
      }
    });
  });

  // Si l'utilisateur redimensionne la fenêtre au-dessus du breakpoint
  // mobile (par ex. rotation tablette en paysage), on ferme le drawer.
  const mq = window.matchMedia('(min-width: 960px)');
  function handleResize(e) {
    if (e.matches && sidebar.classList.contains('is-open')) {
      setOpen(false);
    }
  }
  if (mq.addEventListener) mq.addEventListener('change', handleResize);
  else if (mq.addListener) mq.addListener(handleResize);
})();

/* --- 3) FAQ accordion (smooth) ---------------------------------------- */
(function () {
  document.querySelectorAll('[data-faq-item]').forEach((item) => {
    // <details> est natif — on ajoute une animation max-height sur le contenu.
    const body = item.querySelector('.wt-faq__a');
    if (!body) return;
    item.addEventListener('toggle', () => {
      if (item.open) {
        body.style.maxHeight = body.scrollHeight + 'px';
      } else {
        body.style.maxHeight = '0';
      }
    });
  });
})();

/* --- 4) Bulk delete (messages & notifs) ------------------------------ */
(function () {
  const toolbars = document.querySelectorAll('[data-bulk-toolbar]');
  if (!toolbars.length) return;

  toolbars.forEach((bar) => {
    const main      = bar.closest('main') || document;
    const items     = () => Array.from(main.querySelectorAll('[data-bulk-item]'));
    const toggleAll = bar.querySelector('[data-bulk-toggle-all]');
    const btn       = bar.querySelector('[data-bulk-delete]');
    if (!btn) return;

    if (toggleAll) {
      toggleAll.addEventListener('change', () => {
        items().forEach((i) => { i.checked = toggleAll.checked; });
      });
    }

    btn.addEventListener('click', async () => {
      const ids = items().filter((i) => i.checked).map((i) => i.value);
      if (!ids.length) return;

      if (!confirm(btn.getAttribute('data-confirm') || 'Supprimer la sélection ?')) return;

      const fd = new FormData();
      fd.append('_csrf', btn.getAttribute('data-csrf') || '');
      ids.forEach((id) => fd.append('ids[]', id));

      btn.disabled = true;
      try {
        const r = await fetch(btn.getAttribute('data-endpoint'), {
          method: 'POST', body: fd, credentials: 'same-origin'
        });
        const data = await r.json().catch(() => ({ ok: false }));
        if (data.ok) {
          // Retire visuellement les lignes
          ids.forEach((id) => {
            const row = main.querySelector('[data-msg-id="' + id + '"]');
            if (row) row.remove();
          });
        } else {
          alert(data.error || 'Erreur');
        }
      } catch (e) {
        alert('Erreur réseau');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();

/* --- 5) Ouvrir un message → marquer lu --------------------------------- */
(function () {
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const base = (document.querySelector('meta[name="wt-base"]')   || {}).content || '';
  document.querySelectorAll('[data-msg-toggle]').forEach((d) => {
    d.addEventListener('toggle', async () => {
      if (!d.open) return;
      const id = d.getAttribute('data-id');
      if (!id) return;
      const row = d.closest('.wt-msglist__item');
      if (!row || !row.classList.contains('is-unread')) return;
      const fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('id', id);
      try {
        await fetch(base + '/api/message_read.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        row.classList.remove('is-unread');
        row.classList.add('is-read');
        const dot = row.querySelector('.wt-msglist__dot');
        if (dot) dot.remove();
      } catch (e) { /* silencieux */ }
    });
  });
})();

/* --- 6) Polling léger des badges (toutes les 60 s) -------------------- */
(function () {
  const authed = (document.querySelector('meta[name="wt-authed"]') || {}).content === '1';
  if (!authed) return;
  const base   = (document.querySelector('meta[name="wt-base"]') || {}).content || '';

  function setBadge(selector, count) {
    const node = document.querySelector(selector);
    if (!node) return;
    const badge = node.querySelector('.wt-pill-badge');
    if (count > 0) {
      const txt = count > 9 ? '9+' : String(count);
      if (badge) {
        badge.textContent = txt;
      } else {
        const span = document.createElement('span');
        span.className = 'wt-pill-badge';
        span.textContent = txt;
        node.appendChild(span);
      }
      node.style.display = '';
    } else if (selector.includes('msg-envelope')) {
      // L'enveloppe disparaît à 0
      node.style.display = 'none';
    }
  }

  function setPing(selector, count) {
    const node = document.querySelector(selector);
    if (!node) return;
    let ping = node.querySelector('.wt-ping');
    if (count > 0 && !ping) {
      ping = document.createElement('span');
      ping.className = 'wt-ping';
      node.appendChild(ping);
    } else if (count <= 0 && ping) {
      ping.remove();
    }
  }

  async function refresh() {
    try {
      const r = await fetch(base + '/api/badges.php', { credentials: 'same-origin' });
      const d = await r.json();
      if (!d || !d.ok) return;
      setBadge('[data-msg-envelope]', d.messages || 0);
      setPing('[data-notif-bell]',    d.notifications || 0);
    } catch (e) { /* silencieux */ }
  }

  // Premier rafraîchissement après 30 s, puis toutes les 60 s
  setTimeout(refresh, 30000);
  setInterval(refresh, 60000);
})();

/* =====================================================================
 *  V5 — Confettis discrets sur le podium du leaderboard
 *  Canvas léger (50 particules max), animation rAF avec ralentissement
 *  progressif puis arrêt après 6 s pour ne pas peser sur la batterie.
 * ===================================================================== */
(function () {
  const canvas = document.querySelector('[data-confetti]');
  if (!canvas) return;
  if (matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const ctx = canvas.getContext('2d');
  const DPR = window.devicePixelRatio || 1;

  function resize() {
    const r = canvas.getBoundingClientRect();
    canvas.width  = Math.floor(r.width  * DPR);
    canvas.height = Math.floor(r.height * DPR);
  }
  resize();
  window.addEventListener('resize', resize);

  const colors = ['#facc15', '#3b82f6', '#06b6d4', '#f59e0b', '#22c55e'];
  const N = 48;
  const parts = [];
  for (let i = 0; i < N; i++) {
    parts.push({
      x: Math.random() * canvas.width,
      y: -Math.random() * canvas.height * 0.5,
      vy: (1 + Math.random() * 2) * DPR,
      vx: (Math.random() - 0.5) * 1 * DPR,
      r:  (3 + Math.random() * 4) * DPR,
      rot: Math.random() * Math.PI * 2,
      vr:  (Math.random() - 0.5) * 0.1,
      c: colors[(Math.random() * colors.length) | 0],
      shape: Math.random() > 0.5 ? 'rect' : 'circle',
    });
  }

  const start = performance.now();
  const duration = 6000; // 6 s puis on stoppe

  function frame(t) {
    const elapsed = t - start;
    const fade = Math.max(0, 1 - elapsed / duration);
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    parts.forEach((p) => {
      p.x   += p.vx;
      p.y   += p.vy * fade;
      p.rot += p.vr;

      if (p.y > canvas.height) {
        p.y = -10;
        p.x = Math.random() * canvas.width;
      }

      ctx.save();
      ctx.globalAlpha = 0.55 * fade;
      ctx.translate(p.x, p.y);
      ctx.rotate(p.rot);
      ctx.fillStyle = p.c;
      if (p.shape === 'rect') {
        ctx.fillRect(-p.r, -p.r * 0.4, p.r * 2, p.r * 0.8);
      } else {
        ctx.beginPath();
        ctx.arc(0, 0, p.r, 0, Math.PI * 2);
        ctx.fill();
      }
      ctx.restore();
    });

    if (elapsed < duration) {
      requestAnimationFrame(frame);
    } else {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
  }
  requestAnimationFrame(frame);
})();

/* =====================================================================
 *  V6 — Bannière de cookies RGPD/CNIL
 *  • Affiche le bandeau si aucun consentement enregistré (cookie wt_consent absent)
 *  • 3 boutons : Tout accepter, Refuser, Préférences
 *  • Stocke 'all' | 'essential' | 'custom:...' dans wt_consent (max 6 mois)
 *  • Bouton [data-cookie-reopen] sur /legal/cookies.php → ré-affiche le bandeau
 * ===================================================================== */
(function () {
  'use strict';

  const COOKIE_NAME = 'wt_consent';
  const COOKIE_MAX_AGE = 60 * 60 * 24 * 180; // 180 jours (6 mois)

  function readCookie(name) {
    const m = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return m ? decodeURIComponent(m[2]) : null;
  }
  function writeCookie(name, value) {
    const sec = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie =
      name + '=' + encodeURIComponent(value) +
      '; Max-Age=' + COOKIE_MAX_AGE +
      '; Path=/; SameSite=Lax' + sec;
  }

  function showBanner() {
    if (document.getElementById('wt-cookie-banner')) return;
    const el = document.createElement('div');
    el.id = 'wt-cookie-banner';
    el.className = 'wt-cookie-banner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-labelledby', 'wt-cookie-title');
    el.setAttribute('aria-describedby', 'wt-cookie-desc');
    el.innerHTML = `
      <div class="wt-cookie-banner__inner">
        <div class="wt-cookie-banner__icon" aria-hidden="true">🍪</div>
        <div class="wt-cookie-banner__body">
          <h2 id="wt-cookie-title" class="wt-cookie-banner__title">${window.WT_I18N?.cookie_title || 'Vos cookies, votre choix'}</h2>
          <p id="wt-cookie-desc" class="wt-cookie-banner__desc">${window.WT_I18N?.cookie_desc || 'Nous utilisons des cookies essentiels pour faire fonctionner le site et, avec votre accord, des cookies de mesure d\u2019audience et de publicité. Vous pouvez accepter, refuser ou personnaliser.'}</p>
          <p class="wt-cookie-banner__links">
            <a href="${window.WT_BASE || ''}/legal/cookies.php">${window.WT_I18N?.cookie_learn || 'En savoir plus'}</a>
            ·
            <a href="${window.WT_BASE || ''}/legal/privacy.php">${window.WT_I18N?.cookie_privacy || 'Confidentialité'}</a>
          </p>
        </div>
        <div class="wt-cookie-banner__actions">
          <button type="button" class="wt-btn wt-btn--ghost"   data-consent="essential">${window.WT_I18N?.cookie_refuse || 'Refuser'}</button>
          <button type="button" class="wt-btn wt-btn--ghost"   data-consent-prefs>${window.WT_I18N?.cookie_prefs || 'Préférences'}</button>
          <button type="button" class="wt-btn wt-btn--primary" data-consent="all">${window.WT_I18N?.cookie_accept || 'Tout accepter'}</button>
        </div>
      </div>
    `;
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-visible'));

    el.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-consent]');
      if (btn) {
        accept(btn.dataset.consent);
        return;
      }
      if (e.target.closest('[data-consent-prefs]')) {
        showPrefs();
      }
    });
  }

  function showPrefs() {
    closeBanner();
    const el = document.createElement('div');
    el.id = 'wt-cookie-prefs';
    el.className = 'wt-modal';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = `
      <div class="wt-modal__backdrop" data-close></div>
      <div class="wt-modal__panel" role="document">
        <header class="wt-modal__header">
          <h2>${window.WT_I18N?.cookie_prefs_title || 'Préférences de cookies'}</h2>
          <button type="button" class="wt-modal__close" data-close aria-label="Close">×</button>
        </header>
        <div class="wt-modal__body">
          <label class="wt-cookie-toggle">
            <input type="checkbox" checked disabled>
            <strong>${window.WT_I18N?.cookie_cat_essential || 'Essentiels'}</strong>
            <span class="wt-muted">${window.WT_I18N?.cookie_cat_essential_d || 'Indispensables au fonctionnement (session, langue, thème).'}</span>
          </label>
          <label class="wt-cookie-toggle">
            <input type="checkbox" data-pref="analytics">
            <strong>${window.WT_I18N?.cookie_cat_analytics || 'Mesure d\u2019audience'}</strong>
            <span class="wt-muted">${window.WT_I18N?.cookie_cat_analytics_d || 'Statistiques anonymisées pour améliorer le site.'}</span>
          </label>
          <label class="wt-cookie-toggle">
            <input type="checkbox" data-pref="ads">
            <strong>${window.WT_I18N?.cookie_cat_ads || 'Publicité'}</strong>
            <span class="wt-muted">${window.WT_I18N?.cookie_cat_ads_d || 'Annonces personnalisées via AdSense et partenaires.'}</span>
          </label>
        </div>
        <footer class="wt-modal__footer">
          <button type="button" class="wt-btn wt-btn--ghost"   data-close>${window.WT_I18N?.common_cancel || 'Annuler'}</button>
          <button type="button" class="wt-btn wt-btn--primary" data-save-prefs>${window.WT_I18N?.cookie_save || 'Enregistrer'}</button>
        </footer>
      </div>
    `;
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-visible'));

    el.addEventListener('click', (e) => {
      if (e.target.closest('[data-close]')) closePrefs();
      if (e.target.closest('[data-save-prefs]')) {
        const flags = [];
        el.querySelectorAll('[data-pref]:checked').forEach(c => flags.push(c.dataset.pref));
        if (flags.length === 0) accept('essential');
        else if (flags.length === 2) accept('all');
        else accept('custom:' + flags.join(','));
      }
    });
    document.addEventListener('keydown', escClose);

    function escClose(e) { if (e.key === 'Escape') closePrefs(); }
    function closePrefs() {
      document.removeEventListener('keydown', escClose);
      el.classList.remove('is-visible');
      setTimeout(() => el.remove(), 250);
    }
  }

  function accept(value) {
    writeCookie(COOKIE_NAME, value);
    closeBanner();
    closeAllModals();
    document.dispatchEvent(new CustomEvent('wt:consent', { detail: { value } }));
  }
  function closeBanner() {
    const el = document.getElementById('wt-cookie-banner');
    if (!el) return;
    el.classList.remove('is-visible');
    setTimeout(() => el.remove(), 250);
  }
  function closeAllModals() {
    const m = document.getElementById('wt-cookie-prefs');
    if (m) { m.classList.remove('is-visible'); setTimeout(() => m.remove(), 250); }
  }

  // Init
  document.addEventListener('DOMContentLoaded', () => {
    if (!readCookie(COOKIE_NAME)) {
      // Délai léger pour ne pas concurrencer le preloader
      setTimeout(showBanner, 700);
    }
    // Bouton "Préférences" sur /legal/cookies.php
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-cookie-reopen]')) {
        e.preventDefault();
        showBanner();
      }
    });
  });
})();

/* =====================================================================
 *  V7 — Handler /dashboard/settings : toggles temps réel
 *  Branché sur [data-settings-toggle][data-key="..."].
 *  POST vers /api/settings_toggle.php ; revert + toast si erreur ;
 *  reload si la réponse contient reload:true (changement langue/thème).
 * ===================================================================== */
(function () {
  'use strict';
  document.addEventListener('change', async (e) => {
    const el = e.target.closest('[data-settings-toggle]');
    if (!el) return;
    const key   = el.dataset.key;
    const value = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
    const prev  = (el.type === 'checkbox') ? !el.checked : el.dataset.prevValue;

    const base = window.WT_BASE || '';
    const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    try {
      el.disabled = true;
      const fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('key',   key);
      fd.append('value', value);
      const r = await fetch(base + '/api/settings_toggle.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const data = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));

      if (data && data.ok) {
        if (window.WT && window.WT.toast) window.WT.toast(data.message || 'Sauvegardé', 'ok', 1500);
        if (data.reload) setTimeout(() => window.location.reload(), 700);
      } else {
        if (el.type === 'checkbox') el.checked = prev;
        else el.value = prev;
        if (window.WT && window.WT.toast) window.WT.toast(data.error || 'Erreur', 'err', 2500);
      }
    } catch (err) {
      if (el.type === 'checkbox') el.checked = prev;
      else el.value = prev;
      if (window.WT && window.WT.toast) window.WT.toast('Erreur réseau', 'err', 2500);
    } finally {
      el.disabled = false;
    }
  });
})();

/* =====================================================================
 *  V7 — Handler /admin/security : toggles de configuration
 *  Branché sur [data-admin-config][data-key="..."].
 *  POST vers /api/admin_config_set.php.
 *  Sur change pour les checkbox + select, sur blur pour les inputs text
 *  (évite de hammerer l'API à chaque frappe).
 * ===================================================================== */
(function () {
  'use strict';
  async function sendUpdate(el) {
    const key   = el.dataset.key;
    const value = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
    const base  = window.WT_BASE || '';
    const csrf  = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    try {
      el.disabled = true;
      const fd = new FormData();
      fd.append('_csrf', csrf);
      fd.append('key',   key);
      fd.append('value', value);
      const r = await fetch(base + '/api/admin_config_set.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
      });
      const data = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide' }));
      if (window.WT && window.WT.toast) {
        window.WT.toast(data.ok ? (data.message || 'OK') : (data.error || 'Erreur'),
                        data.ok ? 'ok' : 'err', 1800);
      }
    } catch (err) {
      if (window.WT && window.WT.toast) window.WT.toast('Erreur réseau', 'err', 2500);
    } finally {
      el.disabled = false;
    }
  }

  // Checkbox et select : sur change
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-admin-config]');
    if (!el) return;
    if (el.type === 'text' || el.type === 'number') return; // gérés par 'blur'
    sendUpdate(el);
  });
  // Inputs text / number : sur blur
  document.addEventListener('blur', (e) => {
    const el = e.target.closest('[data-admin-config]');
    if (!el) return;
    if (el.type !== 'text' && el.type !== 'number') return;
    sendUpdate(el);
  }, true); // capture pour les blur (qui ne bullent pas)
})();

/* =====================================================================
 *  V8 — Hero sidebar Live Withdrawals
 *
 *  Auto-refresh du panneau "Derniers retraits" toutes les 30s.
 *  - Pause quand l'onglet n'est pas visible (visibilitychange)
 *  - Reprend au focus, avec un fetch immédiat
 *  - Diffe les ids retournés pour ne ré-animer que les nouveaux items
 *  - N'affiche jamais une régression (ancien snapshot remplaçant le neuf)
 * ===================================================================== */
(function () {
  'use strict';
  const list   = document.querySelector('[data-withdraw-list]');
  if (!list) return; // pas sur la home, ou sidebar masquée

  const REFRESH_MS = 30000;
  let timer       = null;
  let lastFingerprint = '';

  function fingerprint(items) {
    // Empreinte ordonnée des 10 items pour détecter un changement
    return items.map(i => `${i.at}|${i.name}|${i.amount}`).join('::');
  }

  function timeAgoFR(isoUtc) {
    const t = new Date(isoUtc);
    const diff = Math.max(0, (Date.now() - t.getTime()) / 1000);
    if (diff < 60)    return `il y a ${Math.floor(diff)} s`;
    if (diff < 3600)  return `il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `il y a ${Math.floor(diff / 3600)} h`;
    return t.toLocaleDateString();
  }

  function render(items) {
    if (!items || !items.length) return; // garde le snapshot précédent

    const fp = fingerprint(items);
    if (fp === lastFingerprint) return;  // rien de neuf, no-op
    lastFingerprint = fp;

    // On remplace en bloc — l'animation .wt-hero__withdraw-item se rejoue
    list.innerHTML = items.map((it, i) => `
      <li class="wt-hero__withdraw-item" style="--idx:${i}">
        <div class="wt-avatar wt-avatar--xs" aria-hidden="true">${escapeHtml(it.initials)}</div>
        <div class="wt-hero__withdraw-info">
          <strong>${escapeHtml(it.name)}</strong>
          <small class="wt-muted">${escapeHtml(it.method)} · ${escapeHtml(timeAgoFR(it.at))}</small>
        </div>
        <span class="wt-hero__withdraw-amount">+${escapeHtml(it.amount)} €</span>
      </li>
    `).join('');
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }

  async function fetchOnce() {
    try {
      const base = window.WT_BASE || '';
      const r = await fetch(base + '/api/home_live_withdrawals.php', {
        credentials: 'same-origin',
      });
      if (!r.ok) return;
      const data = await r.json();
      if (data && data.ok && Array.isArray(data.items)) render(data.items);
    } catch (_) { /* silencieux : conserve le snapshot précédent */ }
  }

  function start() {
    if (timer) return;
    timer = setInterval(fetchOnce, REFRESH_MS);
  }
  function stop() {
    if (!timer) return;
    clearInterval(timer);
    timer = null;
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { stop(); }
    else                 { fetchOnce(); start(); }
  });

  // Initialisation : le HTML serveur est déjà rendu — on attend 30s
  // avant le premier fetch pour ne pas re-render inutilement.
  start();
})();

/* =====================================================================
 *  V8 — Activity Feed v2 — couleurs avatar par hash + auto-refresh
 *
 *  1) Au chargement : colore tous les avatars [data-hash-color] avec une
 *     couleur HSL déterministe (même pseudo = même couleur partout sur
 *     le site).
 *  2) Refresh : toutes les 30s, fetch /api/home_feed.php, diff sur
 *     `at`+`name` pour détecter les nouveaux items, prepend en haut
 *     avec animation .is-new, retire l'item le plus ancien si plus de 6.
 *  3) Pause au blur d'onglet (visibilitychange).
 * ===================================================================== */
(function () {
  'use strict';

  /* ---- 1) Coloration déterministe des avatars par hash de username --- */
  function hashHue(str) {
    // FNV-1a 32-bit simplifié → entier stable, modulo 360 = teinte HSL
    let h = 2166136261 >>> 0;
    for (let i = 0; i < str.length; i++) {
      h ^= str.charCodeAt(i);
      h = Math.imul(h, 16777619) >>> 0;
    }
    return h % 360;
  }
  function paintAvatar(el) {
    const seed = el.getAttribute('data-hash-color');
    if (!seed) return;
    const hue = hashHue(seed);
    const isDark = document.documentElement.classList.contains('dark');
    // Saturation + lumière calées pour rester lisibles dans les 2 thèmes
    const bg = isDark
      ? `hsl(${hue}, 55%, 22%)`   // bg foncé, ton coloré subtil
      : `hsl(${hue}, 65%, 90%)`;  // bg clair pastel
    const fg = isDark
      ? `hsl(${hue}, 80%, 75%)`   // texte clair vif
      : `hsl(${hue}, 70%, 30%)`;  // texte foncé contrasté
    el.style.setProperty('--avatar-bg', bg);
    el.style.setProperty('--avatar-fg', fg);
  }
  document.querySelectorAll('[data-hash-color]').forEach(paintAvatar);

  /* ---- 2) Auto-refresh du feed -------------------------------------- */
  const list = document.querySelector('[data-feed-list]');
  if (!list) return;

  const REFRESH_MS = 30000;
  const MAX_ITEMS  = 6;
  let timer = null;
  let knownKeys = new Set();

  // Snapshot initial : indexer les items déjà rendus côté serveur
  list.querySelectorAll('.wt-feed-v2__item').forEach(item => {
    const user = item.dataset.user || '';
    const time = item.querySelector('time')?.dataset.utc || '';
    knownKeys.add(user + '|' + time);
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  }
  function timeAgo(isoUtc) {
    const t = new Date(isoUtc);
    const diff = Math.max(0, (Date.now() - t.getTime()) / 1000);
    const lang = document.documentElement.lang || 'fr';
    const ago  = (n, unit) => lang === 'en'
      ? `${n} ${unit} ago`
      : `il y a ${n} ${unit}`;
    if (diff < 60)    return lang === 'en' ? `${Math.floor(diff)}s ago` : `il y a ${Math.floor(diff)}s`;
    if (diff < 3600)  return ago(Math.floor(diff / 60),   lang === 'en' ? 'min' : 'min');
    if (diff < 86400) return ago(Math.floor(diff / 3600), 'h');
    return t.toLocaleDateString();
  }

  /* Mapping type → SVG path (synchro avec PHP) */
  const ICONS = {
    faucet:    'M12 2v6m0 0 4-4m-4 4-4-4M5 12a7 7 0 1 0 14 0M5 12H2m20 0h-3',
    shortlink: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71',
    ptc:       'M2 7v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2zm8 3 5 3-5 3z',
    offerwall: 'M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    referral:  'M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 11A4 4 0 1 0 8.5 3a4 4 0 0 0 0 8zm9-3v6m3-3h-6',
    bonus:     'M20 12V8H6a2 2 0 0 1 0-4h12v4M4 6v12c0 1.1.9 2 2 2h14v-4M18 12a2 2 0 0 0 0 4h4v-4Z',
  };
  const COIN_LABEL = (document.documentElement.lang === 'en') ? 'Coins' : 'Coins';

  function makeItem(it) {
    const li = document.createElement('li');
    li.className = 'wt-feed-v2__item is-new';
    li.style.setProperty('--idx', '0');
    li.dataset.user = it.rawName || it.name;

    const icon = ICONS[it.type] || 'M12 2v20m-10-10h20';
    li.innerHTML = `
      <div class="wt-feed-v2__avatar wt-avatar wt-avatar--xs"
           data-hash-color="${escapeHtml(it.rawName || it.name)}"
           aria-hidden="true">${escapeHtml(it.initials)}</div>
      <div class="wt-feed-v2__body">
        <p class="wt-feed-v2__line">
          <strong>${escapeHtml(it.name)}</strong>
          <span class="wt-feed-v2__verb">${escapeHtml(it.verb)}</span>
          <span class="wt-feed-v2__coins wt-feed-v2__coins--${escapeHtml(it.type)}">
            +${escapeHtml(it.amount)} <small>${COIN_LABEL}</small>
          </span>
        </p>
        <small class="wt-feed-v2__meta">
          <span class="wt-feed-v2__type-icon wt-feed-v2__type-icon--${escapeHtml(it.type)}" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="${icon}"/></svg>
          </span>
          <span>${escapeHtml(it.type.charAt(0).toUpperCase() + it.type.slice(1))}</span>
          <span class="wt-feed-v2__sep" aria-hidden="true">·</span>
          <time data-fmt-time data-utc="${escapeHtml(it.at)}" data-format="relative">${escapeHtml(timeAgo(it.at))}</time>
        </small>
      </div>
    `;
    paintAvatar(li.querySelector('[data-hash-color]'));
    return li;
  }

  async function fetchOnce() {
    try {
      const base = window.WT_BASE || '';
      const r = await fetch(base + '/api/home_feed.php', { credentials: 'same-origin' });
      if (!r.ok) return;
      const data = await r.json();
      if (!data || !data.ok || !Array.isArray(data.items)) return;

      // Détecte les nouveaux items
      const newOnes = data.items.filter(it => {
        const key = (it.rawName || it.name) + '|' + it.at;
        return !knownKeys.has(key);
      });
      if (newOnes.length === 0) return;

      // Prepend du plus ancien au plus récent (= le plus récent finit en haut)
      newOnes.reverse().forEach(it => {
        const li = makeItem(it);
        list.prepend(li);
        knownKeys.add((it.rawName || it.name) + '|' + it.at);
        // Trim : on garde MAX_ITEMS éléments
        while (list.children.length > MAX_ITEMS) {
          list.removeChild(list.lastElementChild);
        }
        // Nettoie .is-new après l'anim flash pour ne pas re-trigger
        setTimeout(() => li.classList.remove('is-new'), 1000);
      });
    } catch (_) { /* silencieux */ }
  }

  function start() { if (!timer) timer = setInterval(fetchOnce, REFRESH_MS); }
  function stop()  { if (timer) { clearInterval(timer); timer = null; } }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else { fetchOnce(); start(); }
  });

  start();
})();

/* =====================================================================
 *  V8 — Trust bar sticky : reveal après le hero
 *
 *  Utilise un IntersectionObserver sur le hero : tant qu'il est visible,
 *  la trust bar reste cachée. Dès qu'il sort du viewport (scroll vers le
 *  bas), la trust bar se révèle. Inversement, elle se cache quand on
 *  remonte au hero.
 * ===================================================================== */
(function () {
  'use strict';
  const bar  = document.querySelector('[data-trustbar]');
  const hero = document.querySelector('.wt-hero');
  if (!bar || !hero) return;

  /* Pas d'IntersectionObserver disponible (vieux navigateur) ? On affiche
   * la barre dès qu'on scroll de plus de 400px. Fallback simple et sûr. */
  if (!('IntersectionObserver' in window)) {
    const onScroll = () => {
      bar.classList.toggle('is-visible', window.scrollY > 400);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    return;
  }

  const io = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      // Le hero est encore (au moins partiellement) visible → cacher la bar
      // Inversement quand il sort entièrement, on affiche
      bar.classList.toggle('is-visible', !entry.isIntersecting);
    });
  }, {
    root: null,
    threshold: 0,
    // On laisse une marge de -80px pour que la bar n'apparaisse pas trop tôt
    rootMargin: '-80px 0px 0px 0px',
  });
  io.observe(hero);
})();

/* =====================================================================
 *  V8 — FAQ accordion : une seule réponse ouverte à la fois
 *
 *  Quand une <details> s'ouvre, on referme automatiquement toutes les
 *  autres dans le même groupe [data-faq-list].
 * ===================================================================== */
(function () {
  'use strict';
  document.querySelectorAll('[data-faq-list]').forEach(group => {
    const items = group.querySelectorAll('details.wt-faq__item');
    items.forEach(detail => {
      detail.addEventListener('toggle', () => {
        if (detail.open) {
          items.forEach(other => {
            if (other !== detail && other.open) other.open = false;
          });
        }
      });
    });
  });
})();

/* =====================================================================
 *  V8 — Shortlinks gateway countdown
 *
 *  Pilote :
 *   - [data-sl-gateway-num]  : nombre central
 *   - [data-sl-gateway-bar]  : cercle SVG circular progress
 *   - [data-sl-gateway-go]   : bouton à révéler à la fin
 *
 *  Remplace l'ancien JS inline pour avoir une UX cohérente avec le
 *  faucet transition (cercle + nombre en dégradé).
 * ===================================================================== */
(function () {
  'use strict';
  const cont = document.querySelector('[data-sl-gateway-count]');
  if (!cont) return;

  const numEl = cont.querySelector('[data-sl-gateway-num]');
  const barEl = cont.querySelector('[data-sl-gateway-bar]');
  const btn   = document.querySelector('[data-sl-gateway-go]');
  if (!numEl || !btn) return;

  const total = parseInt(cont.getAttribute('data-seconds'), 10) || 10;
  const CIRC  = 326.7; // 2π × 52
  let secs    = total;

  function paint() {
    numEl.textContent = Math.max(0, secs);
    if (barEl) {
      const ratio = Math.max(0, secs / total);
      barEl.style.strokeDashoffset = (CIRC * (1 - ratio)).toFixed(2);
    }
  }
  paint();

  const itv = setInterval(() => {
    secs--;
    paint();
    if (secs <= 0) {
      clearInterval(itv);
      // Fin du countdown : on récupère l'URL finale par Ajax (elle n'est
      // jamais dans le DOM initial, pour empêcher tout bypass anti-pub).
      revealAndFetch();
    }
  }, 1000);

  /*
   * Récupère l'URL de redirection finale via /api/get_gateway_link.php,
   * puis révèle le bouton. L'URL (avec le token de transaction) ne touche
   * le DOM qu'à cet instant, à la demande de l'utilisateur réel.
   */
  function revealAndFetch() {
    const endpoint = btn.getAttribute('data-sl-endpoint');
    const token    = btn.getAttribute('data-sl-token');
    const csrf     = btn.getAttribute('data-csrf');
    if (!endpoint || !token) { return; }

    const body = new FormData();
    body.append('token', token);
    body.append('_csrf', csrf || '');

    // État "chargement" du bouton pendant la requête
    btn.classList.remove('is-hidden');
    btn.removeAttribute('aria-hidden');
    btn.disabled = true;
    const originalLabel = btn.textContent;
    btn.textContent = '⏳';

    fetch(endpoint, {
      method: 'POST',
      body: body,
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok && data.url) {
          // On stocke l'URL sur le bouton et on l'active
          btn.dataset.url = data.url;
          btn.disabled = false;
          btn.textContent = originalLabel;
          try { btn.focus({ preventScroll: true }); } catch (_) {}
          // Au clic : redirection vers l'URL fraîchement récupérée
          btn.addEventListener('click', function () {
            window.location.href = btn.dataset.url;
          });
        } else {
          btn.textContent = '⚠';
          btn.disabled = true;
        }
      })
      .catch(function () {
        btn.textContent = '⚠';
        btn.disabled = true;
      });
  }
})();

/* =====================================================================
 *  V8 — Testimonials : compteur de caractères en live
 *
 *  Branche le textarea [data-testi-body] sur le compteur
 *  [data-testi-counter]. Change la couleur du compteur quand on
 *  approche la limite (1800+) ou la dépasse (2000+).
 * ===================================================================== */
(function () {
  'use strict';
  const ta = document.querySelector('[data-testi-body]');
  const c  = document.querySelector('[data-testi-counter]');
  if (!ta || !c) return;

  const MAX  = parseInt(ta.getAttribute('maxlength'), 10) || 2000;
  const WARN = Math.floor(MAX * 0.9);

  function update() {
    const len = ta.value.length;
    c.textContent = String(len);
    const parent = c.closest('.wt-testi-v2__counter');
    if (parent) {
      parent.dataset.warn = (len >= WARN && len <= MAX) ? 'true' : 'false';
      parent.dataset.over = len > MAX ? 'true' : 'false';
    }
  }
  ta.addEventListener('input', update);
  update();
})();

/* =====================================================================
 *  V8 — /help/faq.php : recherche live + bouton clear + auto-anchor
 *
 *  Filtre les <details data-faq-item> par contenu de la question +
 *  réponse. Cache aussi les sections vides après filtrage. Ouvre
 *  automatiquement la question correspondant au #hash de l'URL.
 * ===================================================================== */
(function () {
  'use strict';
  const search   = document.querySelector('[data-faq-search]');
  if (!search) return;

  const clearBtn = document.querySelector('[data-faq-clear]');
  const countEl  = document.querySelector('[data-faq-count]');
  const empty    = document.querySelector('[data-faq-empty]');
  const items    = Array.from(document.querySelectorAll('[data-faq-item-wrap]'));
  const sections = Array.from(document.querySelectorAll('[data-faq-section]'));

  function normalize(s) {
    return (s || '')
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, ''); // strip accents
  }

  function filter(q) {
    const needle = normalize(q.trim());
    let visible = 0;

    items.forEach(wrap => {
      const details = wrap.querySelector('[data-faq-item]');
      const text = normalize(details.textContent);
      const match = needle === '' || text.includes(needle);
      wrap.classList.toggle('is-hidden', !match);
      if (match) visible++;
    });

    // Cache les sections sans aucun item visible
    sections.forEach(sec => {
      const has = sec.querySelector('[data-faq-item-wrap]:not(.is-hidden)');
      sec.classList.toggle('is-hidden', !has);
    });

    // Compteur + empty state
    if (needle === '') {
      if (countEl) countEl.textContent = '';
      if (empty)   empty.classList.add('is-hidden');
    } else {
      if (countEl) countEl.textContent = visible + ' résultat' + (visible > 1 ? 's' : '');
      if (empty)   empty.classList.toggle('is-hidden', visible > 0);
    }

    if (clearBtn) clearBtn.classList.toggle('is-hidden', needle === '');
  }

  search.addEventListener('input', () => filter(search.value));

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      search.value = '';
      filter('');
      search.focus();
    });
  }

  // Auto-anchor : ouvre la question ciblée par le #hash
  function openAnchor() {
    const h = location.hash;
    if (!h || h.length < 4) return;
    const t = document.querySelector(h);
    if (t && t.matches('[data-faq-item]')) {
      t.open = true;
      setTimeout(() => t.scrollIntoView({ behavior: 'smooth', block: 'start' }), 60);
    }
  }

  window.addEventListener('hashchange', openAnchor);
  document.addEventListener('DOMContentLoaded', openAnchor);
  if (document.readyState !== 'loading') openAnchor();

  // Si on arrive depuis le hub avec ?q=xxx, filtre direct
  if (search.value.trim()) filter(search.value);
})();

/* =====================================================================
 *  V8 — /help/contact.php : compteur de caractères du message
 * ===================================================================== */
(function () {
  'use strict';
  const ta = document.querySelector('[data-contact-body]');
  const c  = document.querySelector('[data-contact-counter]');
  if (!ta || !c) return;
  const MAX = parseInt(ta.getAttribute('maxlength'), 10) || 5000;

  function update() {
    c.textContent = String(ta.value.length);
    const parent = c.closest('.wt-contact-v2__counter');
    if (parent) {
      parent.style.color = ta.value.length > MAX * 0.9
        ? 'var(--wt-accent2-hot, #f59e0b)'
        : '';
    }
  }
  ta.addEventListener('input', update);
  update();
})();

/* =====================================================================
 *  Shortlinks — Toast de callback + nettoyage d'URL (déplacé inline → JS)
 *
 *  Conformité CSP : ce comportement était auparavant un <script> inline
 *  dans tasks/shortlinks/index.php. Il est maintenant ici, déclenché par
 *  la présence de [data-sl-callback-toast].
 *
 *  - Nettoie l'URL (retire ?success=...&msg=...&credited=...) sans recharger
 *    ni ajouter d'entrée d'historique (replaceState).
 *  - Fait disparaître le toast après 6 s avec une transition douce.
 * ===================================================================== */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var toast = document.querySelector('[data-sl-callback-toast]');
    if (!toast) { return; }

    // 1) Nettoie l'URL immédiatement (pas de rechargement)
    if (window.history && window.history.replaceState) {
      window.history.replaceState({}, document.title, window.location.pathname);
    }

    // 2) Fait disparaître le toast après 6 s
    setTimeout(function () {
      toast.style.transition = 'opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease';
      toast.style.opacity = '0';
      toast.style.maxHeight = toast.offsetHeight + 'px';
      void toast.offsetHeight; // reflow pour lancer la transition
      toast.style.maxHeight = '0';
      toast.style.marginTop = '0';
      toast.style.marginBottom = '0';
      toast.style.paddingTop = '0';
      toast.style.paddingBottom = '0';
      toast.style.overflow = 'hidden';
      setTimeout(function () { toast.remove(); }, 500);
    }, 6000);
  });
})();

/* =====================================================================
 *  Admin — Confirmation avant diffusion de masse (broadcast)
 *
 *  Sur le formulaire [data-broadcast-form], si la cible sélectionnée est
 *  un envoi de masse ([data-mass] : tous les utilisateurs/admins), on
 *  demande une confirmation explicite avant l'envoi — pour éviter qu'un
 *  clic accidentel n'écrive à toute la base sans retour arrière.
 * ===================================================================== */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-broadcast-form]');
    if (!form) { return; }
    var select = form.querySelector('[data-broadcast-target]');
    if (!select) { return; }

    form.addEventListener('submit', function (e) {
      var opt = select.options[select.selectedIndex];
      var isMass = opt && opt.hasAttribute('data-mass');
      if (!isMass) { return; } // envoi unitaire : pas de confirmation

      var label = opt.textContent.trim();
      var ok = window.confirm(
        'Diffusion de masse\n\n'
        + 'Tu vas envoyer ce message à : ' + label + '.\n'
        + 'Cette action est irréversible et notifiera tous ces utilisateurs.\n\n'
        + 'Confirmer l\'envoi ?'
      );
      if (!ok) {
        e.preventDefault();
      }
    });
  });
})();
