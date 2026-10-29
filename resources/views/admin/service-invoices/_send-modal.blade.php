{{-- "Send to customer" confirmation. $formId = the form its confirm button submits (the page's own form, via the form attribute).
     A ticket with no clear description (under 40 characters) and no photo shows an Important notice and needs a tick. --}}
@php $weakTicket = isset($ticket) && !$ticket->hasEnoughDetail(); @endphp
<div class="co-modal" id="inv-send-modal" hidden role="alertdialog" aria-modal="true" aria-labelledby="inv-send-title" aria-describedby="inv-send-text">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="inv-send-title">Send to Customer</h2>
      <button type="button" class="co-modal-x" data-close-send aria-label="Close">&times;</button>
    </div>
    <p id="inv-send-text" class="inv-confirm-text">{{ \App\Models\ServiceInvoice::SEND_CONFIRMATION }}</p>
    @if($weakTicket)
      <x-notice variant="waiting" title="This ticket has very little detail">There is no clear description (at least 40 characters) and no photo on {{ $ticket->reference_number }}. Make sure you know what the work is before the customer pays.</x-notice>
      <label class="inv-detail-tick"><input type="checkbox" name="details_ok" value="1" form="{{ $formId }}" required/> <span>I have enough details to do this work</span></label>
    @endif
    <div class="co-modal-actions">
      <button type="button" class="lp-btn lp-btn--secondary" data-close-send>Go Back</button>
      <button type="submit" form="{{ $formId }}" name="then" value="send" class="lp-btn lp-btn--primary" data-loading="Sending...">Send Invoice</button>
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
