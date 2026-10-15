@extends('layouts.admin')

@section('title', 'Quote — Admin')
@section('page-title', $quote ? 'Edit Draft Quote' : 'New Quote')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
@endpush

@section('content')
@php
  $r = $designRequest;
  $v = $values;
  $isDelivery = ($v['delivery_option'] ?? 'pickup') === 'delivery';
  $isDeposit  = ($v['payment_terms'] ?? 'full') === 'deposit';
  $nextVersion = $quote ? $quote->version : (($basedOn?->version ?? 0) + 1);
  $minPercent = \App\Support\InstallmentScheduler::MIN_DEPOSIT_PERCENT;
@endphp

<a href="{{ route('admin.custom-orders.show', $r->id) }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to request</a>

@if($errors->any())
  <div class="co-error" role="alert">
    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
  </div>
@endif

<div class="co-detail">
  <form method="POST" action="{{ $action }}" id="quote-form">
    @csrf
    <input type="hidden" name="lock_version" value="{{ $r->lock_version }}"/>

    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Version {{ $nextVersion }} for {{ $r->user->first_name }} {{ $r->user->last_name }}</div>
      </div>
      <div class="admin-panel-body">
        @if($basedOn)
          <p class="co-hint">Pre-filled from version {{ $basedOn->version }}. Saving creates a new draft; the customer keeps seeing the current quote until you send it.</p>
        @endif

        <div class="co-field-grid">
          <div class="co-field co-span-2">
            <label class="form-label" for="final_description">Final description</label>
            <textarea id="final_description" name="final_description" class="form-input" rows="4" maxlength="3000" required>{{ $v['final_description'] ?? '' }}</textarea>
          </div>

          <div class="co-field">
            <label class="form-label" for="width_cm">Width (cm)</label>
            <input type="number" step="0.01" min="0.1" max="9999" id="width_cm" name="width_cm" class="form-input" value="{{ $v['width_cm'] ?? '' }}" required/>
          </div>
          <div class="co-field">
            <label class="form-label" for="depth_cm">Depth (cm)</label>
            <input type="number" step="0.01" min="0.1" max="9999" id="depth_cm" name="depth_cm" class="form-input" value="{{ $v['depth_cm'] ?? '' }}" required/>
          </div>
          <div class="co-field">
            <label class="form-label" for="height_cm">Height (cm)</label>
            <input type="number" step="0.01" min="0.1" max="9999" id="height_cm" name="height_cm" class="form-input" value="{{ $v['height_cm'] ?? '' }}" required/>
          </div>
          <div class="co-field">
            <label class="form-label" for="production_days">Production days</label>
            <input type="number" step="1" min="1" max="365" id="production_days" name="production_days" class="form-input" value="{{ $v['production_days'] ?? '' }}" required/>
          </div>

          <div class="co-field">
            <label class="form-label" for="material">Material</label>
            <input type="text" id="material" name="material" class="form-input" maxlength="100" value="{{ $v['material'] ?? '' }}" required/>
          </div>
          <div class="co-field">
            <label class="form-label" for="finish">Finish <span class="co-opt">(optional)</span></label>
            <input type="text" id="finish" name="finish" class="form-input" maxlength="100" value="{{ $v['finish'] ?? '' }}"/>
          </div>
          <div class="co-field co-span-2">
            <label class="form-label" for="modifications">Modifications <span class="co-opt">(optional)</span></label>
            <textarea id="modifications" name="modifications" class="form-input" rows="2" maxlength="3000">{{ $v['modifications'] ?? '' }}</textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Price and delivery</div></div>
      <div class="admin-panel-body">
        <div class="co-field-grid">
          <div class="co-field">
            <label class="form-label" for="price">Price (₱)</label>
            <input type="number" step="0.01" min="0.01" max="999999" id="price" name="price" class="form-input" value="{{ $v['price'] ?? '' }}" required/>
          </div>
          <div class="co-field">
            <label class="form-label" for="delivery_option">Delivery</label>
            <select id="delivery_option" name="delivery_option" class="form-input">
              <option value="pickup" @selected(!$isDelivery)>Pickup (no fee)</option>
              <option value="delivery" @selected($isDelivery)>Delivery</option>
            </select>
          </div>

          <div class="co-field" id="fee-field" @if(!$isDelivery) hidden @endif>
            <label class="form-label" for="delivery_fee">Delivery fee (₱)</label>
            <input type="number" step="0.01" min="0" max="99999.99" id="delivery_fee" name="delivery_fee" class="form-input"
                   value="{{ $v['delivery_fee'] ?? '' }}" placeholder="{{ \App\Models\CustomQuote::DEFAULT_DELIVERY_FEE }}"/>
            <p class="co-hint">Set per quote. Default {{ number_format(\App\Models\CustomQuote::DEFAULT_DELIVERY_FEE) }} if left blank.</p>
          </div>
          <div class="co-field co-span-2" id="address-field" @if(!$isDelivery) hidden @endif>
            <label class="form-label" for="delivery_address">Delivery address</label>
            <textarea id="delivery_address" name="delivery_address" class="form-input" rows="2" maxlength="500">{{ $v['delivery_address'] ?? '' }}</textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Payment terms</div></div>
      <div class="admin-panel-body">
        <div class="co-radio-row">
          <label><input type="radio" name="payment_terms" value="full" @checked(!$isDeposit)/> Pay in full up front</label>
          <label><input type="radio" name="payment_terms" value="deposit" @checked($isDeposit)/> Deposit, then installments</label>
        </div>

        <div class="co-field-grid" id="deposit-fields" @if(!$isDeposit) hidden @endif>
          <div class="co-field">
            <label class="form-label" for="deposit_amount">Deposit (₱)</label>
            <input type="number" step="0.01" min="0.01" id="deposit_amount" name="deposit_amount" class="form-input" value="{{ $v['deposit_amount'] ?? '' }}"/>
            <p class="co-hint" id="deposit-hint">At least {{ $minPercent }}% of the total including delivery, and less than the total.</p>
          </div>
          <div class="co-field"></div>
          <div class="co-field">
            <label class="form-label" for="installment_duration_months">Balance spread over</label>
            <select id="installment_duration_months" name="installment_duration_months" class="form-input">
              @foreach(\App\Support\InstallmentScheduler::ALLOWED_DURATION_MONTHS as $m)
                <option value="{{ $m }}" @selected((int) ($v['installment_duration_months'] ?? 1) === $m)>{{ $m }} month{{ $m > 1 ? 's' : '' }}</option>
              @endforeach
            </select>
          </div>
          <div class="co-field">
            <label class="form-label" for="installment_frequency_days">Paid every</label>
            <select id="installment_frequency_days" name="installment_frequency_days" class="form-input">
              @foreach(\App\Support\InstallmentScheduler::ALLOWED_FREQUENCY_DAYS as $d)
                <option value="{{ $d }}" @selected((int) ($v['installment_frequency_days'] ?? 30) === $d)>{{ $d }} days</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Internal call notes</div></div>
      <div class="admin-panel-body">
        <textarea name="call_notes" class="form-input" rows="3" maxlength="2000"
                  placeholder="Only visible to the team. Never shown to the customer.">{{ $v['call_notes'] ?? '' }}</textarea>
      </div>
    </div>

    <div class="co-form-actions">
      <a href="{{ route('admin.custom-orders.show', $r->id) }}" class="admin-btn admin-btn-outline">Cancel</a>
      <button type="submit" class="admin-btn admin-btn-gold">{{ $quote ? 'Save draft' : 'Save as draft' }}</button>
    </div>
  </form>

  {{-- Live preview (server-computed through the same scheduler as the real order) --}}
  <div>
    <div class="admin-panel co-sticky">
      <div class="admin-panel-header"><div class="admin-panel-title">Preview</div></div>
      <div class="admin-panel-body" id="quote-preview">
        <dl class="co-money">
          <div><dt>Price</dt><dd id="pv-price">—</dd></div>
          <div><dt>Delivery fee</dt><dd id="pv-fee">—</dd></div>
          <div class="co-money-total"><dt>Total</dt><dd id="pv-total">—</dd></div>
          <div><dt>Due up front</dt><dd id="pv-upfront">—</dd></div>
          <div><dt>Balance</dt><dd id="pv-balance">—</dd></div>
        </dl>
        <div id="pv-schedule-wrap" hidden>
          <div class="co-block-label">Installments</div>
          <ul class="co-log" id="pv-schedule"></ul>
        </div>
        <p class="co-hint" id="pv-problem" style="color:#B04040;" hidden></p>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
  const $ = (id) => document.getElementById(id);
  const form = $('quote-form');
  const money = (n) => '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const previewUrl = @json(route('admin.custom-orders.quotes.preview', $r->id));
  let timer = null;

  function sync() {
    const delivery = $('delivery_option').value === 'delivery';
    const deposit = form.querySelector('input[name="payment_terms"]:checked').value === 'deposit';
    $('fee-field').hidden = !delivery;
    $('address-field').hidden = !delivery;
    $('deposit-fields').hidden = !deposit;
    $('delivery_address').required = delivery;
    $('deposit_amount').required = deposit;
    if (delivery && $('delivery_fee').value === '') $('delivery_fee').value = {{ \App\Models\CustomQuote::DEFAULT_DELIVERY_FEE }};
    if (!delivery) $('delivery_fee').value = '';
    schedule();
  }

  function schedule() { clearTimeout(timer); timer = setTimeout(preview, 200); }

  function preview() {
    const p = new URLSearchParams({
      price: $('price').value,
      delivery_option: $('delivery_option').value,
      delivery_fee: $('delivery_fee').value,
      payment_terms: form.querySelector('input[name="payment_terms"]:checked').value,
      deposit_amount: $('deposit_amount').value,
      installment_duration_months: $('installment_duration_months').value,
      installment_frequency_days: $('installment_frequency_days').value,
    });
    fetch(previewUrl + '?' + p, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
      .then((r) => r.json())
      .then((d) => {
        $('pv-price').textContent = money(d.total - d.delivery_fee);
        $('pv-fee').textContent = money(d.delivery_fee);
        $('pv-total').textContent = money(d.total);
        $('pv-upfront').textContent = money(d.upfront);
        $('pv-balance').textContent = money(d.balance);
        const list = $('pv-schedule');
        list.innerHTML = '';
        d.schedule.forEach((row) => {
          const li = document.createElement('li');
          li.textContent = '#' + row.no + ' · ' + money(row.amount) + ' · day ' + row.day_offset;
          list.appendChild(li);
        });
        $('pv-schedule-wrap').hidden = d.schedule.length === 0;
        $('pv-problem').hidden = !d.deposit_problem;
        $('pv-problem').textContent = d.deposit_problem || '';
      })
      .catch(() => {});
  }

  form.addEventListener('input', schedule);
  form.addEventListener('change', sync);
  sync();
})();
</script>
@endpush
