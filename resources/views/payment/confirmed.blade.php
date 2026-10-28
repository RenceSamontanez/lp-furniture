@extends('layouts.app')

@section('title', 'Order Confirmed — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/payment.css') }}"/>
@endpush

@section('content')
<div class="confirmed-wrap">
  <div class="confirmed-card">

    {{-- Success icon --}}
    <div class="confirmed-icon">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M20 6L9 17l-5-5"/>
      </svg>
    </div>

    <h1 class="confirmed-title">Payment Successful!</h1>

    <p class="confirmed-sub">
      Thank you, <strong>{{ Auth::user()->first_name }}</strong>. Your payment has been received and your order is now confirmed. You'll be notified at every step of the process.
    </p>

    {{-- Order reference --}}
    <div class="confirmed-ref-box">
      <div class="confirmed-ref-label">Your Order Reference</div>
      <div class="confirmed-ref-num">{{ $order->order_number }}</div>
    </div>

    {{-- What happens next --}}
    <div class="confirmed-what-next">
      <div class="confirmed-what-next-title">What Happens Next</div>

      <div class="confirmed-next-step">
        <div class="confirmed-next-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>
          </svg>
        </div>
        Our team confirms your order details and begins preparing your materials.
      </div>

      <div class="confirmed-next-step">
        <div class="confirmed-next-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
          </svg>
        </div>
        Our craftsmen begin building your furniture piece.
      </div>

      <div class="confirmed-next-step">
        <div class="confirmed-next-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8h1a4 4 0 010 8h-1M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8zM6 1v3M10 1v3M14 1v3"/>
          </svg>
        </div>
        You get notified at every stage — production, ready, and delivery.
      </div>

      <div class="confirmed-next-step">
        <div class="confirmed-next-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="1" y="3" width="15" height="13" rx="1"/>
            <path d="M16 8h4l3 5v4h-7V8z"/>
            <circle cx="5.5" cy="18.5" r="2.5"/>
            <circle cx="18.5" cy="18.5" r="2.5"/>
          </svg>
        </div>
        Pick up at our shop in San Juan, Batangas or receive door-to-door delivery.
      </div>
    </div>

    {{-- Actions --}}
    <div class="confirmed-actions">
      <a href="{{ route('orders.show', $order->id) }}" class="btn btn-gold">
        Track My Order
      </a>
      <a href="{{ route('catalog') }}" class="btn btn-outline">
        Continue Shopping
      </a>
    </div>

  </div>
</div>
@endsection