@extends('layouts.app')

@section('title', 'Checkout — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/cart.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/checkout.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/checkout-preparing.css') }}"/>
@endpush

@section('content')
<div class="checkout-wrap">

  <div class="checkout-header">
    <h1 class="checkout-title">Checkout</h1>
    <p class="checkout-subtitle">Review your order and complete your purchase.</p>
  </div>

  <div class="checkout-steps">
    <div class="checkout-step">
      <div class="checkout-step-num done">✓</div>
      <span class="checkout-step-label">Cart</span>
    </div>
    <div class="checkout-step">
      <div class="checkout-step-num active">2</div>
      <span class="checkout-step-label active">Checkout</span>
    </div>
    <div class="checkout-step">
      <div class="checkout-step-num pending">3</div>
      <span class="checkout-step-label">Payment</span>
    </div>
    <div class="checkout-step">
      <div class="checkout-step-num pending">4</div>
      <span class="checkout-step-label">Confirmed</span>
    </div>
  </div>

  @if($errors->any())
    <div class="auth-errors" style="margin-bottom:24px;">
      @foreach($errors->all() as $error)
        <div class="auth-error-item">{{ $error }}</div>
      @endforeach
    </div>
  @endif

  @if(session('stock_error'))
    <div class="stock-error-banner">
      <div class="stock-error-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <div class="stock-error-text">
        <strong>Not Enough Stock</strong>
        <p>
          "{{ session('stock_error')['product_name'] }}" only has
          {{ session('stock_error')['available'] }}
          {{ Str::plural('unit', session('stock_error')['available']) }} left,
          but your order needs {{ session('stock_error')['requested'] }}.
          Please update the quantity and try again.
        </p>
      </div>
      <a href="{{ route('cart.index') }}" class="stock-error-btn">Update Cart</a>
    </div>
  @endif

  <form method="POST" action="{{ route('order.place') }}" id="checkout-form">
    @csrf

    @if($buyNow)
      <input type="hidden" name="buy_now_product_id" value="{{ $buyNow['product_id'] }}"/>
      <input type="hidden" name="buy_now_quantity" value="{{ $buyNow['quantity'] }}"/>
      <input type="hidden" name="buy_now_note" value="{{ $buyNow['note'] }}"/>
    @endif

    <div class="checkout-layout">

      {{-- LEFT COLUMN --}}
      <div>

        {{-- ═══════════════════════════════════
             MOBILE ONLY — Preparing Your Order
             loading screen. Shows immediately on
             page load, before any wizard screen.
             A "Next" button fades in once the
             brief loading animation finishes.
        ═══════════════════════════════════ --}}
        <div class="ckn-preparing" id="ckn-preparing">
          <div class="ckn-preparing-spinner"></div>
          <div class="ckn-preparing-check">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
          </div>
          <p class="ckn-preparing-text">Preparing your order...</p>
          <p class="ckn-preparing-subtext">Just a moment while we get everything ready for you.</p>

          <button type="button" class="ckn-preparing-next" onclick="startCheckoutTransition()">
            Next
          </button>
        </div>

        {{-- ═══════════════════════════════════
             ROW 1 — REVIEW YOUR ORDER (new)
        ═══════════════════════════════════ --}}
        <div class="checkout-screen" id="screen-review" data-desktop-title="1. Review Your Order">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Review Your Order</span>
            <span class="checkout-screen-step-count">Step 1 of 5</span>
          </div>

          <div class="ckn-row">
            <div class="ckn-icon-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
            </div>
            <div class="ckn-content-col">
              <button type="button" class="ckn-row-header" onclick="toggleSection('review')">
                <div class="ckn-row-titles">
                  <h2 class="ckn-section-title">Review Your Order</h2>
                  <span class="ckn-row-caption">Check your items and details.</span>
                </div>
                <span class="ckn-row-summary" id="ckn-summary-review">{{ $cartItems->count() }} item{{ $cartItems->count() > 1 ? 's' : '' }}</span>
                <span class="ckn-edit-pill">Edit</span>
                <svg class="ckn-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
              </button>

              <div class="checkout-screen-body">

                @foreach($cartItems as $item)
                  <div class="ckn-product-card">
                    <div class="ckn-product-img">
                      <img src="{{ $item->product->image_url }}" alt="{{ $item->product->product_name }}"
                           onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                    </div>
                    <div class="ckn-product-details">
                      <div class="ckn-product-name">{{ title_case($item->product->product_name) }}</div>

                      <div class="ckn-product-meta-row">
                        @if($item->product->material)
                          <span>{{ title_case($item->product->material) }}</span>
                        @endif
                        @if($item->product->finish)
                          <span>{{ title_case($item->product->finish) }} Finish</span>
                        @endif
                        @if($item->product->dimensions)
                          <span>{{ $item->product->dimensions_label }}</span>
                        @endif
                      </div>

                      <div class="ckn-product-bottom-row">
                        <div class="ckn-product-qty">
                          Qty: {{ $item->quantity }}
                          <a href="{{ route('cart.index') }}" class="ckn-edit-in-cart">Edit in Cart</a>
                        </div>
                        <div class="ckn-product-price">₱{{ number_format($item->product->price * $item->quantity, 2) }}</div>
                      </div>

                      <div class="ckn-product-tags">
                        <span class="ckn-tag ckn-tag-available">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                          Ready to Build
                        </span>
                        <span class="ckn-tag">Est. 7–10 Business Days</span>
                      </div>
                    </div>
                  </div>
                @endforeach

                {{-- Specifications + Included Services --}}
                <div class="ckn-info-grid">
                  <div class="ckn-info-card">
                    <div class="ckn-info-card-title">Material Information</div>
                    <div class="ckn-info-row"><span>Craftsmanship</span><strong>Handcrafted</strong></div>
                    <div class="ckn-info-row"><span>Warranty</span><strong>1 Year</strong></div>
                  </div>

                  <div class="ckn-info-card">
                    <div class="ckn-info-card-title">Included</div>
                    <div class="ckn-service-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Quality Inspection</div>
                    <div class="ckn-service-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Secure Packaging</div>
                    <div class="ckn-service-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Assembly Guide</div>
                    <div class="ckn-service-item"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Customer Support</div>
                  </div>
                </div>

                <div class="ckn-reassurance-panel">
                  <div class="ckn-reassurance-title">Before We Build Your Furniture</div>
                  <ul class="ckn-reassurance-list">
                    <li>Every furniture piece is handcrafted to order.</li>
                    <li>Materials are inspected before production begins.</li>
                    <li>You'll receive updates as your order progresses.</li>
                    <li>Changes are allowed before production begins.</li>
                  </ul>
                </div>

                <div class="ckn-continue-wrap">
                  <div class="ckn-actions">
                    <x-back-link named type="button" label="Cart" class="ckn-back-btn" onclick="window.location.href='{{ route('cart.index') }}'" />
                    <button type="button" class="ckn-continue-btn" onclick="goNext('review')">Continue to Delivery</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Cart" onclick="window.location.href='{{ route('cart.index') }}'"  class="checkout-screen-prev" />
            <button type="button" class="checkout-screen-continue" onclick="pushToScreen('review','delivery')">Next: Delivery Option</button>
          </div>
        </div>

        {{-- ═══════════════════════════════════
             ROW 2 — DELIVERY
        ═══════════════════════════════════ --}}
        <div class="checkout-screen" id="screen-delivery" data-desktop-title="2. Delivery Option">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Delivery Option</span>
            <span class="checkout-screen-step-count">Step 2 of 5</span>
          </div>

          <div class="ckn-row">
            <div class="ckn-icon-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            </div>
            <div class="ckn-content-col">
              <button type="button" class="ckn-row-header" onclick="toggleSection('delivery')">
                <div class="ckn-row-titles">
                  <h2 class="ckn-section-title">Delivery Option</h2>
                  <span class="ckn-row-caption">Choose how you want your order delivered.</span>
                </div>
                <span class="ckn-row-summary" id="ckn-summary-delivery">Pick-up at Shop · Free</span>
                <span class="ckn-edit-pill">Edit</span>
                <svg class="ckn-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
              </button>

              <div class="checkout-screen-body">
                <div class="cx-cards-row">
                  <label class="delivery-option-card selected" id="label-pickup">
                    <input type="radio" name="delivery_option" value="pickup" checked onchange="selectDelivery('pickup')"/>
                    <div class="delivery-option-icon">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/></svg>
                    </div>
                    <div class="delivery-option-info">
                      <div class="delivery-option-title">Pick-up at Shop</div>
                      <div class="delivery-option-desc">San Juan, Batangas · Mon–Sat 8AM–5PM · We'll notify you when ready</div>
                    </div>
                    <div class="delivery-option-price free">FREE</div>
                  </label>

                  <label class="delivery-option-card" id="label-delivery">
                    <input type="radio" name="delivery_option" value="delivery" onchange="selectDelivery('delivery')"/>
                    <div class="delivery-option-icon">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    </div>
                    <div class="delivery-option-info">
                      <div class="delivery-option-title">Door-to-Door Delivery</div>
                      <div class="delivery-option-desc">Delivery fee based on distance from San Juan, Batangas</div>
                    </div>
                    <div class="delivery-option-price">+ ₱500</div>
                  </label>
                </div>

                {{-- Order details: recipient, phone and the structured delivery address (every order needs them; the server checks again) --}}
                @include('partials.order-details', ['continue' => '[data-od-continue]'])

                <div class="ckn-continue-wrap">
                  <div class="ckn-actions">
                    <x-back-link named type="button" label="Review" class="ckn-back-btn" onclick="goBackTo('review')" />
                    <button type="button" class="ckn-continue-btn" data-od-continue onclick="goNext('delivery')">Continue to Payment Type</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Review" onclick="pushBackToScreen('delivery', 'review')"  class="checkout-screen-prev" />
            <button type="button" class="checkout-screen-continue" data-od-continue onclick="pushToScreen('delivery','paytype')">Next: Payment Type</button>
          </div>
        </div>

        {{-- ═══════════════════════════════════
             ROW 3 — PAYMENT TYPE
        ═══════════════════════════════════ --}}
        <div class="checkout-screen" id="screen-paytype" data-desktop-title="3. Payment Type">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Payment Type</span>
            <span class="checkout-screen-step-count">Step 3 of 5</span>
          </div>

          <div class="ckn-row">
            <div class="ckn-icon-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
            </div>
            <div class="ckn-content-col">
              <button type="button" class="ckn-row-header" onclick="toggleSection('paytype')">
                <div class="ckn-row-titles">
                  <h2 class="ckn-section-title">Payment Type</h2>
                  <span class="ckn-row-caption">Select how you want to pay.</span>
                </div>
                <span class="ckn-row-summary" id="ckn-summary-paytype">Full Payment</span>
                <span class="ckn-edit-pill">Edit</span>
                <svg class="ckn-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
              </button>

              <div class="checkout-screen-body">
                <div class="cx-cards-row">
                  <label class="payment-type-card selected" id="label-full">
                    <input type="radio" name="payment_type" value="full" checked onchange="selectPaymentType('full')"/>
                    <div class="payment-type-info">
                      <div class="payment-type-title">Full Payment</div>
                      <div class="payment-type-desc">Pay the complete amount now. Fastest way to confirm your order.</div>
                    </div>
                  </label>

                  <label class="payment-type-card" id="label-installment">
                    <input type="radio" name="payment_type" value="installment" onchange="selectPaymentType('installment')"/>
                    <div class="payment-type-info">
                      <div class="payment-type-title">Installment Plan</div>
                      <div class="payment-type-desc">Pay 50% now, split the rest into payments you choose.</div>

                      <div class="installment-period-wrap" id="installment-period-wrap">
                        <div class="installment-group-label">Duration</div>
                        <div class="installment-grid">
                          @foreach([1 => '1 Month', 2 => '2 Months', 3 => '3 Months'] as $months => $label)
                            <label class="installment-chip {{ $months === 1 ? 'selected' : '' }}">
                              <input type="radio" name="installment_duration" value="{{ $months }}" {{ $months === 1 ? 'checked' : '' }} onchange="selectInstallmentChip(this)"/>
                              {{ $label }}
                            </label>
                          @endforeach
                        </div>

                        <div class="installment-group-label" style="margin-top:14px;">Payment Frequency</div>
                        <div class="installment-grid">
                          @foreach([15 => 'Every 15 Days', 30 => 'Every 1 Month (30 Days)'] as $days => $label)
                            <label class="installment-chip {{ $days === 15 ? 'selected' : '' }}">
                              <input type="radio" name="installment_frequency" value="{{ $days }}" {{ $days === 15 ? 'checked' : '' }} onchange="selectInstallmentChip(this)"/>
                              {{ $label }}
                            </label>
                          @endforeach
                        </div>

                        <div class="installment-summary-box" id="installment-summary-box"></div>
                      </div>
                    </div>
                  </label>
                </div>

                <div class="ckn-continue-wrap">
                  <div class="ckn-actions">
                    <x-back-link named type="button" label="Delivery Option" class="ckn-back-btn" onclick="goBackTo('delivery')" />
                    <button type="button" class="ckn-continue-btn" onclick="goNext('paytype')">Continue to Payment Method</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Delivery Option" onclick="pushBackToScreen('paytype','delivery')"  class="checkout-screen-prev" />
            <button type="button" class="checkout-screen-continue" onclick="pushToScreen('paytype','paymethod')">Next: Payment Method</button>
          </div>
        </div>

        {{-- ═══════════════════════════════════
             ROW 4 — PAYMENT METHOD
        ═══════════════════════════════════ --}}
        <div class="checkout-screen" id="screen-paymethod" data-desktop-title="4. Payment Method">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Payment Method</span>
            <span class="checkout-screen-step-count">Step 4 of 5</span>
          </div>

          <div class="ckn-row">
            <div class="ckn-icon-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
            <div class="ckn-content-col">
              <button type="button" class="ckn-row-header" onclick="toggleSection('paymethod')">
                <div class="ckn-row-titles">
                  <h2 class="ckn-section-title">Payment Method</h2>
                  <span class="ckn-row-caption">Choose your preferred payment method.</span>
                </div>
                <span class="ckn-row-summary" id="ckn-summary-paymethod">Card (Stripe)</span>
                <span class="ckn-edit-pill">Edit</span>
                <svg class="ckn-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
              </button>

              <div class="checkout-screen-body">
                <div class="cx-cards-row">
                  <label class="payment-method-card selected" id="label-stripe">
                    <input type="radio" name="payment_method" value="stripe" checked onchange="selectPaymentMethod('stripe')"/>
                    <div class="payment-method-icon stripe">
                      <svg width="20" height="14" viewBox="0 0 60 25" fill="white"><path d="M28.3 8.7c0-2.7 2.3-3.7 4.8-3.7 2.7 0 5.3 1 7.3 2.7l3.3-5.3C41.3.8 37.7 0 33.3 0c-6.7 0-12 3.3-12 9.7 0 11 14.7 8.3 14.7 13 0 2.3-2 3.7-5 3.7-3 0-6.3-1.3-8.7-3.3l-3.7 5.3c3 2.3 7.3 3.7 12 3.7 7 0 12.3-3.3 12.3-10 0-11.3-14.6-8.7-14.6-13.4z"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Credit / Debit Card (Stripe)</div>
                      <div class="payment-method-desc">Visa, Mastercard, and more. Processed securely by Stripe.</div>
                    </div>
                  </label>

                  <label class="payment-method-card" id="label-gcash">
                    <input type="radio" name="payment_method" value="gcash" onchange="selectPaymentMethod('gcash')"/>
                    <div class="payment-method-icon gcash">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">GCash</div>
                      <div class="payment-method-desc">Pay securely with your GCash account, powered by PayMongo.</div>
                    </div>
                  </label>

                  <label class="payment-method-card" id="label-onsite">
                    <input type="radio" name="payment_method" value="onsite" onchange="selectPaymentMethod('onsite')"/>
                    <div class="payment-method-icon onsite">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M12 12h.01"/><path d="M17 12h.01"/><path d="M7 12h.01"/></svg>
                    </div>
                    <div class="payment-method-info">
                      <div class="payment-method-title">Cash on Pickup / Delivery</div>
                      <div class="payment-method-desc">Pay in cash when you pick up or upon delivery of your furniture.</div>
                    </div>
                  </label>
                </div>

                <div class="ckn-continue-wrap">
                  <div class="ckn-actions">
                    <x-back-link named type="button" label="Payment Type" class="ckn-back-btn" onclick="goBackTo('paytype')" />
                    <button type="button" class="ckn-continue-btn" onclick="goNext('paymethod')">Continue to Special Instructions</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Payment Type" onclick="pushBackToScreen('paymethod','paytype')"  class="checkout-screen-prev" />
            <button type="button" class="checkout-screen-continue" onclick="pushToScreen('paymethod','notes')">Next: Instructions</button>
          </div>
        </div>

        {{-- ═══════════════════════════════════
             ROW 5 — SPECIAL INSTRUCTIONS
        ═══════════════════════════════════ --}}
        <div class="checkout-screen" id="screen-notes" data-desktop-title="5. Special Instructions">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Special Instructions</span>
            <span class="checkout-screen-step-count">Step 5 of 5</span>
          </div>

          <div class="ckn-row">
            <div class="ckn-icon-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </div>
            <div class="ckn-content-col">
              <button type="button" class="ckn-row-header" onclick="toggleSection('notes')">
                <div class="ckn-row-titles">
                  <h2 class="ckn-section-title">Special Instructions <span class="ckn-optional-tag">Optional</span></h2>
                  <span class="ckn-row-caption">Add any notes or requests for your order.</span>
                </div>
                <span class="ckn-row-summary" id="ckn-summary-notes">None added</span>
                <span class="ckn-edit-pill">Edit</span>
                <svg class="ckn-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6,9 12,15 18,9"/></svg>
              </button>

              <div class="checkout-screen-body">
                <textarea
                  name="customization_note"
                  id="customization-note-input"
                  class="checkout-notes-textarea"
                  placeholder="Any special requests for your order? Size modifications, wood type preference, finish color, delivery instructions, etc."
                  oninput="updateSectionSummaries()"
                >{{ old('customization_note') }}</textarea>

                <div class="ckn-continue-wrap">
                  <div class="ckn-actions">
                    <x-back-link named type="button" label="Payment Method" class="ckn-back-btn" onclick="goBackTo('paymethod')" />
                    <button type="button" class="ckn-continue-btn" id="continue-to-place" onclick="readyToPlace()">Continue to Place Order</button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Payment Method" onclick="pushBackToScreen('notes','paymethod')"  class="checkout-screen-prev" />
            <button type="button" class="checkout-screen-continue" onclick="goToReview()">Review Order</button>
          </div>
        </div>

        {{-- SCREEN 6: Final Review — mobile only --}}
        <div class="checkout-screen checkout-review-screen" id="screen-finalreview" data-desktop-title="">
          <div class="checkout-screen-header">
            <span class="checkout-screen-title">Confirm Your Order</span>
            <span class="checkout-screen-spacer"></span>
          </div>

          <div class="checkout-screen-body">

            <div class="review-block">
              <div class="review-block-header"><span>Items</span></div>
              @foreach($cartItems as $item)
                <div class="review-item-row">
                  <span class="review-item-name">{{ title_case($item->product->product_name) }} ×{{ $item->quantity }}</span>
                  <span class="review-item-price">₱{{ number_format($item->product->price * $item->quantity, 2) }}</span>
                </div>
              @endforeach
            </div>

            <div class="review-block">
              <div class="review-block-header">
                <span>Delivery</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('finalreview','delivery')">Edit</button>
              </div>
              <div class="review-value" id="review-delivery-value">Pick-up at Shop · Free</div>
              <div class="review-value-sub" id="review-delivery-address" style="display:none;"></div>
            </div>

            <div class="review-block">
              <div class="review-block-header">
                <span>Payment Type</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('finalreview','paytype')">Edit</button>
              </div>
              <div class="review-value" id="review-paytype-value">Full Payment</div>
            </div>

            <div class="review-block">
              <div class="review-block-header">
                <span>Payment Method</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('finalreview','paymethod')">Edit</button>
              </div>
              <div class="review-value" id="review-paymethod-value">Card (Stripe)</div>
            </div>

            <div class="review-block">
              <div class="review-block-header">
                <span>Special Instructions</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('finalreview','notes')">Edit</button>
              </div>
              <div class="review-value" id="review-notes-value">None added</div>
            </div>

            <div class="review-block review-cost-block">
              <div class="checkout-cost-row">
                <span>Subtotal</span>
                <span>₱{{ number_format($subtotal, 2) }}</span>
              </div>
              <div class="checkout-cost-row">
                <span>Delivery Fee</span>
                <span id="review-delivery-fee">Free</span>
              </div>
              <div class="checkout-cost-divider checkout-cost-divider--solid"></div>
              <div class="checkout-cost-row checkout-cost-total">
                <span>Total</span>
                <span id="review-total">₱{{ number_format($subtotal, 2) }}</span>
              </div>
            </div>

          </div>

          <div class="checkout-screen-footer">
            <x-back-link named type="button" label="Special Instructions" onclick="pushBackToScreen('finalreview','notes')"  class="checkout-screen-prev" />
            <button type="submit" class="checkout-screen-continue checkout-place-order-btn">Place Order</button>
          </div>
        </div>

        {{-- Need Help panel — desktop only --}}
        <div class="ckn-help-panel">
          <div class="ckn-help-icon">?</div>
          <div class="ckn-help-text">
            <div class="ckn-help-title">Need Help?</div>
            <div class="ckn-help-sub">Our team is here to assist you with your order.</div>
          </div>
          @php $contact = \App\Models\BusinessSetting::current(); @endphp
          <div class="ckn-help-contact">
            <div><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg> <a href="{{ $contact->phone_href }}" style="color:inherit;text-decoration:none;">{{ $contact->phone }}</a></div>
            <div><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/></svg> <a href="mailto:{{ $contact->email }}" style="color:inherit;text-decoration:none;">{{ $contact->email }}</a></div>
            <div><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg> Mon–Sat, 8:00 AM – 5:00 PM</div>
          </div>
        </div>

      </div>

      {{-- RIGHT COLUMN: Desktop sidebar --}}
      <div>

        <div class="ckn-mini-topbar">
          <span><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg> Secure Checkout</span>
          <span class="ckn-mini-divider">|</span>
          <span>Total <strong id="mini-total">₱{{ number_format($subtotal, 2) }}</strong></span>
        </div>

        <div class="checkout-summary" id="checkout-summary">
          <div class="checkout-summary-title">Your Order</div>

          <div class="summary-items">
            @foreach($cartItems as $item)
              <div class="summary-item">
                <div class="summary-item-img">
                  <img src="{{ $item->product->image_url }}" alt="{{ $item->product->product_name }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
                <div class="summary-item-info">
                  <div class="summary-item-name">{{ title_case($item->product->product_name) }}</div>
                  @if($item->product->material)<div class="summary-item-qty">{{ title_case($item->product->material) }}</div>@endif
                  @if($item->product->finish)<div class="summary-item-qty">{{ title_case($item->product->finish) }}</div>@endif
                  <div class="summary-item-qty">Qty: {{ $item->quantity }}</div>
                </div>
                <div class="summary-item-price">₱{{ number_format($item->product->price * $item->quantity, 0) }}</div>
              </div>
            @endforeach
          </div>

          <div class="summary-breakdown">
            <div class="summary-row">
              <span>Subtotal</span>
              <strong>₱{{ number_format($subtotal, 2) }}</strong>
            </div>
            <div class="summary-row delivery-row" id="delivery-fee-row">
              <span>Delivery Fee</span>
              <strong id="delivery-fee-amount">Free (Pick-up)</strong>
            </div>
          </div>

          <div class="summary-total-row">
            <span>Total</span>
            <span id="order-total">₱{{ number_format($subtotal, 2) }}</span>
          </div>

          {{-- Savings banner — only shown when pick-up is selected --}}
          <div class="ckn-savings-banner" id="ckn-savings-banner">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20V10M18 20V4M6 20v-4"/></svg>
            <div>
              <div class="ckn-savings-title">You're saving ₱500.00</div>
              <div class="ckn-savings-sub">Pick-up at Shop is free!</div>
            </div>
          </div>

          <div class="ckn-trust-badges">
            <div class="ckn-trust-badge">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>Secure Payment</span>
            </div>
            <div class="ckn-trust-badge">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
              <span>Handcrafted Quality</span>
            </div>
            <div class="ckn-trust-badge">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
              <span>Free Pick-up Available</span>
            </div>
            <div class="ckn-trust-badge">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
              <span>Customer Support</span>
            </div>
          </div>

          <p class="ckn-ready-tag" id="ckn-ready-tag" role="status"></p>
          <button type="submit" class="btn-place-order" id="place-order-btn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <span id="place-order-btn-label">Place Order</span>
          </button>
          <p class="ckn-charge-note">You won't be charged yet.</p>
        </div>
      </div>

    </div>

  </form>

