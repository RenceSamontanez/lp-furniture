/* Admin: Materials pages. Small conveniences only; the server decides everything again.
   - [data-filter-rows="#tbody"] on a search box hides the rows whose data-search text does not contain what was typed
   - a restock form shows "After restocking: ..." as the amount is typed
   - the Product modal's "Materials Needed" block: the starter is picked from the name and category with the SAME rules the server
     uses (embedded as JSON from App\Support\MaterialEstimator), Reset To Starter, an Edited tag, and the wood-not-in-inventory warning */
(function () {
  'use strict';

  function fmt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 }); }

  document.querySelectorAll('[data-filter-rows]').forEach(function (box) {
    var body = document.querySelector(box.getAttribute('data-filter-rows'));
    if (!body) { return; }
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      [].forEach.call(body.querySelectorAll('tr[data-search]'), function (tr) {
        tr.hidden = q !== '' && tr.getAttribute('data-search').indexOf(q) === -1;
      });
    });
  });

  document.querySelectorAll('form[data-restock]').forEach(function (form) {
    var now = parseFloat(form.getAttribute('data-now')) || 0;
    var unit = form.getAttribute('data-unit') || '';
    var out = form.querySelector('[data-after]');
    var input = form.elements.add_quantity;
    function show() {
      var add = parseFloat(input.value) || 0;
      var after = now + add;
      out.textContent = 'After restocking: ' + (after < 0 ? 'Still short by ' + fmt(-after) : fmt(after)) + ' ' + unit;
    }
    input.addEventListener('input', show);
    show();
  });

  /* ---------- Product modal: Materials Needed ---------- */
  var dataEl = document.getElementById('mt-matcher');
  if (!dataEl) { return; }
  var M = JSON.parse(dataEl.textContent);
  var woods = JSON.parse((document.getElementById('mt-woods') || { textContent: '[]' }).textContent);
  var KEYS = ['wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue'];

  function matchType(name, category) {
    var n = String(name || '').toLowerCase() + ' ';
    for (var k in M.keywords) {
      if (!Object.prototype.hasOwnProperty.call(M.keywords, k)) { continue; }
      // a match counts when it starts a word (so "Rocking" does not match "king"); same rule as App\Support\MaterialEstimator
      var at = n.indexOf(k);
      while (at !== -1) {
        if (at === 0 || !/[a-z]/.test(n.charAt(at - 1))) { return M.keywords[k]; }
        at = n.indexOf(k, at + 1);
      }
    }
    return M.categories[category] || M.other;
  }
  function woodKey(w) {
    w = String(w || '').trim().toLowerCase().replace(/^wood\s*\((.*)\)$/, '$1').replace(/\s+wood$/, '');
    return w.replace('gemelina', 'gmelina').trim();
  }

  document.querySelectorAll('form[data-product-form]').forEach(function (form) {
    var touched = form.getAttribute('data-edited') === '1';
    var tag = form.querySelector('[data-mt-tag]');
    var warn = form.querySelector('[data-mt-warn]');
    var typeInput = form.querySelector('[name="est_type"]');
    var fields = {};
    KEYS.forEach(function (k) { fields[k] = form.querySelector('[name="est_' + k + '"]'); });
    var nameEl = form.elements.product_name, catEl = form.elements.category, woodEl = form.elements.material;
    var isNew = form.getAttribute('data-new') === '1';

    function starter() { return matchType(nameEl.value, catEl.value === '__new__' ? '' : catEl.value); }
    function paintTag(type) {
      tag.textContent = touched ? 'Edited, from ' + type : 'Starter: ' + type;
      tag.className = 'mt-chip ' + (touched ? 'edit' : 'start');
    }
    function apply(force) {
      var type = (isNew || force) ? starter() : typeInput.value;
      if (isNew && touched && !force) { type = typeInput.value; }
      typeInput.value = type;
      if (force || (isNew && !touched)) {
        var v = M.types[type] || M.types[M.other] || {};
        KEYS.forEach(function (k) { fields[k].value = v[k] !== undefined ? v[k] : 0; });
        touched = false;
      }
      paintTag(type);
    }
    function woodWarn() {
      var key = woodKey(woodEl.value === '__new__' ? '' : woodEl.value);
      var has = key !== '' && woods.some(function (w) { return woodKey(w) === key; });
      var label = woodEl.value === '__new__' ? '' : woodEl.value;
      warn.classList.toggle('is-on', !has && label !== '');
      warn.textContent = (!has && label !== '') ? 'Wood (' + label + ') is not in your inventory yet, so its wood will not be tracked. Add it under Materials Inventory first.' : '';
    }

    if (isNew) { [nameEl, catEl].forEach(function (el) { el.addEventListener('input', function () { apply(false); }); el.addEventListener('change', function () { apply(false); }); }); }
    woodEl.addEventListener('change', woodWarn);
    KEYS.forEach(function (k) { fields[k].addEventListener('input', function () { touched = true; paintTag(typeInput.value); }); });
    var reset = form.querySelector('[data-mt-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        // Reset goes back to the starter of the type matched for this name and category (or the type it began from, when editing).
        var type = isNew ? starter() : typeInput.value;
        var v = M.types[type] || M.types[M.other] || {};
        typeInput.value = type;
        KEYS.forEach(function (k) { fields[k].value = v[k] !== undefined ? v[k] : 0; });
        touched = false;
        paintTag(type);
      });
    }
    woodWarn();
    if (isNew && !touched && !nameEl.value) { apply(true); } else { paintTag(typeInput.value); }
  });
})();
