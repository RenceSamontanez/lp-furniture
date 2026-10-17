@extends('layouts.admin')

@php $editing = (bool) $invoice; @endphp
@section('title', ($editing ? 'Edit Draft Invoice' : 'New Invoice') . ' — Admin')
@section('page-title', $editing ? 'Edit Draft Invoice' : 'Create Invoice')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-invoices.css') }}"/>
@endpush

@section('content')
@php
  $v        = fn ($k, $d = null) => old($k, $values[$k] ?? $d);
  $options  = \App\Models\ServiceInvoice::DELIVERY_OPTIONS;
  $chosen   = $v('delivery_option');
  $rows     = old('lines', $values['lines'] ?? []);
  $action   = $editing ? route('admin.service-invoices.update', $invoice->id) : route('admin.service-invoices.store', $ticket->id);
  $back     = $editing ? route('admin.service-invoices.show', $invoice->id) : route('admin.service-inquiries.show', $ticket->id);
@endphp
<div class="co-page">

<a href="{{ $back }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back</a>

@if($errors->any())
  <div class="co-error" role="alert">
    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

<form method="POST" action="{{ $action }}" id="inv-form" class="inv-layout">
  @csrf

  <div class="inv-main">
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Service availed</div></div>
      <div class="admin-panel-body si-form">
        <p class="si-hint" style="margin:0 0 12px;">For ticket {{ $ticket->reference_number }}. The price is the one agreed with the customer in the call.</p>

        <div class="co-field">
          <label class="co-block-label" for="service_description" style="margin-top:0;">Service availed</label>
          <input type="text" id="service_description" name="service_description" class="form-input" maxlength="200" required value="{{ $v('service_description') }}"/>
        </div>

        <div class="co-field">
          <label class="co-block-label" for="service_cost" style="margin-top:0;">Service cost (₱)</label>
          <input type="text" inputmode="decimal" id="service_cost" name="service_cost" class="form-input inv-money" required value="{{ $v('service_cost') }}" placeholder="0.00" autocomplete="off"/>
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Material or other fees</div>
        <button type="button" class="admin-btn admin-btn-outline" id="inv-add-line">Add a fee</button>
      </div>
      <div class="admin-panel-body si-form">
        <p class="si-hint" id="inv-no-lines" style="margin:0;" @if(count($rows)) hidden @endif>No extra fees. Add one if the customer is charged for materials or anything else.</p>
        <div id="inv-lines">
          @foreach($rows as $i => $row)
            <div class="inv-line">
              <select name="lines[{{ $i }}][kind]" class="form-input" aria-label="Fee type">
                <option value="material_fee" @selected(($row['kind'] ?? '') === 'material_fee')>Material</option>
                <option value="other_fee" @selected(($row['kind'] ?? 'other_fee') === 'other_fee')>Other</option>
              </select>
              <input type="text" name="lines[{{ $i }}][description]" class="form-input" maxlength="200" placeholder="Description" aria-label="Fee description" value="{{ $row['description'] ?? '' }}"/>
              <input type="text" inputmode="decimal" name="lines[{{ $i }}][amount]" class="form-input inv-money" placeholder="0.00" aria-label="Fee amount" autocomplete="off" value="{{ $row['amount'] ?? '' }}"/>
              <button type="button" class="admin-btn admin-btn-outline inv-remove" aria-label="Remove this fee">Remove</button>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Delivery</div></div>
      <div class="admin-panel-body si-form">
        <div class="inv-options" role="radiogroup" aria-label="Delivery logic">
          @foreach($options as $key => $def)
            <label class="inv-option">
              <input type="radio" name="delivery_option" value="{{ $key }}" required @checked($chosen === $key)/>
              <span>{{ $def['label'] }}</span>
            </label>
          @endforeach
        </div>
        <div class="co-field" id="inv-delivery-amount-wrap" style="margin-top:14px;" @if(!$chosen || $chosen === 'none') hidden @endif>
          <label class="co-block-label" for="delivery_amount" style="margin-top:0;">Fee amount (₱)</label>
          <input type="text" inputmode="decimal" id="delivery_amount" name="delivery_amount" class="form-input inv-money" value="{{ $chosen && $chosen !== 'none' ? $v('delivery_amount') : '' }}" placeholder="0.00" autocomplete="off"/>
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Customer on the invoice</div></div>
      <div class="admin-panel-body si-form">
        <p class="si-hint" style="margin:0 0 12px;">Copied from the ticket. These are saved on the invoice and can be changed only while it is a draft.</p>
        <div class="si-form-grid">
          <div class="co-field">
            <label class="co-block-label" for="customer_name" style="margin-top:0;">Name</label>
            <input type="text" id="customer_name" name="customer_name" class="form-input" maxlength="120" required value="{{ $v('customer_name') }}"/>
          </div>
          <div class="co-field">
            <label class="co-block-label" for="customer_phone" style="margin-top:0;">Mobile number</label>
            <input type="tel" id="customer_phone" name="customer_phone" class="form-input" maxlength="16" required value="{{ $v('customer_phone') }}"/>
          </div>
        </div>
        <div class="co-field">
          <label class="co-block-label" for="customer_email" style="margin-top:0;">Email (optional)</label>
          <input type="email" id="customer_email" name="customer_email" class="form-input" maxlength="150" value="{{ $v('customer_email') }}"/>
        </div>
        <div class="co-field">
          <label class="co-block-label" for="customer_address" style="margin-top:0;">Address (optional)</label>
          <textarea id="customer_address" name="customer_address" class="form-textarea" rows="2" maxlength="500">{{ $v('customer_address') }}</textarea>
        </div>
        <div class="co-field">
          <label class="co-block-label" for="notes" style="margin-top:0;">Note on the invoice (optional)</label>
          <textarea id="notes" name="notes" class="form-textarea" rows="2" maxlength="1000">{{ $v('notes') }}</textarea>
        </div>
      </div>
    </div>
  </div>

  <aside class="inv-side">
    <div class="admin-panel inv-total-card">
      <div class="admin-panel-header"><div class="admin-panel-title">Running total</div></div>
      <div class="admin-panel-body">
        <dl class="inv-sum" id="inv-sum" aria-live="polite"></dl>
        <div class="inv-grand"><span>Total</span><strong id="inv-total">₱0.00</strong></div>
        <p class="si-hint" id="inv-delivery-label" style="margin-top:10px;"></p>
        <button type="submit" class="admin-btn admin-btn-outline co-wide inv-save" style="margin-top:14px;" data-loading="Saving...">Save draft</button>
        @if($editing)
          <button type="button" class="admin-btn admin-btn-gold co-wide" style="margin-top:10px;" data-open-send>Send to customer</button>
          <p class="si-hint" style="margin-top:8px;">Save draft keeps it private so you can come back and change it. Send to customer saves what is on this page, sends it, and locks it.</p>
        @else
          <p class="si-hint" style="margin-top:8px;">Saving a draft does not send anything to the customer. You can edit it again and send it from the next page.</p>
        @endif
      </div>
    </div>
  </aside>
