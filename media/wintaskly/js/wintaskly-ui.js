/* =====================================================================
   wintaskly-ui.js — Preloader, thème, langue, micro-interactions
   Vanilla JS strict.
   ===================================================================== */
(function () {
  'use strict';

  // ----- Utilitaires ---------------------------------------------------
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const META = (name) => document.querySelector('meta[name="' + name + '"]')?.content || '';

  const BASE = META('wt-base');

  // ----- 1) PRELOADER --------------------------------------------------
  //
  // ⚠️ IMPORTANT : depuis V8.5, le préloader est géré par un script INLINE
  // dans header.php (juste après le HTML du préloader). Ce script-ci ne
  // doit PAS le re-déclencher, sinon on a deux animations qui se battent.
  //
  // Le flag `window.__wtPreloaderHandled` est posé par le script inline
  // pour signaler qu'il a pris le contrôle. Si ce flag est là, on skip
  // tout le module préloader ici.
  //
  // On garde le code en fallback au cas où le inline ne se charge pas
  // (vieux navigateur sans support certaines API).
  if (window.__wtPreloaderHandled) {
    // L'inline gère déjà tout — on saute cette section.
  } else {
  /**
   * Stratégie :
   *  - Phase A : progression initiale rapide (0 → 70%) basée sur
   *    document.readyState et le chargement des ressources critiques.
   *  - Phase B : 70 → 95% une fois "DOMContentLoaded".
   *  - Phase C : 95 → 100% une fois "load" (toutes les images/CSS chargés).
   *  - À 100% : fondu de l'écran, retrait de display:none sur #app-wrapper,
   *    déclenchement des reveals échelonnés.
   */
  const preloader = $('#wt-preloader');
  const appWrap   = $('#app-wrapper');
  const pctEl     = $('[data-pct]', preloader);
  const barEl     = $('.wt-preloader__bar', preloader);
  const CIRC      = 263.9;      // 2 * π * 42 (rayon de l'anneau, version compacte)

  let current = 0;
  let target  = 5;
  let rafId;

  function setPct(p) {
    const clamped = Math.max(0, Math.min(100, p));
    if (pctEl) pctEl.textContent = Math.round(clamped) + '%';
    if (barEl) barEl.setAttribute('stroke-dashoffset', String(CIRC - (CIRC * clamped / 100)));
  }

  function loop() {
    // easing : on s'approche de target avec un facteur < 1 par frame
    current += (target - current) * 0.08;
    if (target - current < 0.4) current = target;
    setPct(current);

    if (current < 100) {
      rafId = requestAnimationFrame(loop);
    } else {
      cancelAnimationFrame(rafId);
      revealApp();
    }
  }

  function bumpTo(val) {
    target = Math.max(target, Math.min(100, val));
  }

  function revealApp() {
    if (!appWrap || !preloader) return;
    // Délai court pour laisser le 100% s'afficher brièvement
    setTimeout(() => {
      preloader.classList.add('is-hidden');
      appWrap.style.display = '';
      void appWrap.offsetWidth;
      appWrap.classList.add('is-ready');
      runStaggeredReveals();
      sendTimezone();
    }, 100);
  }

  function runStaggeredReveals() {
    $$('[data-reveal]').forEach((el, i) => {
      el.style.setProperty('--wt-reveal-delay', (i * 60) + 'ms');
    });
  }

  // Lance la boucle d'animation
  if (preloader && appWrap) {
    setPct(0);
    rafId = requestAnimationFrame(loop);

    // Démarrage rapide
    bumpTo(40);

    // Quand le DOM est prêt (HTML parsé, CSS chargé) → on a déjà
    // visuellement ce qu'il faut. On force 100% pour cacher le preloader
    // SANS attendre que toutes les images soient téléchargées.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => bumpTo(100));
    } else {
      // DOM déjà ready quand ce script tourne
      bumpTo(100);
    }

    // garde-fou : si même DOMContentLoaded tarde trop, on force après 2s
    setTimeout(() => bumpTo(100), 2000);
  }
  } // fin du else (fallback si __wtPreloaderHandled non posé)

  // sendTimezone() doit toujours être appelé indépendamment du préloader
  // (envoie le fuseau horaire au backend pour les calculs de "il y a 2h").
  // Si le script inline a géré le reveal, sendTimezone() doit toujours
  // être appelé. On l'appelle ici après que le DOM soit prêt.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sendTimezone);
  } else {
    sendTimezone();
  }

  // ----- 2) THÈME (light / dark) --------------------------------------
  function setTheme(t) {
    if (t !== 'light' && t !== 'dark') return;
    document.documentElement.classList.remove('light', 'dark');
    document.documentElement.classList.add(t);
    document.documentElement.setAttribute('data-theme', t);
    document.cookie = 'wt_theme=' + t + '; path=/; max-age=' + (60*60*24*365) + '; samesite=lax';
  }
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action="toggle-theme"]');
    if (!btn) return;
    const cur = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
    setTheme(cur === 'dark' ? 'light' : 'dark');
  });

  // ----- 3) LANGUE ----------------------------------------------------
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('[data-action="switch-lang"]');
    if (!sel) return;
    const lang = sel.value;
    const url  = new URL(window.location.href);
    url.searchParams.set('lang', lang);
    window.location.href = url.toString();
  });

  // ----- 4) TIMEZONE CLIENT → SERVEUR --------------------------------
  function sendTimezone() {
    try {
      const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
      const cookieTz = (document.cookie.split('; ').find(c => c.startsWith('wt_tz=')) || '').split('=')[1];
      if (cookieTz === tz) return;
      const fd = new FormData();
      fd.append('tz', tz);
      fd.append('csrf', META('csrf-token'));
      fetch(BASE + '/api/set_timezone.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .catch(() => {});
      document.cookie = 'wt_tz=' + encodeURIComponent(tz) + '; path=/; max-age=' + (60*60*24*365) + '; samesite=lax';
    } catch (e) {}
  }

  // ----- 5) TOAST helper (exposé pour wintaskly.js) -------------------
  window.WT = window.WT || {};
  window.WT.toast = function (message, type = 'ok', duration = 3000) {
    let el = $('.wt-toast');
    if (!el) {
      el = document.createElement('div');
      el.className = 'wt-toast';
      document.body.appendChild(el);
    }
    el.className = 'wt-toast wt-toast--' + (type === 'err' ? 'err' : 'ok');
    el.textContent = message;
    // force reflow
    void el.offsetWidth;
    el.classList.add('is-visible');
    clearTimeout(el._tid);
    el._tid = setTimeout(() => el.classList.remove('is-visible'), duration);
  };

  // ----- 6) COPY-TO-CLIPBOARD -----------------------------------------
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy], [data-copy-target]');
    if (!btn) return;
    const target = btn.getAttribute('data-copy') || btn.getAttribute('data-copy-target');
    const input  = target ? document.querySelector(target) : null;
    const text   = input ? input.value : btn.getAttribute('data-copy-text') || '';
    try {
      await navigator.clipboard.writeText(text);
      window.WT.toast('Copié !', 'ok', 1800);
    } catch (err) {
      window.WT.toast('Impossible de copier', 'err', 2000);
    }
  });

  // ----- 7) COUNT-UP : `[data-countup="1247"]` -------------------------
  //   Anime la valeur de 0 jusqu'au target, ease-out cubique, ~1.2s.
  const animateCountUp = (el) => {
    const target = parseFloat(el.getAttribute('data-countup') || '0');
    if (!isFinite(target) || target <= 0) {
      el.textContent = '0';
      return;
    }
    const duration = 1200;
    const t0 = performance.now();
    const isInt = Number.isInteger(target);
    const step = (now) => {
      const p = Math.min(1, (now - t0) / duration);
      // ease-out cubic
      const eased = 1 - Math.pow(1 - p, 3);
      const value = target * eased;
      el.textContent = isInt
        ? Math.round(value).toLocaleString('fr-FR')
        : value.toFixed(2);
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  const triggerCountUps = () => {
    $$('[data-countup]').forEach((el) => {
      if (el._wtCounted) return;
      el._wtCounted = true;
      animateCountUp(el);
    });
  };

  // Lance dès que le contenu est visible (après reveal)
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting && !entry.target._wtCounted) {
          entry.target._wtCounted = true;
          animateCountUp(entry.target);
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.4 });
    $$('[data-countup]').forEach((el) => io.observe(el));
  } else {
    triggerCountUps();
  }

  // ----- 8) COUNTDOWN : `[data-countdown="seconds"]` -------------------
  //   Affiche HH:MM:SS et décrémente chaque seconde, jusqu'à 00:00:00.
  const pad2 = (n) => String(n).padStart(2, '0');
  const fmtHMS = (s) => pad2(Math.floor(s / 3600)) + ':' + pad2(Math.floor((s % 3600) / 60)) + ':' + pad2(s % 60);

  $$('[data-countdown]').forEach((el) => {
    let left = parseInt(el.getAttribute('data-countdown') || '0', 10);
    if (!isFinite(left) || left <= 0) { el.textContent = '00:00:00'; return; }
    el.textContent = fmtHMS(left);
    const iv = setInterval(() => {
      left--;
      if (left <= 0) {
        clearInterval(iv);
        el.textContent = '00:00:00';
        // Si l'élément est dans une carte verrouillée, on retire le verrou
        const locked = el.closest('.is-locked');
        if (locked) locked.classList.remove('is-locked');
      } else {
        el.textContent = fmtHMS(left);
      }
    }, 1000);
  });

  // ----- 9) FORMATAGE TEMPS CÔTÉ CLIENT : `[data-fmt-time data-utc=...]`
  //   Convertit un datetime UTC du serveur dans le fuseau de l'utilisateur.
  $$('[data-fmt-time][data-utc]').forEach((el) => {
    const raw = el.getAttribute('data-utc');
    if (!raw) return;
    // MySQL "YYYY-MM-DD HH:MM:SS" UTC → ISO
    const iso = raw.replace(' ', 'T') + 'Z';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return;
    try {
      el.textContent = d.toLocaleString(undefined, {
        dateStyle: 'short',
        timeStyle: 'short',
      });
    } catch (_) { /* keep server fallback */ }
  });
})();

