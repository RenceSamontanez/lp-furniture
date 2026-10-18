@extends('layouts.admin')

@section('title', 'Invoice — Admin')
@section('page-title', 'Service Invoice')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-invoices.css') }}"/>
@endpush

@section('content')
@php
  $i = $invoice;
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
  $when = fn ($d) => $d?->timezone('Asia/Manila')->format('M d, Y h:i A');
@endphp
<div class="co-page">

<a href="{{ route('admin.service-inquiries.show', $ticket->id) }}" class="lp-btn lp-btn--secondary" style="margin-bottom:20px;">← Back to ticket {{ $ticket->reference_number }}</a>

@if($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('invoice') ? $errors->first('invoice') : $errors->first() }}</div>
@endif

@if($i->status === 'void')
  <x-notice variant="blocked" title="Cancelled">
    {{ $i->void_reason ?: 'This invoice was cancelled.' }}
  </x-notice>
@elseif($openRequest)
  <x-notice variant="blocked" title="On hold: cancellation requested">
    Request {{ $openRequest->reference }} is {{ strtolower($openRequest->statusLabel()) }}. Payments are paused until it is decided.
    <x-slot:actions><a href="{{ route('admin.refunds.show', $openRequest->id) }}" class="lp-btn lp-btn--primary">Open the request</a></x-slot:actions>
  </x-notice>
@elseif($work === 'started' || $work === 'done')
  <x-notice variant="info" title="{{ $work === 'done' ? 'Service done' : 'Work started' }}">
    The customer can no longer get an automatic refund. A refund now needs an exception.
  </x-notice>
