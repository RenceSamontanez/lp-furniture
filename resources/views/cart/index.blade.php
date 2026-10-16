@extends('layouts.app')

@section('title', 'My Cart — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/cart.css') }}"/>
@endpush

@section('content')
<div class="cart-wrap">

  {{-- Header --}}
  <div class="cart-header">
    <p class="gallery-eyebrow" style="margin-bottom:6px;">Shopping</p>
    <h1 class="cart-title">My Cart</h1>
    <p class="cart-subtitle">Review your items before proceeding to checkout.</p>
  </div>

  {{-- Session messages --}}

  @if($cartItems->isEmpty())

  {{-- Empty state --}}
<div class="cart-empty">
  <div style="max-width:520px;margin:0 auto;text-align:center;padding:60px 40px;">

    <div style="width:80px;height:80px;border-radius:50%;background:var(--cream);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;color:var(--gold);">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.99-1.73L23 6H6"/>
      </svg>
    </div>

    <h2 style="font-family:var(--font-display);font-size:26px;color:var(--brown-deep);margin-bottom:12px;">
      Your cart is empty
    </h2>
    <p style="font-size:15px;color:var(--text-muted);line-height:1.7;margin-bottom:32px;">
      Looks like you haven't added anything yet. Browse our collection of handcrafted Philippine hardwood furniture — from dining tables to custom pieces built just for you.
    </p>

    <a href="{{ route('catalog') }}" class="btn btn-gold" style="font-size:15px;padding:14px 32px;">
      Browse Our Furniture →
    </a>

    {{-- Featured categories --}}
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:28px;">
      @foreach(['Table','Chair','Cabinet','Bed','Sofa'] as $cat)
        <a href="{{ route('catalog', ['category' => $cat]) }}"
           style="font-size:12px;font-weight:600;padding:7px 16px;border-radius:20px;border:1.5px solid var(--border);background:var(--warm-white);color:var(--brown-mid);transition:all .2s;">
          {{ $cat }}s
        </a>
      @endforeach
    </div>

  </div>
</div>

  @else

    <div class="cart-layout">

      {{-- LEFT: Cart items --}}
      <div>
        <div class="cart-actions-bar">
          <span class="cart-count-label">
            <strong>{{ $cartItems->count() }}</strong>
            {{ Str::plural('item', $cartItems->count()) }} in your cart
          </span>
          <form method="POST" action="{{ route('cart.clear') }}" style="display:inline;">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-clear-cart"
                    onclick="return confirm('Remove all items from cart?')">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3,6 5,6 21,6"/>
                <path d="M19 6l-1 14H6L5 6"/>
                <path d="M10 11v6M14 11v6"/>
                <path d="M9 6V4h6v2"/>
              </svg>
              Clear cart
            </button>
          </form>
        </div>

        <div class="cart-items-list">
          @foreach($cartItems as $item)
            <div class="cart-item-card">

              {{-- Product image --}}
              <a href="{{ route('product.show', $item->product->id) }}" class="cart-item-img">
                <img
                  src="{{ $item->product->image_url }}"
                  alt="{{ $item->product->product_name }}"
                  onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
                />
              </a>

              {{-- Details --}}
              <div class="cart-item-details">
                <div class="cart-item-category">{{ $item->product->category }}</div>
                <a href="{{ route('product.show', $item->product->id) }}" class="cart-item-name">
                  {{ $item->product->product_name }}
                </a>

                @if($item->note)
                  <p class="cart-item-note">📝 {{ $item->note }}</p>
                @endif

                {{-- Quantity controls --}}
                <div class="cart-qty-row">
                  <form method="POST" action="{{ route('cart.update', $item->id) }}" class="cart-qty-form">
                    @csrf
                    @method('PATCH')
                    <div class="cart-qty-control">
                      <button
                        type="submit"
                        name="quantity"
                        value="{{ max(1, $item->quantity - 1) }}"
                        class="cart-qty-btn"
                      >−</button>
                      <div class="cart-qty-value">{{ $item->quantity }}</div>
                      <button
                        type="submit"
                        name="quantity"
                        value="{{ $item->quantity + 1 }}"
                        class="cart-qty-btn"
                        {{ $item->quantity >= $item->product->stock_quantity ? 'disabled' : '' }}
                      >+</button>
                    </div>
                  </form>

                  <form method="POST" action="{{ route('cart.remove', $item->id) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cart-remove-btn">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3,6 5,6 21,6"/>
                        <path d="M19 6l-1 14H6L5 6"/>
                      </svg>
                      Remove
                    </button>
                  </form>
                </div>
              </div>

              {{-- Price --}}
              <div class="cart-item-price">
                <span class="cart-item-unit-price">
                  {{ $item->product->formatted_price }} × {{ $item->quantity }}
                </span>
                <span class="cart-item-subtotal">
                  ₱{{ number_format($item->product->price * $item->quantity, 2) }}
                </span>
              </div>

            </div>
          @endforeach
        </div>
      </div>

      {{-- RIGHT: Order Summary --}}
      <div>
        <div class="cart-summary">
          <div class="cart-summary-title">Order Summary</div>

          {{-- Line items --}}
          @foreach($cartItems as $item)
            <div class="summary-line">
              <span>{{ Str::limit($item->product->product_name, 24) }} ×{{ $item->quantity }}</span>
              <strong>₱{{ number_format($item->product->price * $item->quantity, 2) }}</strong>
            </div>
          @endforeach

          <div class="summary-line">
            <span>Delivery fee</span>
            <strong>Calculated at checkout</strong>
          </div>

          <div class="summary-line total">
            <span>Subtotal</span>
            <span>₱{{ number_format($total, 2) }}</span>
          </div>

          {{-- Checkout button --}}
          @if(Auth::user()->is_validated)
            <a href="{{ route('checkout') }}" class="btn-checkout">
              Proceed to Checkout →
            </a>
          @else
            <a href="{{ route('validation.show') }}" class="btn-checkout">
              Verify Identity to Checkout →
            </a>
          @endif

          <a href="{{ route('catalog') }}" class="cart-continue">
            ← Continue Shopping
          </a>

          {{-- Clear cart --}}
          <form method="POST" action="{{ route('cart.clear') }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-clear-cart"
                    onclick="return confirm('Remove all items from cart?')">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3,6 5,6 21,6"/>
                <path d="M19 6l-1 14H6L5 6"/>
              </svg>
              Clear entire cart
            </button>
          </form>

          {{-- Installment notice --}}
          <div class="cart-installment-notice">
            💳 <strong>Installment available</strong> — pay in up to
            6 monthly installments. Select at checkout.
          </div>

        </div>
      </div>

    </div>
  @endif

</div>
@endsection