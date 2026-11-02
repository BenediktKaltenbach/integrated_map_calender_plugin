'use strict';

// [calp_articles layout="slider"]: arrows scroll the row by one card, "Show all" opens the full grid in place.
// The controls only appear when the row is wider than the page.
(function () {
  function setup(box) {
    var row = box.querySelector('.calp-cards');
    var nav = box.querySelector('.calp-articles__nav');
    var prev = box.querySelector('.calp-articles__prev');
    var next = box.querySelector('.calp-articles__next');
    var all = box.querySelector('.calp-articles__all');
    var more = all.textContent;

    function step() {
      var card = row.querySelector('.calp-card');
      return card ? card.getBoundingClientRect().width + 20 : row.clientWidth;
    }

    function update() {
      var open = box.classList.contains('is-open');
      var overflow = row.scrollWidth > row.clientWidth + 2;
      nav.hidden = !open && !overflow;
      prev.hidden = next.hidden = open;
      prev.disabled = row.scrollLeft <= 2;
      next.disabled = row.scrollLeft + row.clientWidth >= row.scrollWidth - 2;
    }

    prev.addEventListener('click', function () { row.scrollBy({ left: -step(), behavior: 'smooth' }); });
    next.addEventListener('click', function () { row.scrollBy({ left: step(), behavior: 'smooth' }); });
    all.addEventListener('click', function () {
      var open = box.classList.toggle('is-open');
      all.setAttribute('aria-expanded', open ? 'true' : 'false');
      all.textContent = open ? all.getAttribute('data-less') : more;
      if (!open) {
        row.scrollLeft = 0;
        if (box.getBoundingClientRect().top < 0) box.scrollIntoView({ behavior: 'smooth' });
      }
      update();
    });
    row.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  function init() { document.querySelectorAll('.calp-articles--slider').forEach(setup); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
