@extends('layouts.app')

@section('title', 'Checkout — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/cart.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/checkout.css') }}"/>
@endpush

@section('content')
<div class="checkout-wrap">

  {{-- Header --}}
  <div class="checkout-header">
    <p class="gallery-eyebrow" style="margin-bottom:6px;">Almost There</p>
    <h1 class="checkout-title">Checkout</h1>
    <p class="checkout-subtitle">Review your order and complete your purchase.</p>
  </div>

  {{-- Progress Steps (desktop) --}}
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

  <form method="POST" action="{{ route('order.place') }}" id="checkout-form">
    @csrf

    <div class="checkout-layout">

      {{-- LEFT COLUMN --}}
      <div>

        {{-- ═══════════════════════════════════
             MOBILE ONLY — highlighted order card
             (always visible, this is "step 0")
        ═══════════════════════════════════ --}}
        <div class="checkout-order-card checkout-order-card--highlighted">
          <div class="checkout-order-card-label">Your Order</div>
          @foreach($cartItems as $item)
            <div class="checkout-order-row">
              <div class="checkout-order-img">
                <img src="{{ $item->product->image_url }}" alt="{{ $item->product->product_name }}"
                     onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
              </div>
              <div class="checkout-order-info">
                <div class="checkout-order-name">{{ $item->product->product_name }}</div>
                <div class="checkout-order-qty">Qty: {{ $item->quantity }}</div>
              </div>
              <div class="checkout-order-price">₱{{ number_format($item->product->price * $item->quantity, 2) }}</div>
            </div>
          @endforeach

          <div class="checkout-cost-divider"></div>
          <div class="checkout-cost-row">
            <span>Subtotal</span>
            <span>₱{{ number_format($subtotal, 2) }}</span>
          </div>
          <div class="checkout-cost-row">
            <span>Delivery Fee</span>
            <span id="mobile-delivery-fee">Free</span>
          </div>
          <div class="checkout-cost-divider checkout-cost-divider--solid"></div>
          <div class="checkout-cost-row checkout-cost-total">
            <span>Total</span>
            <span id="mobile-order-total">₱{{ number_format($subtotal, 2) }}</span>
          </div>
        </div>

        {{-- Mobile-only: starts the wizard --}}
        <button type="button" class="checkout-start-btn" onclick="goToScreen(null, 'delivery')">
          Next: Delivery Option →
        </button>

        {{-- ═══════════════════════════════════
             WIZARD SCREENS
             Mobile: full-screen sequential steps
             Desktop: inline stacked cards (unchanged)
        ═══════════════════════════════════ --}}

        {{-- SCREEN 1: Delivery --}}
        <div class="checkout-screen" id="screen-delivery" data-desktop-title="1. Delivery Option">
          <div class="checkout-screen-header">
            <button type="button" class="checkout-screen-back" onclick="goToScreen('delivery', null)">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <span class="checkout-screen-title">Delivery Option</span>
            <span class="checkout-screen-step-count">Step 1 of 4</span>
          </div>

          <div class="checkout-screen-body">
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

            <div class="delivery-address-fields" id="delivery-address-fields">
              <div class="field-group">
                <label class="form-label">Full Delivery Address</label>
                <input type="text" name="delivery_address" id="input-delivery-address" class="form-input" placeholder="House no., Street, Barangay, City, Province" value="{{ old('delivery_address') }}"/>
              </div>
              <div class="field-group">
                <label class="form-label">Landmark <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="delivery_landmark" id="input-delivery-landmark" class="form-input" placeholder="Near... in front of..." value="{{ old('delivery_landmark') }}"/>
              </div>
            </div>
          </div>

          <div class="checkout-screen-footer">
            <button type="button" class="checkout-screen-continue" onclick="goToScreen('delivery','paytype')">Next: Payment Type →</button>
          </div>
        </div>

        {{-- SCREEN 2: Payment Type --}}
        <div class="checkout-screen" id="screen-paytype" data-desktop-title="2. Payment Type">
          <div class="checkout-screen-header">
            <button type="button" class="checkout-screen-back" onclick="goToScreen('paytype','delivery')">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <span class="checkout-screen-title">Payment Type</span>
            <span class="checkout-screen-step-count">Step 2 of 4</span>
          </div>

          <div class="checkout-screen-body">
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
                <div class="payment-type-desc">Split your payment over time. Subject to admin approval within 24 hours.</div>

                <div class="installment-period-wrap" id="installment-period-wrap">
                  <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Choose installment period:</div>
                  <div class="installment-grid">
                    @foreach(['7 days','15 days','1 month','2 months','3 months','6 months'] as $period)
                      <label class="installment-chip {{ $period === '1 month' ? 'selected' : '' }}">
                        <input type="radio" name="installment_period" value="{{ $period }}" {{ $period === '1 month' ? 'checked' : '' }} onchange="selectInstallmentChip(this)"/>
                        {{ $period }}
                      </label>
                    @endforeach
                  </div>
                </div>
              </div>
            </label>
          </div>

          <div class="checkout-screen-footer">
            <button type="button" class="checkout-screen-continue" onclick="goToScreen('paytype','paymethod')">Next: Payment Method →</button>
          </div>
        </div>

        {{-- SCREEN 3: Payment Method --}}
        <div class="checkout-screen" id="screen-paymethod" data-desktop-title="3. Payment Method">
          <div class="checkout-screen-header">
            <button type="button" class="checkout-screen-back" onclick="goToScreen('paymethod','paytype')">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <span class="checkout-screen-title">Payment Method</span>
            <span class="checkout-screen-step-count">Step 3 of 4</span>
          </div>

          <div class="checkout-screen-body">
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

          <div class="checkout-screen-footer">
            <button type="button" class="checkout-screen-continue" onclick="goToScreen('paymethod','notes')">Next: Instructions →</button>
          </div>
        </div>

        {{-- SCREEN 4: Special Instructions --}}
        <div class="checkout-screen" id="screen-notes" data-desktop-title="4. Special Instructions">
          <div class="checkout-screen-header">
            <button type="button" class="checkout-screen-back" onclick="goToScreen('notes','paymethod')">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <span class="checkout-screen-title">Special Instructions</span>
            <span class="checkout-screen-step-count">Step 4 of 4</span>
          </div>

          <div class="checkout-screen-body">
            <textarea
              name="customization_note"
              id="customization-note-input"
              class="checkout-notes-textarea"
              placeholder="Any special requests for your order? Size modifications, wood type preference, finish color, delivery instructions, etc."
              oninput="updateRowSummaries()"
            >{{ old('customization_note') }}</textarea>
          </div>

          <div class="checkout-screen-footer">
            <button type="button" class="checkout-screen-continue" onclick="goToReview()">Review Order →</button>
          </div>
        </div>

        {{-- SCREEN 5: Final Review — mobile only, this is where Place Order actually lives --}}
        <div class="checkout-screen checkout-review-screen" id="screen-review" data-desktop-title="">
          <div class="checkout-screen-header">
            <button type="button" class="checkout-screen-back" onclick="goToScreen('review','notes')">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            </button>
            <span class="checkout-screen-title">Review Your Order</span>
            <span class="checkout-screen-spacer"></span>
          </div>

          <div class="checkout-screen-body">

            {{-- Product info --}}
            <div class="review-block">
              <div class="review-block-header">
                <span>Items</span>
              </div>
              @foreach($cartItems as $item)
                <div class="review-item-row">
                  <span class="review-item-name">{{ $item->product->product_name }} ×{{ $item->quantity }}</span>
                  <span class="review-item-price">₱{{ number_format($item->product->price * $item->quantity, 2) }}</span>
                </div>
              @endforeach
            </div>

            {{-- Delivery --}}
            <div class="review-block">
              <div class="review-block-header">
                <span>Delivery</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('review','delivery')">Edit</button>
              </div>
              <div class="review-value" id="review-delivery-value">Pick-up at Shop · Free</div>
              <div class="review-value-sub" id="review-delivery-address" style="display:none;"></div>
            </div>

            {{-- Payment Type --}}
            <div class="review-block">
              <div class="review-block-header">
                <span>Payment Type</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('review','paytype')">Edit</button>
              </div>
              <div class="review-value" id="review-paytype-value">Full Payment</div>
            </div>

            {{-- Payment Method --}}
            <div class="review-block">
              <div class="review-block-header">
                <span>Payment Method</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('review','paymethod')">Edit</button>
              </div>
              <div class="review-value" id="review-paymethod-value">Card (Stripe)</div>
            </div>

            {{-- Instructions --}}
            <div class="review-block">
              <div class="review-block-header">
                <span>Special Instructions</span>
                <button type="button" class="review-edit-btn" onclick="goToScreen('review','notes')">Edit</button>
              </div>
              <div class="review-value" id="review-notes-value">None added</div>
            </div>

            {{-- Cost breakdown --}}
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
            <button type="submit" class="checkout-screen-continue checkout-place-order-btn">Place Order →</button>
          </div>
        </div>

      </div>

      {{-- RIGHT COLUMN: Desktop sidebar (unchanged, has its own Place Order for desktop) --}}
      <div>
        <div class="checkout-summary">
          <div class="checkout-summary-title">Your Order</div>

          <div class="summary-items">
            @foreach($cartItems as $item)
              <div class="summary-item">
                <div class="summary-item-img">
                  <img src="{{ $item->product->image_url }}" alt="{{ $item->product->product_name }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
                <div class="summary-item-info">
                  <div class="summary-item-name">{{ $item->product->product_name }}</div>
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
              <span>Delivery fee</span>
              <strong id="delivery-fee-amount">Free (Pick-up)</strong>
            </div>
          </div>

          <div class="summary-total-row">
            <span>Total</span>
            <span id="order-total">₱{{ number_format($subtotal, 2) }}</span>
          </div>

          <button type="submit" class="btn-place-order" id="place-order-btn">Place Order →</button>
          <a href="{{ route('cart.index') }}" class="checkout-back-link">← Back to Cart</a>

          <div class="checkout-secure">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            Secured by Stripe · Your data is safe
          </div>
        </div>
      </div>

    </div>

  </form>

