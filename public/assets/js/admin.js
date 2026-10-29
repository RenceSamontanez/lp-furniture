/* ============================================================
   LP Furniture — Admin sidebar
   Collapsible groups (state kept in localStorage, safely), "Find a page",
   and the phone drawer (backdrop, Escape, focus return, Tab trap, no page scroll).
   ============================================================ */
(function () {
  var KEY = 'lpAdminMenuShut';
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled])';

  function readShut() {
    try { var v = JSON.parse(localStorage.getItem(KEY) || '{}'); return v && typeof v === 'object' ? v : {}; } catch (e) { return {}; }
  }
  function saveShut(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* works without storage */ } }

  document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.getElementById('admin-sidebar');
    var overlay = document.getElementById('admin-overlay');
    var burger = document.getElementById('admin-burger');
    var closeBtn = document.getElementById('admin-sidebar-close');
    var nav = document.getElementById('mnu');
    var find = document.getElementById('mnu-find');
    var none = document.getElementById('mnu-none');
    if (!sidebar || !nav) { return; }

    var shut = readShut();
    var groups = [].slice.call(nav.querySelectorAll('.mnu-g'));

    function setShut(g, isShut) {
      g.classList.toggle('is-shut', isShut);
      var h = g.querySelector('.mnu-h');
      h.setAttribute('aria-expanded', isShut ? 'false' : 'true');
      h.title = h.getAttribute('aria-disabled') === 'true' ? 'This group holds the page you are on' : (isShut ? 'Click to expand' : 'Click to collapse');
    }

    // The group holding the current page can never be collapsed away.
    groups.forEach(function (g) {
      var h = g.querySelector('.mnu-h');
      if (g.hasAttribute('data-current')) { h.setAttribute('aria-disabled', 'true'); }
      setShut(g, !g.hasAttribute('data-current') && shut[g.dataset.group] === true);
    });
    nav.classList.add('no-anim');
    requestAnimationFrame(function () { requestAnimationFrame(function () { nav.classList.remove('no-anim'); }); });

    nav.addEventListener('click', function (e) {
      var h = e.target.closest('.mnu-h');
      if (!h || h.getAttribute('aria-disabled') === 'true') { return; }
      var g = h.parentNode, next = !g.classList.contains('is-shut');
      setShut(g, next);
      shut[g.dataset.group] = next;
      saveShut(shut);
    });

    // Find a page: filters the items live; while searching every matching group shows open.
    if (find) {
      find.addEventListener('input', function () {
        var q = find.value.trim().toLowerCase(), any = false;
        groups.forEach(function (g) {
          var hit = false;
          [].forEach.call(g.querySelectorAll('.mnu-i'), function (a) {
            var ok = !q || a.dataset.label.toLowerCase().indexOf(q) !== -1;
            a.hidden = !ok;
            if (ok) { hit = true; }
          });
          g.hidden = !hit;
          if (hit) { any = true; }
          if (q) { g.classList.remove('is-shut'); g.querySelector('.mnu-h').setAttribute('aria-expanded', 'true'); }
          else { setShut(g, !g.hasAttribute('data-current') && shut[g.dataset.group] === true); }
        });
        if (none) { none.hidden = any || !q; }
      });
    }

    // Phone drawer
    function isDrawer() { return window.matchMedia('(max-width: 1000px)').matches; }
    function openMenu() {
      sidebar.classList.add('open');
      if (overlay) { overlay.classList.add('show'); }
      document.body.classList.add('menu-open');
      if (burger) { burger.setAttribute('aria-expanded', 'true'); }
      (closeBtn || sidebar).focus();
    }
    function closeMenu(restore) {
      if (!sidebar.classList.contains('open')) { return; }
      sidebar.classList.remove('open');
      if (overlay) { overlay.classList.remove('show'); }
      document.body.classList.remove('menu-open');
      if (burger) { burger.setAttribute('aria-expanded', 'false'); if (restore !== false) { burger.focus(); } }
    }
    if (burger) { burger.addEventListener('click', openMenu); }
    if (closeBtn) { closeBtn.addEventListener('click', function () { closeMenu(); }); }
    if (overlay) { overlay.addEventListener('click', function () { closeMenu(); }); }

    document.addEventListener('keydown', function (e) {
      if (!sidebar.classList.contains('open') || !isDrawer()) { return; }
      if (document.body.classList.contains('lp-modal-open')) { return; }
      if (e.key === 'Escape') { e.preventDefault(); closeMenu(); return; }
      if (e.key !== 'Tab') { return; }
      // collapsed groups keep their header reachable but not their links
      var items = [].filter.call(sidebar.querySelectorAll(FOCUSABLE), function (el) {
        return !el.closest('[hidden]') && !(el.classList.contains('mnu-i') && el.closest('.is-shut')) && (el.offsetWidth || el.offsetHeight);
      });
      if (!items.length) { return; }
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    });

    window.addEventListener('resize', function () { if (!isDrawer()) { closeMenu(false); } });
  });
})();
