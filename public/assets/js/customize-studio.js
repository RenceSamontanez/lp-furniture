/*
 * Customize studio. Vanilla JS, no dependencies. The form works on its own: the radios, inputs and the file field are
 * real form controls, the server validates everything again, and nothing here is trusted. This file only adds:
 *   - the step-by-step view and the live brief on the right,
 *   - the quick ideas (from the admin-editable design_ideas table, passed in #cz-data),
 *   - the "new to us / we have built this before" notes (read-only lookup of answered entries),
 *   - reference photo checks and compression (JPEG or PNG in, JPEG out, under 5 MB),
 *   - sizing the page to the screen below the shared nav (it never changes the nav).
 */
(function () {
  'use strict';

  var root = document.getElementById('cz');
  var dataEl = document.getElementById('cz-data');
  if (!root || !dataEl) { return; }

  var data = JSON.parse(dataEl.textContent);
  var form = document.getElementById('cz-form');
  root.classList.add('cz--js');

  var MAX_PHOTO = 5 * 1024 * 1024;
  var MAX_SIDE = 2000;
  var LAST = 4;
  var st = { step: 0, photo: false };

  function $(s, c) { return (c || root).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || root).querySelectorAll(s)); }
  function val(name) { var e = form.querySelector('input[name="' + name + '"]:checked'); return e ? e.value : ''; }
  function clean(s) { return String(s || '').replace(/[<>]/g, '').replace(/\s+/g, ' ').trim(); }
  function words(s) { return String(s || '').trim().split(/\s+/).filter(Boolean); }
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }

  /* ───────── page height: below the shared nav (and any bar above this area) ───────── */
  function fit() {
    var main = root.parentElement;
    var top = (main ? main.getBoundingClientRect().top : 0) + window.pageYOffset;
    var h = Math.max(0, Math.floor(window.innerHeight - top));
    root.style.setProperty('--cz-h', h + 'px');
  }
  var fitTimer;
  window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fit, 80); });
  window.addEventListener('load', fit);
  fit();

  /* ───────── text safety (the server checks again) ───────── */
  var BAD = /(https?:\/\/|hxxps?:\/\/|www\s?\.|[\w.+-]+\s?@\s?[\w-]+\s?\.\s?[a-z]{2,}|\b[a-z0-9-]+\.(com|net|org|ph|io|co|info|biz|xyz|me|app|dev|shop|store|online|site)\b)/i;
  function hasLink(s) { return BAD.test(String(s || '')); }

  /* ───────── reading the form ───────── */
  var typeText = $('#cz-type-text'), woodName = $('#cz-wood-name'), desc = $('#cz-desc');

  function typeLabel() {
    var v = val('furniture_type');
    if (!v) { return ''; }
    if (v === data.other) { return clean(typeText.value); }
    var r = form.querySelector('input[name="furniture_type"]:checked');
    return r ? r.getAttribute('data-label') : v;
  }
  function woodLabel() {
    var v = val('preferred_material');
    if (!v) { return ''; }
    return v === data.special ? clean(woodName.value) : v;
  }
  function descWords() { return words(clean(desc.value)).length; }

  function ok(i) {
    if (i === 0) {
      var t = val('furniture_type');
      if (!t) { return false; }
      return t !== data.other || (clean(typeText.value).length >= 2 && !hasLink(typeText.value));
    }
    if (i === 1) {
      var w = val('preferred_material');
      if (!w) { return false; }
      return w !== data.special || (clean(woodName.value).length >= 2 && !hasLink(woodName.value) && !!val('wood_supply'));
    }
    if (i === LAST) {
      var d = clean(desc.value);
      return d.length >= 15 && words(d).length >= 3 && !hasLink(d);
    }
    return true;
  }
  function allOk() { return ok(0) && ok(1) && ok(LAST) && !hasLink(($('#cz-more') || {}).value); }

  /* ───────── notes under Something else, Other wood and the description ───────── */
  var cache = {}, timers = {}, notes = { type: null, wood: null, idea: null };

  function lookup(kind, text, done) {
    var key = kind + '|' + text.toLowerCase();
    if (cache[key]) { done(cache[key]); return; }
    var url = data.lookupUrl + '?kind=' + encodeURIComponent(kind) + '&text=' + encodeURIComponent(text);
    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('lookup'); } return r.json(); })
      .then(function (j) { cache[key] = j; done(j); })
      .catch(function () { done({ known: false, answer: null, failed: true }); });
  }
  function watch(slot, kind, getText, minLen, delay) {
    clearTimeout(timers[slot]);
    var text = getText();
    if (text.length < minLen || hasLink(text)) { notes[slot] = null; paintNotes(); return; }
    timers[slot] = setTimeout(function () {
      lookup(kind, text, function (j) {
        if (getText() !== text) { return; }
        notes[slot] = { j: j, text: text };
        paintNotes();
      });
    }, delay);
  }
  function noteNode(kind, title, body, cls) {
    var n = el('div', 'cz-note ' + cls); n.appendChild(el('b', '', title)); n.appendChild(document.createTextNode(body)); return n;
  }
  function paintNote(slotId, n, kind) {
    var slot = document.getElementById(slotId);
    if (!slot) { return; }
    slot.textContent = '';
    if (!n) { return; }
    var j = n.j;
    if (j.known) {
      slot.appendChild(noteNode(kind, kind === 'wood' ? 'About ' + n.text : (kind === 'idea' ? 'Good to know' : 'We have built this before'),
        j.answer || 'Our team knows this one. They will confirm the details by phone.', 'cz-note--kb'));
    } else if (kind === 'type') {
      slot.appendChild(noteNode(kind, 'New to us', 'We have not built this often. We save your request so our team can prepare an answer, and they will confirm details by phone.', 'cz-note--new'));
    } else if (kind === 'wood') {
      slot.appendChild(noteNode(kind, 'We will check this wood', 'We usually build with Gmelina, Mahogany and Acacia. Our team will call you to confirm if we can get this wood.', 'cz-note--new'));
    }
  }
  function paintNotes() {
    var isOther = val('furniture_type') === data.other, isSpecial = val('preferred_material') === data.special;
    paintNote('cz-type-note', isOther ? notes.type : null, 'type');
    paintNote('cz-wood-note', isSpecial ? notes.wood : null, 'wood');
    paintNote('cz-desc-note', notes.idea, 'idea');
  }

  /* ───────── quick ideas ───────── */
  function ideasFor() {
    var type = val('furniture_type'), style = val('style_preference');
    var a = (data.ideas.type[type] || []).concat(data.ideas.style[style] || []);
    return (a.length ? a : data.ideas.general).slice(0, 7);
  }
  function paintIdeas() {
    var name = typeLabel().toUpperCase(), style = val('style_preference').toUpperCase();
    var cap = style && name ? 'IDEAS FOR A ' + style + ' ' + name : (name ? 'IDEAS FOR YOUR ' + name : 'QUICK IDEAS');
    $('#cz-icap').textContent = cap + ': TAP TO ADD';
    var box = $('#cz-ideas'), list = ideasFor(), sig = list.join('|');
    if (box.getAttribute('data-sig') === sig) { return; }
    box.setAttribute('data-sig', sig);
    box.textContent = '';
    list.forEach(function (t) {
      var b = el('button', 'lp-btn lp-btn--secondary lp-btn--chip', '+ ' + t);
      b.type = 'button'; b.setAttribute('data-idea', t);
      box.appendChild(b);
    });
  }

  /* ───────── description meter ───────── */
  function dscore(d) {
    var l = d.toLowerCase();
    var c = {
      Size: /\b(\d|six|four|two|three|seat|small|large|big|tall|wide|king|queen|cm|inch|feet|ft|meter)/.test(l),
      Shape: /(round|square|rect|curve|straight|edge|leg|panel|shape|oval)/.test(l),
      Finish: /(finish|matte|glossy|polish|stain|varnish|color|colour|tone|dark|light|natural|brown)/.test(l),
      Place: /(living|bed|dining|kitchen|office|room|outdoor|space|wall|entrance|bath|garden)/.test(l)
    };
    var n = Object.keys(c).filter(function (k) { return c[k]; }).length, wc = words(d).length;
    return { c: c, wc: wc, score: Math.min(100, Math.round(n * 20 + Math.min(wc, 20))) };
  }

  /* ───────── render ───────── */
  var woodColors = data.woods;
  var lastType = null;

  function render() {
    // steps
    $$('.cz-step').forEach(function (s) { s.classList.toggle('on', +s.getAttribute('data-step') === st.step); });
    $$('.cz-st').forEach(function (b) {
      var i = +b.getAttribute('data-i');
      b.classList.toggle('on', i === st.step);
      b.classList.toggle('done', i < st.step && ok(i));
      if (i === st.step) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
    });
    $('#cz-type-other').hidden = val('furniture_type') !== data.other;
    $('#cz-wood-special').hidden = val('preferred_material') !== data.special;

    // footer
    var last = st.step === LAST;
    $('#cz-prev').style.visibility = st.step ? 'visible' : 'hidden';
    $('#cz-next').hidden = last;
    $('#cz-create').hidden = !last;
    var can = last ? allOk() : ok(st.step);
    $('#cz-next').disabled = !can;
    $('#cz-create').disabled = !can || data.outOfDesigns;
    var msg = '';
    if (!can) {
      if (st.step === 0) { msg = val('furniture_type') === data.other ? 'Type what you want built to continue' : 'Pick a furniture type to continue'; }
      else if (st.step === 1) { msg = val('preferred_material') === data.special ? (clean(woodName.value).length < 2 ? 'Type the wood you want' : 'Choose who gets the wood') : 'Pick a wood to continue'; }
      else if (last) { msg = !ok(0) ? 'Go back and pick a furniture type' : (!ok(1) ? 'Go back and pick a wood' : 'Add a bit more detail to continue'); }
    } else if (last && data.outOfDesigns) {
      msg = 'You have used all your designs for today';
    }
    $('#cz-need').textContent = msg;

    // inline messages for links and emails
    $('#cz-type-msg').textContent = hasLink(typeText.value) ? 'Links and email addresses are not allowed here.' : '';
    $('#cz-wood-msg').textContent = hasLink(woodName.value) ? 'Links and email addresses are not allowed here.' : '';
    $('#cz-desc-msg').textContent = hasLink(desc.value) ? 'Links and email addresses are not allowed here.' : '';
    typeText.setAttribute('aria-invalid', hasLink(typeText.value) ? 'true' : 'false');
    woodName.setAttribute('aria-invalid', hasLink(woodName.value) ? 'true' : 'false');
    desc.setAttribute('aria-invalid', hasLink(desc.value) ? 'true' : 'false');

    paintIdeas();
    paintNotes();

    // stage colours follow the wood
    var rs = root.style, wc = woodColors[val('preferred_material')];
    rs.setProperty('--w1', wc ? wc[0] : '#d9b27a');
    rs.setProperty('--w2', wc ? wc[1] : '#8a5a3a');
    rs.setProperty('--glow', wc ? wc[0] + '73' : 'rgba(217,178,122,.45)');

    // stage content
    var t = val('furniture_type'), tn = typeLabel(), wn = woodLabel(), ring = $('#cz-ring');
    var icon = t ? (data.icons[t] || data.icons[data.other]) : data.icons.Table;
    ring.innerHTML = '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">' + icon + '</svg>';
    ring.style.opacity = t ? 1 : 0.55;
    if (t && t !== lastType) { ring.classList.remove('pop'); void ring.offsetWidth; ring.classList.add('pop'); }
    lastType = t;

    var bt = $('#cz-bt'); bt.textContent = '';
    if (t && tn) {
      if (wn) { bt.appendChild(document.createTextNode(wn + ' ')); } else { bt.appendChild(document.createTextNode('Your ')); }
      bt.appendChild(el('em', '', tn.toLowerCase()));
    } else if (t) {
      bt.appendChild(document.createTextNode('Your '));
      bt.appendChild(el('em', '', 'piece'));
    } else {
      bt.textContent = 'Your piece will appear here';
    }

    var supply = val('wood_supply');
    var tags = [tn || null, wn || null,
      val('preferred_material') === data.special && supply ? (supply === 'customer' ? 'You provide the wood' : 'We buy the wood') : null,
      val('style_preference') || null, st.photo ? 'Reference photo' : null].filter(Boolean);
    var tg = $('#cz-tags'); tg.textContent = '';
    if (!tags.length) { tg.appendChild(el('span', 'cz-tag cz-tag--empty', 'Nothing picked yet')); }
    tags.forEach(function (x) { tg.appendChild(el('span', 'cz-tag', x)); });

    var d = clean(desc.value), bd = $('#cz-bd');
    bd.textContent = d || (t ? 'Add a few words about size, shape and finish on the last step.' : 'Start by choosing a furniture type on the left. Everything you pick shows up here.');
    bd.classList.toggle('cz-bd--empty', !d);
    $('#cz-stcount').textContent = [ok(0), ok(1)].filter(Boolean).length + ' of 2 required picked';

    // description meter
    var sc = dscore(desc.value), bar = $('#cz-mbar');
    bar.style.width = sc.score + '%';
    bar.style.background = sc.score < 40 ? '#c0392b' : (sc.score < 70 ? '#b9770e' : '#1d6b45');
    $('#cz-mlabel').textContent = sc.wc === 0 ? 'Describe what you want' : (sc.score < 40 ? 'Too short. Add detail' : (sc.score < 70 ? 'Getting there' : 'Good description'));
    $('#cz-count').textContent = desc.value.length + ' / 1000';
    var ck = $('#cz-check'); ck.textContent = '';
    Object.keys(sc.c).forEach(function (k) { ck.appendChild(el('span', sc.c[k] ? 'y' : '', (sc.c[k] ? '✓ ' : '+ ') + k)); });
  }

  function go(i) {
    st.step = Math.max(0, Math.min(LAST, i));
    render();
  }
  function canReach(i) { for (var k = 0; k < i; k++) { if ((k === 0 || k === 1) && !ok(k)) { return false; } } return true; }

  /* ───────── events ───────── */
  form.addEventListener('change', function (e) {
    var t = e.target;
    if (t.name === 'furniture_type' && t.value === data.other) { setTimeout(function () { typeText.focus(); }, 50); }
    if (t.name === 'preferred_material' && t.value === data.special) { setTimeout(function () { woodName.focus(); }, 50); }
    render();
  });
  // choosing the style that is already chosen clears it (a radio cannot do that by itself)
  $$('input[name="style_preference"]').forEach(function (r) {
    var was = false;
    r.addEventListener('mousedown', function () { was = r.checked; });
    r.addEventListener('click', function () { if (was && r.checked) { r.checked = false; render(); } was = false; });
    r.addEventListener('keydown', function (ev) { if (ev.key === ' ' && r.checked) { ev.preventDefault(); r.checked = false; render(); } });
  });

  typeText.addEventListener('input', function () {
    typeText.value = typeText.value.replace(/[<>]/g, '');
    watch('type', 'furniture_type', function () { return clean(typeText.value); }, 2, 450);
    render();
  });
  woodName.addEventListener('input', function () {
    woodName.value = woodName.value.replace(/[<>]/g, '');
    watch('wood', 'wood', function () { return clean(woodName.value); }, 2, 450);
    render();
  });
  desc.addEventListener('input', function () {
    watch('idea', 'idea', function () { return clean(desc.value); }, 15, 900);
    render();
  });
  var more = $('#cz-more');
  if (more) { more.addEventListener('input', render); }

  root.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button') : null;
    if (!b) { return; }
    if (b.hasAttribute('data-example')) {
      var other = form.querySelector('input[name="furniture_type"][value="' + data.other + '"]');
      other.checked = true; typeText.value = b.getAttribute('data-example');
      watch('type', 'furniture_type', function () { return clean(typeText.value); }, 2, 0);
      render();
    } else if (b.hasAttribute('data-idea')) {
      var cur = desc.value.trim(), add = b.getAttribute('data-idea');
      var next = (cur ? cur.replace(/[.,]$/, '') + ', ' : '') + add;
      if (next.length <= 1000) { desc.value = next; render(); desc.focus(); }
    } else if (b.classList.contains('cz-st')) {
      var i = +b.getAttribute('data-i');
      if (i <= st.step || canReach(i)) { go(i); }
    } else if (b.id === 'cz-prev') { go(st.step - 1); }
    else if (b.id === 'cz-next') { if (ok(st.step)) { go(st.step + 1); } }
    else if (b.id === 'cz-up-x') { clearPhoto(); }
  });

  form.addEventListener('keydown', function (e) {
    // Enter inside a one-line field moves on instead of submitting half a form
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'file' && e.target.type !== 'radio') {
      e.preventDefault();
      if (st.step < LAST && ok(st.step)) { go(st.step + 1); }
    }
  });

  form.addEventListener('submit', function (e) {
    if (!allOk() || data.outOfDesigns) {
      e.preventDefault();
      go(!ok(0) ? 0 : (!ok(1) ? 1 : LAST));
      return;
    }
    // keep the button from sending twice, after the browser has built the form data
    setTimeout(function () { $('#cz-create').disabled = true; }, 0);
  });

  /* ───────── reference photo ───────── */
  var photoIn = $('#cz-photo'), upMsg = $('#cz-up-msg'), upLab = $('#cz-up-lab'), upFace = $('#cz-up-face'), upX = $('#cz-up-x');
  var upDefault = upLab.textContent;

  function mb(n) { return (n / 1048576).toFixed(1) + ' MB'; }
  function clearPhoto() {
    photoIn.value = ''; st.photo = false; upMsg.textContent = '';
    var im = upFace.querySelector('img'); if (im) { URL.revokeObjectURL(im.src); im.remove(); }
    upLab.textContent = upDefault; upX.hidden = true; render();
  }
  function sniff(file) {
    return new Promise(function (res) {
      var r = new FileReader();
      r.onload = function () {
        var b = new Uint8Array(r.result);
        if (b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF) { res('jpeg'); }
        else if (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47) { res('png'); }
        else { res(null); }
      };
      r.onerror = function () { res(null); };
      r.readAsArrayBuffer(file.slice(0, 8));
    });
  }
  function toBlob(canvas, q) { return new Promise(function (r) { canvas.toBlob(r, 'image/jpeg', q); }); }
  function fail(msg) { photoIn.value = ''; st.photo = false; upMsg.textContent = msg; upX.hidden = true; render(); }

  photoIn.addEventListener('change', function () {
    var f = photoIn.files && photoIn.files[0];
    upMsg.textContent = '';
    if (!f) { return; }
    if (f.size > 30 * 1024 * 1024) { fail('That photo is over 30 MB. Choose a smaller one.'); return; }
    sniff(f).then(function (kind) {
      if (!kind) { fail('That file is not a JPG or PNG photo. Other types, like WebP or GIF, are not accepted.'); return null; }
      return createImageBitmap(f).catch(function () { fail('We could not read that photo. Try another.'); return null; });
    }).then(function (bmp) {
      if (!bmp) { return; }
      var scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * scale)); c.height = Math.max(1, Math.round(bmp.height * scale));
      var g = c.getContext('2d');
      g.fillStyle = '#ffffff'; g.fillRect(0, 0, c.width, c.height);   // a PNG with see-through parts becomes white, not black
      g.drawImage(bmp, 0, 0, c.width, c.height);
      var q = 0.88;
      function attempt() {
        return toBlob(c, q).then(function (blob) {
          if (blob && blob.size > MAX_PHOTO && q > 0.35) { q -= 0.1; return attempt(); }
          return blob;
        });
      }
      return attempt().then(function (blob) {
        if (!blob || blob.size > MAX_PHOTO) { fail('We could not shrink that photo enough. Try another.'); return; }
        var out = new File([blob], 'reference.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        var dt = new DataTransfer(); dt.items.add(out); photoIn.files = dt.files;
        var im = upFace.querySelector('img'); if (im) { URL.revokeObjectURL(im.src); im.remove(); }
        im = document.createElement('img'); im.alt = 'Your reference photo'; im.src = URL.createObjectURL(blob);
        upFace.insertBefore(im, upFace.firstChild);
        upLab.textContent = f.size > blob.size ? mb(f.size) + ' → ' + mb(blob.size) : mb(blob.size);
        upX.hidden = false; st.photo = true; render();
      });
    });
  });

  /* ───────── start ───────── */
  if (typeof data.errorStep === 'number') { st.step = data.errorStep; }
  render();
  if (data.hadErrors) { var fine = $('.cz-fine'); if (fine) { fine.textContent += ' If you had added a photo, please add it again.'; } }
})();
