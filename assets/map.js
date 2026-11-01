/* global calpMap, L */
'use strict';

// Ported from "My Map Plugin". Avoid JavaScript features older Safari versions cannot parse
// (no regex lookbehind): one syntax error stops the whole file.

(function waitForLeaflet(retries) {
  // Cache/optimisation plugins may load scripts out of order: wait until Leaflet exists.
  var domReady = document.readyState === 'interactive' || document.readyState === 'complete';
  if (typeof window.L !== 'undefined' && domReady) {
    document.querySelectorAll('.calp-map-wrap').forEach(initMap);
    return;
  }
  if (retries <= 0) {
    console.error('Calendar Plugin: Leaflet was not loaded in time.');
    return;
  }
  setTimeout(function () { waitForLeaflet(retries - 1); }, 100);
})(150);

function escHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function initMap(wrap) {
  var cfg = window.calpMap;
  var el = wrap.querySelector('.calp-map');
  if (!cfg || !el || el.dataset.ready) return;
  el.dataset.ready = '1';
  var t = cfg.i18n;

  // iOS: zoom/fade animations create many GPU layers; above WebKit's limit nothing renders.
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  var map = L.map(el, {
    fadeAnimation: !isIOS,
    zoomAnimation: !isIOS,
    markerZoomAnimation: !isIOS,
    scrollWheelZoom: false, // only after a click, so scrolling the page does not get stuck
  }).setView([cfg.lat, cfg.lng], cfg.zoom);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: t.attribution }).addTo(map);
  map.on('click focus', function () { map.scrollWheelZoom.enable(); });
  map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

  // White-map fixes: the container may become visible later (tabs, fonts, bfcache, tab switch).
  function fixSize() { map.invalidateSize(); }
  setTimeout(fixSize, 100);
  setTimeout(fixSize, 500);
  window.addEventListener('load', fixSize);
  window.addEventListener('pageshow', function (e) { if (e.persisted) setTimeout(fixSize, 50); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') setTimeout(fixSize, 50); });
  document.addEventListener('calp:shown', fixSize);
  if (typeof ResizeObserver !== 'undefined') new ResizeObserver(fixSize).observe(el);

  // Lazy-loading plugins hook into every <img>, including map tiles; WebKit then never loads them.
  if (typeof MutationObserver !== 'undefined') {
    new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          var imgs = node.tagName === 'IMG' ? [node] : node.querySelectorAll('img');
          imgs.forEach(function (img) { img.loading = 'eager'; img.removeAttribute('data-src'); });
        });
      });
    }).observe(el, { childList: true, subtree: true });
  }

  var categories = [];
  var markers = [];
  var active = {};
  var layer = null;

  function pinIcon(color) {
    return L.divIcon({
      className: 'calp-icon',
      html: '<div class="calp-pin" style="background:' + escHtml(color) + '"></div>',
      iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -32],
    });
  }

  function eventIcon(color) {
    var cal = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>';
    return L.divIcon({
      className: 'calp-icon',
      html: '<div class="calp-eventpin" style="background:' + escHtml(color) + '">' + cal + '</div>',
      iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -18],
    });
  }

  function render() {
    if (layer) map.removeLayer(layer);
    layer = typeof L.markerClusterGroup === 'function'
      ? L.markerClusterGroup({
        maxClusterRadius: 50,
        iconCreateFunction: function (cluster) {
          var n = cluster.getChildCount();
          var size = n < 10 ? 36 : n < 50 ? 44 : 52;
          return L.divIcon({ html: '<div class="calp-cluster" style="width:' + size + 'px;height:' + size + 'px">' + n + '</div>', className: '', iconSize: [size, size] });
        },
      })
      : L.layerGroup();

    markers.forEach(function (m) {
      if (!active[m.category]) return;
      var imgs = (m.images || []).map(function (u) { return '<img src="' + escHtml(u) + '" class="calp-popup__img" alt="" loading="lazy">'; }).join('');
      // m.html comes from the server already sanitised (wp_kses_post / esc_html).
      var popup = '<div class="calp-popup">' + imgs + '<strong>' + escHtml(m.title) + '</strong>' +
        '<span class="calp-popup__cat" style="background:' + escHtml(m.color) + '">' + escHtml(m.catName) + '</span>' +
        '<div class="calp-popup__text">' + m.html + '</div></div>';
      var lm = L.marker([m.lat, m.lng], { icon: m.type === 'event' ? eventIcon(m.color) : pinIcon(m.color) })
        .bindPopup(popup, { maxWidth: 280, maxHeight: 320 });
      lm.on('popupopen', function () {
        var p = lm.getPopup().getElement();
        if (p) p.querySelectorAll('a').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
      });
      layer.addLayer(lm);
    });
    map.addLayer(layer);
  }

  function legend() {
    if (!categories.length) return;
    var Legend = L.Control.extend({
      onAdd: function () {
        var div = L.DomUtil.create('div', 'calp-legend');
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.disableScrollPropagation(div);
        var groups = { places: t.categories, events: t.events };
        var html = '';
        Object.keys(groups).forEach(function (g) {
          var list = categories.filter(function (c) { return c.group === g; });
          if (!list.length) return;
          html += '<h4>' + escHtml(groups[g]) + '</h4>' + list.map(function (c) {
            return '<label class="calp-legend__item is-on" data-cat="' + escHtml(c.id) + '"><span class="calp-legend__box" style="--c:' + escHtml(c.color) + '"></span>' + escHtml(c.name) + '</label>';
          }).join('');
        });
        div.innerHTML = html;
        div.querySelectorAll('.calp-legend__item').forEach(function (item) {
          item.addEventListener('click', function () {
            var id = item.getAttribute('data-cat');
            active[id] = !active[id];
            item.classList.toggle('is-on', active[id]);
            render();
          });
        });
        return div;
      },
    });
    new Legend({ position: 'bottomright' }).addTo(map);
  }

  fetch(cfg.rest + 'map')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      categories = data.categories || [];
      markers = data.markers || [];
      categories.forEach(function (c) { active[c.id] = true; });
      render();
      legend();
    })
    .catch(console.error);

  // ── Place suggestion ──────────────────────────────────────────────
  var toggle = wrap.querySelector('.calp-suggest-toggle');
  var form = wrap.querySelector('.calp-suggest-form');
  if (!cfg.suggestions || !toggle || !form) return;

  var q = function (sel) { return form.querySelector(sel); };
  var pin = null;
  var lat = '';
  var lng = '';
  var suggesting = false;
  var feedback = q('.calp-s-feedback');

  function show(msg, ok) {
    feedback.hidden = false;
    feedback.textContent = msg;
    feedback.className = 'calp-s-feedback calp-notice ' + (ok ? 'calp-notice--ok' : 'calp-notice--error');
  }

  function reset() {
    if (pin) { pin.remove(); pin = null; }
    lat = ''; lng = '';
    q('.calp-s-coords').textContent = t.clickMap;
  }

  toggle.addEventListener('click', function () {
    suggesting = form.hidden;
    form.hidden = !suggesting;
    toggle.textContent = suggesting ? t.close : t.open;
    if (!suggesting) reset();
  });

  map.on('click', function (e) {
    if (!suggesting) return;
    lat = e.latlng.lat.toFixed(6);
    lng = e.latlng.lng.toFixed(6);
    q('.calp-s-coords').textContent = t.coordsSet + ' ' + e.latlng.lat.toFixed(4) + ', ' + e.latlng.lng.toFixed(4);
    if (pin) pin.remove();
    pin = L.circleMarker(e.latlng, { radius: 10, color: '#FFA101', fillColor: '#FFA101', fillOpacity: 0.85 }).addTo(map).bindPopup(t.yours).openPopup();
  });

  q('.calp-s-submit').addEventListener('click', function () {
    var btn = this;
    var body = {
      title: q('.calp-s-title').value.trim(),
      content: q('.calp-s-content').value.trim(),
      category: q('.calp-s-category').value,
      lat: lat,
      lng: lng,
      name: q('.calp-s-name').value,
      email: q('.calp-s-email').value,
      website: q('.calp-s-hp').value,
    };
    if (!body.title) { show(t.needTitle, false); return; }
    if (!body.content) { show(t.needText, false); return; }
    if (!body.lat) { show(t.needCoords, false); return; }

    btn.disabled = true;
    btn.textContent = t.sending;
    fetch(cfg.rest + 'suggest-place', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json(); })
      .catch(function () { return { message: t.offline }; })
      .then(function (res) {
        btn.disabled = false;
        btn.textContent = t.send;
        if (res && res.success) {
          show(t.thanks, true);
          ['.calp-s-title', '.calp-s-content', '.calp-s-name', '.calp-s-email'].forEach(function (s) { q(s).value = ''; });
          reset();
        } else {
          show(t.error + ' ' + ((res && res.message) || t.tryAgain), false);
        }
      });
  });
}
