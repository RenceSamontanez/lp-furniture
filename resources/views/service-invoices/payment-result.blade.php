@extends('layouts.app')

@section('title', 'Your payment — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/services.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/service-invoice-pay.css') }}"/>
  <meta name="robots" content="noindex"/>
@endpush

@section('content')
@php
  $copy = [
    'paid' => ['Payment received', 'Thank you. We received your payment for invoice ' . $invoice->invoice_number . '. You can see it on your invoice.'],
    'confirming' => ['We are confirming your payment', 'Your payment was sent. It can take a minute for the bank or GCash to confirm it. Please check your invoice in a moment. You will get a notification as soon as it is confirmed. You do not need to pay again.'],
    'cancelled' => ['Payment cancelled', 'You cancelled the payment, so nothing was charged. Your invoice is still open and you can pay it whenever you are ready.'],
    'failed' => ['Payment could not be completed', 'The payment did not go through and you were not charged. You can try again, use the other payment method, or contact us.'],
  ][$state];
@endphp
<div class="si-wrap">
  <div class="si-layout">
    <div class="si-card sx-main">
      <span class="sx-badge sx-badge--{{ $state }}" role="status">{{ $copy[0] }}</span>
      <h1 class="sx-title">{{ $copy[0] }}</h1>
      <p class="sx-lead">{{ $copy[1] }}</p>
      <div class="ip-links">
        <a href="{{ route('services.invoice', $invoice->id) }}" class="lp-btn lp-btn--primary">Back to my invoice</a>
        <a href="{{ route('services.request') }}" class="lp-btn lp-btn--secondary">My service requests</a>
      </div>
    </div>
    <div class="si-side">
      @include('services._contact-card')
    </div>
  </div>
</div>
@endsection
