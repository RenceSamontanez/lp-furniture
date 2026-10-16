@extends('layouts.app')

@section('title', 'Order ' . $order->order_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
@endpush

@section('content')
<div class="orders-wrap orders-wrap--wide">

<div style="display:flex;gap:16px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
    <a href="{{ route('home') }}" class="orders-back-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Back to Home
    </a>
    <span style="color:var(--border);">|</span>
    <a href="{{ route('orders.index') }}" class="orders-back-link">
      View All Orders
    </a>
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
                  src="{{ ($order->design_agreement_id && !$item->product_id && $item->product_image) ? route('design.agreement.image', $order->design_agreement_id) : ($item->product->image_url ?? ($item->product_image ? asset('images/products/'.$item->product_image) : asset('images/products/placeholder.jpg'))) }}"
                  alt="{{ $item->product->product_name ?? $item->product_name ?? 'Product' }}"
                  onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
                />
              </div>
              <div class="order-item-info">
                <div class="order-item-name">{{ $item->product->product_name ?? $item->product_name ?? 'Product Unavailable' }}</div>
                @php $itemType = $order->design_agreement_id && $order->agreement ? ($order->agreement->terms_snapshot['design']['furniture_type'] ?? null) : null; @endphp
                @if($itemType)<div class="order-item-qty">Furniture type: {{ $itemType }}</div>@endif
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

            @if(in_array($order->payment->payment_method, ['stripe', 'gcash']) && $order->payment->payment_status !== 'paid' && $order->order_status !== 'cancelled')
              <div style="margin-top:14px;padding:14px;background:#FFF3CD;border:1px solid #FFE29A;border-radius:10px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#856404" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <strong style="font-size:13px;color:#856404;">Payment Not Completed</strong>
                </div>
                <p style="font-size:12.5px;color:#856404;margin-bottom:12px;line-height:1.5;">
                  @if($order->design_agreement_id)
                    @php $reservedUntil = $order->designRequest?->payment_due_at; @endphp
                    Your order is saved, but payment wasn't finished.
                    @if($reservedUntil && $reservedUntil->isFuture())
                      Your agreement is reserved until {{ $reservedUntil->timezone('Asia/Manila')->format('M d, Y') }}. Complete payment before then to confirm your order.
                    @else
                      The payment window for this agreement has ended. Please contact us.
                    @endif
                  @else
                  Your order is saved, but payment wasn't finished. Your items are being held for a limited time — complete payment now to confirm your order.
                  @endif
                </p>
                <a href="{{ route($order->payment->payment_method === 'stripe' ? 'payment.stripe' : 'payment.gcash.paymongo', $order->id) }}" class="btn btn-gold" style="display:block;text-align:center;padding:11px;" data-loading="Opening payment..." data-loading-overlay="payment">
                  Finish Payment →
                </a>
              </div>
            @endif

            {{-- Change Payment Method — only while the order hasn't
                 progressed past pending and payment hasn't succeeded.
                 Checking order_status (not just payment_status) matters
                 specifically for onsite/cash orders, whose payment_status
                 never programmatically becomes 'paid' through any
                 existing flow — so payment_status alone can't be
                 trusted to tell us the order is still "open". --}}
            @if($order->order_status === 'pending' && $order->payment->payment_status !== 'paid' && (float) $order->amount_paid <= 0)
              <button type="button" class="order-change-method-toggle" style="margin-top:14px;" onclick="document.getElementById('change-method-panel').classList.toggle('open')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                Change Payment Method
              </button>

              <div class="order-change-method-panel" id="change-method-panel">
                <form method="POST" action="{{ route('orders.paymentMethod.update', $order->id) }}">
                  @csrf

                  <label class="payment-method-card {{ $order->payment->payment_method === 'stripe' ? 'selected' : '' }}" data-method-card="stripe">
                    <input type="radio" name="payment_method" value="stripe" {{ $order->payment->payment_method === 'stripe' ? 'checked' : '' }}/>
                    <div class="payment-method-icon stripe">
                      <svg width="18" height="13" viewBox="0 0 60 25" fill="white"><path d="M28.3 8.7c0-2.7 2.3-3.7 4.8-3.7 2.7 0 5.3 1 7.3 2.7l3.3-5.3C41.3.8 37.7 0 33.3 0c-6.7 0-12 3.3-12 9.7 0 11 14.7 8.3 14.7 13 0 2.3-2 3.7-5 3.7-3 0-6.3-1.3-8.7-3.3l-3.7 5.3c3 2.3 7.3 3.7 12 3.7 7 0 12.3-3.3 12.3-10 0-11.3-14.6-8.7-14.6-13.4z"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Credit / Debit Card (Stripe)</div>
                      <div class="payment-method-desc">Visa, Mastercard, and more.</div>
                    </div>
                  </label>

                  <label class="payment-method-card {{ $order->payment->payment_method === 'gcash' ? 'selected' : '' }}" data-method-card="gcash">
                    <input type="radio" name="payment_method" value="gcash" {{ $order->payment->payment_method === 'gcash' ? 'checked' : '' }}/>
                    <div class="payment-method-icon gcash">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">GCash</div>
                      <div class="payment-method-desc">Pay via GCash, powered by PayMongo.</div>
                    </div>
                  </label>

                  @unless($order->design_agreement_id)
                  <label class="payment-method-card {{ $order->payment->payment_method === 'onsite' ? 'selected' : '' }}" data-method-card="onsite">
                    <input type="radio" name="payment_method" value="onsite" {{ $order->payment->payment_method === 'onsite' ? 'checked' : '' }}/>
                    <div class="payment-method-icon onsite">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 12h.01"/><path d="M17 12h.01"/><path d="M7 12h.01"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Cash on Pickup / Delivery</div>
                      <div class="payment-method-desc">Pay in cash when you receive your order.</div>
                    </div>
                  </label>
                  @endunless

                  <button type="submit" class="btn btn-gold" style="width:100%;padding:11px;margin-top:4px;">
                    Confirm Switch
                  </button>
                </form>
              </div>

              <script>
                document.querySelectorAll('#change-method-panel input[name="payment_method"]').forEach(function (input) {
                  input.addEventListener('change', function () {
                    document.querySelectorAll('#change-method-panel .payment-method-card').forEach(function (card) {
                      card.classList.remove('selected');
                    });
                    this.closest('.payment-method-card').classList.add('selected');
                  });
                });
              </script>
            @endif
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