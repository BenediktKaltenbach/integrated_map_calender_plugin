/* global calpPlace, L */
'use strict';

// Admin: choose the position of a map place by clicking or dragging the marker.
(function () {
  var el = document.getElementById('calp-place-map');
  var latIn = document.getElementById('calp-lat');
  var lngIn = document.getElementById('calp-lng');
  if (!el || !latIn || !lngIn || typeof L === 'undefined') return;

  var lat = parseFloat(latIn.value);
  var lng = parseFloat(lngIn.value);
  var has = !isNaN(lat) && !isNaN(lng);
  var map = L.map(el).setView(has ? [lat, lng] : [calpPlace.lat, calpPlace.lng], has ? 13 : calpPlace.zoom);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
  var marker = has ? L.marker([lat, lng], { draggable: true }).addTo(map) : null;

  function set(ll) {
    latIn.value = ll.lat.toFixed(6);
    lngIn.value = ll.lng.toFixed(6);
    if (!marker) {
      marker = L.marker(ll, { draggable: true }).addTo(map);
      marker.on('dragend', function () { set(marker.getLatLng()); });
    } else {
      marker.setLatLng(ll);
    }
  }
  if (marker) marker.on('dragend', function () { set(marker.getLatLng()); });
  map.on('click', function (e) { set(e.latlng); });

  function fromInputs() {
    var a = parseFloat(latIn.value);
    var b = parseFloat(lngIn.value);
    if (!isNaN(a) && !isNaN(b)) { set(L.latLng(a, b)); map.setView([a, b], Math.max(map.getZoom(), 12)); }
  }
  latIn.addEventListener('change', fromInputs);
  lngIn.addEventListener('change', fromInputs);
  setTimeout(function () { map.invalidateSize(); }, 300);
})();
