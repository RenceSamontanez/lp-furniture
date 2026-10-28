/* Cancel an unpaid order (partials/cancel-order.blade.php): shows the optional text box only when "Other" is picked and counts its characters.
   The server validates the reason again; this only helps the person. */
(function () {
  'use strict';
  document.querySelectorAll('[data-oc-form]').forEach(function (form) {
    var other = form.querySelector('[data-oc-other]');
    var box = other ? other.querySelector('textarea') : null;
    var count = form.querySelector('[data-oc-count]');
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
  });
})();