</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/js/checkout-preparing.js') }}"></script>
<script src="{{ asset('assets/js/checkout-guard.js') }}?v={{ @filemtime(public_path('assets/js/checkout-guard.js')) }}"></script>
<script>
const SUBTOTAL = {{ $subtotal }};
const DELIVERY_FEE = 500;
let currentDelivery = 'pickup';

function isDesktop() {
  return window.innerWidth > 768;
}

const SECTION_ORDER = ['review', 'delivery', 'paytype', 'paymethod', 'notes'];

function toggleSection(name) {
  if (!isDesktop()) return;
  SECTION_ORDER.forEach(section => {
    const screen = document.getElementById('screen-' + section);
    const isTarget = section === name;
    const alreadyActive = screen.classList.contains('ckn-active');
    screen.classList.toggle('ckn-active', isTarget ? !alreadyActive : false);
  });
}

function openSection(name) {
  if (!isDesktop()) return;
  SECTION_ORDER.forEach(section => {
    document.getElementById('screen-' + section).classList.toggle('ckn-active', section === name);
  });
}

function goNext(fromSection) {
  const idx = SECTION_ORDER.indexOf(fromSection);
  const next = SECTION_ORDER[idx + 1];
  if (next) openSection(next);
}

function selectDelivery(type) {
  currentDelivery = type;
  document.getElementById('label-pickup').classList.toggle('selected', type === 'pickup');
  document.getElementById('label-delivery').classList.toggle('selected', type === 'delivery');

  document.getElementById('ckn-savings-banner').style.display = type === 'pickup' ? 'flex' : 'none';

  updateTotal();
  updateSectionSummaries();
}

