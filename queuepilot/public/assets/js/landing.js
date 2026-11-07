/**
 * Homepage only: the principles carousel.
 *
 * Kept in its own file because the CSP is `script-src 'self'` — an inline
 * <script> in the view would be silently blocked. Nothing here touches the
 * console; the file is loaded by the landing page alone.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-lp-carousel]');
  if (!root) return;

  var slides = Array.prototype.slice.call(root.querySelectorAll('[data-lp-slide]'));
  if (slides.length < 2) return;

  var index = 0;
  var show = function (next) {
    index = (next + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      if (i === index) slide.removeAttribute('hidden');
      else slide.setAttribute('hidden', '');
    });
  };

  var prev = root.querySelector('[data-lp-prev]');
  var next = root.querySelector('[data-lp-next]');
  if (prev) prev.addEventListener('click', function () { show(index - 1); });
  if (next) next.addEventListener('click', function () { show(index + 1); });

  // Keyboard support when the carousel itself has focus.
  root.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') { show(index - 1); }
    else if (e.key === 'ArrowRight') { show(index + 1); }
  });

  show(0);
})();
