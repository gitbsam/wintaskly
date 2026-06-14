/**
 * Wintaskly — Service Worker (PWA)
 *
 * Stratégies de cache (par type de ressource) :
 *   - Images : cache-first (versionnées par path, rapides hors-ligne)
 *   - CSS/JS : network-first (toujours la version fraîche après déploiement)
 *   - Pages HTML : network-first (fallback cache si offline)
 *   - API (/api/*) : network-only (jamais cachées)
 *   - Page offline (/offline.html) : cache fallback ultime
 *
 * Versioning AUTOMATIQUE :
 *   La version est lue depuis la query string de l'URL d'enregistrement
 *   du SW (ex: /sw.js?v=8.7.9). Cette valeur est injectée par footer.php
 *   depuis la constante PHP WT_VERSION.
 *
 *   → Plus besoin de bumper manuellement quoi que ce soit ici : il
 *     suffit de changer WT_VERSION dans includes/init.php et tout
 *     l'écosystème (app + service worker) se met à jour ensemble.
 *
 *   Si l'URL n'a pas de ?v= (vieux cache), on retombe sur un fallback.
 */

// Lit ?v=X.Y.Z depuis l'URL de ce script (injectée par footer.php)
function wtGetVersionFromUrl() {
  try {
    const url = new URL(self.location.href);
    const v = url.searchParams.get('v');
    return v || 'fallback';
  } catch (e) {
    return 'fallback';
  }
}

const CACHE_VERSION = 'wintaskly-v' + wtGetVersionFromUrl();
const CACHE_STATIC  = CACHE_VERSION + '-static';
const CACHE_PAGES   = CACHE_VERSION + '-pages';

// Assets à pré-cacher dès l'install (le minimum pour afficher la page offline)
const PRECACHE_URLS = [
  '/offline.html',
  '/manifest.webmanifest',
  '/media/wintaskly/img/logo-light-192.png',
  '/media/wintaskly/img/favicon.ico',
];

/* ====================================================================
 * INSTALL : pré-cache des assets critiques
 * ==================================================================== */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_STATIC)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())  // Active immédiatement la nouvelle version
  );
});

/* ====================================================================
 * ACTIVATE : nettoyage des vieux caches
 * ==================================================================== */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names
          .filter((n) => !n.startsWith(CACHE_VERSION))
          .map((n) => caches.delete(n))
      ))
      .then(() => self.clients.claim())  // Prend le contrôle immédiat
  );
});

/* ====================================================================
 * FETCH : routing intelligent selon le type de ressource
 * ==================================================================== */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Ignore les requêtes hors-domaine (CDN externes, etc.)
  if (url.origin !== self.location.origin) return;

  // Ignore les méthodes non-GET (POST/PUT/DELETE jamais cachées)
  if (request.method !== 'GET') return;

  // Ignore les endpoints API : toujours via réseau
  if (url.pathname.startsWith('/api/')) return;

  // Ignore les pages admin et install : pas pertinent en offline
  if (url.pathname.startsWith('/admin/') || url.pathname.startsWith('/install/')) return;

  /* ----- Stratégie par type de ressource -----
     IMPORTANT : on utilise network-first pour CSS et JS. Sinon, après
     un déploiement, les visiteurs continuent de recevoir l'ancienne
     version du CSS/JS depuis le cache → site cassé visuellement (pas
     de layout, anciens bugs réapparaissent).

     Network-first = on tente le réseau d'abord (donc fraîche version),
     fallback sur le cache UNIQUEMENT si offline. Côté perf : c'est très
     peu différent du cache-first car le CSS est petit (~70 KB gzippé)
     et le navigateur HTTP-cache déjà via les headers Cache-Control.

     Seules les IMAGES restent en cache-first (versionnées via le path,
     on ne déploie pas une image avec le même nom mais un contenu
     différent — donc cache-first est safe pour elles). */
  if (isImageAsset(url.pathname)) {
    // Cache-first pour les images (versionnées par path)
    event.respondWith(cacheFirstStrategy(request));
  } else if (isStaticAsset(url.pathname)) {
    // Network-first pour CSS/JS/fonts — version fraîche prioritaire
    event.respondWith(networkFirstStrategy(request));
  } else {
    // Network-first pour les pages HTML (déjà le cas)
    event.respondWith(networkFirstStrategy(request));
  }
});