function selectPaymentType(type) {
  document.getElementById('label-full').classList.toggle('selected', type === 'full');
  document.getElementById('label-installment').classList.toggle('selected', type === 'installment');
  document.getElementById('installment-period-wrap').classList.toggle('visible', type === 'installment');
  if (type === 'installment') updateInstallmentSummary();
  updateSectionSummaries();
}

function installmentPlanLabel() {
  const durationInput = document.querySelector('input[name="installment_duration"]:checked');
  const frequencyInput = document.querySelector('input[name="installment_frequency"]:checked');
  if (!durationInput || !frequencyInput) return '';
  const months = durationInput.value;
  const freqLabel = frequencyInput.value === '15' ? 'Every 15 Days' : 'Every 1 Month';
  return months + (months === '1' ? ' Month' : ' Months') + ' · ' + freqLabel;
}

function selectInstallmentChip(input) {
  // Scoped to the SAME radio group's grid only — Duration and
  // Frequency are two separate chip groups on the page now, so
  // clearing .selected page-wide would wrongly clear the other group.
  const grid = input.closest('.installment-grid');
  grid.querySelectorAll('.installment-chip').forEach(c => c.classList.remove('selected'));
  input.closest('.installment-chip').classList.add('selected');
  updateInstallmentSummary();
  updateSectionSummaries();
}

