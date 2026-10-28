@extends('layouts.app')

@section('title', 'Track order ' . $order->order_number . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/order-tracking.css') }}"/>
@endpush

@php
  use App\Support\OrderStages;

  $pickup    = OrderStages::isPickup($order);
  $stages    = OrderStages::for($order);
  $status    = $order->order_status;
  $cancelled = $status === 'cancelled';
  $idx       = array_search($status, $stages, true);
  $payBadge  = ['paid' => 'b-ok', 'partial' => 'b-warn', 'awaiting' => 'b-warn'][$pay['state']];
  $place     = $order->delivery_snapshot['city'] ?? null;
  $province  = $order->delivery_snapshot['province'] ?? null;
  // The newest message or photo our team sent (not the system lines).
  $latest    = $events->first(fn ($e) => in_array($e->type, ['stage', 'update'], true) && ($e->note || $e->photo_path));
@endphp

@section('content')
<div class="orders-wrap orders-wrap--wide" style="padding-top:0;">
<div class="ot">
<div class="ot-wrap">

  @if($errors->any())
    <div class="cancelled-box" role="alert">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
  @endif

  <div class="top">
    <x-back-link :href="route('orders.index')" label="My Orders" />
  </div>

  <section class="glass card" aria-labelledby="ot-h1">
    <div class="head">
      <div>
        <h1 id="ot-h1">Order {{ $order->order_number }}</h1>
        <div class="meta">Placed {{ $order->created_at->timezone('Asia/Manila')->format('M j, Y') }} &middot;
          @if($pickup)Pickup at our shop in {{ OrderStages::shopPlace() }}@else Delivery{{ $place ? ' to ' . $place . ($province ? ', ' . $province : '') : '' }}@endif
        </div>
      </div>
      <div class="badges">@unless($cancelled && $pay['paid'] <= 0)<span class="badge {{ $payBadge }}">{{ $pay['label'] }}</span>@endunless</div>
    </div>
    <div style="margin-top:22px;">
      @if($cancelled)
        <div class="cancelled-box">This order was cancelled.</div>
      @else
        <ol class="steps" aria-label="Order progress">
          @foreach($stages as $i => $key)
            <li class="{{ $i < $idx ? 'done' : ($i === $idx ? 'current' : '') }}" @if($i === $idx) aria-current="step" @endif>
              <span class="dot">{{ $i < $idx ? '✓' : $i + 1 }}</span>
              <span>{{ OrderStages::label($key, $pickup) }}</span>
            </li>
          @endforeach
        </ol>
      @endif
    </div>
  </section>

  <div class="cols">
    <div>
      <section class="glass card now" aria-labelledby="ot-now-t">
        <p class="sub" style="margin:0;">Where your furniture is right now</p>
        <h2 id="ot-now-t">{{ $cancelled ? 'Cancelled' : OrderStages::labelFor($order) }}</h2>
        <p>{{ OrderStages::customerCopy($order, $status) }}</p>
        @if($latest)
          <div class="preview" style="margin-top:10px;">
            <small>Latest message from our team &middot; {{ $latest->created_at->timezone('Asia/Manila')->format('M j, g:i A') }}</small>
            @if($latest->note)<span style="white-space:pre-line;overflow-wrap:anywhere;">{{ $latest->note }}</span>@endif
            @if($latest->photo_path)<br><img class="latest-img" src="{{ $latest->photoUrl() }}" alt="Progress photo from our team">@endif
          </div>
        @endif
        <div class="assure">
          <div><b>{{ $cancelled && $pay['paid'] <= 0 ? 'Nothing was charged' : 'Your payment is safe' }}</b><br>{{ $cancelled && $pay['paid'] <= 0 ? 'No payment was taken for this order.' : ($pay['state'] === 'paid' ? 'Paid in full: ₱' . number_format($pay['paid'], 2) : 'Paid ₱' . number_format($pay['paid'], 2) . ' of ₱' . number_format($pay['total'], 2)) }}</div>
          <div><b>You will be told every step</b><br>Notifications and email, each time we update your order.</div>
          <div><b>Questions?</b><br>Message us from the Assistant or the Contact page.</div>
        </div>
      </section>

      <section class="glass card" aria-labelledby="ot-feed-t">
        <h2 id="ot-feed-t">Updates</h2>
        <p class="sub">Newest first</p>
        <ul class="hist">
          @forelse($events as $e)
            <li>
              <span class="pin {{ in_array($e->type, ['placed', 'payment'], true) ? 'sys' : '' }}"></span>
              <div>
                <h3>{{ $e->title ?: ucfirst($e->type) }}</h3>
                <div class="when">{{ $e->created_at->timezone('Asia/Manila')->format('M j, g:i A') }}@if(!in_array($e->type, ['placed', 'payment'], true) && $e->admin_user_id) &middot; LP Furniture @endif</div>
                @if($e->note)<p class="note">{{ $e->note }}</p>@endif
                @if($e->photo_path)<img src="{{ $e->photoUrl() }}" alt="Progress photo from our team">@endif
              </div>
            </li>
          @empty
            <li><span class="pin sys"></span><div><h3>No updates yet</h3></div></li>
          @endforelse
        </ul>
      </section>

      <section class="glass card" aria-labelledby="ot-items-t">
        <h2 id="ot-items-t">Order items</h2>
        @foreach($order->items as $item)
          <div class="order-item-row">
            <div class="order-item-img">
              <img src="{{ $item->image_url }}" alt="{{ $item->product->product_name ?? $item->product_name ?? 'Product' }}" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
            </div>
            <div class="order-item-info">
              <div class="order-item-name">{{ $item->product->product_name ?? $item->product_name ?? 'Product Unavailable' }}</div>
              <div class="order-item-qty">Qty: {{ $item->quantity }} × ₱{{ number_format($item->unit_price, 2) }}</div>
            </div>
            <div class="order-item-subtotal">₱{{ number_format($item->subtotal, 2) }}</div>
          </div>
        @endforeach
        @if($order->customization_note)
          <hr><p class="sub" style="margin:0;white-space:pre-line;"><b>Your instructions:</b> {{ $order->customization_note }}</p>
        @endif
      </section>
    </div>

    <div class="side">
      @include('orders.partials.side-panels')
      <a href="{{ route('catalog') }}" class="lp-btn lp-btn--secondary lp-btn--block">Continue Shopping</a>
    </div>
  </div>

</div>
</div>
</div>
@endsection
