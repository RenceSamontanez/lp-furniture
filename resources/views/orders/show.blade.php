@extends('layouts.app')

@section('title', 'Order ' . $order->order_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="orders-wrap orders-wrap--wide">

<div style="display:flex;gap:16px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
    <x-back-link :href="route('home')" label="Home" />
    <x-back-link :href="route('orders.index')" label="My Orders" />
  </div>

  <div class="orders-header">
    <p class="gallery-eyebrow" style="margin-bottom:6px;">Order Details</p>
    <h1 class="orders-title">{{ $order->order_number }}</h1>
    <p class="orders-subtitle">Placed on {{ $order->created_at->format('M d, Y · h:i A') }}</p>
  </div>

  @if($errors->any())
    <div class="co-error" role="alert" style="margin-bottom:20px;">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
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

  @php
    $designCopy = $order->design_agreement_id && $order->agreement
        ? $order->items->first(fn ($i) => !$i->product_id && $i->product_image)
        : null;
  @endphp
  <div class="order-detail-layout">

    {{-- LEFT: Items + Notes --}}
    <div>
      @if($designCopy)
        {{-- Phones: the agreed design first, short. Wide screens show it in the right column. --}}
        <figure class="co-media-card co-media-card--order co-media-card--phone">
          <img src="{{ route('design.agreement.image', $order->design_agreement_id) }}" alt="Your agreed design"
               onerror="this.closest('.co-media-card').style.display='none';"/>
          <figcaption>Your agreed design</figcaption>
        </figure>
      @endif
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
                  src="{{ ($order->design_agreement_id && !$item->product_id && $item->product_image) ? route('design.agreement.image', $order->design_agreement_id) : $item->image_url }}"
                  alt="{{ $item->product->product_name ?? $item->product_name ?? 'Product' }}"
                  onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
                />
              </div>
              <div class="order-item-info">
                <div class="order-item-name">{{ $item->product->product_name ?? $item->product_name ?? 'Product Unavailable' }}</div>
                @php $itemType = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['design']['furniture_type'] ?? null) : null; @endphp
                @if($itemType)<div class="order-item-qty">Furniture type: {{ $itemType }}</div>@endif
                @php $itemWood = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['design']['wood']['label'] ?? null) : null; @endphp
                @if($itemWood)<div class="order-item-qty">Wood: {{ $itemWood }}</div>@endif
                @php $itemQ = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['quote'] ?? null) : null; @endphp
                @if($itemQ && isset($itemQ['width_cm']))<div class="order-item-qty">Size (length x width x height): {{ \App\Support\Dimensions::triplet($itemQ['width_cm'], $itemQ['depth_cm'], $itemQ['height_cm'], $itemQ['dimension_unit'] ?? null) }}</div>@endif
                <div class="order-item-qty">Qty: {{ $item->quantity }} × ₱{{ number_format($item->unit_price, 2) }}</div>
              </div>
              <div class="order-item-subtotal">₱{{ number_format($item->subtotal, 2) }}</div>
            </div>
          @endforeach
        </div>
      </div>

      @if($order->design_agreement_id && $order->agreement)
        <div class="order-panel">
          <div class="order-panel-header"><div class="order-panel-title">Custom order agreement</div></div>
          <div class="order-panel-body" style="font-size:13.5px;">
            Agreement {{ $order->agreement->agreement_number }}, agreed on
            {{ $order->agreement->accepted_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}.
            <a href="{{ route('design.agreement', $order->agreement->id) }}">View agreement</a>
            @if($order->expected_ready_date)
              <div style="margin-top:6px;color:var(--text-muted);">Expected ready date: about {{ \Illuminate\Support\Carbon::parse($order->expected_ready_date)->format('M d, Y') }}</div>
            @endif
          </div>
        </div>
      @endif

      @if(!empty($timeline))
        <div class="order-panel">
          <div class="order-panel-header"><div class="order-panel-title">What has happened so far</div></div>
          <div class="order-panel-body">@include('agreements._timeline', ['entries' => $timeline])</div>
        </div>
      @endif

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
      @if($designCopy)
        <figure class="co-media-card co-media-card--order co-media-card--wide">
          <img src="{{ route('design.agreement.image', $order->design_agreement_id) }}" alt="Your agreed design"
               onerror="this.closest('.co-media-card').style.display='none';"/>
          <figcaption>Your agreed design</figcaption>
        </figure>
      @endif
      @include('orders.partials.side-panels')

      <a href="{{ route('catalog') }}" class="btn btn-outline" style="display:block;text-align:center;padding:12px;">
        Continue Shopping
      </a>
    </div>

  </div>

</div>
@endsection