</div>
@endsection

@push('scripts')
<script>
const SUBTOTAL = {{ $subtotal }};
const DELIVERY_FEE = 500;
let currentDelivery = 'pickup';

function selectDelivery(type) {
  currentDelivery = type;
  document.getElementById('label-pickup').classList.toggle('selected', type === 'pickup');
  document.getElementById('label-delivery').classList.toggle('selected', type === 'delivery');

  const addressFields = document.getElementById('delivery-address-fields');
  addressFields.classList.toggle('visible', type === 'delivery');
  const addressInput = addressFields.querySelector('input[name="delivery_address"]');
  if (addressInput) addressInput.required = (type === 'delivery');

  updateTotal();
}

function selectPaymentType(type) {
  document.getElementById('label-full').classList.toggle('selected', type === 'full');
  document.getElementById('label-installment').classList.toggle('selected', type === 'installment');
  document.getElementById('installment-period-wrap').classList.toggle('visible', type === 'installment');
}

function selectPaymentMethod(method) {
  ['stripe', 'onsite'].forEach(m => document.getElementById('label-' + m)?.classList.remove('selected'));
  document.getElementById('label-' + method)?.classList.add('selected');
}

function selectInstallmentChip(input) {
  document.querySelectorAll('.installment-chip').forEach(c => c.classList.remove('selected'));
  input.closest('.installment-chip').classList.add('selected');
}