/* ── INSTALLMENT SUMMARY ──
   Mirrors the server's split logic exactly (same 30-day-month
   basis, same "last payment absorbs the rounding remainder" rule)
   so the number shown here always matches what actually gets
   created at checkout — never just an approximation. */
function computeInstallmentPlan(total, durationMonths, frequencyDays) {
  const downpayment = Math.round(total * 0.5 * 100) / 100;
  const remaining = Math.round((total - downpayment) * 100) / 100;
  const numPayments = Math.round((durationMonths * 30) / frequencyDays);
  const baseAmount = Math.floor((remaining / numPayments) * 100) / 100;

  const amounts = [];
  let runningTotal = 0;
  for (let i = 1; i <= numPayments; i++) {
    const isLast = i === numPayments;
    const amount = isLast ? Math.round((remaining - runningTotal) * 100) / 100 : baseAmount;
    runningTotal += amount;
    amounts.push(amount);
  }

  return { downpayment, remaining, numPayments, amounts };
}

function updateInstallmentSummary() {
  const box = document.getElementById('installment-summary-box');
  if (!box) return;

  const durationInput = document.querySelector('input[name="installment_duration"]:checked');
  const frequencyInput = document.querySelector('input[name="installment_frequency"]:checked');
  if (!durationInput || !frequencyInput) return;

  const fee = currentDelivery === 'delivery' ? DELIVERY_FEE : 0;
  const total = SUBTOTAL + fee;
  const duration = parseInt(durationInput.value, 10);
  const frequency = parseInt(frequencyInput.value, 10);
  const frequencyLabel = frequency === 15 ? '15 days' : 'month';

  const plan = computeInstallmentPlan(total, duration, frequency);
  const fmt = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2 });

  box.innerHTML = `
    <div class="installment-summary-row"><span>Pay now (50% downpayment)</span><strong>${fmt(plan.downpayment)}</strong></div>
    <div class="installment-summary-row"><span>Then every ${frequencyLabel}, ${plan.numPayments} payment${plan.numPayments === 1 ? '' : 's'}</span><strong>${fmt(plan.amounts[0])}${plan.numPayments > 1 ? ' each' : ''}</strong></div>
  `;
}