@endif
<div class="co-detail">
  <div>
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">{{ $i->invoice_number ?: 'Draft invoice #' . $i->id }}</div>
        <span class="co-chip inv-status--{{ $i->status }}">{{ $i->statusLabel() }}</span>
      </div>
      <div class="admin-panel-body">
        <dl class="co-dl">
          <div><dt>Customer</dt><dd>{{ $i->customer_name }}</dd></div>
          <div><dt>Phone</dt><dd>{{ $i->customer_phone }}</dd></div>
          <div><dt>Email</dt><dd>{{ $i->customer_email ?: '—' }}</dd></div>
          <div><dt>Address</dt><dd style="white-space:pre-line;">{{ $i->customer_address ?: '—' }}</dd></div>
          <div><dt>Service availed</dt><dd>{{ $i->service_description }}</dd></div>
          <div><dt>Delivery</dt><dd>{{ $i->deliveryLabel() }}</dd></div>
          <div><dt>Account</dt><dd>{{ $i->user_id ? 'Linked, the customer can open this invoice and is notified' : 'No account, so no in-app notification is sent' }}</dd></div>
          @if($i->issued_at)<div><dt>Sent</dt><dd>{{ $when($i->issued_at) }}</dd></div>@endif
          @if($i->paid_at)
            <div><dt>Paid</dt><dd>{{ $when($i->paid_at) }} · {{ $i->paidMethodLabel() }}@if($i->paid_reference) · ref {{ $i->paid_reference }}@endif · {{ $peso($i->amount_received) }} received</dd></div>
          @endif
          @if($i->voided_at)
            <div><dt>Cancelled</dt><dd>{{ $when($i->voided_at) }}. {{ $i->void_reason }}</dd></div>
          @endif
        </dl>

        <div class="co-block-label">Lines</div>
        <table class="inv-table">
          <thead><tr><th>Description</th><th>Amount</th></tr></thead>
          <tbody>
            @foreach($i->lines as $line)
              <tr>
                <td>{{ $line->description }}<span class="inv-kind">{{ \App\Models\ServiceInvoiceLine::KINDS[$line->kind] ?? $line->kind }}</span></td>
                <td>{{ $peso($line->amount) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot><tr><td>Total</td><td>{{ $peso($i->total) }}</td></tr></tfoot>
        </table>

        @if($i->notes)
          <div class="co-block-label">Note on the invoice</div>
          <div class="co-quote si-concern">{{ $i->notes }}</div>
        @endif
      </div>
    </div>
  </div>

  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Actions</div></div>
      <div class="admin-panel-body inv-actions">
        <a href="{{ route('admin.service-invoices.print', $i->id) }}" class="lp-btn lp-btn--secondary lp-btn--block" target="_blank" rel="noopener">Open printable invoice</a>

        @if($i->status === 'draft')
          <p class="si-hint inv-draft-note">This is a draft. The customer cannot see it. You can edit it as many times as you need.</p>
          <a href="{{ route('admin.service-invoices.edit', $i->id) }}" class="lp-btn lp-btn--secondary lp-btn--block">Edit draft</a>
          <button type="button" class="lp-btn lp-btn--primary lp-btn--block" data-open-send>Send to customer</button>
          <form method="POST" action="{{ route('admin.service-invoices.issue', $i->id) }}" id="inv-send-form">@csrf</form>
          <form method="POST" action="{{ route('admin.service-invoices.delete', $i->id) }}" onsubmit="return confirm('Delete this draft? Drafts have no invoice number, so nothing is lost from the numbering.')">
            @csrf
            <button type="submit" class="lp-btn lp-btn--danger lp-btn--block inv-delete">Delete draft</button>
          </form>
          <p class="si-hint">Sending gives the invoice its number{{ $i->user_id ? ' and notifies the customer' : '' }}.</p>
        @endif

        @if($i->status === 'issued')
          <p class="si-hint">This invoice has been sent, so it is locked. To change it, cancel it and send a new one.</p>
        @endif

        @if(in_array($i->status, ['issued', 'paid'], true))
          @if($i->hasPayment())
            <x-notice variant="blocked" title="This invoice has a payment on it">
              Open a cancellation request so the refund is recorded.
              <x-slot:actions><a href="{{ route('admin.service-inquiries.show', $ticket->id) }}#cancellation-request" class="lp-btn lp-btn--primary">Open a cancellation request</a></x-slot:actions>
            </x-notice>
          @else
            <button type="button" class="lp-btn lp-btn--danger lp-btn--block inv-delete" id="inv-open-cancel">Cancel invoice</button>
          @endif
        @endif

        @if($i->status === 'paid')
          <p class="si-hint">Paid in full. The payment details stay on the record.</p>
        @elseif($i->status === 'void')
          <p class="co-note-closed">This invoice was cancelled. It stays on record.</p>
        @endif
      </div>
    </div>
  </div>
</div>

@if(in_array($i->status, ['issued', 'paid'], true) || $i->payments->isNotEmpty())
@php
  $planLabel = \App\Support\InvoicePaymentRules::PLANS[$i->payment_plan] ?? \App\Support\InvoicePaymentRules::PLANS['full'];
  $slots     = $i->installmentCents();
  $paidBy    = $i->payments->where('status', 'paid')->keyBy('installment');
@endphp
<div class="admin-panel inv-pay-panel" id="payments">
  <div class="admin-panel-header">
    <div class="admin-panel-title">Payments</div>
    <span class="co-chip inv-pay--{{ strtolower(str_replace(' ', '-', $i->paymentStateLabel())) }}">{{ $i->paymentStateLabel() }}</span>
  </div>
  <div class="admin-panel-body">
    <dl class="co-dl">
      <div><dt>Plan</dt><dd>{{ $planLabel }}</dd></div>
      <div><dt>Paid so far</dt><dd>{{ $peso($i->amount_paid) }} of {{ $peso($i->total) }}</dd></div>
      <div><dt>Service done</dt><dd>{{ $i->service_done_at ? $when($i->service_done_at) : 'Not yet' }}</dd></div>
    </dl>

    <table class="inv-table inv-installments">
      <thead><tr><th>Payment</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
        @foreach($slots as $n => $cents)
          @php
            $label = count($slots) === 1 ? 'Full payment' : ($n === 1 ? 'First half' : 'Remaining balance');
            if (isset($paidBy[$n])) { $status = 'Paid'; }
            elseif ($i->status === 'void') { $status = 'Not due (cancelled)'; }
            elseif ($n === 2 && !$i->service_done_at) { $status = 'Waiting for "Service done"'; }
            elseif ($n === ($due['installment'] ?? null)) { $status = 'Due now'; }
            else { $status = 'Not due yet'; }
          @endphp
          <tr><td>{{ $label }}</td><td>{{ $peso($cents / 100) }}</td><td>{{ $status }}</td></tr>
        @endforeach
      </tbody>
    </table>

    @if($i->payments->isNotEmpty())
      <div class="co-block-label">Payment records</div>
      <table class="inv-table">
        <thead><tr><th>When</th><th>How</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($i->payments as $p)
            <tr>
              <td>{{ $when($p->paid_at ?? $p->created_at) }}</td>
              <td>{{ $p->methodLabel() }}@if(count($slots) > 1) · {{ $p->installment === 1 ? 'first half' : 'remaining' }}@endif @if($p->recorder)<span class="inv-kind">recorded by {{ $p->recorder->first_name }}</span>@endif</td>
              <td>{{ $peso($p->amount) }}</td>
              <td>{{ ucfirst($p->status) }}@if($p->note)<span class="inv-kind">{{ $p->note }}</span>@endif</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    @if($i->status === 'paid' && !$i->service_done_at)
      <div class="inv-pay-actions">
        <form method="POST" action="{{ route('admin.service-invoices.service-done', $i->id) }}" onsubmit="return confirm('Mark the service as done? This is recorded on the ticket.')">
          @csrf
          <button type="submit" class="lp-btn lp-btn--primary">Service done</button>
        </form>
      </div>
    @endif

    @if($i->status === 'issued')
      <div class="inv-pay-actions">
        @if(!$i->service_done_at)
          <form method="POST" action="{{ route('admin.service-invoices.service-done', $i->id) }}" onsubmit="return confirm('Mark the service as done? {{ $i->isHalfHalf() ? 'The customer will be told the remaining balance is due.' : 'This is recorded on the ticket.' }}')">
            @csrf
            <button type="submit" class="lp-btn lp-btn--primary">Service done</button>
          </form>
        @endif
        @if($due['installment'] !== null)
          <button type="button" class="lp-btn lp-btn--secondary" id="inv-open-cash" @disabled(!$due['available'])>Record cash payment</button>
        @endif
      </div>
      @if($due['installment'] !== null && !$due['available'])
        <p class="si-hint">The remaining balance can be paid (online or in cash) only after you tap "Service done".</p>
      @endif
    @endif

    @if($i->refundNotes->isNotEmpty())
      <div class="co-block-label" style="margin-top:22px;">Earlier refund notes</div>
      <p class="si-hint">Read only. These were written before cancellations and refunds had their own screen.</p>
      <ul class="inv-refunds">
        @foreach($i->refundNotes as $r)
          <li><strong>{{ $peso($r->amount) }}</strong> · {{ \App\Models\ServiceInvoiceRefundNote::METHODS[$r->method] ?? $r->method }} · {{ $when($r->created_at) }}@if($r->recorder) · {{ $r->recorder->first_name }}@endif<br/>{{ $r->note }}</li>
        @endforeach
      </ul>
    @endif

    @foreach($i->refundRequests as $rr)
      <div class="co-block-label" style="margin-top:22px;">Cancellation request</div>
      <p class="si-hint"><a href="{{ route('admin.refunds.show', $rr->id) }}">{{ $rr->reference }}</a> · {{ $rr->statusLabel() }}</p>
    @endforeach
  </div>
</div>
@endif

@if($i->status === 'issued' && $due['installment'] !== null && $due['available'])
<div class="co-modal" id="inv-cash-modal" hidden role="dialog" aria-modal="true" aria-labelledby="inv-cash-title">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="inv-cash-title">Record cash payment</h2>
      <button type="button" class="co-modal-x" data-close-cash aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('admin.service-invoices.cash', $i->id) }}">
      @csrf
      <p class="inv-confirm-text">This records cash taken in person for <strong>{{ $due['label'] === 'Pay in full' ? 'the full amount' : strtolower(str_replace('Pay ', '', $due['label'])) }}</strong>. The amount must be exactly <strong>{{ $peso($due['cents'] / 100) }}</strong>.</p>
      <label class="co-modal-label" for="inv-cash-amount">Cash received (₱)</label>
      <input type="text" inputmode="decimal" name="amount" id="inv-cash-amount" class="form-input inv-money" required value="{{ old('amount', number_format($due['cents'] / 100, 2, '.', '')) }}"/>
      <label class="inv-check"><input type="checkbox" name="confirm" value="1" required/> I received this cash in person.</label>
      <div class="co-modal-actions">
        <button type="button" class="lp-btn lp-btn--secondary" data-close-cash>Go back</button>
        <button type="submit" class="lp-btn lp-btn--primary" data-loading="Saving...">Record cash payment</button>
      </div>
    </form>
  </div>
