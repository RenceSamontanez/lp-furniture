/* Home hero rails: a rail only loops when one copy of its list is taller than the rail; otherwise it stands still
   (the clones are hidden by CSS through .is-static). Phones show the rails as a sideways swipe, where the loop is switched off by CSS. */
(function () {
  var rails = document.querySelectorAll('.lp-rail');
  if (!rails.length) return;

  function measure() {
    rails.forEach(function (rail) {
      var view = rail.querySelector('.lp-rail__view');
      var track = rail.querySelector('.lp-rail__track');
      if (!view || !track || !view.clientHeight) return;
      rail.classList.remove('is-static');
      var one = track.scrollHeight / 2;
      if (one <= view.clientHeight) rail.classList.add('is-static');
    });
  }

  var t;
  window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(measure, 150); });
  window.addEventListener('load', measure);
  measure();
})();