function selectPaymentMethod(method) {
  ['stripe', 'gcash', 'onsite'].forEach(m => document.getElementById('label-' + m)?.classList.remove('selected'));
  document.getElementById('label-' + method)?.classList.add('selected');
  updateSectionSummaries();
}

function updateTotal() {
  const fee   = currentDelivery === 'delivery' ? DELIVERY_FEE : 0;
  const total = SUBTOTAL + fee;
  const formatted = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });

  const feeEl = document.getElementById('delivery-fee-amount');
  if (feeEl) feeEl.textContent = fee === 0 ? 'Free (Pick-up)' : '₱' + fee.toLocaleString();

  const totalEl = document.getElementById('order-total');
  if (totalEl) totalEl.textContent = formatted;

  const miniTotalEl = document.getElementById('mini-total');
  if (miniTotalEl) miniTotalEl.textContent = formatted;

  const mFeeEl = document.getElementById('mobile-delivery-fee');
  if (mFeeEl) mFeeEl.textContent = fee === 0 ? 'Free' : '₱' + fee.toLocaleString();

  const mTotalEl = document.getElementById('mobile-order-total');
  if (mTotalEl) mTotalEl.textContent = formatted;

  const rFeeEl = document.getElementById('review-delivery-fee');
  if (rFeeEl) rFeeEl.textContent = fee === 0 ? 'Free' : '₱' + fee.toLocaleString();

  const rTotalEl = document.getElementById('review-total');
  if (rTotalEl) rTotalEl.textContent = formatted;

  // Delivery option affects the total, which affects the 50%
  // downpayment and per-payment amounts — keep the summary in sync.
  const installmentChecked = document.querySelector('input[name="payment_type"]:checked')?.value === 'installment';
  if (installmentChecked) updateInstallmentSummary();
}