</div>
@endif
</div>
@if(in_array($i->status, ['issued', 'paid'], true))
<div class="co-modal" id="inv-cancel-modal" hidden role="alertdialog" aria-modal="true" aria-labelledby="inv-cancel-title" aria-describedby="inv-cancel-text">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="inv-cancel-title">Cancel invoice</h2>
      <button type="button" class="co-modal-x" data-close-cancel aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('admin.service-invoices.void', $i->id) }}">
      @csrf
      <p id="inv-cancel-text" class="inv-confirm-text">{{ \App\Models\ServiceInvoice::CANCEL_CONFIRMATION }}</p>
      <label class="co-modal-label" for="inv-cancel-reason">Reason</label>
      <textarea name="reason" id="inv-cancel-reason" class="form-input" rows="3" minlength="5" maxlength="1000" required
                placeholder="e.g. Wrong amount. A new invoice will be sent." aria-describedby="inv-cancel-help">{{ old('reason') }}</textarea>
      <p class="si-hint" id="inv-cancel-help" style="margin:6px 0 0;">The customer will see this reason.</p>
      <div class="co-modal-actions">
        <button type="button" class="lp-btn lp-btn--secondary" data-close-cancel>Go back</button>
        <button type="submit" class="lp-btn lp-btn--danger" data-loading="Cancelling...">Cancel invoice</button>
      </div>
    </form>
  </div>
