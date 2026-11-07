/**
 * Homepage only.
 *
 * Kept in its own file because the CSP is `script-src 'self'` — an inline
 * <script> in the view would be silently blocked. Nothing here touches the
 * console; the landing page alone loads it.
 *
 * Everything is progressive enhancement. The reveal styles are armed by this
 * script (`.lp-reveal-ready` on <html>), so with scripting off, or if
 * IntersectionObserver is missing, the page is simply fully visible. Motion is
 * skipped entirely when the visitor has asked for reduced motion.
 */
(function () {
  'use strict';

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- scroll reveal ------------------------------------------------ */
  var targets = document.querySelectorAll('[data-reveal], [data-reveal-group]');
  if (!reduce && 'IntersectionObserver' in window && targets.length) {
    document.documentElement.classList.add('lp-reveal-ready');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target); // reveal once; never yank content away
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.01 });
    Array.prototype.forEach.call(targets, function (el) { io.observe(el); });
  }

  /* ---- header state + reading progress ------------------------------ */
  var header = document.querySelector('[data-lp-header]');
  var bar = document.querySelector('[data-lp-progress]');
  var ticking = false;

  function onScroll() {
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    if (header) header.classList.toggle('is-stuck', y > 8);
    if (bar) {
      var doc = document.documentElement;
      var max = (doc.scrollHeight - window.innerHeight) || 1;
      var p = Math.max(0, Math.min(1, y / max));
      bar.style.setProperty('--lp-progress', p.toFixed(4));
    }
    ticking = false;
  }
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(onScroll);
  }, { passive: true });
  onScroll();

  /* ---- count up to the real figure ---------------------------------- */
  // The target is read from the DOM, which the server rendered from the
  // database. This animates towards a true number; it never invents one, and
  // the final frame is always exactly the server's value.
  var counters = document.querySelectorAll('[data-count]');
  function runCount(el) {
    var raw = (el.getAttribute('data-count') || '').trim();
    var target = parseFloat(raw);
    if (!isFinite(target)) return;
    var suffix = el.getAttribute('data-count-suffix') || '';
    if (reduce) { el.textContent = raw + suffix; return; }
    var start = null, dur = 900;
    function step(ts) {
      if (start === null) start = ts;
      var t = Math.min(1, (ts - start) / dur);
      var eased = 1 - Math.pow(1 - t, 3);
      el.textContent = Math.round(target * eased) + suffix;
      if (t < 1) window.requestAnimationFrame(step);
      else el.textContent = raw + suffix; // land on the exact server value
    }
    window.requestAnimationFrame(step);
  }
  if (counters.length && 'IntersectionObserver' in window) {
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        runCount(entry.target);
        co.unobserve(entry.target);
      });
    }, { threshold: 0.5 });
    Array.prototype.forEach.call(counters, function (el) { co.observe(el); });
  }

  /* ---- principles carousel ------------------------------------------ */
  var root = document.querySelector('[data-lp-carousel]');
  if (!root) return;
  var slides = Array.prototype.slice.call(root.querySelectorAll('[data-lp-slide]'));
  if (slides.length < 2) return;

  var index = 0;
  function show(next) {
    index = (next + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      if (i === index) slide.removeAttribute('hidden');
      else slide.setAttribute('hidden', '');
    });
  }
  var prev = root.querySelector('[data-lp-prev]');
  var next = root.querySelector('[data-lp-next]');
  if (prev) prev.addEventListener('click', function () { show(index - 1); });
  if (next) next.addEventListener('click', function () { show(index + 1); });
  root.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowLeft') show(index - 1);
    else if (e.key === 'ArrowRight') show(index + 1);
  });
  show(0);
})();
