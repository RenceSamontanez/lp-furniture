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

<a href="{{ route('admin.service-inquiries.show', $ticket->id) }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to ticket {{ $ticket->reference_number }}</a>

@if($errors->any())
  <div class="co-error" role="alert">{{ $errors->has('invoice') ? $errors->first('invoice') : $errors->first() }}</div>
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
      <div class="admin-panel-body">
        <a href="{{ route('admin.service-invoices.print', $i->id) }}" class="admin-btn admin-btn-outline co-wide" target="_blank" rel="noopener" style="margin-bottom:10px;">Open printable invoice</a>

        @if($i->status === 'draft')
          <p class="si-hint inv-draft-note">This is a draft. The customer cannot see it. You can edit it as many times as you need.</p>
          <a href="{{ route('admin.service-invoices.edit', $i->id) }}" class="admin-btn admin-btn-outline co-wide" style="margin-bottom:10px;">Edit draft</a>
          <button type="button" class="admin-btn admin-btn-gold co-wide" data-open-send>Send to customer</button>
          <form method="POST" action="{{ route('admin.service-invoices.issue', $i->id) }}" id="inv-send-form">@csrf</form>
          <form method="POST" action="{{ route('admin.service-invoices.delete', $i->id) }}" onsubmit="return confirm('Delete this draft? Drafts have no invoice number, so nothing is lost from the numbering.')">
            @csrf
            <button type="submit" class="admin-btn admin-btn-outline co-wide inv-delete" style="margin-top:10px;">Delete draft</button>
          </form>
          <p class="si-hint" style="margin-top:10px;">Sending gives the invoice its number{{ $i->user_id ? ' and notifies the customer' : '' }}.</p>
        @endif

        @if($i->status === 'issued')
          <button type="button" class="admin-btn admin-btn-gold co-wide" id="inv-open-pay">Record payment</button>
          <p class="si-hint" style="margin-top:10px;">This invoice has been sent, so it is locked. To change it, cancel it and send a new one.</p>
        @endif

        @if(in_array($i->status, ['issued', 'paid'], true))
          <button type="button" class="admin-btn admin-btn-outline co-wide inv-delete" id="inv-open-cancel" style="margin-top:10px;">Cancel invoice</button>
        @endif

        @if($i->status === 'paid')
          <p class="si-hint">Paid in full. The payment details above stay on the record.</p>
        @elseif($i->status === 'void')
          <p class="co-note-closed">This invoice was cancelled. It stays on record.</p>
        @endif
      </div>
    </div>
  </div>
</div>

@if($i->status === 'issued')
<div class="co-modal" id="inv-pay-modal" hidden role="dialog" aria-modal="true" aria-labelledby="inv-pay-title">
  <div class="co-modal-box">
    <div class="co-modal-head">
      <h2 id="inv-pay-title">Record payment</h2>
      <button type="button" class="co-modal-x" id="inv-pay-close" aria-label="Close">&times;</button>
    </div>
    <form method="POST" action="{{ route('admin.service-invoices.paid', $i->id) }}">
      @csrf
      <label class="co-modal-label" for="inv-method">How did the customer pay?</label>
      <select name="paid_method" id="inv-method" class="form-input" required>
        @foreach(\App\Models\ServiceInvoice::PAID_METHODS as $k => $label)
          <option value="{{ $k }}" @selected(old('paid_method') === $k)>{{ $label }}</option>
        @endforeach
      </select>

      <label class="co-modal-label" for="inv-ref">Reference number <span class="si-hint" id="inv-ref-hint">(optional for cash)</span></label>
      <input type="text" name="paid_reference" id="inv-ref" class="form-input" maxlength="100" value="{{ old('paid_reference') }}"/>

      <label class="co-modal-label" for="inv-received">Amount received (₱)</label>
      <input type="text" inputmode="decimal" name="amount_received" id="inv-received" class="form-input inv-money" required value="{{ old('amount_received', number_format((float) $i->total, 2, '.', '')) }}"/>

      <label class="co-modal-label" for="inv-date">Date received</label>
      <input type="date" name="paid_date" id="inv-date" class="form-input" required max="{{ $today }}" value="{{ old('paid_date', $today) }}"/>

      <div class="co-modal-actions">
        <button type="button" class="admin-btn admin-btn-outline" id="inv-pay-cancel">Cancel</button>
        <button type="submit" class="admin-btn admin-btn-gold" data-loading="Saving...">Save payment</button>
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
        <button type="button" class="admin-btn admin-btn-outline" data-close-cancel>Go back</button>
        <button type="submit" class="admin-btn admin-btn-gold" data-loading="Cancelling...">Cancel invoice</button>
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