</div>
@endif
@if($i->status === 'draft')
  @include('admin.service-invoices._send-modal', ['formId' => 'inv-send-form'])
@endif
@endsection

@push('scripts')
@if(in_array($i->status, ['issued', 'paid'], true))
<script>
(function () {
  var modal = document.getElementById('inv-cancel-modal');
  var opener = document.getElementById('inv-open-cancel');
  if (!opener || !modal) { return; }
  function open() { modal.hidden = false; document.getElementById('inv-cancel-reason').focus(); }
  function close() { modal.hidden = true; opener.focus(); }
  opener.addEventListener('click', open);
  modal.querySelectorAll('[data-close-cancel]').forEach(function (b) { b.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
  @if($errors->has('reason'))
  open();
  @endif
})();
</script>
@endif
@if($i->status === 'issued')
<script>
(function () {
  var modal = document.getElementById('inv-pay-modal');
  var opener = document.getElementById('inv-open-pay');
  var method = document.getElementById('inv-method');
  var hint = document.getElementById('inv-ref-hint');
  function open() { modal.hidden = false; method.focus(); }
  function close() { modal.hidden = true; opener.focus(); }
  function hintText() { hint.textContent = method.value === 'cash' ? '(optional for cash)' : '(required)'; }
  opener.addEventListener('click', open);
  document.getElementById('inv-pay-close').addEventListener('click', close);
  document.getElementById('inv-pay-cancel').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
  method.addEventListener('change', hintText);
  hintText();
  @if($errors->has('paid_method') || $errors->has('amount_received') || $errors->has('paid_date'))
  open();
  @endif
})();
</script>
@endif
@endpush
