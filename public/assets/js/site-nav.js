/* Hamburger for the glass nav (<= 860px). Escape closes it and returns focus to the button; a click outside or growing
   the window past 860px also closes it. Nothing else lives here. */
(function () {
  var nav = document.getElementById('site-nav');
  var btn = document.getElementById('sn-burger');
  if (!nav || !btn) return;

  function setOpen(open) {
    nav.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }

  btn.addEventListener('click', function () { setOpen(!nav.classList.contains('is-open')); });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && nav.classList.contains('is-open')) { setOpen(false); btn.focus(); }
  });

  document.addEventListener('click', function (e) {
    if (nav.classList.contains('is-open') && !nav.contains(e.target)) setOpen(false);
  });

  var mq = window.matchMedia('(min-width: 861px)');
  var onChange = function (e) { if (e.matches) setOpen(false); };
  if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
})();