/* ---------------------------------------------------------------------
 * V7 — TOGGLE VISIBILITÉ MOT DE PASSE (icône œil)
 * Click sur [data-toggle-pw] → bascule l'input frère/parent password ↔ text.
 * Anime les deux icônes (œil ouvert / barré) via .is-hidden.
 * --------------------------------------------------------------------- */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-toggle-pw]');
  if (!btn) return;
  e.preventDefault();

  // Cherche l'input dans le même wrapper
  const wrap  = btn.closest('.wt-input-wrap--password') || btn.parentElement;
  const input = wrap ? wrap.querySelector('input[type="password"], input[type="text"]') : null;
  if (!input) return;

  const wasPassword = input.type === 'password';
  input.type = wasPassword ? 'text' : 'password';

  // Bascule les icônes (œil ouvert ↔ œil barré)
  const off = btn.querySelector('.wt-input-eye__off');
  const on  = btn.querySelector('.wt-input-eye__on');
  if (off && on) {
    off.classList.toggle('is-hidden', wasPassword);  // password caché → on cache l'œil ouvert
    on.classList.toggle('is-hidden', !wasPassword);
  }

  // Petite ré-position du curseur à la fin pour ne pas perdre la sélection
  const v = input.value;
  input.focus();
  try { input.setSelectionRange(v.length, v.length); } catch (_) {}
});

