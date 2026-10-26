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

    inp.addEventListener('focus', function () { if (!inp.disabled) { inp.select(); open(''); } });
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
      focus: function () { inp.focus(); }
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
      if (!a.city_id) { C.err(a.province_id ? 'Choose a city or municipality.' : ''); ok = false; }
      if (!a.barangay_id) { B.err(a.city_id ? 'Choose a barangay.' : ''); ok = false; }
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

    function nameOk() {
      var v = nameIn.value.trim();
      return v.length > 0 && /^[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ '’.\-]*$/.test(v) && !BAD.test(v);
    }
    function missing() {
      var m = [];
      if (!nameOk()) { m.push('Recipient name'); }
      if (!phone.valid()) { m.push('Phone number'); }
      if (!(hasSaved && mode.value === 'saved')) { m = m.concat(addr.missing()); }
      return m;
    }
    function refresh() {
      var m = missing();
      conts.forEach(function (b) { b.setAttribute('aria-disabled', m.length ? 'true' : 'false'); });
      msg.innerHTML = m.length ? '<b>To continue, add:</b> ' + m.map(esc).join(', ') + '.' : 'Everything we need to deliver is here.';
      return m;
    }
    function showErrors() {
      if (!nameOk()) { nameIn.classList.add('bad'); nameErr.textContent = nameIn.value.trim() ? 'Use letters only. No numbers or symbols.' : 'Enter the recipient name.'; nameErr.classList.add('show'); }
      phone.check();
      if (!(hasSaved && mode.value === 'saved')) { addr.validate(); }
    }
    function focusFirstMissing() {
      if (!nameOk()) { nameIn.focus(); } else if (!phone.valid()) { phone.number.focus(); } else if (!(hasSaved && mode.value === 'saved')) { addr.focusFirst(); }
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
        var m = refresh();
        if (m.length) {
          e.preventDefault(); e.stopImmediatePropagation();
          showErrors(); focusFirstMissing();
          toast('error', 'Add the missing delivery details first');
        }
      }, true);
    });
    if (form) {
      form.addEventListener('submit', function (e) {
        var m = refresh();
        if (m.length) { e.preventDefault(); showErrors(); focusFirstMissing(); toast('error', 'Add the missing delivery details first'); }
      });
    }
    refresh();
    window.MAOrder = {
      ready: function () { return missing().length === 0; },
      summary: function () {
        var line = (hasSaved && mode.value === 'saved') ? $('[data-od-saved] b', box).textContent + ', ' + $('[data-od-saved] small', box).textContent.trim() : addr.line();
        return nameIn.value.trim() + ' · ' + (phone.number.value.trim()) + ' · ' + line;
      }
    };
  }

  /* ───────── My Account page ───────── */
  function initPage() {
    var data = DATA;
    var tabs = $$('.tab', root), views = $$('.view', root);
    var foot = $('#foot'), fSave = $('#fSave'), fDisc = $('#fDisc'), fMsg = $('#fMsg');
    var pT = $('#pT'), pS = $('#pS'), pChip = $('#pChip');
    var personal = $('#v-personal');
    var tab = data.tab || 'personal';

    var phone = initPhone($('[data-phone]', personal));
    var addr = initAddress($('[data-address]', personal));
    var init = JSON.parse(personal.getAttribute('data-init') || '{}');
    var nameIns = $$('[data-name]', personal);

    function nameCheck(i) {
      var v = i.value.trim(), e = i.parentNode.querySelector('.err'), m = '';
      if (!v) { m = 'Enter your name.'; }
      else if (BAD.test(v)) { m = 'Links and emails are not allowed here.'; }
      else if (!/^[A-Za-zÀ-ɏ][A-Za-zÀ-ɏ '’.\-]{0,59}$/.test(v)) { m = 'Use letters only. No numbers or symbols.'; }
      e.textContent = m; e.classList.toggle('show', !!m); i.classList.toggle('bad', !!m);
      return !m;
    }
    function currentState() {
      var a = addr.get();
      return [$('#fn').value.trim(), $('#ln').value.trim(), phone.country(), phone.number.value.replace(/[\s\-()]/g, ''),
              a.province_id, a.city_id, a.barangay_id, a.street, a.postal_code, a.landmark].join('|');
    }
    var initState = [init.first_name, init.last_name, init.phone_country, String(init.phone_number || '').replace(/[\s\-()]/g, ''),
                     String(init.province_id || ''), String(init.city_id || ''), String(init.barangay_id || ''), init.street || '', init.postal_code || '', init.landmark || ''].join('|');
    function dirty() { return currentState() !== initState; }

    /* footer */
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
      var email = !data.googleOnly;
      if (tab === 'personal') {
        foot.hidden = false;
        var d = dirty();
        fSave.setAttribute('form', 'f-personal'); fSave.textContent = 'Save changes';
        fDisc.hidden = !d;
        fMsg.innerHTML = d ? '<b>You have unsaved changes.</b>' : 'Your changes are saved to your account only.';
        fSave.setAttribute('aria-disabled', d ? 'false' : 'true');
      } else if (tab === 'security' && email) {
        foot.hidden = false; fDisc.hidden = true;
        fSave.setAttribute('form', 'f-password'); fSave.textContent = 'Update password';
        fMsg.textContent = data.locked ? 'You can change your password once a week.' : 'Changing your password signs you out of other devices.';
        fSave.setAttribute('aria-disabled', (data.locked || !secState().ok) ? 'true' : 'false');
      } else { foot.hidden = true; }
    }

    /* tabs */
    function go(t, focus) {
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
      try { var u = new URL(window.location.href); u.searchParams.set('tab', t); window.history.replaceState(null, '', u.toString()); } catch (e) { /* no history: fine */ }
      if (focus) { v.setAttribute('tabindex', '-1'); }
    }
    tabs.forEach(function (b) { b.addEventListener('click', function () { go(b.dataset.t); }); });
    $$('[data-go]', root).forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); go(a.dataset.go); }); });

    /* personal form */
    nameIns.forEach(function (i) { i.addEventListener('input', function () { nameCheck(i); renderFoot(); }); });
    phone.onChange(renderFoot); addr.onChange(renderFoot);
    personal.addEventListener('input', renderFoot);
    fDisc.addEventListener('click', function () {
      $('#fn').value = init.first_name || ''; $('#ln').value = init.last_name || '';
      phone.set(init.phone_country, init.phone_number);
      addr.set(init);
      $$('.inp.bad', personal).forEach(function (i) { i.classList.remove('bad'); });
      $$('.err', personal).forEach(function (e) { e.classList.remove('show'); });
      renderFoot();
    });
    fSave.addEventListener('click', function (e) {
      if (fSave.getAttribute('aria-disabled') === 'true') {
        e.preventDefault();
        if (tab === 'security' && data.locked) { toast('info', 'You can change your password again after the date shown.'); }
        return;
      }
      if (tab === 'personal') {
        var ok = nameIns.map(nameCheck).every(Boolean);
        if (!phone.check()) { ok = false; }
        if (!addr.empty() && !addr.validate()) { ok = false; }
        if (!ok) { e.preventDefault(); toast('error', !addr.empty() && addr.missing().length ? 'Finish the address or clear it, then save' : 'Fix the highlighted fields first'); }
      } else if (tab === 'security') {
        if (!secState().ok) { e.preventDefault(); }
      }
    });
    ['p0', 'p1', 'p2'].forEach(function (id) { var el = $('#' + id); if (el) { el.addEventListener('input', renderFoot); } });

    /* verification uploads */
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
      var syncUp = function () { sub.setAttribute('aria-disabled', files[0] && files[1] && gen.checked ? 'false' : 'true'); setSteps(); };
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
      vf.addEventListener('submit', function (e) {
        if (sub.getAttribute('aria-disabled') === 'true') { e.preventDefault(); toast('error', 'Add both photos and tick the box first'); return; }
        sub.setAttribute('aria-disabled', 'true');
      });
      syncUp();
    }

    /* the first paint of the footer, and an error from the server opens the tab that holds it */
    go(tab);
    if (data.hasErrors && tab === 'personal') { var bad = $('.inp.bad, .err.show', personal); if (bad && bad.focus) { try { bad.focus(); } catch (e) { /* fine */ } } }
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (root && $('#v-personal')) { initPage(); }
    var od = $('[data-order-details]');
    if (od) { initOrderDetails(od); }
  });
})();
