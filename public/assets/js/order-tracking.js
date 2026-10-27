/* Admin order page: the Update progress card. Plain forms posted to the server; this script only adds the confirmation dialog,
   the message counter, the photo preview and the saving state. The server re-checks everything (OrderTracking). */
(function () {
  'use strict';

  var root = document.querySelector('.ot[data-ot-admin]');
  if (!root) { return; }

  var MIN_SPIN = 400;
  var form = document.getElementById('ot-form');
  var actionForm = document.getElementById('ot-action');
  var dlg = document.getElementById('ot-dlg');
  var yes = document.getElementById('ot-dlg-yes');
  var no = document.getElementById('ot-dlg-no');
  var chk = document.getElementById('ot-dlg-chk');
  var reason = document.getElementById('ot-dlg-reason');
  var reasonErr = document.getElementById('ot-dlg-reason-err');
  var current = null;
  var submitting = false;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function setBusy(btn, label) {
    if (!btn || btn.classList.contains('is-busy')) { return; }
    btn.__old = btn.innerHTML;
    btn.classList.add('is-busy');
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<i class="spin" aria-hidden="true"></i><span>' + esc(label) + '</span>';
  }
  function lockAll() {
    submitting = true;
    root.querySelectorAll('button').forEach(function (b) { if (!b.classList.contains('is-busy')) { b.disabled = true; } });
  }
  function send(f, btn, label) {
    if (submitting) { return; }
    var started = Date.now();
    setBusy(btn, label);
    lockAll();
    setTimeout(function () { f.submit(); }, Math.max(0, MIN_SPIN - (Date.now() - started)));
  }

  /* ───────── message counter, send-only state ───────── */
  var msg = document.getElementById('ot-msg');
  var cnt = document.getElementById('ot-cnt');
  var sendOnly = document.getElementById('ot-send-only');
  var file = document.getElementById('ot-photo');
  var addBtn = document.getElementById('ot-add-photo');
  var thumbWrap = document.getElementById('ot-thumb');
  var photoErr = document.getElementById('ot-photo-err');
  var pv = document.getElementById('ot-pv-msg');

  function refresh() {
    var hasMsg = msg && msg.value.trim() !== '';
    var hasPhoto = file && file.files && file.files.length > 0;
    if (cnt && msg) { cnt.textContent = msg.value.length + '/300'; }
    if (sendOnly) { sendOnly.disabled = !(hasMsg || hasPhoto); }
    if (pv) { pv.innerHTML = hasMsg ? '<br><em>&ldquo;' + esc(msg.value.trim()) + '&rdquo;</em>' : ''; }
  }
  if (msg) { msg.addEventListener('input', refresh); }

  function clearPhoto() {
    if (file) { file.value = ''; }
    if (thumbWrap) { thumbWrap.innerHTML = ''; }
    if (addBtn) { addBtn.hidden = false; }
    refresh();
  }
  if (addBtn && file) {
    addBtn.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
      var f = file.files[0];
      if (photoErr) { photoErr.textContent = ''; }
      if (!f) { return clearPhoto(); }
      if (['image/jpeg', 'image/png'].indexOf(f.type) < 0) { if (photoErr) { photoErr.textContent = 'Photo must be a JPG or PNG.'; } return clearPhoto(); }
      if (f.size > 5 * 1024 * 1024) { if (photoErr) { photoErr.textContent = 'Photo must be 5 MB or smaller.'; } return clearPhoto(); }
      var url = URL.createObjectURL(f);
      thumbWrap.innerHTML = '<img src="' + url + '" alt="Photo to send"> <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" id="ot-rm-photo">Remove photo</button>';
      addBtn.hidden = true;
      document.getElementById('ot-rm-photo').addEventListener('click', clearPhoto);
      refresh();
    });
  }
  refresh();

  if (sendOnly) {
    sendOnly.addEventListener('click', function (e) {
      e.preventDefault();
      form.setAttribute('action', sendOnly.getAttribute('data-ot-url'));
      send(form, sendOnly, 'Sending…');
    });
  }

  /* ───────── confirmation dialog ───────── */
  function openDlg(btn) {
    current = { kind: btn.getAttribute('data-ot-kind'), btn: btn, url: btn.getAttribute('data-ot-url') };
    document.getElementById('ot-dlg-title').textContent = btn.getAttribute('data-ot-title');
    document.getElementById('ot-dlg-q').textContent = btn.getAttribute('data-ot-question');
    document.getElementById('ot-dlg-chk-text').textContent = btn.getAttribute('data-ot-check');
    var needReason = btn.getAttribute('data-ot-reason') === '1';
    document.getElementById('ot-dlg-reason-wrap').hidden = !needReason;
    reason.value = ''; reasonErr.hidden = true;
    var prev = document.getElementById('ot-dlg-prev');
    var copy = btn.getAttribute('data-ot-copy');
    if (copy) {
      var m = msg && msg.value.trim() ? '<br><em>&ldquo;' + esc(msg.value.trim()) + '&rdquo;</em>' : '';
      var p = file && file.files && file.files.length ? '<br>+ 1 progress photo' : '';
      prev.innerHTML = '<small>The customer will receive (Notifications + email)</small>' + esc(copy) + m + p;
      prev.hidden = false;
    } else {
      prev.hidden = true;
    }
    chk.checked = false;
    yes.textContent = btn.getAttribute('data-ot-yes');
    yes.className = 'lp-btn ' + (btn.getAttribute('data-ot-danger') === '1' ? 'lp-btn--danger' : 'lp-btn--primary');
    yes.disabled = true;
    if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    (needReason ? reason : chk).focus();
  }
  root.querySelectorAll('[data-ot-open]').forEach(function (b) {
    b.addEventListener('click', function () { openDlg(b); });
  });
  if (chk) { chk.addEventListener('change', function () { yes.disabled = !chk.checked; }); }
  if (no) { no.addEventListener('click', function () { dlg.close(); }); }
  if (yes) {
    yes.addEventListener('click', function () {
      if (!current || !chk.checked) { return; }
      var needReason = !document.getElementById('ot-dlg-reason-wrap').hidden;
      var r = reason.value.trim();
      if (needReason && (r.length < 8 || r.length > 300)) { reasonErr.hidden = false; reason.focus(); return; }
      var c = current; current = null;
      dlg.close();
      if (c.kind === 'advance') {
        form.setAttribute('action', c.url);
        document.getElementById('ot-confirmed').value = '1';
        send(form, c.btn, 'Saving…');
      } else {
        actionForm.setAttribute('action', c.url);
        actionForm.querySelector('[name=confirmed]').value = '1';
        actionForm.querySelector('[name=reason]').value = r;
        send(actionForm, c.btn, 'Saving…');
      }
    });
  }
  // a double click on "Confirm" or the Enter key must not post twice
  if (form) { form.addEventListener('submit', function (e) { if (submitting && !e.__ok) { e.preventDefault(); } }); }
})();

/* Record payment form: the same saving state (the server also refuses a double submit through its one-time token). */
(function () {
  'use strict';
  var f = document.getElementById('record-payment-form');
  var b = document.getElementById('rp-submit');
  if (!f || !b) { return; }
  f.addEventListener('submit', function () {
    b.__old = b.innerHTML;
    b.classList.add('is-busy');
    b.setAttribute('aria-busy', 'true');
    b.innerHTML = '<i class="spin" aria-hidden="true"></i><span>Recording…</span>';
    setTimeout(function () { b.disabled = true; }, 0);
  });
})();