function updateSectionSummaries() {
  const deliveryChecked = document.querySelector('input[name="delivery_option"]:checked');
  if (deliveryChecked) {
    const el = document.getElementById('ckn-summary-delivery');
    if (el) el.textContent = deliveryChecked.value === 'pickup' ? 'Pick-up at Shop · Free' : 'Door-to-Door · +₱500';
  }

  const payTypeChecked = document.querySelector('input[name="payment_type"]:checked');
  if (payTypeChecked) {
    const el = document.getElementById('ckn-summary-paytype');
    if (payTypeChecked.value === 'full') {
      if (el) el.textContent = 'Full Payment';
    } else {
      if (el) el.textContent = 'Installment · ' + installmentPlanLabel();
    }
  }

  const payMethodChecked = document.querySelector('input[name="payment_method"]:checked');
  if (payMethodChecked) {
    const labels = { stripe: 'Card (Stripe)', gcash: 'GCash', onsite: 'Cash' };
    const el = document.getElementById('ckn-summary-paymethod');
    if (el) el.textContent = labels[payMethodChecked.value] || '';
  }

  const notesInput = document.getElementById('customization-note-input');
  const notesEl = document.getElementById('ckn-summary-notes');
  if (notesInput && notesEl) {
    const val = notesInput.value.trim();
    notesEl.textContent = val ? (val.length > 26 ? val.substring(0, 26) + '…' : val) : 'None added';
  }
}

