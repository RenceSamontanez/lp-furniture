@extends('layouts.app')

@section('title', 'Pay for your custom order — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
@php
  $m = $terms['money'];
  $peso = fn ($n) => '₱' . number_format((float) $n, 2);
@endphp
<div class="cd-wrap cd-wrap--wide">
  <div class="cd-top-bar">
    <a href="{{ route('design.show', $designRequest->id) }}" class="cd-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Back to your design
    </a>
  </div>


  <div class="co-layout {{ $imageUrl ? 'co-layout--media' : '' }}">
  <div class="co-main">
  <div class="co-card co-review">
    <div class="co-head">
      <h1 class="co-title">Pay for your custom order</h1>
      <p class="co-lead">
        Agreement {{ $agreement->agreement_number }}, agreed on
        {{ $agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
        These terms are fixed and cannot be changed here.
      </p>
    </div>

    @if($errors->any())
      <div class="co-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @include('agreements._terms', ['terms' => $terms, 'paymentDue' => $terms['payment_due_at'] ?? null])

    <div class="co-quote-card" style="margin-top:20px;">
      <div class="co-status-title">Amount due now: {{ $peso($m['upfront']) }}</div>
      <div class="co-status-text">
        @if((float) $m['balance'] > 0)
          Remaining balance of {{ $peso($m['balance']) }} follows the payment schedule above.
        @else
          This pays your order in full.
        @endif
        Please start your payment by {{ $due->copy()->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
      </div>
    </div>

    <form method="POST" action="{{ route('custom.checkout.place', $agreement->id) }}" class="co-decline-form" style="border-top:0;margin-top:20px;padding-top:0;">
      @csrf
      <div class="co-action-title">How would you like to pay?</div>
      <label class="co-agree"><input type="radio" name="payment_method" value="stripe" required {{ old('payment_method', 'stripe') === 'stripe' ? 'checked' : '' }}/> <span>Card (Stripe)</span></label>
      <label class="co-agree"><input type="radio" name="payment_method" value="gcash" required {{ old('payment_method') === 'gcash' ? 'checked' : '' }}/> <span>GCash</span></label>
      <button type="submit" class="ai-action-btn ai-action-primary" id="co-pay-btn">Continue to payment</button>
    </form>
  </div>
  </div>
  @if($imageUrl)
    <aside class="co-media">
      <figure class="co-media-card">
        <img src="{{ $imageUrl }}" alt="Reference design for this agreement" onerror="this.closest('.co-media').style.display='none';"/>
        <figcaption>Reference design</figcaption>
      </figure>
    </aside>
  @endif
  </div>
</div>
<script>
  (function () {
    var f = document.querySelector('form[action="{{ route('custom.checkout.place', $agreement->id) }}"]');
    if (!f) return;
    f.addEventListener('submit', function () {
      var b = document.getElementById('co-pay-btn');
      if (b) { b.disabled = true; }
    });
  })();
</script>
@endsection
