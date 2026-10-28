/* Customer notifications: the navbar dropdown (>= 1001px) and the filter controls on the full Notifications page.
   Markup: resources/views/partials/notif-dropdown.blade.php, notif-controls.blade.php. Styles: assets/css/notifications.css.
   - Nothing from a notification is ever put in with innerHTML: titles and texts go in with textContent.
   - The dropdown reads GET /notifications/feed (the signed-in customer's own rows) and marks read with PATCH /notifications/{id}/read.
   - While the dropdown is open Luma is hidden with the body class "notif-open" (CSS only). Her chat window keeps its own "open" class and
     her conversation stays in the page, so closing the dropdown shows her exactly as she was. */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  /* ---------- shared: wire the three controls of one scope ---------- */
  function bindControls(root, h) {
    var sw = root.querySelector('input[name="unread"]');
    var from = root.querySelector('[data-nf-from]');
    var to = root.querySelector('[data-nf-to]');
    var datesBtn = root.querySelector('[data-nf-act="dates"]');
    var panel = datesBtn ? document.getElementById(datesBtn.getAttribute('aria-controls')) : null;

    function syncDatesBtn() {
      if (!datesBtn || !panel) return;
      datesBtn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
      if ((from && from.value) || (to && to.value)) datesBtn.setAttribute('data-active', '1'); else datesBtn.removeAttribute('data-active');
    }
    if (datesBtn && panel) {
      datesBtn.addEventListener('click', function () { panel.hidden = !panel.hidden; syncDatesBtn(); });
    }
    if (sw) sw.addEventListener('change', function () { h.onChange(); });
    [from, to].forEach(function (i) { if (i) i.addEventListener('change', function () { syncDatesBtn(); h.onChange(); }); });
    qsa(root, '[data-nf-act="markall"]').forEach(function (b) { b.addEventListener('click', function () { h.onMarkAll && h.onMarkAll(); }); });
    qsa(document, '[data-nf-act="clear"]').forEach(function (b) {
      if (!root.contains(b) && !(h.clearScope && h.clearScope.contains(b))) return;
      b.addEventListener('click', function () { h.onClear(); });
    });
    return {
      get: function () { return { unread: !!(sw && sw.checked), from: from ? from.value : '', to: to ? to.value : '' }; },
      reset: function () { if (sw) sw.checked = false; if (from) from.value = ''; if (to) to.value = ''; syncDatesBtn(); },
      syncDatesBtn: syncDatesBtn
    };
  }

  /* ---------- full page: filters are query-string links, so a change is a plain GET ---------- */
  var pageForm = document.querySelector('[data-nf-page]');
  if (pageForm) {
    bindControls(pageForm, {
      onChange: function () {
        /* leave empty dates out of the address */
        qsa(pageForm, 'input[type="date"]').forEach(function (i) { if (!i.value) i.disabled = true; });
        if (pageForm.requestSubmit) pageForm.requestSubmit(); else pageForm.submit();
      },
      onClear: function () {}
    });
  }

  /* ---------- navbar dropdown ---------- */
  var dd = document.getElementById('notif-dd');
  var bell = document.getElementById('sn-bell');
  if (!dd || !bell) return;

  var wide = window.matchMedia('(min-width: 1001px)');
  var list = dd.querySelector('[data-nf-list]');
  var countEl = dd.querySelector('[data-nf-count]');
  var dot = document.getElementById('sn-bell-dot');
  var sr = document.getElementById('sn-bell-sr');
  var unreadNow = parseInt(dd.getAttribute('data-unread'), 10) || 0;
  var seq = 0;
  var filters = { unread: false, from: '', to: '' };
  var SVG = 'http://www.w3.org/2000/svg';
  var ICON = { order: 'nf-bag', service: 'nf-pencil', account: 'nf-shield' };

  var ctl = bindControls(dd, {
    onChange: function () { filters = ctl.get(); load(); },
    onClear: function () { ctl.reset(); filters = ctl.get(); load(); },
    onMarkAll: function () { send(dd.getAttribute('data-read-all-url')).then(load); },
    clearScope: list
  });

  function setUnread(n) {
    unreadNow = n;
    countEl.textContent = n ? n + ' unread' : 'All caught up';
    if (dot) dot.hidden = !n;
    if (sr) sr.hidden = !n;
  }

  function send(url) {
    return fetch(url, {
      method: 'PATCH',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); }).then(function (j) {
      if (j && typeof j.unread === 'number') setUnread(j.unread);
      return j;
    });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  /* only same-site relative links ever reach an href */
  function safeHref(u) { return typeof u === 'string' && u.charAt(0) === '/' && u.charAt(1) !== '/' ? u : null; }

  function item(n) {
    var a = el('article', 'nf-item' + (n.read ? '' : ' nf-item--unread'));
    a.setAttribute('data-id', n.id);
    a.tabIndex = -1;
    var ic = el('div', 'nf-ic');
    ic.setAttribute('aria-hidden', 'true');
    var svg = document.createElementNS(SVG, 'svg');
    var use = document.createElementNS(SVG, 'use');
    use.setAttribute('href', '#' + (ICON[n.kind] || ICON.account));
    svg.appendChild(use);
    ic.appendChild(svg);
    var body = el('div', 'nf-body');
    var h = el('h3', 'nf-item-title');
    if (!n.read) { var ud = el('span', 'nf-ud'); ud.setAttribute('role', 'img'); ud.setAttribute('aria-label', 'Unread'); h.appendChild(ud); }
    h.appendChild(document.createTextNode(window.LpTitleCase ? window.LpTitleCase.apply(n.title) : n.title));
    body.appendChild(h);
    body.appendChild(el('p', 'nf-text', n.message));
    var meta = el('div', 'nf-meta');
    var t = el('time', 'nf-when', n.time);
    t.setAttribute('datetime', n.iso);
    meta.appendChild(t);
    var href = safeHref(n.details_url);
    if (href) { var v = el('a', 'lp-btn lp-btn--primary lp-btn--chip nf-view', 'View Details'); v.href = href; meta.appendChild(v); }
    if (!n.read) {
      var m = el('button', 'lp-link', 'Mark Read');
      m.type = 'button';
      m.setAttribute('data-nf-read', n.id);
      meta.appendChild(m);
    }
    body.appendChild(meta);
    a.appendChild(ic);
    a.appendChild(body);
    return a;
  }

  function empty(anyFilter) {
    var d = el('div', 'nf-empty-inline');
    d.appendChild(document.createTextNode(anyFilter ? 'No notifications match.' : 'No notifications yet.'));
    if (anyFilter) {
      d.appendChild(document.createElement('br'));
      var b = el('button', 'lp-link lp-link--tap', 'Clear filters');
      b.type = 'button';
      b.setAttribute('data-nf-act', 'clear');
      b.addEventListener('click', function () { ctl.reset(); filters = ctl.get(); load(); });
      d.appendChild(b);
    }
    return d;
  }

  function render(items) {
    while (list.firstChild) list.removeChild(list.firstChild);
    if (!items.length) { list.appendChild(empty(filters.unread || filters.from || filters.to)); return; }
    items.forEach(function (n) { list.appendChild(item(n)); });
  }

  function load() {
    var mine = ++seq;
    var q = [];
    if (filters.unread) q.push('unread=1');
    if (filters.from) q.push('from=' + encodeURIComponent(filters.from));
    if (filters.to) q.push('to=' + encodeURIComponent(filters.to));
    return fetch(dd.getAttribute('data-feed') + (q.length ? '?' + q.join('&') : ''), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); }).then(function (j) {
      if (mine !== seq) return;
      setUnread(j.unread);
      render(j.items);
    }).catch(function () {
      if (mine !== seq) return;
      while (list.firstChild) list.removeChild(list.firstChild);
      list.appendChild(el('div', 'nf-empty-inline', 'Notifications could not be loaded. Please try again.'));
    });
  }

  /* Mark read: update that row, the count and the bell dot in place */
  dd.addEventListener('click', function (e) {
    var b = e.target.closest('[data-nf-read]');
    if (!b) return;
    var id = b.getAttribute('data-nf-read');
    b.disabled = true;
    send(dd.getAttribute('data-read-url').replace('/0/', '/' + encodeURIComponent(id) + '/')).then(function () {
      var row = b.closest('.nf-item');
      if (filters.unread) {
        var next = row.nextElementSibling || row.previousElementSibling;
        row.parentNode.removeChild(row);
        if (!list.querySelector('.nf-item')) { list.appendChild(empty(true)); }
        (next && next.classList.contains('nf-item') ? next : list).setAttribute('tabindex', '-1');
        (next && next.classList.contains('nf-item') ? next : list).focus();
        return;
      }
      row.classList.remove('nf-item--unread');
      var ud = row.querySelector('.nf-ud');
      if (ud) ud.parentNode.removeChild(ud);
      b.parentNode.removeChild(b);
      row.focus();
    }).catch(function () { b.disabled = false; });
  });

  /* ---------- open / close ---------- */
  var isOpen = false;
  function firstFocusable() { return qsa(dd, 'button:not([disabled]), a[href], input:not([disabled])').filter(function (x) { return x.offsetParent !== null; })[0]; }

  function open() {
    if (isOpen) return;
    isOpen = true;
    dd.hidden = false;
    bell.setAttribute('aria-expanded', 'true');
    document.body.classList.add('notif-open'); /* hides Luma's launcher and chat window; her state is untouched */
    filters = ctl.get();
    load();
    var f = firstFocusable();
    if (f) f.focus();
  }

  function close(toBell) {
    if (!isOpen) return;
    isOpen = false;
    dd.hidden = true;
    bell.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('notif-open'); /* Luma is back exactly as she was */
    if (toBell) bell.focus();
  }

  bell.addEventListener('click', function (e) {
    if (!wide.matches || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return; /* narrow screens and new-tab clicks: the normal link */
    e.preventDefault();
    isOpen ? close(false) : open();
  });

  document.addEventListener('click', function (e) {
    if (isOpen && !dd.contains(e.target) && !bell.contains(e.target)) close(false);
  });

  /* capture phase + preventDefault: Luma's own Escape handler (which closes her chat) then leaves her alone */
  document.addEventListener('keydown', function (e) {
    if (!isOpen) return;
    if (e.key === 'Escape') { e.preventDefault(); close(true); return; }
    if (e.key === 'Tab' && e.shiftKey && document.activeElement === firstFocusable()) { e.preventDefault(); bell.focus(); }
  }, true);

  dd.addEventListener('focusout', function (e) {
    var to = e.relatedTarget;
    if (isOpen && to && !dd.contains(to) && to !== bell && !bell.contains(to)) close(false);
  });

  var onWide = function (e) { if (!e.matches) close(false); };
  if (wide.addEventListener) wide.addEventListener('change', onWide); else if (wide.addListener) wide.addListener(onWide);
})();
