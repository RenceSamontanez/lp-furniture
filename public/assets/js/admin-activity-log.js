/* Admin Activity Log (docs/admin/activity-log-v1.html). The server does all the filtering and sorting: the controls here only
   submit the filter form (the page works without this file, through the Apply button in <noscript>), and Details is filled from
   data attributes on the button, so no request is made. */
(function () {
  'use strict';

  var form = document.getElementById('al-form');
  if (form) {
    var q = document.getElementById('al-q');
    var timer = null;

    var send = function () {
      // a blank field is left out of the URL so the address stays short
      Array.prototype.forEach.call(form.elements, function (el) {
        if (el.name && el.type !== 'hidden' && el.value === '' && el.type !== 'submit') { el.disabled = true; }
      });
      if (document.getElementById('al-sort') && document.getElementById('al-sort').value === 'new') { document.getElementById('al-sort').disabled = true; }
      form.submit();
    };

    Array.prototype.forEach.call(form.querySelectorAll('select, input[type="date"]'), function (el) {
      el.addEventListener('change', send);
    });
    if (q) {
      q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(send, 500); });
      q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); send(); } });
      // after a search reload, keep typing where the person left off
      if (q.value && /[?&]q=/.test(location.search) && sessionStorage.getItem('alFocus') === '1') {
        q.focus(); q.setSelectionRange(q.value.length, q.value.length);
      }
      q.addEventListener('focus', function () { try { sessionStorage.setItem('alFocus', '1'); } catch (e) {} });
      q.addEventListener('blur', function () { try { sessionStorage.removeItem('alFocus'); } catch (e) {} });
    }
  }

  // Details: runs before the shared modal script opens the window (this listener sits lower in the page than the document one)
  var rows = document.getElementById('al-rows');
  if (rows) {
    rows.addEventListener('click', function (e) {
      var b = e.target.closest('[data-when]');
      if (!b) { return; }
      var box = document.getElementById('al-dl');
      ['when', 'who', 'what', 'type', 'item'].forEach(function (k) {
        var dd = box.querySelector('[data-f="' + k + '"]');
        if (dd) { dd.textContent = b.getAttribute('data-' + k) || ''; }
      });
      var open = document.getElementById('al-open');
      var url = b.getAttribute('data-url') || '';
      if (url) { open.setAttribute('href', url); open.hidden = false; } else { open.removeAttribute('href'); open.hidden = true; }
    });
  }
})();