function updateTotal() {
  const fee   = currentDelivery === 'delivery' ? DELIVERY_FEE : 0;
  const total = SUBTOTAL + fee;
  const formatted = '₱' + total.toLocaleString('en-PH', { minimumFractionDigits: 2 });

  const feeEl = document.getElementById('delivery-fee-amount');
  if (feeEl) feeEl.textContent = fee === 0 ? 'Free (Pick-up)' : '₱' + fee.toLocaleString();

  const totalEl = document.getElementById('order-total');
  if (totalEl) totalEl.textContent = formatted;

  const mFeeEl = document.getElementById('mobile-delivery-fee');
  if (mFeeEl) mFeeEl.textContent = fee === 0 ? 'Free' : '₱' + fee.toLocaleString();

  const mTotalEl = document.getElementById('mobile-order-total');
  if (mTotalEl) mTotalEl.textContent = formatted;

  const rFeeEl = document.getElementById('review-delivery-fee');
  if (rFeeEl) rFeeEl.textContent = fee === 0 ? 'Free' : '₱' + fee.toLocaleString();

  const rTotalEl = document.getElementById('review-total');
  if (rTotalEl) rTotalEl.textContent = formatted;
}

/* ── WIZARD NAVIGATION ──
   from = screen currently open (or null if on the main page)
   to   = screen to open next (or null to return to main page) ── */
function goToScreen(from, to) {
  if (from) {
    document.getElementById('screen-' + from)?.classList.remove('open');
  }
  if (to) {
    document.getElementById('screen-' + to)?.classList.add('open');
    document.body.style.overflow = 'hidden';
  } else {
    document.body.style.overflow = '';
  }
}

function goToReview() {
  document.getElementById('screen-notes')?.classList.remove('open');
  updateReviewScreen();
  document.getElementById('screen-review')?.classList.add('open');
}

/* Populate the final review screen with everything selected so far */
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
      const addr = document.getElementById('input-delivery-address')?.value || '';
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
      const period = document.querySelector('input[name="installment_period"]:checked');
      payTypeEl.textContent = 'Installment Plan · ' + (period ? period.value : '');
    }
  }

  const payMethodChecked = document.querySelector('input[name="payment_method"]:checked');
  const payMethodEl = document.getElementById('review-paymethod-value');
  if (payMethodChecked) {
    const labels = { stripe: 'Credit / Debit Card (Stripe)', onsite: 'Cash on Pickup / Delivery' };
    payMethodEl.textContent = labels[payMethodChecked.value] || '';
  }

  const notesInput = document.getElementById('customization-note-input');
  const notesEl = document.getElementById('review-notes-value');
  const val = notesInput?.value.trim();
  notesEl.textContent = val ? val : 'None added';
}

document.addEventListener('DOMContentLoaded', () => {
  updateTotal();

  document.addEventListener('keydown', (e) => {
    // Escape closes whichever screen is open, back to main
    const openScreen = document.querySelector('.checkout-screen.open');
    if (e.key === 'Escape' && openScreen) {
      openScreen.classList.remove('open');
      document.body.style.overflow = '';
    }
  });
});
</script>
@endpush