/* ====================================================================
 * STRATÉGIES
 * ==================================================================== */

/**
 * Cache-first : on retourne le cache si dispo, sinon réseau + mise en cache.
 * Idéal pour les assets versionnés ou peu changeants.
 */
async function cacheFirstStrategy(request) {
  const cached = await caches.match(request);
  if (cached) {
    // En parallèle : refresh silencieux (stale-while-revalidate)
    fetchAndCache(request, CACHE_STATIC);
    return cached;
  }
  return fetchAndCache(request, CACHE_STATIC);
}

/**
 * Network-first : on tente le réseau d'abord, fallback cache, puis page offline.
 * Idéal pour les pages HTML (toujours frais quand possible) ET pour les CSS/JS
 * qui changent à chaque déploiement.
 */
async function networkFirstStrategy(request) {
  // Choisit le bon bucket selon le type de ressource
  const url = new URL(request.url);
  const cacheName = isStaticAsset(url.pathname) ? CACHE_STATIC : CACHE_PAGES;

  try {
    const response = await fetch(request);
    // Cache uniquement les réponses OK
    if (response && response.status === 200) {
      const clone = response.clone();
      caches.open(cacheName).then((cache) => cache.put(request, clone));
    }
    return response;
  } catch (err) {
    // Réseau KO : on tente le cache
    const cached = await caches.match(request);
    if (cached) return cached;

    // Vraiment rien → page offline (uniquement pour HTML)
    if (request.headers.get('accept')?.includes('text/html')) {
      return caches.match('/offline.html');
    }
    // Pour les autres types (images manquantes, etc.) : erreur silencieuse
    return new Response('', { status: 503, statusText: 'Offline' });
  }
}

/**
 * Helper : fetch + met en cache si OK.
 */
async function fetchAndCache(request, cacheName) {
  try {
    const response = await fetch(request);
    if (response && response.status === 200) {
      const clone = response.clone();
      caches.open(cacheName).then((cache) => cache.put(request, clone));
    }
    return response;
  } catch (err) {
    // Fallback ultime : retourner ce qui est en cache même si on a échoué
    const cached = await caches.match(request);
    if (cached) return cached;
    throw err;
  }
}

/**
 * Détecte si une URL pointe vers un asset statique (CSS, JS, fonts, images).
 */
function isStaticAsset(pathname) {
  return /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|eot)$/i.test(pathname);
}

/**
 * Détecte si une URL pointe spécifiquement vers une IMAGE.
 * Les images sont versionnées par leur path (logo-light-192.png ne change
 * jamais de contenu — c'est toujours le logo). On peut donc les cacher
 * agressivement en cache-first. À l'inverse, wintaskly.css peut avoir
 * son contenu mis à jour à chaque déploiement → network-first.
 */
function isImageAsset(pathname) {
  return /\.(png|jpg|jpeg|gif|webp|svg|ico)$/i.test(pathname);
}

/* ====================================================================
 * PUSH NOTIFICATIONS (basique — déclenchées par le serveur)
 * ==================================================================== */
self.addEventListener('push', (event) => {
  let payload = { title: 'Wintaskly', body: 'Nouvelle notification' };
  try {
    if (event.data) payload = event.data.json();
  } catch (e) {
    if (event.data) payload.body = event.data.text();
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'Wintaskly', {
      body:  payload.body  || '',
      icon:  payload.icon  || '/media/wintaskly/img/logo-light-192.png',
      badge: payload.badge || '/media/wintaskly/img/logo-light-64.png',
      tag:   payload.tag   || 'wintaskly-general',
      data:  { url: payload.url || '/' },
      vibrate: [120, 60, 120],
      requireInteraction: false,
    })
  );
});

/* Quand l'utilisateur clique sur la notification → ouvrir l'URL */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((clients) => {
      // Si une fenêtre est déjà ouverte sur Wintaskly, focus dessus
      for (const client of clients) {
        if (client.url.includes(self.location.origin) && 'focus' in client) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }
      // Sinon, ouvrir une nouvelle fenêtre
      if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
    })
  );
});

/* ====================================================================
 * MESSAGE : permet à la page d'envoyer des commandes au SW
 *   - 'SKIP_WAITING' : active immédiatement une nouvelle version dispo
 * ==================================================================== */
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