/* ---------------------------------------------------------------------
 * V7 — MODAL DE CONFIRMATION GÉNÉRIQUE
 *
 * Usage minimal en HTML :
 *   <button data-confirm
 *           data-confirm-title="Supprimer le compte"
 *           data-confirm-body="Action irréversible."
 *           data-confirm-ok="Supprimer définitivement"
 *           data-confirm-ok-class="wt-btn--danger"
 *           data-confirm-href="/api/account_delete.php">Supprimer</button>
 *
 * Pour exiger une saisie de confirmation :
 *   data-confirm-typed="SUPPRIMER"    → le bouton OK reste désactivé tant
 *                                       que l'utilisateur n'a pas tapé ce mot.
 *
 * Si data-confirm-href est posé : navigation simple (lien dur).
 * Sinon on déclenche un événement 'wt:confirm:ok' sur le bouton initial
 * pour brancher du JS sur-mesure.
 * --------------------------------------------------------------------- */
window.WT = window.WT || {};
window.WT.confirm = function (opts) {
  return new Promise((resolve) => {
    const o = Object.assign({
      title:    'Confirmation',
      body:     '',
      ok:       'OK',
      cancel:   'Annuler',
      okClass:  'wt-btn--primary',
      typed:    null,   // chaîne exacte que l'utilisateur doit retaper
    }, opts || {});

    const overlay = document.createElement('div');
    overlay.className = 'wt-modal';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = `
      <div class="wt-modal__backdrop" data-close></div>
      <div class="wt-modal__panel" role="document">
        <header class="wt-modal__header">
          <h2>${escapeHtml(o.title)}</h2>
          <button type="button" class="wt-modal__close" data-close aria-label="Close">×</button>
        </header>
        <div class="wt-modal__body">
          <p>${escapeHtml(o.body)}</p>
          ${o.typed ? `
            <p class="wt-card__hint" style="margin-top:.75rem">
              Pour confirmer, tape <code><strong>${escapeHtml(o.typed)}</strong></code> ci-dessous :
            </p>
            <input type="text" class="wt-input" data-confirm-input
                   autocomplete="off" autocapitalize="characters" spellcheck="false">
          ` : ''}
        </div>
        <footer class="wt-modal__footer">
          <button type="button" class="wt-btn wt-btn--ghost" data-close>${escapeHtml(o.cancel)}</button>
          <button type="button" class="wt-btn ${o.okClass}" data-ok ${o.typed ? 'disabled' : ''}>${escapeHtml(o.ok)}</button>
        </footer>
      </div>
    `;
    document.body.appendChild(overlay);
    requestAnimationFrame(() => overlay.classList.add('is-visible'));

    const okBtn = overlay.querySelector('[data-ok]');
    const inp   = overlay.querySelector('[data-confirm-input]');

    if (o.typed && inp) {
      inp.addEventListener('input', () => {
        okBtn.disabled = inp.value.trim().toUpperCase() !== o.typed.toUpperCase();
      });
      setTimeout(() => inp.focus(), 60);
    } else {
      setTimeout(() => okBtn.focus(), 60);
    }

    function close(ok) {
      document.removeEventListener('keydown', onKey);
      overlay.classList.remove('is-visible');
      setTimeout(() => { overlay.remove(); resolve(ok); }, 200);
    }
    function onKey(e) {
      if (e.key === 'Escape') close(false);
      if (e.key === 'Enter' && !okBtn.disabled) close(true);
    }
    document.addEventListener('keydown', onKey);

    overlay.addEventListener('click', (e) => {
      if (e.target.closest('[data-close]')) close(false);
      if (e.target.closest('[data-ok]') && !okBtn.disabled) close(true);
    });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;',
    })[c]);
  }
};