function goToScreen(from, to) {
  if (from) document.getElementById('screen-' + from)?.classList.remove('open');
  if (to) {
    document.getElementById('screen-' + to)?.classList.add('open');
    document.body.style.overflow = 'hidden';
  } else {
    document.body.style.overflow = '';
  }
}

function goToReview() {
  updateReviewScreen();
  pushToScreen('notes', 'finalreview');
}

function updateReviewScreen() {
  const deliveryChecked = document.querySelector('input[name="delivery_option"]:checked');
  const deliveryValueEl = document.getElementById('review-delivery-value');
  const deliveryAddrEl  = document.getElementById('review-delivery-address');

  if (deliveryChecked) {
    if (deliveryChecked.value === 'pickup') {
      deliveryValueEl.textContent = 'Pick-up at Shop · Free';
      deliveryAddrEl.style.display = 'none';
    } else {
      deliveryValueEl.textContent = 'Door-to-Door Delivery · +₱500';
      const addr = (window.MAOrder && window.MAOrder.summary()) || '';
      if (addr) {
        deliveryAddrEl.textContent = addr;
        deliveryAddrEl.style.display = 'block';
      } else {
        deliveryAddrEl.style.display = 'none';
      }
    }
  }

  const payTypeChecked = document.querySelector('input[name="payment_type"]:checked');
  const payTypeEl = document.getElementById('review-paytype-value');
  if (payTypeChecked) {
    if (payTypeChecked.value === 'full') {
      payTypeEl.textContent = 'Full Payment';
    } else {
      payTypeEl.textContent = 'Installment Plan · ' + installmentPlanLabel();
    }
  }

  const payMethodChecked = document.querySelector('input[name="payment_method"]:checked');
  const payMethodEl = document.getElementById('review-paymethod-value');
  if (payMethodChecked) {
    const labels = { stripe: 'Credit / Debit Card (Stripe)', gcash: 'GCash', onsite: 'Cash on Pickup / Delivery' };
    payMethodEl.textContent = labels[payMethodChecked.value] || '';
  }

  const notesInput = document.getElementById('customization-note-input');
  const notesEl = document.getElementById('review-notes-value');
  const val = notesInput?.value.trim();
  notesEl.textContent = val ? val : 'None added';
}

