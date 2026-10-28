/* Cancel an unpaid order (partials/cancel-order.blade.php): shows the optional text box only when "Other" is picked and counts its characters,
   and sends the cancel request in the background so the dialog stays open with a loading button, shows an error inside itself on failure,
   and closes (then shows the result) on success. The server validates the reason again; this only helps the person. */
(function () {
  'use strict';
  document.querySelectorAll('[data-oc-form]').forEach(function (form) {
    var other = form.querySelector('[data-oc-other]');
    var box = other ? other.querySelector('textarea') : null;
    var count = form.querySelector('[data-oc-count]');
    var modal = form.closest('.lp-modal');
    var confirmBtn = modal ? modal.querySelector('[data-oc-confirm]') : null;
    var error = form.querySelector('[data-oc-error]');

    function sync() {
      var picked = form.querySelector('input[name=reason]:checked');
      var isOther = !!picked && picked.value === 'Other';
      if (other) { other.hidden = !isOther; }
      if (!isOther && box) { box.value = ''; }
      if (count && box) { count.textContent = String(box.value.length); }
    }
    form.addEventListener('change', sync);
    if (box) { box.addEventListener('input', sync); }
    sync();

    function showError(text) {
      if (!error) { return; }
      error.textContent = text;
      error.hidden = false;
      error.scrollIntoView({ block: 'nearest' });
    }

    function setBusy(on) {
      if (!modal || !confirmBtn) { return; }
      if (on) { modal.setAttribute('data-busy', ''); } else { modal.removeAttribute('data-busy'); }
      confirmBtn.disabled = on;
      confirmBtn.classList.toggle('oc-loading', on);
      if (on) { confirmBtn.setAttribute('aria-busy', 'true'); } else { confirmBtn.removeAttribute('aria-busy'); }
      confirmBtn.querySelector('[data-oc-label]').textContent = on ? 'Cancelling…' : 'Cancel Order';
    }

    form.addEventListener('submit', function (e) {
      if (!window.fetch || !window.FormData) { return; } // old browser: ordinary post and redirect
      e.preventDefault();
      if (modal && modal.hasAttribute('data-busy')) { return; }
      if (!form.checkValidity()) { form.reportValidity(); return; }
      if (error) { error.hidden = true; }
      setBusy(true);
      var started = Date.now();
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) { return { ok: res.ok, data: data }; });
      }).then(function (r) {
        var wait = Math.max(0, 400 - (Date.now() - started));
        setTimeout(function () {
          if (r.ok && r.data && r.data.redirect) {
            setBusy(false);
            if (window.LpModal) { window.LpModal.close(); }
            window.location.assign(r.data.redirect); // the success message is flashed on that page
            return;
          }
          setBusy(false);
          showError((r.data && r.data.message) || 'We could not cancel this order. Please try again.');
        }, wait);
      }).catch(function () {
        setBusy(false);
        showError('We could not reach the server. Check your connection and try again.');
      });
    });
  });
})();
