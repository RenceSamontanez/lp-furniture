/*
 * Loading feedback for the custom order flow (customer and admin).
 *
 * On submit of any POST form on a page that includes this file:
 *   - the submit button is disabled and shows a small spinner and a short label,
 *   - a second submit of the same form is ignored (no double clicks),
 *   - the page is restored (button usable again) if it comes back from the
 *     back/forward cache, so a server-side error page is never stuck.
 * It does not touch the form's data, its one-time tokens or its own
 * onsubmit confirmations: a form whose handler cancelled the submit is left alone.
 */
(function () {
  'use strict';

  var LABELS = [
    [/\/call-update/, 'Sending...'],
    [/\/quotes\/\d+\/send/, 'Sending...'],
    [/\/send(\?|$)/, 'Sending...'],
    [/\/quotes\/\d+\/delete/, 'Deleting...'],
    [/\/quotes/, 'Saving...'],
    [/\/edit/, 'Saving...'],
    [/\/payments/, 'Saving...'],
    [/\/status/, 'Saving...'],
    [/\/read/, 'Saving...'],
    [/\/accept/, 'Processing...'],
    [/\/decline/, 'Processing...'],
    [/\/checkout/, 'Processing...'],
    [/\/review/, 'Saving...']
  ];

  function labelFor(form, button) {
    if (button && button.getAttribute('data-loading')) { return button.getAttribute('data-loading'); }
    if (form.getAttribute('data-loading')) { return form.getAttribute('data-loading'); }
    var action = form.getAttribute('action') || '';
    for (var i = 0; i < LABELS.length; i++) {
      if (LABELS[i][0].test(action)) { return LABELS[i][1]; }
    }
    return 'Processing...';
  }

  function setLoading(button, text) {
    if (button.getAttribute('data-co-original') === null) {
      button.setAttribute('data-co-original', button.innerHTML);
    }
    button.classList.add('co-loading');
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="co-spinner" aria-hidden="true"></span><span>' + text + '</span>';
    button.disabled = true;
  }

  function reset(form) {
    form.removeAttribute('data-submitting');
    form.querySelectorAll('[data-co-original]').forEach(function (b) {
      b.innerHTML = b.getAttribute('data-co-original');
      b.removeAttribute('data-co-original');
      b.classList.remove('co-loading');
      b.removeAttribute('aria-busy');
      b.disabled = false;
    });
    form.querySelectorAll('input[data-co-hidden]').forEach(function (i) { i.remove(); });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM' || e.defaultPrevented) { return; }
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
    if (form.hasAttribute('data-no-loading')) { return; }

    if (form.getAttribute('data-submitting') === '1') {
      e.preventDefault();             // already on its way
      return;
    }

    var button = e.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    form.setAttribute('data-submitting', '1');

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
    setTimeout(function () { setLoading(button, labelFor(form, button)); }, 0);
  });

  // Back/forward cache: never leave a page with a stuck button.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      document.querySelectorAll('form[data-submitting]').forEach(reset);
    }
  });
})();