/* Hook sur les boutons [data-confirm] : ouvre la modal, puis :
 *  - si data-confirm-href → navigation simple
 *  - si data-confirm-post → POST vers cet endpoint avec CSRF
 *  - sinon → dispatch event 'wt:confirm:ok' sur le bouton
 */
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-confirm]');
  if (!btn) return;
  e.preventDefault();

  const ok = await window.WT.confirm({
    title:    btn.dataset.confirmTitle  || 'Confirmation',
    body:     btn.dataset.confirmBody   || 'Confirmer cette action ?',
    ok:       btn.dataset.confirmOk     || 'OK',
    cancel:   btn.dataset.confirmCancel || 'Annuler',
    okClass:  btn.dataset.confirmOkClass|| 'wt-btn--primary',
    typed:    btn.dataset.confirmTyped  || null,
  });
  if (!ok) return;

  const href = btn.dataset.confirmHref;
  const post = btn.dataset.confirmPost;

  if (href) {
    window.location.href = href;
    return;
  }
  if (post) {
    try {
      const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      const fd = new FormData();
      fd.append('_csrf', csrf);
      // Champs additionnels portés par data-confirm-data='{"k":"v"}'
      if (btn.dataset.confirmData) {
        try {
          const extra = JSON.parse(btn.dataset.confirmData);
          Object.entries(extra).forEach(([k, v]) => fd.append(k, String(v)));
        } catch (_) {}
      }
      const r = await fetch(post, { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await r.json().catch(() => ({}));
      if (data.ok) {
        if (data.redirect) window.location.href = data.redirect;
        else window.location.reload();
      } else {
        window.WT.toast(data.error || 'Erreur', 'err', 3000);
      }
    } catch (err) {
      window.WT.toast('Erreur réseau', 'err', 2500);
    }
    return;
  }
  btn.dispatchEvent(new CustomEvent('wt:confirm:ok', { bubbles: true }));
});

/* =====================================================================
 *  V8 — Countdown live ([data-countdown][data-target="UTC"])
 *
 *  Compte à rebours en temps réel. Tick chaque seconde tant que > 1 min,
 *  puis chaque 0.5s pour la dernière minute (effet "imminent"). Affiche
 *  "data-label-ready" et ajoute la classe .is-ready quand le temps est
 *  écoulé (le serveur fera la validation finale au prochain clic).
 *
 *  Format adaptatif :
 *    > 1h  → "2h 14m"
 *    < 1h  → "14m 32s"
 *    < 1m  → "32s"
 * ===================================================================== */
