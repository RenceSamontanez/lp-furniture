/* Admin: Reports and Analytics. The five tabs switch in the browser (arrow keys move between them); the chosen tab is kept in the
   address and in the period links, so switching the period stays on the same tab. Every number is rendered by the server. */
(function () {
  'use strict';
  var tabs = [].slice.call(document.querySelectorAll('.rp-tab'));
  if (!tabs.length) { return; }

  function show(key, focus) {
    tabs.forEach(function (t) {
      var on = t.dataset.tab === key;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      if (on && focus) { t.focus(); }
    });
    var root = document.querySelector('.rp'); if (root) { root.classList.toggle('rp--materials', key === 'materials'); }
    [].forEach.call(document.querySelectorAll('.rp-pane'), function (p) { p.classList.toggle('is-on', p.id === 'rp-' + key); });
    [].forEach.call(document.querySelectorAll('.rp-seg a'), function (a) {
      var u = new URL(a.href, location.href);
      u.searchParams.set('tab', key);
      a.href = u.pathname + u.search;
    });
    try {
      var here = new URL(location.href);
      here.searchParams.set('tab', key);
      history.replaceState(null, '', here.pathname + here.search);
    } catch (e) { /* the address is only a convenience */ }
  }

  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.dataset.tab); });
    t.addEventListener('keydown', function (e) {
      var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? tabs.length - 1 : null;
      if (j === null) { return; }
      e.preventDefault();
      var next = tabs[(j + tabs.length) % tabs.length];
      show(next.dataset.tab, true);
    });
  });
  var on = tabs.filter(function (t) { return t.classList.contains('is-on'); })[0] || tabs[0];
  show(on.dataset.tab);
})();
