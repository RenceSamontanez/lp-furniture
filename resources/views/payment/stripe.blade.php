@extends('layouts.app')

@section('title', 'Card Payment — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/payment.css') }}"/>
@endpush

@section('content')
<div class="payment-wrap">
  <div class="payment-container">

    {{-- Header --}}
    <div class="payment-header">
      <p class="gallery-eyebrow" style="margin-bottom:6px;">Secure Payment</p>
      <h1 class="payment-title">Card Payment</h1>
      <p class="payment-subtitle">Your payment is encrypted and processed securely by Stripe.</p>
    </div>

    {{-- Order reference bar --}}
    <div class="payment-ref-bar">
      <div>
        <div class="payment-ref-label">Order Reference</div>
        <div class="payment-ref-value">{{ $order->order_number }}</div>
      </div>
      <div class="payment-ref-amount">
        <div class="payment-ref-amount-label">Amount Due</div>
        <div class="payment-ref-amount-value">₱{{ number_format($order->total_amount, 2) }}</div>
      </div>
    </div>

    {{-- Errors --}}
    @if($errors->any())
      <div class="auth-errors" style="margin-bottom:20px;">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">⚠ {{ $error }}</div>
        @endforeach
      </div>
    @endif

    {{-- Stripe card --}}
    <div class="stripe-card">

      <div class="stripe-card-header">
        <svg width="28" height="28" viewBox="0 0 60 25" fill="white">
          <path d="M28.3 8.7c0-2.7 2.3-3.7 4.8-3.7 2.7 0 5.3 1 7.3 2.7l3.3-5.3C41.3.8 37.7 0 33.3 0c-6.7 0-12 3.3-12 9.7 0 11 14.7 8.3 14.7 13 0 2.3-2 3.7-5 3.7-3 0-6.3-1.3-8.7-3.3l-3.7 5.3c3 2.3 7.3 3.7 12 3.7 7 0 12.3-3.3 12.3-10 0-11.3-14.6-8.7-14.6-13.4z"/>
        </svg>
        <div class="stripe-card-header-text">
          <h3>Pay with Stripe</h3>
          <p>Visa · Mastercard · and more</p>
        </div>
        <span class="stripe-badge">🔒 Secure</span>
      </div>

      <div class="stripe-card-body">

        {{-- Accepted cards --}}
        <div class="stripe-card-icons">
          <div class="card-icon visa">VISA</div>
          <div class="card-icon master">MC</div>
          <div class="card-icon amex">AMEX</div>
          <div class="card-icon">JCB</div>
        </div>

        <div class="auth-info-box" style="margin-bottom:24px;">
          Clicking below will take you to Stripe's secure payment page. Your card details are never seen or stored by LP Furniture — everything is handled directly by Stripe.
        </div>

        <form method="POST" action="{{ route('payment.stripe.checkout', $order->id) }}">
          @csrf
          <button type="submit" class="btn-pay-stripe" id="pay-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/>
              <path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            Pay ₱{{ number_format($order->total_amount, 2) }} Securely
          </button>
        </form>

        <div class="stripe-security">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          256-bit SSL encryption · PCI-DSS compliant · Powered by Stripe
        </div>

      </div>
    </div>

    <a href="{{ route('orders.show', $order->id) }}" class="stripe-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      View My Order
    </a>

  </div>
</div>
@endsection