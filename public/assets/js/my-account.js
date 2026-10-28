/*
 * My Account page and the order details block. Vanilla JS, no dependencies.
 * Every form works as a normal form post and the server validates everything again (names, phone, places by id, the
 * address chain, the weekly password limit). This file adds the searchable pickers, the live address preview, the hints
 * and the tab/footer behaviour. Nothing here is trusted.
 *   Combo   : searchable A-Z dropdown (province -> city -> barangay, and the phone country). Its list is moved to <body>
 *             while open, so no card or scroll area can clip it. Data comes from our own /places endpoints, one level
 *             at a time.
 */
(function () {
  'use strict';

  var dataEl = document.getElementById('ma-data');
  var DATA = dataEl ? JSON.parse(dataEl.textContent) : { countries: [], phoneRules: {} };

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function toast(type, msg) { if (window.LpToast) { window.LpToast.show(type, msg); } }

  /* ───────── saving feedback: the same on every submit button ─────────
   * The button shows a spinner and a short word ("Saving…"), is locked against double clicks and says aria-busy. A request
   * that finishes in under MIN_SPIN ms keeps the spinner up to that long, so it is noticed. */
  var MIN_SPIN = 400;
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  function isBusy(btn) { return !!btn && btn.classList.contains('is-busy'); }
  function setBusy(btn, label) {
    if (btn.__old === undefined) { btn.__old = btn.innerHTML; }
    btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<i class="spin" aria-hidden="true"></i><span>' + esc(label) + '</span>';
  }
  function clearBusy(btn) {
    if (btn.__old !== undefined) { btn.innerHTML = btn.__old; btn.__old = undefined; }
    btn.classList.remove('is-busy'); btn.removeAttribute('aria-busy');
  }
  function minTime(started, fn) { var wait = Math.max(0, MIN_SPIN - (Date.now() - started)); if (wait) { setTimeout(fn, wait); } else { fn(); } }
  var BAD = /(https?:\/\/|hxxps?:\/\/|www\s?\.|[\w.+-]+\s?(@|\(at\)|\[at\])\s?[\w-]+\s?(\.|\(dot\))\s?[a-z]{2,}|\b[a-z0-9-]+\.(com|net|org|ph|io|co|info|biz|xyz|me|app|dev|shop|store|online|site|gov|edu)\b)/i;

  /* ───────── page height: below the shared nav ───────── */
  var root = document.getElementById('ma');
  function fit() {
    if (!root) { return; }
    var top = root.getBoundingClientRect().top + window.pageYOffset;
    root.style.setProperty('--ma-h', Math.max(0, Math.floor(window.innerHeight - top)) + 'px');
  }
  if (root) {
    var ft;
    window.addEventListener('resize', function () { clearTimeout(ft); ft = setTimeout(fit, 80); });
    window.addEventListener('load', fit);
    fit();
  }

  /* ───────── searchable combobox ───────── */
  var comboSeq = 0;
  function Combo(host, o) {
    var w = host.querySelector('.cbw');
    var inp = w.querySelector('input[role=combobox]');
    var hid = w.querySelector('input[type=hidden]');
    var btn = w.querySelector('.cbt');
    var err = host.querySelector('.err');
    var ul = document.createElement('ul');
    ul.className = 'mx-cbl';
    ul.setAttribute('role', 'listbox');
    ul.id = (inp.getAttribute('aria-controls') || ('mx-cb-' + (++comboSeq)));
    ul.hidden = true;
    var quiet = false;
    var items = null, loading = null, shown = [], act = -1, val = hid.value, text = inp.value, isOpen = false;
    var label = o.label || function (it) { return it.name; };

    function load() {
      if (items) { return Promise.resolve(items); }
      if (!loading) {
        loading = o.load().then(function (r) { items = r.slice(); loading = null; return items; },
                                function (e) { loading = null; throw e; });
      }
      return loading;
    }
    function haystack(it) { return (it.search || (it.name + ' ' + label(it))).toLowerCase(); }

    function render(q) {
      q = (q || '').trim().toLowerCase();
      var html = '', idx = 0, list = items.slice();
      shown = [];
      if (!q) {
        var L = '';
        list.forEach(function (it) {
          if (o.letters) {
            var l = it.name.charAt(0).toUpperCase();
            if (l !== L) { L = l; html += '<li class="ltr" role="presentation">' + esc(l) + '</li>'; }
          }
          shown.push(it);
          html += '<li role="option" id="' + ul.id + '-o' + idx + '" data-i="' + (shown.length - 1) + '"' + (String(it.id) === String(val) ? ' aria-selected="true"' : '') + '>' + esc(label(it)) + '</li>';
          idx++;
        });
      } else {
        var st = list.filter(function (x) { return haystack(x).indexOf(q) === 0 || x.name.toLowerCase().indexOf(q) === 0; });
        var ct = list.filter(function (x) { return st.indexOf(x) < 0 && haystack(x).indexOf(q) >= 0; });
        st.concat(ct).forEach(function (it) {
          shown.push(it);
          var t = label(it), k = t.toLowerCase().indexOf(q);
          var body = k >= 0 ? esc(t.slice(0, k)) + '<b>' + esc(t.slice(k, k + q.length)) + '</b>' + esc(t.slice(k + q.length)) : esc(t);
          html += '<li role="option" id="' + ul.id + '-o' + idx + '" data-i="' + (shown.length - 1) + '"' + (String(it.id) === String(val) ? ' aria-selected="true"' : '') + '>' + body + '</li>';
          idx++;
        });
      }
      if (!shown.length) { html = '<li class="none" role="presentation">No match. Check the spelling.</li>'; }
      ul.innerHTML = html;
      act = shown.length ? Math.max(0, shown.findIndex(function (x) { return String(x.id) === String(val); })) : -1;
      if (q) { act = shown.length ? 0 : -1; }
      mark(!q);
    }
    function mark(scroll) {
      var opts = $$('[role=option]', ul);
      opts.forEach(function (li, i) { li.classList.toggle('act', i === act); });
      var cur = opts[act];
      inp.setAttribute('aria-activedescendant', cur ? cur.id : '');
      if (cur && scroll !== false && cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
    }
    function place() {
      var r = inp.getBoundingClientRect();
      var below = window.innerHeight - r.bottom, above = r.top;
      var w2 = Math.max(r.width, o.minWidth || 0);
      ul.style.width = w2 + 'px';
      ul.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w2 - 8)) + 'px';
      if (below < 290 && above > below) {
        ul.style.top = 'auto';
        ul.style.bottom = (window.innerHeight - r.top + 6) + 'px';
        ul.style.maxHeight = Math.max(140, Math.min(260, above - 16)) + 'px';
      } else {
        ul.style.bottom = 'auto';
        ul.style.top = (r.bottom + 6) + 'px';
        ul.style.maxHeight = Math.max(140, Math.min(260, below - 16)) + 'px';
      }
    }
    function open(q) {
      if (inp.disabled) { return; }
      if (!ul.parentNode) { document.body.appendChild(ul); }
      isOpen = true;
      w.dataset.open = 'true';
      inp.setAttribute('aria-expanded', 'true');
      ul.hidden = false;
      if (!items) { ul.innerHTML = '<li class="none" role="presentation">Loading&hellip;</li>'; }
      place();
      load().then(function () { if (isOpen) { render(q); place(); } }, function () {
        ul.innerHTML = '<li class="none" role="presentation">Could not load the list. Check your connection and try again.</li>';
      });
    }
    function close() {
      isOpen = false;
      ul.hidden = true;
      w.dataset.open = 'false';
      inp.setAttribute('aria-expanded', 'false');
    }
    function clearErr() { inp.classList.remove('bad'); if (err) { err.classList.remove('show'); } }
    function pick(it) {
      val = it.id; text = label(it); hid.value = val; inp.value = text;
      close(); clearErr();
      if (o.onPick) { o.onPick(it); }
    }

    inp.addEventListener('focus', function () { if (quiet) { return; } if (!inp.disabled) { inp.select(); open(''); } });
    inp.addEventListener('click', function () { if (ul.hidden) { open(''); } });
    inp.addEventListener('input', function () { open(inp.value); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (ul.hidden) { open(inp.value); } else { act = Math.min(shown.length - 1, act + 1); mark(); }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); act = Math.max(0, act - 1); mark();
      } else if (e.key === 'Enter') {
        if (!ul.hidden && act >= 0 && shown[act]) { e.preventDefault(); pick(shown[act]); }
      } else if (e.key === 'Escape') {
        if (!ul.hidden) { e.preventDefault(); close(); inp.value = text; }
      }
    });
    ul.addEventListener('mousedown', function (e) { e.preventDefault(); });
    ul.addEventListener('click', function (e) {
      var li = e.target.closest('[data-i]');
      if (li && shown[+li.getAttribute('data-i')]) { pick(shown[+li.getAttribute('data-i')]); }
    });
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function () { if (inp.disabled) { return; } if (ul.hidden) { inp.focus(); open(''); } else { close(); } });
    inp.addEventListener('blur', function () {
      setTimeout(function () {
        close();
        var t = inp.value.trim();
        if (!t) { if (val !== '') { val = ''; text = ''; hid.value = ''; if (o.onPick) { o.onPick(null); } } return; }
        if (t === text) { return; }
        var m = items && items.filter(function (x) { return label(x).toLowerCase() === t.toLowerCase() || x.name.toLowerCase() === t.toLowerCase(); })[0];
        if (m) { if (String(m.id) !== String(val)) { pick(m); } else { inp.value = text; } return; }
        inp.value = text;
        inp.classList.add('bad');
        if (err) { err.textContent = 'Choose a place from the list.'; err.classList.add('show'); }
      }, 120);
    });
    ['scroll', 'resize'].forEach(function (ev) {
      window.addEventListener(ev, function () { if (isOpen) { place(); } }, true);
    });

    return {
      input: inp,
      get: function () { return val === '' ? '' : String(val); },
      text: function () { return text; },
      set: function (id, name) { val = id == null ? '' : id; text = name || ''; hid.value = val; inp.value = text; clearErr(); },
      reset: function () { val = ''; text = ''; hid.value = ''; inp.value = ''; items = null; loading = null; clearErr(); },
      invalidate: function () { items = null; loading = null; },
      disable: function (b, ph) { inp.disabled = b; btn.disabled = b; inp.placeholder = ph || 'Search or choose'; if (b) { close(); } },
      err: function (m) { if (err) { err.textContent = m; err.classList.toggle('show', !!m); } inp.classList.toggle('bad', !!m); },
      // focus without opening the list (used after opening a card or showing an error: the list would cover the other fields)
      focus: function () { quiet = true; inp.focus(); quiet = false; }
    };
  }

  function getJson(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('bad'); } return r.json(); });
  }

  /* ───────── phone ───────── */
  function parsePhone(raw, cc) {
    var R = DATA.phoneRules[cc];
    if (!R) { return { ok: false, message: 'Choose the country of your phone number.' }; }
    raw = String(raw || '').trim();
    if (!raw) { return { ok: false, message: 'Enter your phone number. We call you about your orders.' }; }
    if (!/^\+?[\d\s\-().]+$/.test(raw)) { return { ok: false, message: 'Use digits only, for example 0917 123 4567.' }; }
    var d = raw.replace(/\D/g, ''), nat;
    if (raw.charAt(0) === '+') {
      if (d.indexOf(R.dial) !== 0) { return { ok: false, message: 'This number does not start with +' + R.dial + '. Pick the matching country.' }; }
      nat = d.slice(R.dial.length);
      if (nat.length > R.max && nat.charAt(0) === '0') { nat = nat.slice(1); }
    } else if (cc === 'PH') {
      nat = (d.indexOf('63') === 0 && d.length === 12) ? d.slice(2) : (d.charAt(0) === '0' ? d.slice(1) : d);
    } else {
      nat = d.charAt(0) === '0' ? d.slice(1) : d;
    }
    if (cc === 'PH') {
      if (!/^9\d{9}$/.test(nat)) { return { ok: false, message: 'Enter a Philippine mobile number like 0917 123 4567.' }; }
    } else if (nat.length < R.min || nat.length > R.max) {
      return { ok: false, message: 'A number in this country has ' + (R.min === R.max ? R.min + ' digits' : R.min + ' to ' + R.max + ' digits') + ' after the country code.' };
    } else if (!/^[1-9]\d+$/.test(nat)) {
      return { ok: false, message: 'This does not look like a valid phone number.' };
    }
    return { ok: true, e164: '+' + R.dial + nat };
  }

  function initPhone(host) {
    var ccHost = $('[data-combo=country]', host);
    var num = $('input[type=tel]', host);
    var errEl = $('[data-phone-err]', host.parentNode);
    var hidden = $('input[type=hidden]', ccHost);
    var onChange = function () {};
    var cc = Combo(ccHost, {
      minWidth: 270,
      load: function () {
        return Promise.resolve(DATA.countries.map(function (c) { return { id: c.code, name: c.name, label: c.code + ' ' + c.dial, search: (c.name + ' ' + c.code + ' ' + c.dial).toLowerCase() }; }));
      },
      label: function (it) { return it.label; },
      onPick: function () { if (num.value) { check(); } onChange(); }
    });
    cc.set(hidden.value, cc.input.value);
    function show(m) { if (errEl) { errEl.textContent = m || ''; errEl.classList.toggle('show', !!m); } num.classList.toggle('bad', !!m); }
    function check() { var r = parsePhone(num.value, hidden.value); show(r.ok ? '' : r.message); return r.ok; }
    function quiet() { return parsePhone(num.value, hidden.value).ok; }
    num.addEventListener('input', function () { if (num.classList.contains('bad')) { check(); } onChange(); });
    num.addEventListener('blur', function () { if (num.value.trim()) { check(); } });
    return {
      check: check, valid: quiet, number: num, country: function () { return hidden.value; },
      set: function (code, nat) { var c = DATA.countries.filter(function (x) { return x.code === code; })[0] || DATA.countries[0]; cc.set(c.code, c.code + ' ' + c.dial); num.value = nat || ''; show(''); },
      onChange: function (f) { onChange = f; }
    };
  }

  /* ───────── address block (provinces -> cities -> barangays) ───────── */
  function initAddress(host) {
    var urls = { prov: host.dataset.urlProvinces, city: host.dataset.urlCities, brgy: host.dataset.urlBarangays };
    var onChange = function () {};
    var provHost = $('[data-combo=province]', host), cityHost = $('[data-combo=city]', host), brgyHost = $('[data-combo=barangay]', host);
    var inputs = $$('input.inp:not([role=combobox])', host);
    var st = inputs.filter(function (i) { return /street$/.test(i.name); })[0];
    var zip = inputs.filter(function (i) { return /postal_code$/.test(i.name); })[0];
    var nt = inputs.filter(function (i) { return /landmark$/.test(i.name); })[0];
    var pv = $('[data-preview]', host);

    var P = Combo(provHost, { letters: true, load: function () { return getJson(urls.prov); }, onPick: function () { C.reset(); B.reset(); sync(); onChange(); } });
    var C = Combo(cityHost, { letters: true, load: function () { return P.get() ? getJson(urls.city.replace('__ID__', P.get())) : Promise.resolve([]); }, onPick: function () { B.reset(); sync(); onChange(); } });
    var B = Combo(brgyHost, { letters: true, load: function () { return C.get() ? getJson(urls.brgy.replace('__ID__', C.get())) : Promise.resolve([]); }, onPick: function () { sync(); onChange(); } });

    function fe(i, m) { var e = i.parentNode.querySelector('.err'); e.textContent = m || ''; e.classList.toggle('show', !!m); i.classList.toggle('bad', !!m); return !m; }
    function get() {
      return { province_id: P.get(), province: P.text(), city_id: C.get(), city: C.text(), barangay_id: B.get(), barangay: B.text(),
               street: st.value.trim(), postal_code: zip.value.trim(), landmark: nt.value.trim() };
    }
    function line() {
      var a = get(), brgy = a.barangay ? 'Brgy. ' + a.barangay.replace(/^Brgy\.?\s+/i, '') : '';
      return [a.street, brgy, a.city, a.province].filter(Boolean).join(', ') + (a.postal_code ? ' ' + a.postal_code : '');
    }
    function preview() {
      var a = get(), any = a.province_id || a.city_id || a.barangay_id || a.street || a.postal_code;
      pv.classList.toggle('on', !!any);
      $('.pvt', pv).textContent = any ? line() : 'Your full address appears here as you fill it in.';
    }
    function sync() {
      C.disable(!P.get(), 'Choose a province first');
      B.disable(!C.get(), 'Choose a city first');
      preview();
    }
    function checkStreet() {
      var v = st.value.trim();
      if (!v) { return fe(st, 'Enter your house number and street.'); }
      if (v.length < 3) { return fe(st, 'Enter your house number and street.'); }
      if (BAD.test(v)) { return fe(st, 'Links and emails are not allowed here.'); }
      if (!/^[A-Za-z0-9À-ɏ ,.'’#\/\-]+$/.test(v)) { return fe(st, 'Use letters, numbers and , . # - / only.'); }
      return fe(st, '');
    }
    function checkZip() {
      var v = zip.value.trim();
      if (!v) { return fe(zip, 'Enter your 4-digit postal code.'); }
      if (!/^\d{4}$/.test(v)) { return fe(zip, 'Postal code has 4 digits.'); }
      return fe(zip, '');
    }
    function checkNote() {
      var v = nt.value.trim();
      if (BAD.test(v)) { return fe(nt, 'Links and emails are not allowed here.'); }
      if (v && !/^[A-Za-z0-9À-ɏ ,.'’#\/\-]+$/.test(v)) { return fe(nt, 'Use letters, numbers and , . # - / only.'); }
      return fe(nt, '');
    }
    zip.addEventListener('input', function () { zip.value = zip.value.replace(/\D/g, ''); if (zip.classList.contains('bad')) { checkZip(); } preview(); onChange(); });
    zip.addEventListener('blur', function () { if (zip.value) { checkZip(); } });
    st.addEventListener('input', function () { if (st.classList.contains('bad')) { checkStreet(); } preview(); onChange(); });
    st.addEventListener('blur', function () { if (st.value.trim()) { checkStreet(); } });
    nt.addEventListener('input', function () { checkNote(); onChange(); });

    function missing() {
      var a = get(), m = [];
      if (!a.province_id) { m.push('Province'); }
      if (!a.city_id) { m.push('City or municipality'); }
      if (!a.barangay_id) { m.push('Barangay'); }
      if (a.street.length < 3) { m.push('House no. and street'); }
      if (!/^\d{4}$/.test(a.postal_code)) { m.push('Postal code'); }
      return m;
    }
    function empty() { var a = get(); return !(a.province_id || a.city_id || a.barangay_id || a.street || a.postal_code || a.landmark); }
    function validate() {
      var ok = true, a = get();
      if (!a.province_id) { P.err('Choose a province.'); ok = false; }
      if (!a.city_id) { C.err('Choose a city or municipality.'); ok = false; }
      if (!a.barangay_id) { B.err('Choose a barangay.'); ok = false; }
      if (!checkStreet()) { ok = false; }
      if (!checkZip()) { ok = false; }
      if (!checkNote()) { ok = false; }
      return ok;
    }
    function set(a) {
      a = a || {};
      P.set(a.province_id, a.province); C.set(a.city_id, a.city); B.set(a.barangay_id, a.barangay);
      P.invalidate(); C.invalidate(); B.invalidate();
      st.value = a.street || ''; zip.value = a.postal_code || ''; nt.value = a.landmark || '';
      [st, zip, nt].forEach(function (i) { fe(i, ''); });
      [P, C, B].forEach(function (c) { c.err(''); });
      sync();
    }
    sync();
    var api = {
      get: get, set: set, missing: missing, empty: empty, validate: validate, line: line,
      onChange: function (f) { onChange = f; },
      focusFirst: function () {
        var m = missing()[0];
        ({ 'Province': P, 'City or municipality': C, 'Barangay': B }[m] || { focus: function () { (zip.value.trim() && st.value.trim().length < 3 ? st : (st.value.trim() ? zip : st)).focus(); } }).focus();
      }
    };
    host.__addr = api;
    return api;
  }

  /* ───────── order details block ───────── */
  function initOrderDetails(box) {
    var host = $('[data-address]', box), addr = initAddress(host);
    var phone = initPhone($('[data-phone]', box));
    var nameIn = $('#oName', box), nameErr = nameIn.parentNode.querySelector('.err');
    var mode = $('[data-od-mode]', box), saved = $('[data-od-saved]', box), formBox = $('[data-od-form]', box), diff = $('[data-od-diff]', box);
    var legend = $('[data-od-ask-legend]', box), msg = $('[data-od-msg]', box);
    var hasSaved = box.dataset.hasSaved === '1';
    var conts = box.dataset['continue'] ? $$(box.dataset['continue']) : [];
    var form = box.closest('form');
    var pk = $('[data-od-pickup]', box);
    var fixed = box.dataset.fixed || '';
    function isPickup() {
      var r = document.querySelector('input[name=delivery_option]:checked');
      return fixed ? fixed === 'pickup' : (r ? r.value === 'pickup' : false);
    }
    function applyMode() {
      var p = isPickup();
      pk.hidden = !p;
      formBox.hidden = p || (hasSaved && mode.value === 'saved');
      if (saved) { saved.hidden = p || mode.value !== 'saved'; }
      refresh();
    }

    function nameOk() {
      var v = nameIn.value.trim();
      return v.length > 0 && /^[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ '’.\-]*$/.test(v) && !BAD.test(v);
    }
    function missing() {
      var m = [];
      if (!nameOk()) { m.push('Recipient name'); }
      if (!phone.valid()) { m.push('Phone number'); }
      if (!isPickup() && !(hasSaved && mode.value === 'saved')) { m = m.concat(addr.missing()); }
      return m;
    }
    function refresh() {
      var m = missing();
      conts.forEach(function (b) { b.setAttribute('aria-disabled', m.length ? 'true' : 'false'); });
      msg.innerHTML = m.length ? '<b>To continue, add:</b> ' + m.map(esc).join(', ') + '.' : (isPickup() ? 'Everything we need is here.' : 'Everything we need to deliver is here.');
      return m;
    }
    function showErrors() {
      if (!nameOk()) { nameIn.classList.add('bad'); nameErr.textContent = nameIn.value.trim() ? 'Use letters only. No numbers or symbols.' : 'Enter the recipient name.'; nameErr.classList.add('show'); }
      phone.check();
      if (!isPickup() && !(hasSaved && mode.value === 'saved')) { addr.validate(); }
    }
    function focusFirstMissing() {
      if (!nameOk()) { nameIn.focus(); } else if (!phone.valid()) { phone.number.focus(); } else if (!isPickup() && !(hasSaved && mode.value === 'saved')) { addr.focusFirst(); }
    }
    nameIn.addEventListener('input', function () { nameIn.classList.remove('bad'); nameErr.classList.remove('show'); refresh(); });
    phone.onChange(refresh); addr.onChange(refresh);
    $$('input[name=keep_address]', box).forEach(function (r) { r.addEventListener('change', refresh); });

    if (diff) {
      diff.addEventListener('click', function () {
        mode.value = 'new';
        saved.hidden = true; formBox.hidden = false;
        addr.set(null);
        // a saved address exists: the question is whether to replace it, default "No, only for this order"
        var no = $('input[name=keep_address][value=no]', box); if (no) { no.checked = true; }
        refresh();
        addr.focusFirst();
      });
    }
    if (!hasSaved) { mode.value = 'new'; }

    conts.forEach(function (b) {
      // runs before the button's own handler: a click with details missing stops here
      b.addEventListener('click', function (e) {
        if (b.__go) { b.__go = false; return; }                 // second pass: the page's own handler runs now
        if (isBusy(b)) { e.preventDefault(); e.stopImmediatePropagation(); return; }
        var m = refresh();
        if (m.length) {
          e.preventDefault(); e.stopImmediatePropagation();
          showErrors(); focusFirstMissing();
          toast('error', 'Add the missing delivery details first');
          return;
        }
        // details are complete: show the spinner at once, then hand the click on (a step change or the real submit)
        e.preventDefault(); e.stopImmediatePropagation();
        var started = Date.now(), submits = b.type === 'submit';
        setBusy(b, 'Continuing…');
        box.inert = true; box.setAttribute('aria-busy', 'true');
        minTime(started, function () {
          box.inert = false; box.removeAttribute('aria-busy');
          if (!submits) { clearBusy(b); }                        // a submit keeps its spinner until the next page loads
          b.__go = true; b.click();
        });
      }, true);
    });
    if (form) {
      form.addEventListener('submit', function (e) {
        var m = refresh();
        if (m.length) { e.preventDefault(); showErrors(); focusFirstMissing(); toast('error', 'Add the missing delivery details first'); }
      });
    }
    $$('input[name=delivery_option]').forEach(function (r) { r.addEventListener('change', applyMode); });
    applyMode();
    window.addEventListener('pageshow', function (ev) { if (ev.persisted) { conts.forEach(function (b) { if (isBusy(b)) { clearBusy(b); } }); } });
    window.MAOrder = {
      ready: function () { return missing().length === 0; },
      // what is still missing, by field group (the checkout page names them in its own "can't place your order yet" notice)
      problems: function () {
        var out = [];
        if (!nameOk()) { out.push({ key: 'name' }); }
        if (!phone.valid()) { out.push({ key: 'phone' }); }
        if (!isPickup() && !(hasSaved && mode.value === 'saved') && addr.missing().length) { out.push({ key: 'address' }); }
        return out;
      },
      showErrors: showErrors,
      focusFirst: focusFirstMissing,
      summary: function () {
        if (isPickup()) { return nameIn.value.trim() + ' · ' + phone.number.value.trim() + ' · Pick up at our shop'; }
        var line = (hasSaved && mode.value === 'saved') ? $('[data-od-saved] b', box).textContent + ', ' + $('[data-od-saved] small', box).textContent.trim() : addr.line();
        return nameIn.value.trim() + ' · ' + (phone.number.value.trim()) + ' · ' + line;
      }
    };
  }

  /* ───────── My Account page ───────── */
  var LABELS = { name: 'name', phone: 'phone number', address: 'delivery address' };
  var GENERIC_FAIL = 'We could not save. Check your connection and try again.';

  /*
   * One card of Personal info. Read-only until its Edit button is pressed. Saves go through fetch to PUT /profile/section
   * and the server answers with the values it stored; the page only shows what the server returned.
   *   adapter.cur()   what the fields hold now          adapter.ser(v)  the same shape from saved values
   *   adapter.reset() put the saved values back         adapter.validate() quick check before the request
   */
  function Card(el, adapter, saved, hooks) {
    var name = el.dataset.sec;
    var form = $('form', el), editBtn = $('[data-edit]', el), saveBtn = $('[data-save]', el), cancelBtn = $('[data-cancel]', el);
    var ed = $('.ed', el), fs = $('.edf', el), tag = $('.sv', el), notice = $('[data-name-notice]', el), acts = $('[data-acts]', ed);
    var hide = $$('[data-rd]', el);
    var self = { el: el, name: name, label: LABELS[name], isOpen: false, busy: false };
    var confirmed = false, tagTimer = null;
    var tokenEl = $('input[name=_token]', form);

    function clearErrors() {
      $$('.err', el).forEach(function (e) { e.classList.remove('show'); });
      $$('.inp.bad', el).forEach(function (i) { i.classList.remove('bad'); i.removeAttribute('aria-invalid'); });
    }
    function showNotice(on) {
      if (!notice) { return; }
      notice.hidden = !on; acts.hidden = !!on;
      if (on) { try { notice.focus(); } catch (e) { /* fine */ } }
    }

    self.dirty = function () { return self.isOpen && adapter.cur() !== adapter.ser(saved); };

    self.openEdit = function () {
      if (self.isOpen) { return; }
      self.isOpen = true; confirmed = false;
      hide.forEach(function (n) { n.hidden = true; });
      ed.hidden = false; editBtn.hidden = true;
      el.classList.add('editing'); el.classList.remove('just');
      clearTimeout(tagTimer);
      hooks.opened(self);
      var first = adapter.focus;
      requestAnimationFrame(function () { try { first(); } catch (e) { /* fine */ } });
    };
    function close(focusEdit) {
      self.isOpen = false; confirmed = false;
      showNotice(false);
      hide.forEach(function (n) { n.hidden = false; });
      ed.hidden = true; editBtn.hidden = false;
      el.classList.remove('editing');
      hooks.closed(self);
      if (focusEdit) { try { editBtn.focus(); } catch (e) { /* fine */ } }
    }
    self.cancel = function (focusEdit) {
      if (self.busy) { return; }
      adapter.reset(saved); clearErrors(); close(focusEdit !== false);
    };

    function showErrors(errors) {
      var firstBad = null;
      Object.keys(errors || {}).forEach(function (k) {
        var inp = $('[name="' + k + '"]', form);
        if (!inp) { return; }
        var fld = inp.closest('.fld') || inp.parentNode;
        var e = $('[data-phone-err]', fld) || $('.err', fld);
        var box = inp.type === 'hidden' ? $('input[role=combobox]', inp.closest('.fld') || fld) : inp;
        if (e) { e.textContent = [].concat(errors[k])[0]; e.classList.add('show'); }
        if (box) { box.classList.add('bad'); box.setAttribute('aria-invalid', 'true'); if (!firstBad) { firstBad = box; } }
      });
      if (firstBad) { try { firstBad.focus(); } catch (e) { /* fine */ } }
    }
    function lock(on) {
      self.busy = on;
      fs.disabled = on; cancelBtn.disabled = on;
      if (notice) { $$('button', notice).forEach(function (b) { b.disabled = on; }); }
      if (on) { el.setAttribute('aria-busy', 'true'); } else { el.removeAttribute('aria-busy'); }
    }
    function done(res) {
      var j = res.json || {};
      if (res.status === 200 && j.ok) {
        Object.keys(j.values || {}).forEach(function (k) { saved[k] = j.values[k]; });
        adapter.show(saved);
        adapter.reset(saved);
        clearErrors(); close(true);
        el.classList.add('just');
        tagTimer = setTimeout(function () { el.classList.remove('just'); }, 3000);
        toast('success', j.message || 'Saved');
        return true;
      }
      if (res.status === 422 && j.errors) {
        if (j.errors.name_confirm) { showNotice(true); return false; }
        showErrors(j.errors);
        toast('error', [].concat(j.errors[Object.keys(j.errors)[0]])[0] || 'Fix the highlighted fields and try again.');
        return false;
      }
      toast('error', res.status === 419 ? 'Your session timed out. Reload the page and sign in again.' : GENERIC_FAIL);
      return false;
    }

    self.save = function () {
      if (self.busy || !self.isOpen) { return Promise.resolve(false); }
      clearErrors();
      if (!adapter.validate()) { toast('error', adapter.invalid()); return Promise.resolve(false); }
      if (adapter.needsConfirm && adapter.needsConfirm(saved) && !confirmed) { showNotice(true); return Promise.resolve(false); }
      var fd = new FormData(form);                       // built before the fields are locked: disabled fields are not sent
      if (name === 'name') { fd.set('confirm_name_change', confirmed ? '1' : '0'); }
      var started = Date.now();
      setBusy(saveBtn, saveBtn.dataset.busyText || 'Saving…');
      lock(true);
      return fetch(form.action, {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': tokenEl ? tokenEl.value : '' }
      }).then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }, function () { return { status: r.ok ? 0 : r.status, json: {} }; }); },
              function () { return { status: 0, json: {} }; })
        .then(function (res) {
          return new Promise(function (resolve) {
            minTime(started, function () { clearBusy(saveBtn); lock(false); resolve(done(res)); });
          });
        });
    };

    editBtn.addEventListener('click', function () { hooks.request(self); });
    cancelBtn.addEventListener('click', function () { self.cancel(true); });
    form.addEventListener('submit', function (e) { e.preventDefault(); self.save(); });
    if (notice) {
      $('[data-keep]', notice).addEventListener('click', function () { showNotice(false); try { adapter.focus(); } catch (e) { /* fine */ } });
      $('[data-anyway]', notice).addEventListener('click', function () { confirmed = true; showNotice(false); self.save(); });
      // typing again means the customer may have changed their mind: ask once more only if the name still differs
      $$('[data-name]', el).forEach(function (i) { i.addEventListener('input', function () { if (!notice.hidden) { showNotice(false); } }); });
    }
    return self;
  }

  function initPage() {
    var data = DATA;
    var tabs = $$('.tab', root), views = $$('.view', root);
    var foot = $('#foot'), fSave = $('#fSave'), fMsg = $('#fMsg');
    var pT = $('#pT'), pS = $('#pS'), pChip = $('#pChip');
    var personal = $('#v-personal');
    var tab = data.tab || 'personal';
    var init = JSON.parse(personal.getAttribute('data-init') || '{}');
    var saved = {
      first_name: init.first_name || '', last_name: init.last_name || '', phone_country: init.phone_country || 'PH', phone_number: init.phone_number || '',
      province_id: init.province_id || '', province: init.province || '', city_id: init.city_id || '', city: init.city || '', barangay_id: init.barangay_id || '', barangay: init.barangay || '',
      street: init.street || '', postal_code: init.postal_code || '', landmark: init.landmark || '', line: ''
    };
    var cards = [];

    /* ── the three cards ── */
    var nameEl = $('#s-name'), phoneEl = $('#s-phone'), addrEl = $('#s-addr');
    var nameIns = $$('[data-name]', nameEl);
    function nameCheck(i) {
      var v = i.value.trim(), e = i.parentNode.querySelector('.err'), m = '';
      if (!v) { m = 'Enter your ' + (i.id === 'fn' ? 'first' : 'last') + ' name.'; }
      else if (BAD.test(v)) { m = 'Links and emails are not allowed here.'; }
      else if (!/^[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ '’.\-]{0,59}$/.test(v)) { m = 'Use letters only. No numbers or symbols.'; }
      e.textContent = m; e.classList.toggle('show', !!m); i.classList.toggle('bad', !!m);
      if (m) { i.setAttribute('aria-invalid', 'true'); } else { i.removeAttribute('aria-invalid'); }
      return !m;
    }
    nameIns.forEach(function (i) { i.addEventListener('blur', function () { if (i.value.trim()) { nameCheck(i); } }); });
    var nameAdapter = {
      cur: function () { return $('#fn').value.trim() + '|' + $('#ln').value.trim(); },
      ser: function (v) { return v.first_name + '|' + v.last_name; },
      reset: function (v) { $('#fn').value = v.first_name; $('#ln').value = v.last_name; },
      show: function (v) { $('[data-v=first_name]', nameEl).textContent = v.first_name; $('[data-v=last_name]', nameEl).textContent = v.last_name; $('.who h2').textContent = (v.first_name + ' ' + v.last_name).trim(); $('.who .mono').textContent = ((v.first_name.charAt(0) + v.last_name.charAt(0)).toUpperCase()) || 'U'; },
      validate: function () { return nameIns.map(nameCheck).every(Boolean); },
      invalid: function () { return 'Fix the highlighted name and try again.'; },
      focus: function () { $('#fn').focus(); },
      needsConfirm: function (v) { return data.verified && (nameAdapter.cur() !== nameAdapter.ser(v)); }
    };

    var phone = initPhone($('[data-phone]', phoneEl));
    var digits = function (s) { return String(s || '').replace(/[\s\-()]/g, ''); };
    var phoneAdapter = {
      cur: function () { return phone.country() + '|' + digits(phone.number.value); },
      ser: function (v) { return v.phone_country + '|' + digits(v.phone_number); },
      reset: function (v) { phone.set(v.phone_country, v.phone_number); },
      show: function (v) {
        var b = $('[data-v=phone]', phoneEl);
        b.textContent = v.display || v.phone_number;
        $('[data-edit-text]', phoneEl).textContent = 'Change number';
        $('[data-edit]', phoneEl).setAttribute('aria-label', 'Change phone number');
      },
      validate: function () { return phone.check(); },
      invalid: function () { return 'Enter a phone number we can call.'; },
      focus: function () { phone.number.focus(); }
    };

    var addr = initAddress($('[data-address]', addrEl));
    var addrKey = function (v) { return [v.province_id, v.city_id, v.barangay_id, v.street, v.postal_code, v.landmark].map(function (x) { return String(x == null ? '' : x).trim(); }).join('|'); };
    var addrAdapter = {
      cur: function () { return addrKey(addr.get()); },
      ser: function (v) { return addrKey(v); },
      reset: function (v) { addr.set(v); },
      show: function (v) {
        var read = $('[data-addr-read]', addrEl);
        var html = '<div class="rdr"><span class="k">Saved address</span><b data-v="address">' + esc(v.line || addr.line()) + '</b></div>';
        if (v.landmark) { html += '<div class="rdr"><span class="k">Landmark</span><b data-v="landmark">' + esc(v.landmark) + '</b></div>'; }
        read.innerHTML = html;
        $('[data-edit-text]', addrEl).textContent = 'Change address';
        $('[data-edit]', addrEl).setAttribute('aria-label', 'Change delivery address');
      },
      validate: function () { return addr.validate(); },        // an empty address fails here: Cancel is how to leave without saving
      invalid: function () { return addr.missing().length ? 'Fill in the highlighted address fields.' : 'Fix the highlighted address fields.'; },
      focus: function () { addr.focusFirst(); }
    };

    /* ── leaving with unsaved changes ── */
    var leave = { modal: $('#ma-leave'), card: null, proceed: null };
    function openCard() { return cards.filter(function (c) { return c.isOpen; })[0] || null; }
    function dirtyCard() { return cards.filter(function (c) { return c.dirty(); })[0] || null; }
    function guard(proceed) {
      var c = dirtyCard();
      if (!c) { proceed(); return; }
      if (!leave.modal || !window.LpModal) { if (window.confirm('You have unsaved changes. Leave without saving?')) { c.cancel(false); proceed(); } return; }
      leave.card = c; leave.proceed = proceed;
      $('#ma-leave-text').textContent = 'You changed your ' + c.label + ' but did not save it.';
      window.LpModal.open(leave.modal, document.activeElement);
    }
    if (leave.modal) {
      leave.modal.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ma-leave]');
        if (!b) { return; }
        var act = b.getAttribute('data-ma-leave'), c = leave.card, go2 = leave.proceed;
        if (act === 'keep') { leave.proceed = null; return; }
        window.LpModal.close();
        if (act === 'discard') { c.cancel(false); if (go2) { go2(); } }
        if (act === 'save') { c.save().then(function (ok) { if (ok && go2) { go2(); } }); }
      });
    }

    var guardPushed = false;
    function armBack() { if (!guardPushed) { try { window.history.pushState({ maGuard: 1 }, '', window.location.href); guardPushed = true; } catch (e) { /* no history: fine */ } } }
    window.addEventListener('popstate', function () {
      if (!guardPushed) { return; }
      if (dirtyCard()) {
        try { window.history.pushState({ maGuard: 1 }, '', window.location.href); } catch (e) { /* fine */ }
        guard(function () { guardPushed = false; window.history.go(-2); });
      } else { guardPushed = false; window.history.back(); }
    });
    window.addEventListener('beforeunload', function (e) { if (dirtyCard()) { e.preventDefault(); e.returnValue = ''; } });
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
      var a = e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || a.target === '_blank' || a.hasAttribute('download')) { return; }
      var h = a.getAttribute('href');
      if (!h || h.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(h) || !dirtyCard()) { return; }
      e.preventDefault(); e.stopPropagation();
      guard(function () { window.location.href = a.href; });
    }, true);
    document.addEventListener('submit', function (e) {
      var f = e.target;
      if (!f || !f.matches || f.matches('[data-section]') || !dirtyCard()) { return; }
      e.preventDefault(); e.stopImmediatePropagation();
      guard(function () { f.submit(); });
    }, true);

    var hooks = {
      opened: function () { armBack(); },
      closed: function () { /* the history guard stays: the next Back press is passed on by the popstate handler */ },
      // one card open at a time: opening another cancels the first (asking first when it holds unsaved changes)
      request: function (c) {
        var o = openCard();
        if (!o || o === c) { c.openEdit(); return; }
        guard(function () { var still = openCard(); if (still && still !== c) { still.cancel(false); } c.openEdit(); });
      }
    };
    cards.push(Card(nameEl, nameAdapter, saved, hooks), Card(phoneEl, phoneAdapter, saved, hooks), Card(addrEl, addrAdapter, saved, hooks));
    cards.forEach(function (c) { if (c.el.dataset.open) { c.openEdit(); } });

    // phone and tablet: keep the field being typed in on screen when the keyboard opens
    personal.addEventListener('focusin', function (e) {
      if (window.innerWidth > 860 || !e.target.closest('.sec.editing')) { return; }
      var t = e.target;
      setTimeout(function () { try { t.scrollIntoView({ block: 'center', behavior: reduced ? 'auto' : 'smooth' }); } catch (x) { /* fine */ } }, 300);
    });

    /* ── footer: only the Security tab has one (Update password) ── */
    function secState() {
      var p0 = $('#p0'), p1 = $('#p1'), p2 = $('#p2');
      if (!p0 || p0.disabled) { return { ok: false, rules: false }; }
      var p = p1.value, q = p2.value, c = p0.value;
      var ok = { len: p.length >= 8, 'case': /[a-z]/.test(p) && /[A-Z]/.test(p), num: /\d/.test(p), diff: !!p && !!c && p !== c, same: !!p && p === q };
      $$('#rules li').forEach(function (li) { li.classList.toggle('ok', !!ok[li.dataset.r]); });
      var all = Object.keys(ok).every(function (k) { return ok[k]; });
      return { ok: all && !!c, rules: all };
    }
    function renderFoot() {
      if (tab === 'security' && !data.googleOnly) {
        foot.hidden = false;
        fMsg.textContent = data.locked ? 'You can change your password once a week.' : 'Changing your password signs you out of other devices.';
        if (!isBusy(fSave)) { fSave.setAttribute('aria-disabled', (data.locked || !secState().ok) ? 'true' : 'false'); }
      } else { foot.hidden = true; }
    }

    /* ── tabs ── */
    function go(t) {
      var o = openCard();
      if (o && t !== 'personal') { o.cancel(false); }
      tab = t;
      tabs.forEach(function (b) {
        var on = b.dataset.t === t;
        if (on) { b.setAttribute('aria-current', 'page'); } else { b.removeAttribute('aria-current'); }
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      views.forEach(function (v) { v.classList.toggle('on', v.id === 'v-' + t); });
      var v = $('#v-' + t);
      pT.textContent = v.dataset.title; pS.textContent = v.dataset.sub;
      if (t === 'verify') { pChip.hidden = false; pChip.className = 'chip ' + v.dataset.chipClass; pChip.textContent = v.dataset.chip; } else { pChip.hidden = true; }
      renderFoot();
      $('.pb', root).scrollTop = 0;
      try { var u = new URL(window.location.href); u.searchParams.set('tab', t); window.history.replaceState(window.history.state, '', u.toString()); } catch (e) { /* no history: fine */ }
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { if (b.dataset.t === tab) { return; } guard(function () { go(b.dataset.t); }); }); });
    $$('[data-go]', root).forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); guard(function () { go(a.dataset.go); }); }); });

    /* ── Update password ── */
    fSave.addEventListener('click', function (e) {
      if (isBusy(fSave)) { e.preventDefault(); return; }
      if (fSave.getAttribute('aria-disabled') === 'true') {
        e.preventDefault();
        if (tab === 'security' && data.locked) { toast('info', 'You can change your password again after the date shown.'); }
        else if (tab === 'security') { toast('error', 'Fill in all three password boxes and meet every rule first.'); }
        return;
      }
      if (tab === 'security' && !secState().ok) { e.preventDefault(); }
    });
    var pf = $('#f-password');
    if (pf) {
      pf.addEventListener('submit', function (e) {
        if (isBusy(fSave) || fSave.getAttribute('aria-disabled') === 'true') { e.preventDefault(); return; }
        setBusy(fSave, fSave.dataset.busyText || 'Updating…');
        $$('input', pf).forEach(function (i) { i.readOnly = true; });
        pf.setAttribute('aria-busy', 'true');
      });
    }
    ['p0', 'p1', 'p2'].forEach(function (id) { var el = $('#' + id); if (el) { el.addEventListener('input', renderFoot); } });

    /* ── verification uploads ── */
    var vf = $('#f-verify');
    if (vf) {
      var MAX = 5 * 1024 * 1024, files = [false, false], gen = $('#gen'), sub = $('#vSub');
      var stepsEls = $$('#steps li');
      var setSteps = function () {
        var n = files[0] && files[1] ? 3 : (files[0] ? 2 : 1);
        stepsEls.forEach(function (li) {
          var s = +li.dataset.s;
          li.className = s < n ? 'done' : (s === n ? 'now' : '');
          li.querySelector('b').innerHTML = s < n ? '&#10003;' : String(s);
        });
      };
      var syncUp = function () { if (!isBusy(sub)) { sub.setAttribute('aria-disabled', files[0] && files[1] && gen.checked ? 'false' : 'true'); } setSteps(); };
      var magic = function (f, cb) {
        var r = new FileReader();
        r.onload = function () { var b = new Uint8Array(r.result); cb((b[0] === 0xFF && b[1] === 0xD8 && b[2] === 0xFF) || (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4E && b[3] === 0x47)); };
        r.onerror = function () { cb(false); };
        r.readAsArrayBuffer(f.slice(0, 4));
      };
      $$('.drop input', vf).forEach(function (inp) {
        inp.addEventListener('change', function () {
          var i = +inp.dataset.s, f = inp.files[0], box = $('#d' + i), m = $('#m' + i), im = $('img', box);
          m.classList.remove('show'); box.classList.remove('bad');
          var fail = function (t) { m.textContent = t; m.classList.add('show'); box.classList.add('bad'); inp.value = ''; files[i] = false; box.classList.remove('has'); im.hidden = true; syncUp(); };
          if (!f) { files[i] = false; box.classList.remove('has'); syncUp(); return; }
          if (['image/jpeg', 'image/png'].indexOf(f.type) < 0) { return fail('Only JPEG or PNG photos are accepted.'); }
          if (f.size > MAX) { return fail('This photo is over 5 MB. Choose a smaller one.'); }
          magic(f, function (good) {
            if (!good) { return fail('Only JPEG or PNG photos are accepted.'); }
            files[i] = true; box.classList.add('has');
            if (im.dataset.url) { URL.revokeObjectURL(im.dataset.url); }
            im.dataset.url = URL.createObjectURL(f); im.src = im.dataset.url; im.alt = 'Preview'; im.hidden = false; syncUp();
          });
        });
      });
      gen.addEventListener('change', syncUp);
      sub.addEventListener('click', function (e) { if (isBusy(sub)) { e.preventDefault(); } });
      vf.addEventListener('submit', function (e) {
        if (isBusy(sub)) { e.preventDefault(); return; }
        if (sub.getAttribute('aria-disabled') === 'true') { e.preventDefault(); toast('error', 'Add both photos and tick the box first'); return; }
        setBusy(sub, 'Submitting…');
        vf.classList.add('is-sending'); vf.setAttribute('aria-busy', 'true');   // pointer lock; the fields stay enabled so they are still sent
      });
      syncUp();
    }

    // coming back with the browser's Back button can show the page as it was left: clear any spinner
    window.addEventListener('pageshow', function (e) {
      if (!e.persisted) { return; }
      [fSave, $('#vSub')].forEach(function (b) { if (b && isBusy(b)) { clearBusy(b); } });
      if (vf) { vf.classList.remove('is-sending'); vf.removeAttribute('aria-busy'); }
      if (pf) { pf.removeAttribute('aria-busy'); $$('input', pf).forEach(function (i) { i.readOnly = false; }); }
      renderFoot();
    });

    go(tab);
    if (data.hasErrors && tab === 'personal') { var bad = $('.inp.bad, .err.show', personal); if (bad && bad.focus) { try { bad.focus(); } catch (e) { /* fine */ } } }
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (root && $('#v-personal')) { initPage(); }
    var od = $('[data-order-details]');
    if (od) { initOrderDetails(od); }
  });
})();
