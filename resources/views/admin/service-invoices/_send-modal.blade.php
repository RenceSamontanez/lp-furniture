{{-- "Send to customer" confirmation. $formId = the form its confirm button submits (the page's own form, via the form attribute). --}}
<div class="co-modal" id="inv-send-modal" hidden role="alertdialog" aria-modal="true" aria-labelledby="inv-send-title" aria-describedby="inv-send-text">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="inv-send-title">Send to customer</h2>
      <button type="button" class="co-modal-x" data-close-send aria-label="Close">&times;</button>
    </div>
    <p id="inv-send-text" class="inv-confirm-text">{{ \App\Models\ServiceInvoice::SEND_CONFIRMATION }}</p>
    <div class="co-modal-actions">
      <button type="button" class="lp-btn lp-btn--secondary" data-close-send>Go back</button>
      <button type="submit" form="{{ $formId }}" name="then" value="send" class="lp-btn lp-btn--primary" data-loading="Sending...">Send invoice</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('inv-send-modal');
  var openers = document.querySelectorAll('[data-open-send]');
  var last = null;
  function open(e) { last = e.currentTarget; modal.hidden = false; modal.querySelector('.lp-btn--secondary').focus(); }
  function close() { modal.hidden = true; if (last) { last.focus(); } }
  openers.forEach(function (b) { b.addEventListener('click', open); });
  modal.querySelectorAll('[data-close-send]').forEach(function (b) { b.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
})();
</script>