document.addEventListener('DOMContentLoaded', () => {
  updateTotal();
  updateSectionSummaries();

  // Desktop only: Review Your Order opens immediately via the
  // accordion. Mobile shows the Preparing Your Order screen
  // first instead (handled by checkout-preparing.js), and its
  // Next button is what calls goToScreen(null, 'review').
  if (isDesktop()) {
    document.getElementById('screen-review')?.classList.add('ckn-active');
  }

  document.addEventListener('keydown', (e) => {
    const openScreen = document.querySelector('.checkout-screen.open');
    if (e.key === 'Escape' && openScreen) {
      openScreen.classList.remove('open');
      document.body.style.overflow = '';
    }
  });
});

/* ── DOUBLE-SUBMIT GUARD ──
   Both "Place Order" buttons (desktop sidebar + mobile review
   screen) submit this same form. Disabling on the form's submit
   event — rather than each button's click — catches either one,
   and disabling doesn't cancel the submission already in flight. */
document.getElementById('checkout-form').addEventListener('submit', function () {
  this.querySelectorAll('button[type="submit"]').forEach(btn => btn.disabled = true);

  const iconLabel = document.getElementById('place-order-btn-label');
  if (iconLabel) iconLabel.textContent = 'Placing Order...';

  const reviewBtn = document.querySelector('.checkout-place-order-btn');
  if (reviewBtn) reviewBtn.textContent = 'Placing Order...';
});
</script>
@endpush