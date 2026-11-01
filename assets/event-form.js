/* global calpEventForm */
'use strict';

// "Find place" for the event form and the event admin box: shows up to five OpenStreetMap
// matches to choose from. No search-as-you-type – Nominatim does not allow that.
(function () {
  function setup(box) {
    var cfg = window.calpEventForm;
    var input = box.querySelector('.calp-place-q');
    var btn = box.querySelector('.calp-place-btn');
    var hits = box.querySelector('.calp-place-hits');
    var target = document.getElementById(box.getAttribute('data-target'));
    if (!cfg || !input || !btn || !hits || !target) return;
    var t = cfg.i18n;

    btn.addEventListener('click', function () {
      hits.textContent = t.searching;
      fetch(cfg.places + (cfg.places.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(input.value))
        .then(function (r) { return r.json(); })
        .then(function (list) {
          hits.textContent = '';
          if (!Array.isArray(list) || !list.length) {
            hits.textContent = list && list.message ? list.message : t.none;
            return;
          }
          list.forEach(function (hit) {
            var row = document.createElement('p');
            var choose = document.createElement('button');
            choose.type = 'button';
            choose.className = 'button button-small calp-btn calp-btn--small';
            choose.textContent = t.choose;
            choose.addEventListener('click', function () {
              target.value = hit.lat + ', ' + hit.lng;
              hits.textContent = t.chosen + ' ' + hit.name;
            });
            var link = document.createElement('a');
            link.href = 'https://www.openstreetmap.org/?mlat=' + hit.lat + '&mlon=' + hit.lng + '#map=15/' + hit.lat + '/' + hit.lng;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = hit.name;
            row.appendChild(choose);
            row.appendChild(document.createTextNode(' '));
            row.appendChild(link);
            hits.appendChild(row);
          });
        })
        .catch(function () { hits.textContent = t.none; });
    });
  }

  function init() { document.querySelectorAll('.calp-place-search').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
