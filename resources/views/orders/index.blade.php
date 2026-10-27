@extends('layouts.app')

@section('title', 'My Orders — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/orders.css') }}"/>
@endpush

@section('content')
<div class="orders-wrap">

<a href="{{ route('home') }}" class="orders-back-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    Back to Home
  </a>

  <div class="orders-header">
    <p class="gallery-eyebrow" style="margin-bottom:6px;">Track Your Purchases</p>
    <h1 class="orders-title">My Orders</h1>
    <p class="orders-subtitle">View your order history and track the status of current orders.</p>
  </div>


  @if($orders->isEmpty())

    <div class="orders-empty">
      <div class="orders-empty-icon">
        <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
          <polyline points="14,2 14,8 20,8"/>
        </svg>
      </div>
      <h3>No orders yet</h3>
      <p>Once you place an order, you'll be able to track its progress here.</p>
      <a href="{{ route('catalog') }}" class="btn btn-gold">Browse Catalog →</a>
    </div>

  @else

    <div>
      @foreach($orders as $order)
        <a href="{{ route('orders.show', $order->id) }}" class="order-card">

          <div class="order-card-top">
            <div>
              <div class="order-card-ref">{{ $order->order_number }}</div>
              <div class="order-card-date">{{ $order->created_at->format('M d, Y · h:i A') }}</div>
            </div>
            @if($order->payment && $order->payment->payment_method === 'stripe' && $order->payment->payment_status === 'pending' && $order->order_status !== 'cancelled')
              <span class="order-status-pill" style="background:#FFF3CD;color:#856404;">
                Payment Pending
              </span>
            @else
              <span class="order-status-pill {{ $order->order_status }}">
                {{ str_replace('_', ' ', $order->order_status) }}
              </span>
            @endif
          </div>

          <div class="order-card-items">
          @foreach($order->items->take(4) as $item)
              <div class="order-card-thumb">
                <img
                  src="{{ $item->image_url }}"
                  alt="{{ $item->product->product_name ?? $item->product_name ?? 'Product' }}"
                  onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
                />
              </div>
            @endforeach

            @if($order->items->count() > 4)
              <div class="order-card-thumb-more">+{{ $order->items->count() - 4 }}</div>
            @endif

            <span class="order-card-items-label">
              <strong>{{ $order->items->count() }}</strong> {{ Str::plural('item', $order->items->count()) }}
            </span>
          </div>

          <div class="order-card-footer">
            <span class="order-card-total">₱{{ number_format($order->total_amount, 2) }}</span>
            <span class="order-card-view">
              View Details
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 18l6-6-6-6"/>
              </svg>
            </span>
          </div>

        </a>
      @endforeach
    </div>

    <div style="margin-top:20px;">
      {{ $orders->links() }}
    </div>

  @endif

</div>
@endsection