@extends('layouts.app')

@section('title', $title . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/installments.css') }}?v={{ @filemtime(public_path('assets/css/installments.css')) }}"/>
@endpush

@section('content')
<div class="inst-page">
  <p><x-back-link :href="$backUrl" label="Order" /></p>
  <h1>{{ $title }}</h1>
  <p class="lead">Order {{ $order->order_number }}. You pay <b>₱{{ number_format($amount, 2) }}</b> now ({{ $purpose }}).</p>

  @if($errors->any())<div class="co-error" role="alert">{{ $errors->first() }}</div>@endif

  <form class="inst-form" id="card-form" autocomplete="off" novalidate>
    <label for="cc-number">Card Number</label>
    <input id="cc-number" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="4120 0000 0000 0007" required>
    <div class="two">
      <div><label for="cc-exp">Expiry (MM/YY)</label><input id="cc-exp" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="12/30" required></div>
      <div><label for="cc-cvc">CVC</label><input id="cc-cvc" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123" required></div>
    </div>
    <p class="inst-note">Your card details go straight to PayMongo's secure service. We never see or store your card number.</p>
    <p class="inst-err" id="card-err" role="alert"></p>
    <button type="submit" class="lp-btn lp-btn--primary lp-btn--block" id="card-go" style="margin-top:16px;">Pay ₱{{ number_format($amount, 2) }}</button>
  </form>
</div>
@endsection

@push('scripts')
<script>
(function () {
  var form = document.getElementById('card-form'), err = document.getElementById('card-err'), go = document.getElementById('card-go');
  var PK = @json($publicKey), POST = @json($postUrl);
  function fail(m) { err.textContent = m; err.style.display = 'block'; go.disabled = false; go.textContent = 'Pay ₱{{ number_format($amount, 2) }}'; }

  form.addEventListener('submit', function (e) {
    e.preventDefault(); err.style.display = 'none';
    var num = document.getElementById('cc-number').value.replace(/\s+/g, ''), exp = document.getElementById('cc-exp').value.split('/'),
        cvc = document.getElementById('cc-cvc').value.trim();
    if (!/^\d{13,19}$/.test(num) || exp.length !== 2 || !/^\d{3,4}$/.test(cvc)) { return fail('Please check your card number, expiry and CVC.'); }
    if (!PK) { return fail('Card payments are not set up yet. Please try GCash or contact us.'); }
    go.disabled = true; go.textContent = 'Processing…';

    // 1. The card goes from this browser to PayMongo (public key). Only the resulting payment-method id comes back to us.
    fetch('https://api.paymongo.com/v1/payment_methods', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Basic ' + btoa(PK + ':') },
      body: JSON.stringify({ data: { attributes: { type: 'card', details: { card_number: num, exp_month: parseInt(exp[0], 10), exp_year: 2000 + parseInt(exp[1], 10), cvc: cvc } } } })
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.data || !j.data.id) { throw new Error((j.errors && j.errors[0] && j.errors[0].detail) || 'Your card was declined.'); }
      // 2. Our server starts the payment with that id.
      return fetch(POST, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: JSON.stringify({ payment_method_id: j.data.id })
      });
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (x) {
      if (!x.ok) { throw new Error(x.j.error || 'The payment could not be started.'); }
      window.location = x.j.redirect;
    }).catch(function (e) { fail(e.message || 'The payment could not be started.'); });
  });
})();
</script>
@endpush
