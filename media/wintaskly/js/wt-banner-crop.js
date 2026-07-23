/**
 * Wintaskly — Admin · Recadrage de bannière (vanilla JS, sans dépendance)
 * ----------------------------------------------------------------------
 * Affiche l'image uploadée avec un cadre de recadrage à RATIO FIXE
 * (celui de la taille cible : 728x90, 468x60 ou 300x250). L'admin peut :
 *   - déplacer le cadre (glisser à l'intérieur)
 *   - le redimensionner (poignée en bas à droite, ratio conservé)
 * Les coordonnées du cadre sont converties en pixels RÉELS de l'image
 * source (pas les pixels affichés à l'écran) et écrites dans les champs
 * cachés crop_x / crop_y / crop_w / crop_h avant soumission du formulaire.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-banner-cropper]');
  if (!root) { return; }

  var img       = root.querySelector('[data-cropper-img]');
  var box       = root.querySelector('[data-cropper-box]');
  var stage     = root.querySelector('.wt-cropper__stage');
  var inputX    = root.querySelector('[data-crop-x]');
  var inputY    = root.querySelector('[data-crop-y]');
  var inputW    = root.querySelector('[data-crop-w]');
  var inputH    = root.querySelector('[data-crop-h]');

  var realImgW  = parseInt(root.getAttribute('data-img-w'), 10);
  var realImgH  = parseInt(root.getAttribute('data-img-h'), 10);
  var targetW   = parseInt(root.getAttribute('data-target-w'), 10);
  var targetH   = parseInt(root.getAttribute('data-target-h'), 10);
  var ratio     = targetW / targetH;

  var state = { dispW: 0, dispH: 0, scale: 1, boxX: 0, boxY: 0, boxW: 0, boxH: 0 };

  function init() {
    // Attend que l'image soit chargée pour connaître sa taille affichée
    if (img.complete && img.naturalWidth) {
      layout();
    } else {
      img.addEventListener('load', layout);
    }
  }

  function layout() {
    state.dispW = img.clientWidth;
    state.dispH = img.clientHeight;
    state.scale = realImgW / state.dispW;

    // Cadre initial : le plus grand possible au ratio cible, centré
    var boxW = state.dispW;
    var boxH = boxW / ratio;
    if (boxH > state.dispH) {
      boxH = state.dispH;
      boxW = boxH * ratio;
    }
    state.boxW = boxW;
    state.boxH = boxH;
    state.boxX = (state.dispW - boxW) / 2;
    state.boxY = (state.dispH - boxH) / 2;

    paint();
  }

  function paint() {
    box.style.left   = state.boxX + 'px';
    box.style.top    = state.boxY + 'px';
    box.style.width  = state.boxW + 'px';
    box.style.height = state.boxH + 'px';

    // Conversion en coordonnées réelles de l'image source
    var realX = Math.round(state.boxX * state.scale);
    var realY = Math.round(state.boxY * state.scale);
    var realW = Math.round(state.boxW * state.scale);
    var realH = Math.round(state.boxH * state.scale);

    inputX.value = realX;
    inputY.value = realY;
    inputW.value = realW;
    inputH.value = realH;
  }

  function clampBox() {
    if (state.boxX < 0) state.boxX = 0;
    if (state.boxY < 0) state.boxY = 0;
    if (state.boxX + state.boxW > state.dispW) state.boxX = state.dispW - state.boxW;
    if (state.boxY + state.boxH > state.dispH) state.boxY = state.dispH - state.boxH;
  }

  /* ---- Déplacement du cadre (drag) ---- */
  var dragging = false, dragStartX = 0, dragStartY = 0, boxStartX = 0, boxStartY = 0;

  box.addEventListener('mousedown', function (e) {
    if (e.target.hasAttribute('data-cropper-handle')) { return; }
    dragging = true;
    dragStartX = e.clientX; dragStartY = e.clientY;
    boxStartX = state.boxX; boxStartY = state.boxY;
    e.preventDefault();
  });

  document.addEventListener('mousemove', function (e) {
    if (dragging) {
      state.boxX = boxStartX + (e.clientX - dragStartX);
      state.boxY = boxStartY + (e.clientY - dragStartY);
      clampBox();
      paint();
    } else if (resizing) {
      resizeTo(e.clientX, e.clientY);
    }
  });

  document.addEventListener('mouseup', function () {
    dragging = false;
    resizing = false;
  });

  /* ---- Redimensionnement (poignée coin bas-droit, ratio conservé) ---- */
  var handle = document.createElement('div');
  handle.setAttribute('data-cropper-handle', '1');
  handle.className = 'wt-cropper__handle';
  box.appendChild(handle);

  var resizing = false;

  handle.addEventListener('mousedown', function (e) {
    resizing = true;
    e.preventDefault();
    e.stopPropagation();
  });

  function resizeTo(clientX, clientY) {
    var stageRect = stage.getBoundingClientRect();
    var newW = clientX - stageRect.left - state.boxX;
    newW = Math.max(40, Math.min(newW, state.dispW - state.boxX));
    var newH = newW / ratio;
    if (state.boxY + newH > state.dispH) {
      newH = state.dispH - state.boxY;
      newW = newH * ratio;
    }
    state.boxW = newW;
    state.boxH = newH;
    paint();
  }

  /* ---- Support tactile (mobile) ---- */
  box.addEventListener('touchstart', function (e) {
    if (e.target.hasAttribute('data-cropper-handle')) { return; }
    var t = e.touches[0];
    dragging = true;
    dragStartX = t.clientX; dragStartY = t.clientY;
    boxStartX = state.boxX; boxStartY = state.boxY;
  }, { passive: true });

  document.addEventListener('touchmove', function (e) {
    if (!dragging && !resizing) { return; }
    var t = e.touches[0];
    if (dragging) {
      state.boxX = boxStartX + (t.clientX - dragStartX);
      state.boxY = boxStartY + (t.clientY - dragStartY);
      clampBox();
      paint();
    } else if (resizing) {
      resizeTo(t.clientX, t.clientY);
    }
  }, { passive: true });

  document.addEventListener('touchend', function () {
    dragging = false;
    resizing = false;
  });

  window.addEventListener('resize', layout);

  init();
})();
