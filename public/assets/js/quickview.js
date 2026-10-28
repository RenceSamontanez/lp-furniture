/* Shared glass Quick view (catalog, home and product pages). Any element with data-quick-view="{id}" data-name="{name}" opens it.
   Markup: resources/views/partials/quick-view.blade.php. Styles: assets/css/quickview.css.
   Behaviour: role=dialog + aria-modal, focus moves in and is trapped, Escape / X / backdrop close, focus returns to the
   opener, body scroll locked, body.modal-open hides the nav while open. Left/Right arrows move through the photos. */
(function () {
  'use strict';
  var mod = document.getElementById('cxMod');
  if (!mod) return;
  var qd = document.getElementById('cxQd');
  var $ = function (id) { return document.getElementById(id); };
  var el = {
    bg: $('cxBg'), ph: $('cxPh'), img: $('cxImg'), badge: $('cxBadge'), prev: $('cxPrev'), next: $('cxNext'), pos: $('cxPos'),
    close: $('cxClose'), cat: $('cxCat'), name: $('cxName'), price: $('cxPrice'), priceLbl: $('cxPriceLbl'), desc: $('cxDesc'),
    info: $('cxInfo'), sizeWrap: $('cxSizeWrap'), dims: $('cxDims'), more: $('cxMore'), retry: $('cxRetry')
  };
  var PLACEHOLDER = '/images/products/placeholder.jpg';
  var cache = {};
  var opener = null, currentId = null, currentName = '', data = null, photo = 0, token = 0, lastOverflow = '';

  function setState(s) { qd.setAttribute('data-state', s); }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
  function make(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function focusables() {
    return Array.prototype.filter.call(
      qd.querySelectorAll('button, a[href], [tabindex]:not([tabindex="-1"])'),
      function (n) { return !n.hidden && n.offsetParent !== null && !n.disabled; }
    );
  }

  function open(id, name, btn) {
    opener = btn; currentId = id; currentName = name; data = null; photo = 0;
    el.name.textContent = name;           // labels the dialog straight away, before the data arrives
    el.cat.textContent = '';
    lastOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.body.classList.add('modal-open');
    mod.hidden = false;
    mod.classList.add('on');
    load();
    el.close.focus();
  }

  function close() {
    token++;                               // ignore any answer still on its way
    mod.classList.remove('on');
    mod.hidden = true;
    document.body.style.overflow = lastOverflow;
    document.body.classList.remove('modal-open');
    if (opener && document.contains(opener)) opener.focus();
    opener = null;
  }

  function load() {
    var mine = ++token;
    if (cache[currentId]) { render(cache[currentId]); return; }
    setState('loading');
    el.img.removeAttribute('src');
    fetch('/product/' + encodeURIComponent(currentId) + '/quick-view', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (j) {
        if (mine !== token) return;
        cache[currentId] = j;
        render(j);
      })
      .catch(function () {
        if (mine !== token) return;
        setState('error');
        el.retry.focus();
      });
  }

  function addRow(label, value) {
    if (value == null || String(value).trim() === '') return;
    var row = make('div', 'cx-ir');
    row.appendChild(make('dt', null, label));
    row.appendChild(make('dd', null, value));
    el.info.appendChild(row);
  }

  function num(v) { return Number(v).toLocaleString('en-PH', { maximumFractionDigits: 1 }); }

  function render(p) {
    data = p;
    var mine = token;
    el.name.textContent = p.name || currentName;
    el.cat.textContent = p.category || '';
    el.price.textContent = p.price_display || p.price || '';
    el.priceLbl.textContent = p.on_sale ? ('Was ' + p.price) : 'Price';
    if (p.description && String(p.description).trim()) { el.desc.textContent = p.description; el.desc.hidden = false; }
    else { el.desc.textContent = ''; el.desc.hidden = true; }

    clear(el.info);
    addRow('Material', p.material);
    addRow('Finish', p.finish);
    el.info.hidden = !el.info.firstChild;

    clear(el.dims);
    var cm = p.size_cm, inch = p.size_inches;
    if (cm && typeof cm === 'object' && cm.length != null && cm.width != null && cm.height != null) {
      [['Length', 'length'], ['Width', 'width'], ['Height', 'height']].forEach(function (d) {
        var cell = make('div', 'cx-dm');
        cell.appendChild(make('small', null, d[0]));
        var b = make('b', null, num(cm[d[1]]));
        b.appendChild(make('i', null, 'cm'));
        cell.appendChild(b);
        if (inch && inch[d[1]] != null) cell.appendChild(make('em', null, num(inch[d[1]]) + ' in'));
        el.dims.appendChild(cell);
      });
      el.sizeWrap.hidden = false;
    } else {
      el.sizeWrap.hidden = true;
    }

    if (p.badge) { el.badge.textContent = p.badge; el.badge.hidden = false; } else { el.badge.hidden = true; }
    el.more.setAttribute('href', p.detail_url || '#');
    var n = (p.gallery_images || []).length;
    el.prev.hidden = el.next.hidden = el.pos.hidden = n < 2;
    showPhoto(0).then(function () {
      if (mine !== token) return;
      setState('ready');
      if (!qd.contains(document.activeElement) || el.retry === document.activeElement) el.close.focus();
    });
  }

  /* The photo box takes the photo's own proportions, so the whole photo shows (object-fit: contain), never stretched or cropped. */
  function showPhoto(i) {
    var list = (data && data.gallery_images && data.gallery_images.length) ? data.gallery_images : [PLACEHOLDER];
    photo = (i + list.length) % list.length;
    var src = list[photo];
    var mine = token;
    return new Promise(function (resolve) {
      var t = new Image();
      var done = function (ok) {
        if (mine !== token) return resolve();
        var useSrc = ok ? src : PLACEHOLDER;
        var ar = (ok && t.naturalWidth && t.naturalHeight) ? t.naturalWidth / t.naturalHeight : 1.1;
        el.ph.style.setProperty('--ar', Math.min(Math.max(ar, 0.6), 2.2).toFixed(3));
        el.img.src = useSrc;
        el.img.alt = (data ? data.name : currentName) + (list.length > 1 ? ', photo ' + (photo + 1) + ' of ' + list.length : '');
        el.bg.style.backgroundImage = 'url("' + String(useSrc).replace(/["\\\n]/g, '') + '")';
        el.pos.textContent = (photo + 1) + ' / ' + list.length;
        resolve();
      };
      t.onload = function () { done(true); };
      t.onerror = function () { done(false); };
      t.src = src;
    });
  }

  function step(d) {
    if (!data || !data.gallery_images || data.gallery_images.length < 2) return;
    var which = document.activeElement;
    showPhoto(photo + d).then(function () { if (which && which.focus && !which.hidden) which.focus(); });
  }

  // wiring
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-quick-view]');
    if (b) {
      e.preventDefault();
      // a click on the card photo opens it too; focus comes back to that card's own Quick view button
      var btn = b.tagName === 'BUTTON' ? b : (b.querySelector('button[data-quick-view]') || b);
      open(b.getAttribute('data-quick-view'), b.getAttribute('data-name') || '', btn);
    }
  });
  el.close.addEventListener('click', close);
  el.retry.addEventListener('click', load);
  el.prev.addEventListener('click', function () { step(-1); });
  el.next.addEventListener('click', function () { step(1); });
  mod.addEventListener('mousedown', function (e) { if (e.target === mod) close(); });
  document.addEventListener('keydown', function (e) {
    if (!mod.classList.contains('on')) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'ArrowLeft') { step(-1); return; }
    if (e.key === 'ArrowRight') { step(1); return; }
    if (e.key === 'Tab') {
      var f = focusables();
      if (!f.length) { e.preventDefault(); return; }
      var first = f[0], last = f[f.length - 1];
      if (!qd.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
      else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });
})();
