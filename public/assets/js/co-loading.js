/*
 * Loading feedback for the whole site (customer and admin layouts include it).
 *
 * Forms. On submit of a POST form (opt out with data-no-loading):
 *   - the submit button is disabled and shows a spinner and a short label
 *     ("Processing payment...", "Sending...", "Saving..."),
 *   - a second submit of the same form is ignored (no double clicks),
 *   - payment actions (Stripe, GCash, Place order, Accept agreement, Record
 *     payment, ...) also show a full-page overlay asking people not to close
 *     or refresh the page,
 *   - a thin progress bar runs along the top for any navigation.
 * Links. An <a data-loading="Opening payment..."> does the same on click;
 *   add data-loading-overlay="payment" to show the overlay too.
 * Restore. When the page comes back from the back/forward cache everything is
 *   reset, so a button is never stuck.
 *
 * It never touches a form's data, its one-time tokens or its own handlers:
 * it runs after them, and a submit that a handler cancelled (a declined
 * confirm(), AJAX forms) is left alone.
 */
(function () {
  'use strict';

  if (window.__lpLoading) { return; }          // never register twice
  window.__lpLoading = true;
  window.LPLoading = {                         // for pages that submit with form.submit()
    progress: function (on) { progress(on); }
  };

  var LABELS = [
    [/\/payment\/(stripe|gcash)/, 'Processing payment...'],
    [/\/payments(\?|$)/, 'Recording payment...'],
    [/\/call-update/, 'Sending...'],
    [/\/quotes\/\d+\/send/, 'Sending...'],
    [/\/send(\?|$)/, 'Sending...'],
    [/\/quotes\/\d+\/delete/, 'Deleting...'],
    [/\/accept/, 'Processing...'],
    [/\/decline/, 'Processing...'],
    [/\/quotes/, 'Saving...'],
    [/\/edit/, 'Saving...'],
    [/\/status/, 'Saving...'],
    [/\/read/, 'Saving...'],
    [/\/checkout/, 'Processing payment...'],
    [/\/order(\?|$)/, 'Processing payment...'],
    [/\/review/, 'Saving...'],
    [/\/logout/, 'Signing out...'],
    [/\/login/, 'Signing in...'],
    [/\/register/, 'Creating account...']
  ];

  // Actions that move money or commit to it: they get the full-page overlay.
  var PAYMENT_ACTION = /\/payment\/(stripe|gcash)|\/payments(\?|$)|\/checkout(\?|$)|\/order(\?|$)|\/quotes\/\d+\/accept/;

  var SAFETY_MS = 45000;                       // give the page back if nothing happened
  var safetyTimer = null;

  function labelFor(form, button) {
    if (button && button.getAttribute('data-loading')) { return button.getAttribute('data-loading'); }
    if (form.getAttribute('data-loading')) { return form.getAttribute('data-loading'); }
    var action = form.getAttribute('action') || '';
    for (var i = 0; i < LABELS.length; i++) {
      if (LABELS[i][0].test(action)) { return LABELS[i][1]; }
    }
    return 'Processing...';
  }

  function isPayment(form, button) {
    var flag = (button && button.getAttribute('data-loading-overlay')) || form.getAttribute('data-loading-overlay');
    if (flag) { return flag === 'payment'; }
    return PAYMENT_ACTION.test(form.getAttribute('action') || '');
  }

  /* ── progress bar and overlay (markup lives in partials/co-loading) ── */

  function progress(on) {
    var bar = document.getElementById('lp-progress');
    if (!bar) { return; }
    if (on) { bar.hidden = false; bar.classList.add('is-running'); }
    else { bar.classList.remove('is-running'); bar.hidden = true; }
  }

  function overlay(on) {
    var el = document.getElementById('lp-pay-overlay');
    if (!el) { return; }
    el.hidden = !on;
    document.documentElement.classList.toggle('lp-overlay-open', on);
  }

  function armSafety() {
    clearTimeout(safetyTimer);
    safetyTimer = setTimeout(restoreAll, SAFETY_MS);
  }

  /* ── button state ── */

  function remember(button) {
    if (button.getAttribute('data-co-original') !== null) { return; }
    button.setAttribute('data-co-original', button.innerHTML);
    // The page's own :disabled style can grey a button out; keep its colours
    // so the loading state stays clearly "working", not "dead".
    var cs = window.getComputedStyle(button);
    button.style.setProperty('--co-bg', cs.backgroundColor);
    button.style.setProperty('--co-fg', cs.color);
  }

  function setLoading(button, text) {
    remember(button);
    button.classList.add('co-loading');
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="co-spinner" aria-hidden="true"></span><span>' + text + '</span>';
    button.disabled = true;
  }

  function resetButton(b) {
    if (b.getAttribute('data-co-original') === null) { return; }
    b.innerHTML = b.getAttribute('data-co-original');
    b.removeAttribute('data-co-original');
    b.classList.remove('co-loading');
    b.removeAttribute('aria-busy');
    b.style.removeProperty('--co-bg');
    b.style.removeProperty('--co-fg');
    b.disabled = false;
  }

  function resetForm(form) {
    form.removeAttribute('data-submitting');
    form.querySelectorAll('[data-co-original]').forEach(resetButton);
    form.querySelectorAll('input[data-co-hidden]').forEach(function (i) { i.remove(); });
  }

  // The submit went nowhere (cancelled, GET, opted out): drop what the capture
  // phase remembered so the button is exactly as the page made it.
  function forget(form) {
    form.querySelectorAll('[data-co-original]').forEach(function (b) {
      if (!b.classList.contains('co-loading')) {
        b.removeAttribute('data-co-original');
        b.style.removeProperty('--co-bg');
        b.style.removeProperty('--co-fg');
      }
    });
  }

  function restoreAll() {
    clearTimeout(safetyTimer);
    document.querySelectorAll('form[data-submitting]').forEach(resetForm);
    document.querySelectorAll('[data-co-original]').forEach(resetButton);
    document.querySelectorAll('a[data-loading-active]').forEach(function (a) {
      a.removeAttribute('data-loading-active');
    });
    progress(false);
    overlay(false);
  }

  /* ── forms ── */

  // Capture phase, before any handler of the page: remember how the submit
  // buttons looked (a page may rewrite or disable them in its own handler).
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || form.hasAttribute('data-no-loading')) { return; }
    form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach(function (b) {
      if (b.tagName === 'BUTTON') { remember(b); }
    });
  }, true);

  // Bubble phase, after the page's own handlers.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if (e.defaultPrevented) { forget(form); return; }
    if (form.hasAttribute('data-no-loading') || (form.target && form.target !== '_self')) { forget(form); return; }

    var method = (form.getAttribute('method') || 'get').toLowerCase();

    if (method !== 'post') {
      // Search and filter forms: the bar only, nothing is disabled.
      if (!form.getAttribute('data-loading')) { forget(form); progress(true); armSafety(); return; }
    }

    if (form.getAttribute('data-submitting') === '1') {
      e.preventDefault();             // already on its way
      return;
    }

    var button = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    form.setAttribute('data-submitting', '1');
    progress(true);
    armSafety();

    if (isPayment(form, button)) { overlay(true); }

    if (!button) { return; }

    // A disabled button is not submitted: keep its name/value as a hidden field.
    if (button.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = button.name;
      hidden.value = button.value;
      hidden.setAttribute('data-co-hidden', '1');
      form.appendChild(hidden);
    }

    // Disable after the browser has started the submit.
    if (button.tagName === 'BUTTON') {
      setTimeout(function () { setLoading(button, labelFor(form, button)); }, 0);
    }
  });

  /* ── links ── */

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
    var link = e.target.closest ? e.target.closest('a[href]') : null;
    if (!link || link.hasAttribute('data-no-loading')) { return; }

    var href = link.getAttribute('href') || '';
    var target = link.getAttribute('target');
    if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
    if ((target && target !== '_self') || link.hasAttribute('download')) { return; }
    if (/\.(pdf|csv|xlsx?|zip|png|jpe?g|gif|webp)(\?|$)|\/(export|download)/i.test(link.pathname || '')) { return; }   // stays on this page
    if (link.origin && link.origin !== window.location.origin) { return; }
    if (link.pathname === window.location.pathname && link.search === window.location.search && link.hash) { return; }

    // Only links that ask for it get a spinner and the label; every other
    // internal link just gets the progress bar.
    var text = link.getAttribute('data-loading');

    if (text) {
      if (link.getAttribute('data-loading-active') === '1') { e.preventDefault(); return; }
      link.setAttribute('data-loading-active', '1');
      remember(link);
      link.classList.add('co-loading');
      link.setAttribute('aria-busy', 'true');
      link.innerHTML = '<span class="co-spinner" aria-hidden="true"></span><span>' + text + '</span>';
      if (link.getAttribute('data-loading-overlay') === 'payment') { overlay(true); }
    }

    progress(true);
    armSafety();
  });

  /* ── back/forward cache: never leave a page with a stuck button or overlay ── */
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) { restoreAll(); }
  });
})();
