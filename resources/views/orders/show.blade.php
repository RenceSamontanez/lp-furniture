@extends('layouts.app')

@section('title', 'Order ' . $order->order_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/orders.css') }}"/>
@endpush

@section('content')
<div class="orders-wrap">

  <a href="{{ route('orders.index') }}" class="orders-back-link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    Back to My Orders
  </a>

  <div class="orders-header">
    <p class="gallery-eyebrow" style="margin-bottom:6px;">Order Details</p>
    <h1 class="orders-title">{{ $order->order_number }}</h1>
    <p class="orders-subtitle">Placed on {{ $order->created_at->format('M d, Y · h:i A') }}</p>
  </div>

  @if(session('success'))
    <div class="auth-success" style="margin-bottom:20px;">{{ session('success') }}</div>
  @endif

  {{-- Status Timeline --}}
  @php
    // Build the step sequence based on delivery option
    $steps = ['pending' => 'Placed', 'confirmed' => 'Confirmed', 'in_production' => 'In Production'];
    if ($order->delivery_option === 'delivery') {
        $steps['out_for_delivery'] = 'Out for Delivery';
    } else {
        $steps['ready'] = 'Ready for Pickup';
    }
    $steps['completed'] = 'Completed';

    $stepKeys = array_keys($steps);
    $currentIndex = array_search($order->order_status, $stepKeys);
    $isCancelled = $order->order_status === 'cancelled';
  @endphp

  @if($isCancelled)
    <div class="order-panel" style="margin-bottom:18px;">
      <div class="order-panel-body" style="display:flex;align-items:center;gap:14px;">
        <div style="width:44px;height:44px;border-radius:50%;background:#FBE0E0;color:var(--danger);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
          </svg>
        </div>
        <div>
          <div style="font-family:var(--font-display);font-size:16px;color:var(--brown-deep);margin-bottom:2px;">Order Cancelled</div>
          <div style="font-size:13px;color:var(--text-muted);">This order was cancelled and will not be processed further.</div>
        </div>
      </div>
    </div>
  @else
    <div class="order-panel" style="margin-bottom:18px;">
      <div class="order-panel-body">
        <div class="order-timeline">
          @foreach($steps as $key => $label)
            @php
              $stepIndex = array_search($key, $stepKeys);
              $stateClass = $stepIndex < $currentIndex ? 'done' : ($stepIndex === $currentIndex ? 'active' : '');
            @endphp
            <div class="order-timeline-step {{ $stateClass }}">
              <div class="order-timeline-dot">
                @if($stateClass === 'done')
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                    <path d="M20 6L9 17l-5-5"/>
                  </svg>
                @else
                  {{ $stepIndex + 1 }}
                @endif
                <div class="order-timeline-line"></div>
              </div>
              <div class="order-timeline-label">{{ $label }}</div>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  @endif

  <div class="order-detail-layout">

    {{-- LEFT: Items + Notes --}}
    <div>
      <div class="order-panel">
        <div class="order-panel-header">
          <div class="order-panel-title">Order Items</div>
          <span class="order-status-pill {{ $order->order_status }}">
            {{ str_replace('_', ' ', $order->order_status) }}
          </span>
        </div>
        <div class="order-panel-body">
          @foreach($order->items as $item)
            <div class="order-item-row">
            <div class="order-item-img">
                <img
                  src="{{ $item->product->image_url ?? ($item->product_image ? asset('images/products/'.$item->product_image) : asset('images/products/placeholder.jpg')) }}"
                  alt="{{ $item->product->product_name ?? $item->product_name ?? 'Product' }}"
                  onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
                />
              </div>
              <div class="order-item-info">
                <div class="order-item-name">{{ $item->product->product_name ?? $item->product_name ?? 'Product Unavailable' }}</div>
                <div class="order-item-qty">Qty: {{ $item->quantity }} × ₱{{ number_format($item->unit_price, 2) }}</div>
              </div>
              <div class="order-item-subtotal">₱{{ number_format($item->subtotal, 2) }}</div>
            </div>
          @endforeach
        </div>
      </div>

      @if($order->customization_note)
        <div class="order-panel">
          <div class="order-panel-header"><div class="order-panel-title">Your Instructions</div></div>
          <div class="order-panel-body" style="font-size:13.5px;color:var(--text-muted);line-height:1.6;">
            {{ $order->customization_note }}
          </div>
        </div>
      @endif
    </div>

    {{-- RIGHT: Summary --}}
    <div>
      @if($order->payment)
        <div class="order-panel">
          <div class="order-panel-header"><div class="order-panel-title">Payment</div></div>
          <div class="order-panel-body">
            <div class="order-info-row">
              <span class="order-info-label">Method</span>
              <span class="order-info-value" style="text-transform:capitalize;">{{ $order->payment->payment_method }}</span>
            </div>
            <div class="order-info-row">
              <span class="order-info-label">Type</span>
              <span class="order-info-value" style="text-transform:capitalize;">{{ $order->payment->payment_type }}</span>
            </div>
            <div class="order-info-row">
              <span class="order-info-label">Status</span>
              <span class="order-status-pill {{ $order->payment->payment_status }}" style="font-size:10px;">
                {{ $order->payment->payment_status }}
              </span>
            </div>
          </div>
        </div>
      @endif

      <div class="order-panel">
        <div class="order-panel-header"><div class="order-panel-title">Delivery</div></div>
        <div class="order-panel-body">
          <div class="order-info-row">
            <span class="order-info-label">Method</span>
            <span class="order-info-value" style="text-transform:capitalize;">{{ $order->delivery_option }}</span>
          </div>
          @if($order->delivery_option === 'delivery' && $order->delivery_address)
            <div class="order-info-row">
              <span class="order-info-label">Address</span>
              <span class="order-info-value">{{ $order->delivery_address }}</span>
            </div>
          @endif
          <div class="order-info-row">
            <span class="order-info-label">Delivery Fee</span>
            <span class="order-info-value">₱{{ number_format($order->delivery_fee, 2) }}</span>
          </div>
        </div>
      </div>

      <div class="order-panel">
        <div class="order-panel-header"><div class="order-panel-title">Order Summary</div></div>
        <div class="order-panel-body">
          @php $subtotal = $order->items->sum('subtotal'); @endphp
          <div class="order-info-row">
            <span class="order-info-label">Subtotal</span>
            <span class="order-info-value">₱{{ number_format($subtotal, 2) }}</span>
          </div>
          <div class="order-info-row">
            <span class="order-info-label">Delivery Fee</span>
            <span class="order-info-value">₱{{ number_format($order->delivery_fee, 2) }}</span>
          </div>
          <div class="order-total-row">
            <span>Total</span>
            <span>₱{{ number_format($order->total_amount, 2) }}</span>
          </div>
        </div>
      </div>

      <a href="{{ route('catalog') }}" class="btn btn-outline" style="display:block;text-align:center;padding:12px;">
        Continue Shopping
      </a>
    </div>

  </div>

</div>
@endsection