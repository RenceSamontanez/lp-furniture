@extends('layouts.app')

@section('title', 'GCash Payment — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/payment.css') }}"/>
@endpush

@section('content')
<div class="payment-wrap">
  <div class="payment-container">

    {{-- Back --}}
    <a href="{{ route('orders.show', $order->id) }}" class="payment-back-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Back to My Order
    </a>

    {{-- Header --}}
    <div class="payment-header">
      <p class="gallery-eyebrow" style="margin-bottom:6px;">Secure Payment</p>
      <h1 class="payment-title">GCash Payment</h1>
      <p class="payment-subtitle">You'll be securely redirected to GCash to complete your payment.</p>
    </div>

    {{-- Order reference bar --}}
    <div class="payment-ref-bar">
      <div>
        <div class="payment-ref-label">Order Reference</div>
        <div class="payment-ref-value">{{ $order->order_number }}</div>
      </div>
      <div class="payment-ref-amount">
        <div class="payment-ref-amount-label">{{ $order->payment->payment_type === 'installment' ? ($order->design_agreement_id ? 'Deposit Due' : 'Downpayment Due (50%)') : 'Amount Due' }}</div>
        <div class="payment-ref-amount-value">₱{{ number_format($order->payment->amount, 2) }}</div>
      </div>
    </div>

    @if($order->design_agreement_id)
      @php
        $pct = (float) $order->total_amount > 0 ? round(((float) $order->payment->amount / (float) $order->total_amount) * 100, 1) : 100;
        $pctLabel = rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
      @endphp
      <div class="auth-info-box" style="margin-bottom:20px;">
        @if($order->payment->payment_type === 'installment')
          This order total is ₱{{ number_format($order->total_amount, 2) }}. You're paying the downpayment of ₱{{ number_format($order->payment->amount, 2) }} ({{ $pctLabel }}% of the total) now. The remaining balance follows the payment schedule in your agreement.
        @else
          This is your full payment of ₱{{ number_format($order->payment->amount, 2) }}.
        @endif
      </div>
    @elseif($order->payment->payment_type === 'installment')
      <div class="auth-info-box" style="margin-bottom:20px;">
        This order total is ₱{{ number_format($order->total_amount, 2) }}. You're paying the 50% downpayment now — the remaining balance is split into installments you can review on your order page.
      </div>
    @endif

    {{-- Payment failure notice --}}
    @if($errors->any())
      <div class="payment-fail-card">
        <div class="payment-fail-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </div>
        <div class="payment-fail-text">
          <div class="payment-fail-title">Payment Not Completed</div>
          @foreach($errors->all() as $error)
            <p class="payment-fail-message">{{ $error }}</p>
          @endforeach
          <p class="payment-fail-reassure">No charge was made to your account. You're welcome to try again below.</p>
        </div>
      </div>
    @endif

    @include('payment._switch')

    {{-- GCash card --}}
    <div class="gcash-card">

      <div class="gcash-card-header">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <div class="gcash-card-header-text">
          <h3>Pay with GCash</h3>
          <p>Powered by PayMongo · Fast and secure</p>
        </div>
      </div>

      <div class="gcash-card-body">

        <div class="gcash-steps">
          <div class="gcash-step">
            <div class="gcash-step-num">1</div>
            <div class="gcash-step-text">Click <strong>Pay with GCash</strong> below — you'll be redirected to GCash's official payment page.</div>
          </div>
          <div class="gcash-step">
            <div class="gcash-step-num">2</div>
            <div class="gcash-step-text">Log in to your GCash app or account and authorize the payment of <strong>₱{{ number_format($order->payment->amount, 2) }}</strong>.</div>
          </div>
          <div class="gcash-step">
            <div class="gcash-step-num">3</div>
            <div class="gcash-step-text">You'll be brought back here automatically once payment is confirmed — no receipt upload needed.</div>
          </div>
        </div>

        <div class="auth-info-box" style="margin-bottom:24px;">
          Your GCash credentials are never seen or stored by LP Furniture — everything is handled directly by GCash and PayMongo.
        </div>

        <form method="POST" action="{{ route('payment.gcash.paymongo.checkout', $order->id) }}" id="gcash-payment-form">
          @csrf
          <button type="button" class="btn-submit-gcash" id="pay-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            Pay ₱{{ number_format($order->payment->amount, 2) }} with GCash
          </button>
        </form>

      </div>
    </div>

    <a href="{{ route('orders.show', $order->id) }}" class="stripe-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      View My Order
    </a>

  </div>

  {{-- ═══════════════════════════════════
       REDIRECT TRANSITION — shown the instant the customer
       clicks Pay. The confirm panel is the deliberate point
       where they can still back out (same Back link as the rest
       of the page) before the real request to PayMongo is ever
       made. Only once they click "Continue to GCash" do we swap
       to the processing panel and actually submit the form —
       at that point they've committed, same as clicking Pay
       used to do directly.
  ═══════════════════════════════════ --}}
  <div class="gcash-redirect-overlay" id="gcash-redirect-overlay">

    <div class="gcash-redirect-panel" id="gcash-redirect-confirm">
      <div class="gcash-redirect-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
      </div>
      <h2 class="gcash-redirect-title">You're about to be redirected to GCash</h2>
      <p class="gcash-redirect-text">
        This will open PayMongo's secure payment page to complete your ₱{{ number_format($order->payment->amount, 2) }} GCash payment. Once you finish there, you'll be brought back here automatically.
      </p>
      <div class="gcash-redirect-actions">
        <button type="button" class="btn-submit-gcash" id="gcash-continue-btn">Continue to GCash →</button>
        <a href="{{ route('orders.show', $order->id) }}" class="payment-back-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
          </svg>
          Back to My Order
        </a>
      </div>
    </div>

    <div class="gcash-redirect-panel" id="gcash-redirect-processing" style="display:none;">
      <div class="gcash-redirect-spinner"></div>
      <h2 class="gcash-redirect-title">Redirecting you to GCash…</h2>
      <p class="gcash-redirect-text">
        Processing your payment. Please don't close or refresh this page.
      </p>
    </div>

  </div>
</div>

<script>
  const gcashOverlay = document.getElementById('gcash-redirect-overlay');
  const gcashConfirmPanel = document.getElementById('gcash-redirect-confirm');
  const gcashProcessingPanel = document.getElementById('gcash-redirect-processing');
  const gcashForm = document.getElementById('gcash-payment-form');

  document.getElementById('pay-btn').addEventListener('click', function () {
    gcashOverlay.classList.add('visible');
  });

  document.getElementById('gcash-continue-btn').addEventListener('click', function () {
    this.disabled = true;
    gcashConfirmPanel.style.display = 'none';
    gcashProcessingPanel.style.display = 'flex';
    if (window.LPLoading) { window.LPLoading.progress(true); }
    gcashForm.submit();
  });
</script>
@endsection
