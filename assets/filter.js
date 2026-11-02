'use strict';

// Category filter above the event list. Switches without reloading, keeps ?calp_cat= in the
// address (one category) and stays in step with the map: both send and listen to "calp:filter"
// with detail { cats: [slugs] or null for all, source }.
(function () {
  function setup(nav) {
    var list = nav.nextElementSibling;
    var after = list && list.nextElementSibling;
    var empty = after && after.classList.contains('calp-empty--filtered') ? after : null;
    var buttons = Array.prototype.slice.call(nav.querySelectorAll('.calp-filter__btn'));
    var all = buttons.map(function (b) { return b.getAttribute('data-cat'); }).filter(Boolean);

    function apply(cats) {
      if (cats && all.every(function (c) { return cats.indexOf(c) !== -1; })) cats = null;
      buttons.forEach(function (b) {
        var c = b.getAttribute('data-cat');
        var on = cats ? cats.length === 1 && cats[0] === c : c === '';
        b.classList.toggle('is-active', on);
        if (on) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
      });
      var shown = 0;
      if (list) {
        list.querySelectorAll('.calp-events__group').forEach(function (g) {
          var n = 0;
          g.querySelectorAll('.calp-event').forEach(function (li) {
            var on = !cats || cats.indexOf(li.getAttribute('data-cat')) !== -1;
            li.hidden = !on;
            if (on) n++;
          });
          g.hidden = !n;
          shown += n;
        });
      }
      if (empty) empty.hidden = shown > 0;
      if (window.URL && history.replaceState) {
        var url = new URL(location.href);
        if (cats && cats.length === 1) url.searchParams.set('calp_cat', cats[0]);
        else url.searchParams.delete('calp_cat');
        history.replaceState(null, '', url.toString());
      }
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        var c = b.getAttribute('data-cat');
        var cats = c ? [c] : null;
        apply(cats);
        document.dispatchEvent(new CustomEvent('calp:filter', { detail: { cats: cats, source: nav } }));
      });
    });

    document.addEventListener('calp:filter', function (e) {
      if (e.detail && e.detail.source !== nav) apply(e.detail.cats);
    });
  }

  function init() { document.querySelectorAll('.calp-filter').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
