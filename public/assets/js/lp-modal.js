/* Shared confirmation modal (.lp-modal). See docs/ui-components.md.
   - open:    any element with data-lp-modal-open="<modal id>"
   - close:   [data-lp-modal-close], the backdrop, ESC (all blocked while the modal is busy submitting)
   - summary: a modal with data-lp-summary-form="<form id>" copies the form's values into [data-summary="<field name>"]
              and shows [data-summary-if="<field name>=<value>"] only when that field has that value
   - tick:    a button with data-lp-requires="<checkbox id>" stays disabled until the box is ticked
   - submit:  a button with form="<form id>" submits that form; the modal locks so it cannot be sent twice
   - gate:    a form with data-lp-gate="<modal id>" opens the modal after it passes validation instead of submitting;
              the modal's confirm button (data-lp-gate-submit="<form id>") submits it */
(function () {
  var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  var current = null, opener = null;

  function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

  function radioGroup(form, name) {
    var els = form.elements[name];
    return els && els.length !== undefined && els.tagName !== 'SELECT' ? els : null;
  }

  function fieldText(form, name) {
    var els = form.elements[name];
    if (!els) { return ''; }
    var group = radioGroup(form, name);
    if (group) {
      for (var i = 0; i < group.length; i++) {
        if (group[i].type === 'radio' && group[i].checked) {
          var lab = group[i].closest('label');
          return (lab ? lab.textContent : group[i].value).replace(/\s+/g, ' ').trim();
        }
      }
      return group[0] && group[0].type !== 'radio' ? (group[0].value || '').trim() : '';
    }
    if (els.tagName === 'SELECT') { return els.selectedOptions.length ? els.selectedOptions[0].text.trim() : ''; }
    return (els.value || '').trim();
  }

  function fieldRaw(form, name) {
    var els = form.elements[name];
    if (!els) { return ''; }
    var group = radioGroup(form, name);
    if (group) {
      for (var i = 0; i < group.length; i++) { if (group[i].type === 'radio' && group[i].checked) { return group[i].value; } }
      return group[0] && group[0].type !== 'radio' ? group[0].value : '';
    }
    return els.value;
  }

  function fill(modal) {
    var form = document.getElementById(modal.getAttribute('data-lp-summary-form'));
    if (!form) { return; }
    modal.querySelectorAll('[data-summary]').forEach(function (n) {
      n.textContent = fieldText(form, n.getAttribute('data-summary')) || '—';
    });
    modal.querySelectorAll('[data-summary-if]').forEach(function (n) {
      var pair = n.getAttribute('data-summary-if').split('=');
      n.hidden = fieldRaw(form, pair[0]) !== pair[1];
    });
  }

  function syncRequires(modal) {
    modal.querySelectorAll('[data-lp-requires]').forEach(function (btn) {
      var box = document.getElementById(btn.getAttribute('data-lp-requires'));
      btn.disabled = !(box && box.checked) || modal.hasAttribute('data-busy');
    });
  }

  function open(modal, from) {
    if (current) { return; }
    current = modal; opener = from || document.activeElement;
    if (modal.hasAttribute('data-lp-summary-form')) { fill(modal); }
    modal.querySelectorAll('input[type=checkbox][data-lp-reset]').forEach(function (c) { c.checked = false; });
    syncRequires(modal);
    modal.hidden = false;
    document.body.classList.add('lp-modal-open', 'modal-open');
    var first = modal.querySelector('[data-lp-autofocus]') || modal.querySelector('.lp-modal__foot .lp-btn--secondary') || modal.querySelector(FOCUSABLE);
    if (first) { first.focus(); }
  }

  function close() {
    if (!current || current.hasAttribute('data-busy')) { return; }
    current.hidden = true;
    document.body.classList.remove('lp-modal-open');
    document.body.classList.remove('modal-open');
    var back = opener; current = null; opener = null;
    if (back && back.focus && document.contains(back)) { back.focus(); }
  }

  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-lp-modal-open]');
    if (o) {
      var m = document.getElementById(o.getAttribute('data-lp-modal-open'));
      if (m) { e.preventDefault(); open(m, o); }
      return;
    }
    if (current && (e.target === current || e.target.closest('[data-lp-modal-close]'))) { close(); }
  });

  document.addEventListener('change', function (e) {
    if (current && e.target.type === 'checkbox') { syncRequires(current); }
  });

  document.addEventListener('keydown', function (e) {
    if (!current) { return; }
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') { return; }
    var items = Array.prototype.filter.call(current.querySelectorAll(FOCUSABLE), visible);
    if (!items.length) { return; }
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!current.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  });

  document.querySelectorAll('form[data-lp-gate]').forEach(function (form) {
    var modal = document.getElementById(form.getAttribute('data-lp-gate'));
    form.addEventListener('submit', function (e) {
      if (form.hasAttribute('data-lp-confirmed')) { return; }
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (modal) { open(modal, document.activeElement); }
    });
  });
  document.querySelectorAll('[data-lp-gate-submit]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = btn.closest('.lp-modal');
      var form = document.getElementById(btn.getAttribute('data-lp-gate-submit'));
      if (!form || modal.hasAttribute('data-busy')) { return; }
      modal.setAttribute('data-busy', ''); btn.disabled = true; btn.setAttribute('aria-busy', 'true');
      form.setAttribute('data-lp-confirmed', '');
      var finalAction = form.getAttribute('data-lp-final-action');
      if (finalAction) { form.action = finalAction; }
      form.submit();
    });
  });

  document.addEventListener('submit', function (e) {
    var btn = e.submitter;
    if (!current || e.defaultPrevented || !btn || btn.closest('.lp-modal') !== current) { return; }
    current.setAttribute('data-busy', '');
    setTimeout(function () { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }, 0);
  });

  window.LpModal = { open: open, close: close };
})();
