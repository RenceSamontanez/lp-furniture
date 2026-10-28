/*
 * Glass toasts (partials/alerts.blade.php, css/alerts.css).
 * - server-rendered toasts and window.LpToast.show(type, message) share one behaviour
 * - closes after data-lp-toast-timeout ms (5000, errors 8000); hover or keyboard focus inside pauses it and it resumes
 *   with the time that was left; the X closes at once
 * - newest on top, at most 4 shown
 * - light or dark glass follows what sits behind the toast: the closest [data-nav] under its centre (default light),
 *   re-checked on creation, scroll (rAF throttled) and resize
 */
(function () {
  'use strict';

  var MAX = 4;
  var ICONS = {
    success: '<path d="M5 12.5l4.5 4.5L19 7"/>',
    error: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/>',
    warning: '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.01"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>'
  };
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function host() { return document.getElementById('lp-toasts'); }

  function remove(toast) {
    if (!toast || !toast.parentNode || toast.classList.contains('is-leaving')) { return; }
    toast.classList.add('is-leaving');
    setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, reduced ? 0 : 280);
  }

  function arm(toast) {
    var remaining = parseInt(toast.getAttribute('data-lp-toast-timeout'), 10) || 5000;
    var timer = null;
    var startedAt = 0;

    function start() {
      if (timer !== null) { return; }
      startedAt = Date.now();
      timer = setTimeout(function () { remove(toast); }, remaining);
    }
    function pause() {
      if (timer === null) { return; }
      clearTimeout(timer);
      timer = null;
      remaining = Math.max(300, remaining - (Date.now() - startedAt));
    }

    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('mouseleave', function () { if (!toast.contains(document.activeElement)) { start(); } });
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', function () { if (!toast.matches(':hover')) { start(); } });

    var close = toast.querySelector('[data-lp-toast-close]');
    if (close) { close.addEventListener('click', function () { pause(); remove(toast); }); }

    start();
  }

  /* ---- light / dark behind each toast ---- */
  function themeBehind(toast) {
    var r = toast.getBoundingClientRect();
    if (!r.width) { return; }
    var els = document.elementsFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    var theme = 'light';
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      if (e.closest('.lp-toasts') || e.closest('.site-nav')) { continue; }
      var h = e.closest('[data-nav]');
      if (h) { theme = h.getAttribute('data-nav'); break; }
    }
    toast.classList.toggle('light', theme === 'light');
  }
  function themeAll() {
    var h = host();
    if (!h) { return; }
    var list = h.querySelectorAll('.lp-toast');
    for (var i = 0; i < list.length; i++) { themeBehind(list[i]); }
  }
  var raf = 0;
  window.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(themeAll); }, { passive: true });
  window.addEventListener('resize', themeAll);

  function trim() {
    var h = host();
    if (!h) { return; }
    var list = h.querySelectorAll('.lp-toast:not(.is-leaving)');
    for (var i = list.length - 1; i >= MAX; i--) { list[i].parentNode.removeChild(list[i]); }
  }

  // title is optional: a short bold line above the message (message-only calls are unchanged)
  function show(type, message, title) {
    var h = host();
    if (!h || !message) { return null; }
    if (!ICONS[type]) { type = 'info'; }
    var isError = type === 'error';
    var secs = isError ? 8 : 5;
    var el = document.createElement('div');
    el.className = 'lp-toast lp-toast--' + type;
    el.setAttribute('role', isError ? 'alert' : 'status');
    el.setAttribute('data-lp-toast', '');
    el.setAttribute('data-lp-toast-timeout', String(secs * 1000));
    el.style.setProperty('--d', secs + 's');
    el.innerHTML =
      '<span class="lp-toast-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' + ICONS[type] + '</svg></span>' +
      '<div class="lp-toast-msg"></div>' +
      '<button type="button" class="lp-toast-close" data-lp-toast-close aria-label="Dismiss notification"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
      '<span class="lp-toast-bar" aria-hidden="true"></span>';
    var msgEl = el.querySelector('.lp-toast-msg');
    if (title) {
      var t = document.createElement('strong');
      t.className = 'lp-toast-title';
      t.textContent = String(title);
      msgEl.appendChild(t);
    }
    var line = document.createElement('span');
    line.className = 'lp-toast-line';
    line.textContent = String(message);
    msgEl.appendChild(line);
    h.insertBefore(el, h.firstChild);
    themeBehind(el);
    arm(el);
    trim();
    return el;
  }

  window.LpToast = { show: show, dismiss: remove };

  function init() {
    var h = host();
    if (!h) { return; }
    var toasts = h.querySelectorAll('[data-lp-toast]');
    for (var i = 0; i < toasts.length; i++) { arm(toasts[i]); themeBehind(toasts[i]); }
    trim();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