(function () {
  'use strict';
  const elements = document.querySelectorAll('[data-countdown]');
  if (!elements.length) return;

  const lang = (document.documentElement.lang || 'fr').toLowerCase();
  const L = lang.startsWith('en')
    ? { h: 'h', m: 'm', s: 's' }
    : { h: 'h', m: 'min', s: 's' };

  function format(remaining) {
    if (remaining <= 0) return null;
    const sec  = Math.floor(remaining);
    const h    = Math.floor(sec / 3600);
    const m    = Math.floor((sec % 3600) / 60);
    const s    = sec % 60;
    if (h > 0) return `${h}${L.h} ${String(m).padStart(2,'0')}${L.m}`;
    if (m > 0) return `${m}${L.m} ${String(s).padStart(2,'0')}${L.s}`;
    return `${s}${L.s}`;
  }

  function parseUtc(s) {
    // "2026-05-22 03:45:12" → Date, force UTC
    if (!s) return null;
    if (s.includes('T') || s.includes('Z')) return new Date(s);
    return new Date(s.replace(' ', 'T') + 'Z');
  }

  elements.forEach(el => {
    const target = parseUtc(el.dataset.target);
    if (!target) return;
    const ready = el.dataset.labelReady || 'Ready';

    let interval = null;

    function tick() {
      const remainingMs = target.getTime() - Date.now();
      const formatted   = format(remainingMs / 1000);

      if (formatted === null) {
        el.textContent = ready;
        el.classList.add('is-ready');
        if (interval) { clearInterval(interval); interval = null; }
        // Optionnel : reload léger après 5s pour rafraîchir l'état serveur
        setTimeout(() => location.reload(), 3000);
        return;
      }
      el.textContent = formatted;

      // Switch en mode rapide pour la dernière minute (effet imminent)
      if (remainingMs < 60000 && interval) {
        clearInterval(interval);
        interval = setInterval(tick, 500);
      }
    }

    tick();
    interval = setInterval(tick, 1000);
  });
})();

/* =====================================================================
 *  V8 — Bouton copier générique [data-copy-target]
 *
 *  Pattern :
 *     <input data-cron-url value="...">
 *     <button data-copy-target="[data-cron-url]"
 *             data-copy-label="Copié !">
 *       Copier
 *     </button>
 *
 *  Le bouton lit l'élément ciblé (input ou code) et copie son contenu
 *  dans le presse-papier via Clipboard API (avec fallback execCommand).
 *  À la copie réussie, le label du bouton est temporairement remplacé
 *  par data-copy-label (2 secondes).
 * ===================================================================== */
(function () {
  'use strict';
  const buttons = document.querySelectorAll('[data-copy-target]');
  if (!buttons.length) return;

  function getTextOf(el) {
    if (!el) return '';
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') return el.value;
    return el.textContent || '';
  }

  async function copyTo(text) {
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(text);
        return true;
      } catch (_) { /* fallback ci-dessous */ }
    }
    // Fallback : textarea hors-écran + execCommand
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      const ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch (_) {
      document.body.removeChild(ta);
      return false;
    }
  }

  buttons.forEach(btn => {
    btn.addEventListener('click', async () => {
      const sel = btn.getAttribute('data-copy-target');
      const target = sel ? document.querySelector(sel) : null;
      const text = getTextOf(target);
      if (!text) return;

      const ok = await copyTo(text);
      if (!ok) return;

      const originalHtml = btn.innerHTML;
      const label = btn.getAttribute('data-copy-label') || '✓ Copied';
      btn.innerHTML = '✓ ' + label;
      btn.classList.add('is-copied');
      setTimeout(() => {
        btn.innerHTML = originalHtml;
        btn.classList.remove('is-copied');
      }, 2000);
    });
  });
})();

/* =====================================================================
 *  V8 — Header sticky : ajoute "is-scrolled" quand on défile
 *
 *  L'élément [data-header-sticky] reçoit la classe `is-scrolled` dès
 *  qu'on défile > 8px. Permet d'animer l'ombre/le border pour signaler
 *  un détachement visuel.
 * ===================================================================== */
(function () {
  'use strict';
  const header = document.querySelector('[data-header-sticky]');
  if (!header) return;

  let ticking = false;
  function update() {
    const scrolled = window.scrollY > 8;
    header.classList.toggle('is-scrolled', scrolled);
    ticking = false;
  }
  function onScroll() {
    if (!ticking) {
      requestAnimationFrame(update);
      ticking = true;
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  update(); // état initial
})();
