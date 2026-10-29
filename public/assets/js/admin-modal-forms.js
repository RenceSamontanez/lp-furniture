/* Admin form modals (Chatbot Training, Contact Settings). Works with the shared modal (lp-modal.js, loaded first):
   - a modal with data-open-on-load opens when the page loads (a rejected submit reopens the same modal with the typed values)
   - a modal with data-guard asks before it throws away typed text: Escape, the x button, a click on the backdrop and Cancel all
     show "Discard what you typed?" first, unless nothing was changed
   - a form with data-modal-form checks fields marked data-required before posting (the server stays the source of truth: it
     validates again and its messages are shown under the fields when the page comes back)
   - a button with data-set-action="<form id>" fills that form's action and [data-fill] label from its data-url / data-label
     before the shared modal opens (the one Delete dialog of the intents table) */
(function () {
  'use strict';

  function serialize(form) {
    var out = [];
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
      out.push(el.name + '=' + (el.type === 'checkbox' || el.type === 'radio' ? (el.checked ? '1' : '0') : el.value));
    });
    return out.join('&');
  }

  function guarded(modal) { return modal && modal.hasAttribute('data-guard'); }
  function formOf(modal) { return modal.querySelector('form[data-modal-form]'); }

  function dirty(modal) {
    var f = formOf(modal);
    if (!f) { return false; }
    if (modal.hasAttribute('data-force-dirty')) { return true; }
    return f.__base !== undefined && serialize(f) !== f.__base;
  }

  function discardBar(modal) {
    var bar = modal.querySelector('.am-discard');
    if (bar) { return bar; }
    bar = document.createElement('div');
    bar.className = 'am-discard';
    bar.setAttribute('role', 'alertdialog');
    bar.setAttribute('aria-label', 'Discard what you typed?');
    bar.innerHTML = '<span>Discard what you typed?</span><span style="display:flex;gap:8px;"><button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-keep>Keep Editing</button><button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-discard>Discard</button></span>';
    modal.querySelector('.lp-modal__foot').insertBefore(bar, modal.querySelector('.lp-modal__foot').firstChild);
    bar.querySelector('[data-keep]').addEventListener('click', function () { bar.classList.remove('is-on'); var first = modal.querySelector('.form-input'); if (first) { first.focus(); } });
    bar.querySelector('[data-discard]').addEventListener('click', function () {
      bar.classList.remove('is-on');
      var f = formOf(modal);
      if (f) { f.reset(); clearErrors(f); }
      modal.removeAttribute('data-force-dirty');
      if (window.LpModal) { window.LpModal.close(); }
    });
    return bar;
  }

  function currentModal() {
    var open = document.querySelectorAll('.lp-modal:not([hidden])');
    return open.length ? open[open.length - 1] : null;
  }

  // closing attempts: capture phase, before the shared modal sees them
  function interceptClose(e, modal) {
    if (!guarded(modal) || !dirty(modal)) { return false; }
    e.preventDefault(); e.stopPropagation();
    var bar = discardBar(modal);
    bar.classList.add('is-on');
    bar.querySelector('[data-keep]').focus();
    return true;
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    var m = currentModal();
    if (m) { interceptClose(e, m); }
  }, true);

  document.addEventListener('click', function (e) {
    var m = currentModal();
    if (!m) { return; }
    var isBackdrop = e.target === m;
    var closer = e.target.closest && e.target.closest('[data-lp-modal-close]');
    if (isBackdrop || (closer && m.contains(closer))) { interceptClose(e, m); }
  }, true);

  // fill the shared Delete dialog before it opens
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-set-action]');
    if (!b) { return; }
    var form = document.getElementById(b.getAttribute('data-set-action'));
    if (form) { form.action = b.getAttribute('data-url'); }
    var lab = document.querySelector('[data-fill="' + b.getAttribute('data-set-action') + '"]');
    if (lab) { lab.textContent = b.getAttribute('data-label') || ''; }
  }, true);

  // "Answer" on the Unanswered Questions tab: the same Add Intent modal, Keywords pre-filled with the question, a note on top.
  function blankAdd(modal) {
    var form = formOf(modal);
    if (!form) { return null; }
    ['keywords', 'response', 'answer_tl', 'suggestions', 'category'].forEach(function (n) { if (form.elements[n]) { form.elements[n].value = ''; } });
    if (form.elements.is_active) { form.elements.is_active.checked = true; }
    var id = form.querySelector('[data-answer-id]'); if (id) { id.value = ''; }
    var note = modal.querySelector('[data-answer-note]'); if (note) { note.hidden = true; }
    clearErrors(form);
    modal.removeAttribute('data-force-dirty');
    var bar = modal.querySelector('.am-discard'); if (bar) { bar.classList.remove('is-on'); }
    return form;
  }

  document.addEventListener('click', function (e) {
    var modal = document.getElementById('intent-add');
    if (!modal) { return; }
    var answer = e.target.closest && e.target.closest('[data-answer-question]');
    if (answer) {
      var row = answer.closest('[data-answer-row]');
      if (!row) { return; }
      e.preventDefault(); e.stopPropagation();
      var form = blankAdd(modal);
      form.elements.keywords.value = row.getAttribute('data-keywords') || '';
      form.querySelector('[data-answer-id]').value = row.getAttribute('data-id') || '';
      var note = modal.querySelector('[data-answer-note]');
      note.querySelector('[data-answer-q]').textContent = '\u201C' + (row.getAttribute('data-original') || '') + '\u201D';
      note.querySelector('[data-answer-times]').textContent = row.getAttribute('data-times') || '';
      note.querySelector('[data-answer-tl]').hidden = row.getAttribute('data-tagalog') !== '1';
      note.hidden = false;
      form.__base = serialize(form);       // the pre-filled keywords are not "typed text": Cancel leaves quietly until something is changed
      if (window.LpModal) { window.LpModal.open(modal, answer); }
      form.elements.response.focus();
      return;
    }
    var plain = e.target.closest && e.target.closest('[data-lp-modal-open="intent-add"]');
    if (plain) {
      var f = blankAdd(modal);
      if (f) { f.__base = serialize(f); }
    }
  }, true);
  function clearErrors(form) {
    form.querySelectorAll('.am-field.is-invalid').forEach(function (f) { f.classList.remove('is-invalid'); });
  }

  function init() {
    document.querySelectorAll('form[data-modal-form]').forEach(function (form) {
      form.__base = serialize(form);
      form.addEventListener('input', function (e) {
        var f = e.target.closest && e.target.closest('.am-field');
        if (f) { f.classList.remove('is-invalid'); }
      });
      form.addEventListener('submit', function (e) {
        var bad = null;
        form.querySelectorAll('.am-field').forEach(function (f) {
          var el = f.querySelector('[data-required]');
          if (!el) { return; }
          var empty = !el.value.trim();
          f.classList.toggle('is-invalid', empty);
          var msg = f.querySelector('.am-err[data-client]');
          if (empty && msg) { msg.textContent = msg.getAttribute('data-client'); }
          if (empty && !bad) { bad = el; }
        });
        if (bad) { e.preventDefault(); e.stopImmediatePropagation(); bad.focus(); }
      });
    });
    var toOpen = document.querySelector('.lp-modal[data-open-on-load]');
    if (toOpen && window.LpModal) {
      window.LpModal.open(toOpen, null);
      var firstBad = toOpen.querySelector('.am-field.is-invalid .form-input');
      if (firstBad) { firstBad.focus(); }
    }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
