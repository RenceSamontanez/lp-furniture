/*
 * Auto-dismiss for the session alert toasts (partials/alerts.blade.php).
 * Each toast closes after data-lp-toast-timeout ms (6000). The countdown
 * pauses while the pointer is over the toast or focus is inside it, and
 * resumes with the time that was left. The close button removes it at once.
 */
(function () {
  'use strict';

  function remove(toast) {
    if (!toast || !toast.parentNode) { return; }
    toast.classList.add('is-leaving');
    setTimeout(function () {
      var host = toast.parentNode;
      if (host) { host.removeChild(toast); }
      if (host && host.id === 'lp-toasts' && !host.children.length) { host.remove(); }
    }, 220);
  }

  function arm(toast) {
    var remaining = parseInt(toast.getAttribute('data-lp-toast-timeout'), 10) || 6000;
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
      remaining = Math.max(500, remaining - (Date.now() - startedAt));
    }

    toast.addEventListener('mouseenter', pause);
    toast.addEventListener('mouseleave', start);
    toast.addEventListener('focusin', pause);
    toast.addEventListener('focusout', start);

    var close = toast.querySelector('[data-lp-toast-close]');
    if (close) {
      close.addEventListener('click', function () { pause(); remove(toast); });
    }

    start();
  }

  function init() {
    var toasts = document.querySelectorAll('[data-lp-toast]');
    for (var i = 0; i < toasts.length; i++) { arm(toasts[i]); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
