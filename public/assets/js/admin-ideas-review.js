/* Admin: Ideas to Review (docs/admin/admin-menu-v1.html). Works with the shared modal (lp-modal.js) and the form guard (admin-modal-forms.js).
   The rows are rendered by the server; this file filters them live, builds the View / Add as Option windows from the embedded data
   and sends every change to the existing routes. The server validates again and, if it refuses, the page comes back with the same
   window open, the typed values and the message under the field. */
(function () {
  'use strict';
  var node = document.getElementById('ir-data');
  if (!node) { return; }
  var D = JSON.parse(node.textContent), byId = {};
  D.rows.forEach(function (r) { byId[r.id] = r; });

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return [].slice.call((c || document).querySelectorAll(s)); };
  function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function url(name, id) { return D.routes[name].replace('__ID__', id); }
  function serial(form) { // same format as admin-modal-forms.js, so its "Discard what you typed?" check agrees
    var out = [];
    [].forEach.call(form.elements, function (el) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
      out.push(el.name + '=' + (el.type === 'checkbox' || el.type === 'radio' ? (el.checked ? '1' : '0') : el.value));
    });
    return out.join('&');
  }

  /* ───── tabs, search, type filter, sort (live, no Apply) ───── */
  var S = { tab: D.initial.tab, kind: D.initial.kind, sort: D.initial.sort === 'newest' ? 'recent' : 'most', q: (D.initial.q || '').toLowerCase() };
  var tbody = $('#ir-rows'), emptyBox = $('#ir-empty');

  function apply() {
    var rows = $$('.ir2-row', tbody), shown = 0;
    rows.forEach(function (tr) {
      var ok = (S.tab === 'all' || tr.dataset.status === S.tab) && (!S.kind || tr.dataset.kind === S.kind) && (!S.q || tr.dataset.search.indexOf(S.q) !== -1);
      tr.hidden = !ok;
      if (ok) { shown++; }
    });
    rows.sort(function (a, b) {
      if (S.sort === 'recent') { return (+b.dataset.last) - (+a.dataset.last) || (+b.dataset.id) - (+a.dataset.id); }
      return (+b.dataset.times) - (+a.dataset.times) || (+b.dataset.last) - (+a.dataset.last) || (+b.dataset.id) - (+a.dataset.id);
    }).forEach(function (tr) { tbody.appendChild(tr); });
    $$('.ir2-tab').forEach(function (b) { var on = b.dataset.tab === S.tab; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
    emptyBox.hidden = shown > 0;
    if (!shown) {
      var none = !D.rows.length;
      $('#ir-empty-title').textContent = none ? 'Nothing here yet' : (S.tab === 'new' && !S.q && !S.kind ? 'Nothing needs review' : 'No ideas match');
      $('#ir-empty-text').textContent = none ? 'No customer has typed anything we had no answer for yet. New ones show up here by themselves.'
        : (S.tab === 'new' && !S.q && !S.kind ? 'Every idea customers typed has been handled.' : 'Try a different tab, type or search.');
    }
  }
  $$('.ir2-tab').forEach(function (b) { b.addEventListener('click', function () { S.tab = b.dataset.tab; apply(); }); });
  $('#ir-q').addEventListener('input', function (e) { S.q = e.target.value.trim().toLowerCase(); apply(); });
  $('#ir-kind').addEventListener('change', function (e) { S.kind = e.target.value; apply(); });
  $('#ir-sort').addEventListener('change', function (e) { S.sort = e.target.value; apply(); });
  apply();

  /* ───── actions that post straight away (Dismiss, Restore) ───── */
  function post(action) {
    var f = $('#ir-act-form');
    f.action = action;
    f.submit();
  }

  /* ───── the More menu ───── */
  var menu = $('#ir-menu'), menuBtn = null;
  function closeMenu(focus) {
    if (menu.hidden) { return; }
    menu.hidden = true;
    if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); if (focus) { menuBtn.focus(); } }
    menuBtn = null;
  }
  function openMenu(btn) {
    var r = byId[btn.dataset.more];
    closeMenu();
    menuBtn = btn; btn.setAttribute('aria-expanded', 'true');
    var h = '';
    if (r.status === 'new') {
      h += r.canOption
        ? '<button type="button" role="menuitem" data-a="opt" data-id="' + r.id + '">Add as Option</button>'
        : '<button type="button" role="menuitem" aria-disabled="true" class="is-off" title="The wood list is fixed to Gmelina, Mahogany and Acacia">Add as Option<small>Not for a wood</small></button>';
      h += '<button type="button" role="menuitem" data-a="dis" data-id="' + r.id + '">Dismiss</button><hr>';
    }
    h += '<button type="button" role="menuitem" class="is-del" data-a="del" data-id="' + r.id + '">Delete</button>';
    menu.innerHTML = h; menu.hidden = false;
    var b = btn.getBoundingClientRect(), mw = menu.offsetWidth, mh = menu.offsetHeight;
    var x = Math.min(Math.max(8, b.right - mw), window.innerWidth - mw - 8), y = b.bottom + 6;
    if (y + mh > window.innerHeight - 8) { y = Math.max(8, b.top - mh - 6); }
    menu.style.left = x + 'px'; menu.style.top = y + 'px';
    menu.querySelector('button').focus();
  }
  document.addEventListener('click', function (e) {
    var m = e.target.closest('[data-more]');
    if (m) { m.getAttribute('aria-expanded') === 'true' ? closeMenu() : openMenu(m); return; }
    var a = e.target.closest('#ir-menu [data-a]');
    if (a) { var btn = menuBtn; closeMenu(); run(a.dataset.a, byId[a.dataset.id], btn); return; }
    if (!e.target.closest('#ir-menu')) { closeMenu(); }
  });
  menu.addEventListener('keydown', function (e) {
    var bs = $$('button:not([aria-disabled])', menu), i = bs.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); bs[(i + 1) % bs.length].focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); bs[(i - 1 + bs.length) % bs.length].focus(); }
    if (e.key === 'Tab') { closeMenu(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !menu.hidden) { e.stopPropagation(); closeMenu(true); } }, true);
  window.addEventListener('scroll', function () { closeMenu(); }, true);
  window.addEventListener('resize', function () { closeMenu(); });

  $('#ir-rows').addEventListener('click', function (e) {
    var v = e.target.closest('[data-view]');
    if (v) { openView(byId[v.dataset.view], v); }
  });

  function run(action, r, opener) {
    if (action === 'opt') { openOption(r, opener); }
    if (action === 'dis') { post(url('dismiss', r.id)); }
    if (action === 'del') { openDelete(r, opener); }
  }

  /* ───── Delete ───── */
  function openDelete(r, opener) {
    $('#ir-delete-form').action = url('delete', r.id);
    $('#ir-del-name').textContent = '“' + r.text + '”';
    LpModal.open($('#ir-delete'), opener);
  }

  /* ───── View + reply ───── */
  var view = $('#ir-view'), replyForm = $('#ir-reply-form'), ans = $('#ir-answer'), cur = null, auto = '';
  var noun = { wood: 'wood', furniture_type: 'furniture type', idea: 'option' };
  function item(r) { return r.furniture[0] || 'piece of furniture'; }
  var TPL = {
    yes: function (r) {
      if (r.kind === 'wood') { return 'Yes, we can make your ' + item(r) + ' in ' + r.text + '. Tell us the size and style you want and we will confirm the details with you.'; }
      if (r.kind === 'furniture_type') { return 'Yes, we can make a ' + r.text + '. Tell us the size and style you want and we will confirm the details with you.'; }
      return 'Yes, we can add ' + r.text + ' to your ' + item(r) + '. We will confirm the details with you.';
    },
    more: function (r) {
      var what = r.kind === 'wood' ? 'your ' + item(r) + ' in ' + r.text : r.kind === 'furniture_type' ? 'a ' + r.text : 'your ' + item(r) + ' with ' + r.text;
      return 'We can make ' + what + ', but it may cost a little more or take a bit longer. We will confirm the price and the build time with you before you pay.';
    },
    no: function (r) {
      if (r.kind === 'wood') { return 'Sorry, we don\'t offer ' + r.text + ' right now. Please pick Gmelina, Mahogany or Acacia, and we will help you find the closest match for your ' + item(r) + '.'; }
      if (r.kind === 'furniture_type') { return 'Sorry, we don\'t make a ' + r.text + ' right now. Please pick another furniture type from the choices on the page, and we will help you find the closest match.'; }
      return 'Sorry, we can\'t do ' + r.text + ' right now. We will suggest the closest option for your ' + item(r) + '.';
    }
  };
  var CHIPS = [
    ['Strength And Care', function (r) { return r.text + ' is [describe how strong it is and how to care for it].'; }],
    ['Price Note', function (r) { return 'The price may change with ' + r.text + '. We will confirm it before you pay.'; }],
    ['Build Time', function (r) { return 'Building with ' + r.text + ' may take [number] more days.'; }],
    ['Other Furniture', function (r) { return 'We can also make other pieces, like [a bed frame or a cabinet], with ' + r.text + '.'; }],
    ['Suggest A Similar Choice', function (r) { return 'If you want something close, we suggest [a similar ' + (noun[r.kind] || 'choice') + '].'; }]
  ];

  function crumbs(r) {
    var last = r.kind === 'furniture_type' ? ['Furniture Type', 'Something Else'] : r.kind === 'wood' ? ['Wood', 'Other Wood'] : ['Describe', 'Your Idea'];
    return ['Customize Page'].concat(last);
  }
  function where(r) {
    var who = r.times > 1 ? 'Customers' : 'A customer';
    if (r.kind === 'furniture_type') { return who + ' chose <b>Something Else</b> under Furniture Type on the Customize page and typed this.'; }
    if (r.kind === 'wood') { return who + ' chose <b>Other Wood</b> in the Wood choices on the Customize page and typed this.'; }
    return who + ' wrote this in the description box, the Describe step of the Customize page.';
  }
  function suggestion(r) {
    if (r.status !== 'new') { return ''; }
    var n = r.times;
    if (r.kind === 'wood') { return 'Typed ' + n + (n === 1 ? ' time' : ' times') + '. A wood cannot be added as an option, because the wood list is fixed to Gmelina, Mahogany and Acacia. Write a reply to explain what we can do.'; }
    if (n >= 3) { return 'Typed ' + n + ' times. This one is popular, so adding it as an option would save customers from typing it.'; }
    if (n === 1) { return 'Only one customer has asked so far. A written reply is enough for now. Add it as an option later if more people ask.'; }
    return 'Typed ' + n + ' times. If you can make it regularly, add it as an option. If it depends, write a reply.';
  }
  function requestsHtml(r) {
    var h = '<div class="ir2-sec"><h3 class="ir2-h">Requests That Asked For It (' + r.requestTotal + ')</h3>';
    if (!r.requests.length) { return h + '<p class="ir2-p ir2-soft">No matching request was found. It may have been deleted.</p></div>'; }
    h += '<div class="ir2-reqs">' + r.requests.map(function (q) {
      return '<div class="ir2-rq"><span><b>Request #' + q.id + '</b> from ' + esc(q.who) + '<br><span class="ir2-soft">Designing a ' + esc(q.item) + ' on ' + esc(q.date) + '</span></span>' +
        '<a class="lp-btn lp-btn--secondary ir2-small" href="' + esc(q.url) + '">View Request</a></div>';
    }).join('') + '</div>';
    if (r.requestTotal > r.requests.length) { h += '<p class="ir2-p ir2-soft">and ' + (r.requestTotal - r.requests.length) + ' more.</p>'; }
    return h + '</div>';
  }

  function previewNow() {
    var v = ans.value.trim();
    $('#ir-preview').innerHTML = v ? esc(v) : '<span class="ir2-soft">Your reply will show here.</span>';
  }
  function fieldErr(wrap, errEl, msg) {
    wrap.classList.toggle('is-invalid', !!msg);
    if (errEl) { errEl.textContent = msg || ''; }
  }

  function footer(r) {
    var f = $('.lp-modal__foot', view), h = '<button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button>';
    if (r.status === 'new') {
      h += '<button type="button" class="lp-btn lp-btn--secondary" data-v="dis">Dismiss</button>';
      h += r.canOption ? '<button type="button" class="lp-btn lp-btn--secondary" data-v="opt">Add as Option</button>' : '';
    }
    if (r.status === 'dismissed') { h += '<button type="button" class="lp-btn lp-btn--primary ir2-primary" data-v="undo">Restore Idea</button>'; }
    else { h += '<button type="submit" form="ir-reply-form" class="lp-btn lp-btn--primary ir2-primary" data-lp-confirm>' + (r.status === 'answered' || (r.status === 'added_as_option' && r.answer) ? 'Save Changes' : 'Send Reply') + '</button>'; }
    f.innerHTML = h;
  }

  function openView(r, opener, over) {
    cur = r; auto = '';
    $('#ir-view .lp-modal__title').textContent = 'Customer Idea';
    var chips = '<span class="ir2-chip">' + esc(r.kindLabel) + '</span> <span class="ir2-chip ir2-st-' + r.status + '">' + esc(D.statusLabels[r.status] || r.status) + '</span>';
    var cr = crumbs(r).map(function (c, i, a) { return '<span class="ir2-crumb' + (i === a.length - 1 ? ' is-hl' : '') + '">' + esc(c) + '</span>'; }).join('<span class="ir2-sep">/</span>');
    var sug = suggestion(r);
    $('#ir-view-top').innerHTML =
      '<div class="ir2-sec"><h3 class="ir2-h">What The Customer Typed ' + chips + '</h3><p class="ir2-quote">' + esc(r.text) + '</p></div>' +
      '<div class="ir2-sec"><h3 class="ir2-h">Where It Came From</h3><p class="ir2-p">' + where(r) + '</p><div class="ir2-crumbs">' + cr + '</div></div>' +
      requestsHtml(r) + (sug ? '<div class="ir2-tip"><b>Suggestion:</b> ' + esc(sug) + '</div>' : '');
    var dismissed = r.status === 'dismissed';
    replyForm.hidden = dismissed; $('.ir2-rule', view).hidden = dismissed;
    replyForm.action = url('answer', r.id);
    $('#ir-reply-id').value = r.id;
    ans.value = r.answer || '';
    $$('#ir-can .ir2-opt').forEach(function (o) { o.setAttribute('aria-checked', 'false'); });
    fieldErr($('#ir-reply-field'), $('#ir-answer-err'), '');
    $('#ir-chips').innerHTML = CHIPS.map(function (c, n) { return '<button type="button" class="ir2-chp" data-c="' + n + '">+ ' + c[0] + '</button>'; }).join('');
    footer(r); previewNow();
    view.removeAttribute('data-force-dirty');
    if (over) { ans.value = over.answer || ''; previewNow(); if (over.error) { fieldErr($('#ir-reply-field'), $('#ir-answer-err'), over.error); } view.setAttribute('data-force-dirty', ''); }
    replyForm.__base = serial(replyForm);
    if (over) { replyForm.__base = ''; }
    LpModal.open(view, opener);
    if (over && ans.offsetParent) { ans.focus(); }
  }

  $('#ir-can').addEventListener('click', function (e) {
    var o = e.target.closest('.ir2-opt');
    if (!o) { return; }
    $$('#ir-can .ir2-opt').forEach(function (x) { x.setAttribute('aria-checked', x === o ? 'true' : 'false'); });
    if (!ans.value.trim() || ans.value === auto) {
      auto = TPL[o.dataset.o](cur); ans.value = auto;
      fieldErr($('#ir-reply-field'), $('#ir-answer-err'), ''); previewNow();
    }
  });
  $('#ir-chips').addEventListener('click', function (e) {
    var c = e.target.closest('.ir2-chp');
    if (!c) { return; }
    ans.value = (ans.value.trim() ? ans.value.trim() + ' ' : '') + CHIPS[c.dataset.c][1](cur);
    auto = ''; fieldErr($('#ir-reply-field'), $('#ir-answer-err'), ''); previewNow(); ans.focus();
  });
  ans.addEventListener('input', function () { fieldErr($('#ir-reply-field'), $('#ir-answer-err'), ''); previewNow(); });

  replyForm.addEventListener('submit', function (e) {
    var v = ans.value.trim(), msg = '';
    if (/\[[^\]]*\]/.test(v)) { msg = 'Replace the [brackets] with your own words before saving.'; }
    else if (v.length > D.answerMax) { msg = 'The reply is too long. Keep it under ' + D.answerMax + ' characters.'; }
    if (msg) { e.preventDefault(); e.stopImmediatePropagation(); fieldErr($('#ir-reply-field'), $('#ir-answer-err'), msg); ans.focus(); }
  });

  // Dismiss / Add as Option / Restore from inside the View window. Typed text is never thrown away silently.
  view.addEventListener('click', function (e) {
    var b = e.target.closest('[data-v]');
    if (!b) { return; }
    var what = b.dataset.v, r = cur;
    function go() {
      if (what === 'dis') { post(url('dismiss', r.id)); }
      else if (what === 'undo') { post(url('undo', r.id)); }
      else { LpModal.close(); openOption(r, b.__opener || null); }
    }
    if (what !== 'undo' && ans.value.trim() !== (r.answer || '').trim() && ans.value.trim() !== '') {
      $('[data-lp-modal-close]', view).click();      // the guard shows "Discard what you typed?"
      var d = $('.am-discard [data-discard]', view);
      if (d) { d.addEventListener('click', function () { setTimeout(go, 0); }, { once: true }); }
      return;
    }
    go();
  });

  /* ───── Add as Option ───── */
  var optModal = $('#ir-option'), optForm = $('#ir-option-form'), optName = $('#ir-opt-name'), optScope = $('#ir-opt-scope'), optKey = $('#ir-opt-key');
  var optRow = null;
  function fillKeys(selected) {
    optKey.textContent = '';
    var list = optScope.value === 'style' ? D.styles.map(function (s) { return [s, s]; }) : Object.keys(D.typeChoices).map(function (k) { return [k, D.typeChoices[k]]; });
    list.forEach(function (p) { var o = document.createElement('option'); o.value = p[0]; o.textContent = p[1]; optKey.appendChild(o); });
    if (selected) { optKey.value = selected; }
  }
  function pills() {
    var name = optName.value.trim() || 'New Option', base, tail;
    if (optRow.kind === 'furniture_type') {
      base = Object.keys(D.typeChoices).filter(function (k) { return k !== 'Other'; }).map(function (k) { return D.typeChoices[k]; }).concat(D.extraTypes);
      tail = 'Something Else';
    } else {
      base = D.existingIdeas[optScope.value + ':' + optKey.value] || []; tail = null;
    }
    $('#ir-opt-pills').innerHTML = base.map(function (x) { return '<span class="ir2-pl">' + esc(x) + '</span>'; }).join('') +
      '<span class="ir2-pl is-new">' + esc(name) + '<i>New</i></span>' + (tail ? '<span class="ir2-pl">' + esc(tail) + '</span>' : '');
  }
  ['input', 'change'].forEach(function (ev) {
    optName.addEventListener(ev, function () { fieldErr($('#ir-opt-name-field'), $('#ir-opt-name-err'), ''); pills(); });
    optScope.addEventListener(ev, function () { if (ev === 'change') { fillKeys(); } pills(); });
    optKey.addEventListener(ev, pills);
  });

  function openOption(r, opener, over) {
    optRow = r;
    optForm.action = url('option', r.id);
    $('#ir-option-id').value = r.id;
    var asked = r.askedFor ? 'Customers designing a <b>' + esc(r.askedFor) + '</b> typed ' : 'Customers typed ';
    var isType = r.kind === 'furniture_type';
    $('#ir-option-ctx').innerHTML = asked + '<b>' + esc(r.text) + '</b> ' + (isType ? 'under <b>Something Else</b>. Add it as a quick pick so they can click it next time.' : 'in their description. Add it as a quick idea so they can click it next time.');
    $('#ir-option-list-fixed').hidden = !isType; $('#ir-option-list-pick').hidden = isType;
    $('#ir-option-list-fixed').textContent = 'Furniture Type: The Something Else Quick Picks';
    optScope.disabled = isType; optKey.disabled = isType;
    optName.maxLength = (D.nameMax[isType ? 'furniture_type' : 'idea'] || 60);
    optScope.value = 'type'; fillKeys();
    optName.value = r.text.slice(0, optName.maxLength);
    fieldErr($('#ir-opt-name-field'), $('#ir-opt-name-err'), ''); fieldErr($('#ir-option-list-wrap'), $('#ir-opt-key-err'), '');
    if (over) {
      if (over.old.scope) { optScope.value = over.old.scope; fillKeys(over.old.key); }
      if (over.old.wording != null) { optName.value = over.old.wording; }
      if (over.errors.wording) { fieldErr($('#ir-opt-name-field'), $('#ir-opt-name-err'), over.errors.wording[0]); }
      var other = (over.errors.key || over.errors.scope || [])[0];
      if (other) { fieldErr($('#ir-option-list-wrap'), $('#ir-opt-key-err'), other); }
      optModal.setAttribute('data-force-dirty', '');
    } else { optModal.removeAttribute('data-force-dirty'); }
    pills();
    optForm.__base = over ? '' : serial(optForm);
    LpModal.open(optModal, opener);
    if (over) { (over.errors.wording ? optName : optKey).focus(); }
  }

  /* ───── a refused save comes back with the same window open ───── */
  document.addEventListener('DOMContentLoaded', function () {
    var re = D.reopen;
    if (!re || !byId[re.id]) { return; }
    var r = byId[re.id], errs = re.errors || {};
    if (re.modal === 'option') { openOption(r, null, { old: re.old || {}, errors: errs }); }
    else { openView(r, null, { answer: (re.old || {}).answer, error: (errs.answer || [])[0] }); }
  });
})();