</form>
</div>
@if($editing)
  @include('admin.service-invoices._send-modal', ['formId' => 'inv-form'])
@endif
@endsection

@push('scripts')
<script>
(function () {
  var optionLabels = @json(collect($options)->map(fn ($d) => $d['short']));
  var form = document.getElementById('inv-form');
  var linesBox = document.getElementById('inv-lines');
  var none = document.getElementById('inv-no-lines');
  var sum = document.getElementById('inv-sum');
  var totalEl = document.getElementById('inv-total');
  var amountWrap = document.getElementById('inv-delivery-amount-wrap');
  var amountInput = document.getElementById('delivery_amount');
  var deliveryLabel = document.getElementById('inv-delivery-label');
  var next = linesBox.querySelectorAll('.inv-line').length;

  function num(v) { var n = parseFloat(String(v).replace(/,/g, '')); return isFinite(n) && n > 0 ? n : 0; }
  function peso(n) { return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function row(label, n) {
    var d = document.createElement('div');
    var a = document.createElement('dt'); a.textContent = label;
    var b = document.createElement('dd'); b.textContent = peso(n);
    d.appendChild(a); d.appendChild(b); sum.appendChild(d);
  }

  function render() {
    var chosen = form.querySelector('input[name="delivery_option"]:checked');
    var key = chosen ? chosen.value : '';
    var hasFee = key !== '' && key !== 'none';
    amountWrap.hidden = !hasFee;
    if (!hasFee) { amountInput.value = ''; }
    deliveryLabel.textContent = chosen ? optionLabels[key] : '';

    sum.innerHTML = '';
    var total = 0, cost = num(document.getElementById('service_cost').value);
    row(document.getElementById('service_description').value.trim() || 'Service cost', cost); total += cost;
    linesBox.querySelectorAll('.inv-line').forEach(function (l) {
      var a = num(l.querySelector('.inv-money').value);
      var d = l.querySelector('input[name$="[description]"]').value.trim();
      if (a > 0) { row(d || 'Fee', a); total += a; }
    });
    if (hasFee) { var f = num(amountInput.value); if (f > 0) { row(optionLabels[key], f); total += f; } }
    totalEl.textContent = peso(total);
    none.hidden = linesBox.children.length > 0;
  }

  document.getElementById('inv-add-line').addEventListener('click', function () {
    var i = next++;
    var div = document.createElement('div');
    div.className = 'inv-line';
    div.innerHTML =
      '<select name="lines[' + i + '][kind]" class="form-input" aria-label="Fee type"><option value="material_fee">Material</option><option value="other_fee" selected>Other</option></select>' +
      '<input type="text" name="lines[' + i + '][description]" class="form-input" maxlength="200" placeholder="Description" aria-label="Fee description"/>' +
      '<input type="text" inputmode="decimal" name="lines[' + i + '][amount]" class="form-input inv-money" placeholder="0.00" aria-label="Fee amount" autocomplete="off"/>' +
      '<button type="button" class="admin-btn admin-btn-outline inv-remove" aria-label="Remove this fee">Remove</button>';
    linesBox.appendChild(div);
    div.querySelector('input').focus();
    render();
  });
  linesBox.addEventListener('click', function (e) {
    var b = e.target.closest('.inv-remove');
    if (b) { b.closest('.inv-line').remove(); render(); }
  });
  form.addEventListener('input', render);
  form.addEventListener('change', render);
  render();
})();
</script>
@endpush
