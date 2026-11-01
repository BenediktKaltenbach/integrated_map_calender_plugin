'use strict';

// Tabs for [calp_explorer]: each tab has its own hash (#map, #calendar).
(function () {
  function setup(box) {
    var tabs = Array.prototype.slice.call(box.querySelectorAll('.calp-tab'));
    var panels = Array.prototype.slice.call(box.querySelectorAll('.calp-panel'));

    function show(name, focus) {
      var found = false;
      tabs.forEach(function (tab) {
        var on = tab.getAttribute('href') === '#' + name;
        found = found || on;
        tab.classList.toggle('is-active', on);
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on && focus) tab.focus();
      });
      if (!found) return false;
      panels.forEach(function (p) { p.hidden = p.getAttribute('data-tab') !== name; });
      // The map needs to recalculate its size once its panel is visible.
      document.dispatchEvent(new CustomEvent('calp:shown', { detail: name }));
      return true;
    }

    tabs.forEach(function (tab, i) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        var name = tab.getAttribute('href').slice(1);
        show(name);
        if (history.replaceState) history.replaceState(null, '', '#' + name);
      });
      tab.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        var next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        next.click();
        next.focus();
      });
    });

    var hash = location.hash.slice(1);
    if (!hash || !show(hash)) show(box.getAttribute('data-default') || 'map');
    window.addEventListener('hashchange', function () { show(location.hash.slice(1)); });
  }

  function init() { document.querySelectorAll('.calp-explorer').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
