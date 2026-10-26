/* Identity review page: photo fit/rotate/enlarge (client side only), required checks, reason chips.
   The server enforces everything again; nothing here is trusted. */
(function () {
  'use strict';
  var root = document.querySelector('[data-idr-review]');
  if (!root) { return; }

  var stages = [].slice.call(root.querySelectorAll('[data-stage]'));
  var rot = stages.map(function () { return 0; });
  var viewer = document.getElementById('idr-viewer');
  var vStage = document.getElementById('idr-viewer-stage');
  var vDeg = 0, vIndex = 0;

  /* Size the image to fit the stage at the current rotation (a 90 degree turn swaps the available width and height). */
  function fit(stage, deg) {
    var img = stage.querySelector('img');
    if (!img) { return; }
    var w = stage.clientWidth, h = stage.clientHeight;
    var turned = (deg / 90) % 2 !== 0;
    img.style.maxWidth = (turned ? h : w) + 'px';
    img.style.maxHeight = (turned ? w : h) + 'px';
    img.style.transform = 'translate(-50%, -50%) rotate(' + deg + 'deg)';
  }
  function fitAll() {
    stages.forEach(function (s, i) { fit(s, rot[i]); });
    if (viewer && viewer.open) { fit(vStage, vDeg); }
  }

  stages.forEach(function (s) {
    var img = s.querySelector('img');
    if (img) {
      img.addEventListener('load', fitAll);
      img.addEventListener('error', function () {
        img.hidden = true;
        var m = s.querySelector('.idr-missing');
        if (m) { m.hidden = false; }
      });
    }
    if (window.ResizeObserver) { new ResizeObserver(fitAll).observe(s); }
  });
  window.addEventListener('resize', fitAll);

  function openViewer(i) {
    var s = stages[i], img = s.querySelector('img');
    if (!img || img.hidden) { return; }
    vIndex = i; vDeg = rot[i];
    document.getElementById('idr-viewer-title').textContent = s.getAttribute('data-title');
    var big = vStage.querySelector('img');
    big.src = img.src; big.alt = img.alt;
    if (viewer.showModal) { viewer.showModal(); } else { viewer.setAttribute('open', ''); }
    fit(vStage, vDeg);
    big.onload = function () { fit(vStage, vDeg); };
  }

  root.querySelectorAll('[data-rotate]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = +b.getAttribute('data-rotate');
      rot[i] = (rot[i] + 90) % 360;
      fitAll();
    });
  });
  root.querySelectorAll('[data-enlarge]').forEach(function (b) {
    b.addEventListener('click', function () { openViewer(+b.getAttribute('data-enlarge')); });
  });
  stages.forEach(function (s, i) {
    s.addEventListener('click', function () { openViewer(i); });
    s.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openViewer(i); }
    });
  });

  if (viewer) {
    document.getElementById('idr-viewer-rotate').addEventListener('click', function () {
      vDeg = (vDeg + 90) % 360; fit(vStage, vDeg);
    });
    document.getElementById('idr-viewer-close').addEventListener('click', function () { viewer.close(); });
    viewer.addEventListener('click', function (e) { if (e.target === viewer) { viewer.close(); } });
    viewer.addEventListener('close', function () { rot[vIndex] = vDeg; fitAll(); });
  }

  /* Required checks */
  var boxes = [].slice.call(root.querySelectorAll('[data-check]'));
  var approve = document.getElementById('idr-approve-btn');
  if (!approve) { return; }
  var cnt = document.getElementById('idr-cnt');
  var checkMsg = document.getElementById('idr-check-msg');
  var group = document.getElementById('idr-checks');

  function ticked() { return boxes.filter(function (b) { return b.checked; }).length; }
  function sync() {
    var n = ticked();
    cnt.textContent = n + ' of ' + boxes.length;
    cnt.classList.toggle('is-full', n === boxes.length);
    approve.setAttribute('aria-disabled', n === boxes.length ? 'false' : 'true');
    approve.classList.toggle('is-locked', n !== boxes.length);
    if (n === boxes.length) { checkMsg.hidden = true; }
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  sync();

  approve.addEventListener('click', function () {
    if (ticked() < boxes.length) {
      checkMsg.hidden = false;
      group.classList.remove('is-shake'); void group.offsetWidth; group.classList.add('is-shake');
      return;
    }
    window.LpModal.open(document.getElementById('idr-approve-modal'), approve);
  });

  /* Reject: a reason is required, then confirm */
  var why = document.getElementById('idr-why');
  var whyMsg = document.getElementById('idr-why-msg');
  function clearWhy() { why.classList.remove('is-err'); whyMsg.hidden = true; }
  why.addEventListener('input', clearWhy);
  root.querySelectorAll('[data-reason-chip]').forEach(function (c) {
    c.addEventListener('click', function () { why.value = c.textContent.trim(); clearWhy(); why.focus(); });
  });
  document.getElementById('idr-reject-btn').addEventListener('click', function (e) {
    var text = why.value.trim();
    if (!text) {
      why.classList.add('is-err'); whyMsg.hidden = false; why.focus();
      return;
    }
    document.getElementById('idr-reject-quote').textContent = text;
    window.LpModal.open(document.getElementById('idr-reject-modal'), e.currentTarget);
  });
  if (why.classList.contains('is-err')) { why.focus(); }
})();
