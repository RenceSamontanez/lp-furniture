/* Smart glass nav (resources/views/layouts/app.blade.php, assets/css/site-nav.css). Values follow docs/nav/nav-prototype.html.
   - hamburger (<= 1000px): Escape closes it and returns focus to the button; a click outside or growing the window closes it
   - auto light/dark: the closest [data-nav] under the bar decides the "light" class (page default: light)
   - hide on scroll: collapses to just the logo after 140px of downward scroll, back on scroll up; hover on the logo (mouse),
     focus inside the nav, or the first tap on the logo (touch) bring it back. Links and burger are inert while collapsed. */
(function () {
  var nav = document.getElementById('site-nav');
  var btn = document.getElementById('sn-burger');
  if (!nav || !btn) return;
  var brand = nav.querySelector('.sn-brand');
  var links = document.getElementById('sn-links');

  var TOP = 40, HIDE_AFTER = 140, DELTA = 8;
  var coarse = window.matchMedia('(pointer: coarse)').matches;
  var lastY = window.pageYOffset, collapsed = false, peek = false, peekTimer = null;

  function isOpen() { return nav.classList.contains('is-open'); }
  function focusInside() {
    var a = document.activeElement;
    return !!a && a !== document.body && a !== brand && nav.contains(a);
  }

  function setOpen(open) {
    nav.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    if (open) setCollapsed(false);
  }

  function setCollapsed(c) {
    if (c === collapsed) return;
    collapsed = c;
    nav.classList.toggle('collapsed', c);
    if (links) { links.toggleAttribute('inert', c); }
    btn.toggleAttribute('inert', c);
    pickTheme();
  }

  /* ---- light / dark from whatever sits under the bar ---- */
  function themeAt(x, y, skip) {
    var els = document.elementsFromPoint(x, y);
    for (var i = 0; i < els.length; i++) {
      if (skip && skip(els[i])) continue;
      var h = els[i].closest && els[i].closest('[data-nav]');
      if (h) return h.getAttribute('data-nav');
    }
    return 'light';
  }
  function pickTheme() {
    var x = collapsed ? 60 : window.innerWidth / 2, y = collapsed ? 26 : 46;
    nav.classList.toggle('light', themeAt(x, y, function (e) { return nav.contains(e); }) === 'light');
  }
  window.lpNavTheme = themeAt;

  var raf = 0;
  function themeSoon() { cancelAnimationFrame(raf); raf = requestAnimationFrame(pickTheme); }

  /* ---- hide on scroll ---- */
  function onScroll() {
    var y = window.pageYOffset, d = y - lastY;
    if (Math.abs(d) < DELTA && y > TOP) { themeSoon(); return; }
    if (y <= TOP) setCollapsed(false);
    else if (d > 0 && y > HIDE_AFTER && !isOpen() && !focusInside() && !peek) setCollapsed(true);
    else if (d < 0) setCollapsed(false);
    lastY = y;
    themeSoon();
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', themeSoon);
  if (window.MutationObserver) {
    new MutationObserver(function () { themeSoon(); }).observe(nav, { attributes: true, attributeFilter: ['class'] });
  }

  function show() { clearTimeout(peekTimer); peek = true; setCollapsed(false); }
  function maybeHide() {
    clearTimeout(peekTimer);
    peekTimer = setTimeout(function () {
      peek = false;
      if (window.pageYOffset > HIDE_AFTER && !isOpen() && !focusInside()) setCollapsed(true);
    }, 700);
  }
  if (brand) {
    brand.addEventListener('mouseenter', function () { if (!coarse) show(); });
    /* touch: the first tap on a collapsed logo shows the bar instead of going home */
    brand.addEventListener('click', function (e) {
      if (collapsed && coarse) { e.preventDefault(); show(); maybeHide(); }
    });
  }
  nav.addEventListener('mouseleave', function () { if (!coarse && peek) maybeHide(); });
  nav.addEventListener('focusin', function () { if (collapsed) setCollapsed(false); });

  btn.addEventListener('click', function () { setOpen(!isOpen()); });
  /* the Assistant link opens the chat, which hides the bar: close the mobile menu so it is not left open behind it */
  nav.addEventListener('click', function (e) { if (e.target.closest('.sn-reactivate') && isOpen()) setOpen(false); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isOpen()) { setOpen(false); btn.focus(); }
  });

  document.addEventListener('click', function (e) {
    if (isOpen() && !nav.contains(e.target)) setOpen(false);
  });

  var mq = window.matchMedia('(min-width: 1001px)');
  var onChange = function (e) { if (e.matches) setOpen(false); };
  if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);

  /* page-level overlays that are not the shared .lp-modal (design preview, gallery lightbox) hide the bar too */
  var OVERLAYS = '#lightboxOverlay.open, #cd-preview-modal-overlay.show';
  function syncOverlay() { document.body.classList.toggle('overlay-open', !!document.querySelector(OVERLAYS)); }
  if (window.MutationObserver) {
    new MutationObserver(syncOverlay).observe(document.body, { attributes: true, attributeFilter: ['class'], subtree: true });
  }
  syncOverlay();

  pickTheme();
  window.addEventListener('load', pickTheme);
})();
