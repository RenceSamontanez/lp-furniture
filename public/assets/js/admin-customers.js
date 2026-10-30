/* Admin Customers list and profile (docs/admin/admin-menu-v1.html). The server stays the source of truth:
   every control below only builds a URL (or fetches it for the live search); the page works with these off. */
(function () {
  'use strict';

  var page = document.getElementById('cu-page');
  var DEFAULTS = { status: 'all', orders: 'any', joined: 'any', sort: 'joined-desc' };

  /* ───────────── list ───────────── */
  if (page) {
    var base = page.getAttribute('data-url');
    var tools = document.getElementById('cu-tools');
    var q = document.getElementById('cu-q');
    var sort = document.getElementById('cu-sort');
    var fform = document.getElementById('cu-fform');
    var timer = null, seq = 0, ctl = null;

    // the current view as a short URL: defaults are left out
    function urlFor(p) {
      var parts = [];
      ['search', 'status', 'orders', 'joined', 'min', 'max', 'sort'].forEach(function (k) {
        var v = (p[k] == null ? '' : String(p[k])).trim();
        if (v === '' || DEFAULTS[k] === v) { return; }
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      });
      return base + (parts.length ? '?' + parts.join('&') : '');
    }

    function state() {
      var s = {};
      Array.prototype.forEach.call(tools.elements, function (el) { if (el.name) { s[el.name] = el.value; } });
      return s;
    }

    function swap(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      ['cu-stats', 'cu-dyn'].forEach(function (id) {
        var next = doc.getElementById(id), cur = document.getElementById(id);
        if (next && cur) { cur.innerHTML = next.innerHTML; }
      });
    }

    // live search: fetch the same page the server would show, swap the results, keep the cursor in the box
    function live() {
      var target = urlFor(state());
      var mine = ++seq;
      if (ctl) { ctl.abort(); }
      ctl = window.AbortController ? new AbortController() : null;
      fetch(target, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
        .then(function (r) { if (!r.ok) { throw new Error('bad'); } return r.text(); })
        .then(function (html) { if (mine === seq) { swap(html); history.replaceState(null, '', target); } })
        .catch(function (e) { if (e && e.name !== 'AbortError') { window.location.href = target; } });
    }

    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(live, 300); });
    tools.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); window.location.href = urlFor(state()); });
    sort.addEventListener('change', function () { window.location.href = urlFor(state()); });

    /* ───────────── Filters window ───────────── */
    var min = document.getElementById('cu-min'), max = document.getElementById('cu-max'), err = document.getElementById('cu-ferr');
    function num(v) { var n = parseFloat(String(v).replace(/[^\d.]/g, '')); return isNaN(n) ? null : n; }
    function clearError() { err.hidden = true; [min, max].forEach(function (i) { i.classList.remove('is-bad'); }); }

    document.getElementById('cu-freset').addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('#cu-filters input[type=radio]'), function (r) { r.checked = (r.value === r.getAttribute('data-default')); });
      min.value = ''; max.value = ''; clearError();
    });
    [min, max].forEach(function (i) { i.addEventListener('input', clearError); });

    function submitFilters(e) {
      e.preventDefault();
      var a = num(min.value), b = num(max.value);
      if (a !== null && b !== null && a > b) {
        err.hidden = false; [min, max].forEach(function (i) { i.classList.add('is-bad'); }); min.focus();
        return;
      }
      var s = state();
      ['status', 'orders', 'joined'].forEach(function (k) {
        var r = document.querySelector('#cu-filters input[name=' + k + ']:checked');
        s[k] = r ? r.value : DEFAULTS[k];
      });
      s.min = a === null ? '' : a; s.max = b === null ? '' : b;
      window.location.href = urlFor(s);
    }
    fform.addEventListener('submit', submitFilters);

    // a hand-edited link with min above max: nothing was applied, so open the window with the message
    if (page.getAttribute('data-range-error') === '1' && window.LpModal) {
      var m = document.getElementById('cu-filters');
      if (m) { window.LpModal.open(m, document.querySelector('.cu-filter-btn')); }
    }
  }

  /* ───────────── profile tabs ───────────── */
  var tabs = document.querySelectorAll('.cp-tab');
  if (tabs.length) {
    var show = function (name, focus) {
      Array.prototype.forEach.call(tabs, function (t) {
        var on = t.getAttribute('data-tab') === name;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (on && focus) { t.focus(); }
      });
      Array.prototype.forEach.call(document.querySelectorAll('.cp-pane'), function (p) { p.hidden = p.getAttribute('data-pane') !== name; });
      try {
        var u = new URL(window.location.href);
        if (name === 'orders') { u.searchParams.delete('tab'); } else { u.searchParams.set('tab', name); }
        history.replaceState(null, '', u.toString());
      } catch (e) { /* no history API: the tab still works */ }
    };
    Array.prototype.forEach.call(tabs, function (t, i) {
      t.addEventListener('click', function () { show(t.getAttribute('data-tab'), false); });
      t.addEventListener('keydown', function (e) {
        var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
        if (n === null) { return; }
        e.preventDefault();
        show(tabs[(n + tabs.length) % tabs.length].getAttribute('data-tab'), true);
      });
    });
  }
}());
