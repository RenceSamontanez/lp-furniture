@extends('layouts.app')

@section('title', $product->product_name . ' — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/product.css') }}"/>
@endpush

@section('content')
<div class="product-detail-wrap">

  {{-- Breadcrumb --}}
  <div class="detail-breadcrumb">
    <a href="{{ route('home') }}">Home</a>
    <span>›</span>
    <a href="{{ route('catalog') }}">Catalog</a>
    <span>›</span>
    @if($product->category)
      <a href="{{ route('catalog', ['category' => $product->category]) }}">{{ $product->category }}</a>
      <span>›</span>
    @endif
    <strong>{{ $product->product_name }}</strong>
  </div>

  {{-- Main Layout --}}
  <div class="detail-layout">

    {{-- LEFT: Image Gallery --}}
    <div class="detail-gallery">

      {{-- Main Image --}}
      <div class="detail-main-img">
        @php
          $images = $product->images;
          $primaryImg = $images->firstWhere('is_primary', true) ?? $images->first();
          $mainImgUrl = $primaryImg
            ? asset('images/products/' . $primaryImg->image_path)
            : ($product->product_image
                ? asset('images/products/' . $product->product_image)
                : asset('images/products/placeholder.jpg'));
        @endphp

        <img
          src="{{ $mainImgUrl }}"
          alt="{{ $product->product_name }}"
          id="detail-main-img"
          onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"
        />

        @if($product->is_featured)
          <div class="detail-main-badge">★ Featured</div>
        @endif

        @if($product->on_sale)
          <div class="detail-main-badge sale">-{{ $product->discount_percent }}% OFF</div>
        @endif
      </div>

      {{-- Thumbnail Strip --}}
      @if($images->count() > 1)
        <div class="detail-thumbs">
          @foreach($images as $i => $img)
            <div
              class="detail-thumb {{ $i === 0 ? 'active' : '' }}"
              onclick="switchDetailImage('{{ asset('images/products/' . $img->image_path) }}', this)"
            >
              <img
                src="{{ asset('images/products/' . $img->image_path) }}"
                alt="{{ $img->label ?? $product->product_name }}"
                onerror="this.closest('.detail-thumb').style.display='none';"
              />
            </div>
          @endforeach
        </div>
      @endif

    </div>

    {{-- RIGHT: Product Info --}}
    <div class="detail-info">

      <div class="detail-category">{{ $product->category }}</div>
      <h1 class="detail-name">{{ $product->product_name }}</h1>

      {{-- Price --}}
      <div class="detail-price-block">
        @if($product->on_sale)
          <span class="detail-price-old">{{ $product->formatted_price }}</span>
          <span class="detail-price-now">{{ $product->formatted_sale_price }}</span>
          <span class="detail-price-badge">-{{ $product->discount_percent }}%</span>
        @else
          <span class="detail-price-now">{{ $product->formatted_price }}</span>
        @endif
      </div>

      <div class="detail-installment">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="2" y="5" width="20" height="14" rx="2"/>
          <path d="M2 10h20"/>
        </svg>
        As low as ₱{{ number_format($product->price / 6, 0) }}/month on 6-month installment
      </div>

      {{-- Description --}}
      <p class="detail-description">{{ $product->description }}</p>

      {{-- Specs --}}
      @if($product->material || $product->finish || $product->dimensions)
        <div class="detail-specs">
          @if($product->material)
            <div class="detail-spec-row">
              <span class="detail-spec-label">Material</span>
              <span class="detail-spec-value">{{ $product->material }}</span>
            </div>
          @endif
          @if($product->finish)
            <div class="detail-spec-row">
              <span class="detail-spec-label">Finish</span>
              <span class="detail-spec-value">{{ $product->finish }}</span>
            </div>
          @endif
          @if($product->dimensions)
            <div class="detail-spec-row">
              <span class="detail-spec-label">Dimensions</span>
              <span class="detail-spec-value">{{ $product->dimensions }}</span>
            </div>
          @endif
          <div class="detail-spec-row">
            <span class="detail-spec-label">Customizable</span>
            <span class="detail-spec-value">
              {{ $product->is_customizable ? 'Yes — custom sizes available' : 'Standard size only' }}
            </span>
          </div>
        </div>
      @endif

      {{-- Production availability status --}}
      <div class="detail-stock">
        @if($product->stock_quantity > 10)
          <div class="stock-dot in-stock"></div>
          <span style="color:var(--success);">Available to Order</span>
          <span style="color:var(--text-muted);">· {{ $product->stock_quantity }} available to build</span>
        @elseif($product->stock_quantity > 0)
          <div class="stock-dot low-stock"></div>
          <span style="color:#E8A020;">Limited Availability This Month</span>
          <span style="color:var(--text-muted);">· Only {{ $product->stock_quantity }} left to build this month</span>
        @else
          <div class="stock-dot out"></div>
          <span style="color:var(--danger);">Currently at Capacity</span>
        @endif
      </div>

      {{-- Made-to-order explanation --}}
      <div class="detail-notice detail-notice--info">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/>
        </svg>
        <span>
          <strong>Handcrafted to Order.</strong> Every piece is built specifically for you after your order is placed, shaped from solid Philippine wood by hand — not pulled off a warehouse shelf.
        </span>
      </div>

      {{-- Auth / Validation notices --}}
      @guest
        <div class="detail-notice">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 8v4M12 16h.01"/>
          </svg>
          <span>
            <a href="{{ route('login') }}">Sign in</a> or
            <a href="{{ route('register') }}">create an account</a>
            to add items to your cart and place orders.
          </span>
        </div>
      @else
        @if(!Auth::user()->is_validated)
          <div class="detail-notice">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <span>
              <a href="{{ route('validation.show') }}">Verify your identity</a>
              to place orders. You can still browse and add items to your cart.
            </span>
          </div>
        @endif
      @endguest

      {{-- Quantity selector --}}
      @if($product->stock_quantity > 0)
        <div class="detail-qty-row">
          <span class="detail-qty-label">Quantity</span>
          <div class="qty-control">
            <button class="qty-btn" onclick="changeQty(-1)" type="button">−</button>
            <input class="qty-display" type="number" id="qty-input" value="1" min="1" max="{{ $product->stock_quantity }}" readonly/>
            <button class="qty-btn" onclick="changeQty(1)" type="button">+</button>
          </div>
        </div>
      @endif

      {{-- Customization note --}}
      @if($product->is_customizable)
        <div class="detail-customize">
          <label class="detail-customize-label">Customization Note (optional)</label>
          <textarea
            id="customization-note"
            placeholder="Any special requests? Size modifications, wood type preference, color stain, etc."
          ></textarea>
        </div>
      @endif

      {{-- Action buttons --}}
      @if($product->product_status === 'available' && $product->stock_quantity > 0)
        <div class="detail-actions">
          @auth
            <form method="POST" action="{{ route('cart.add', $product->id) }}" id="cart-form">
              @csrf
              <input type="hidden" name="quantity" id="cart-qty" value="1"/>
              <input type="hidden" name="note" id="cart-note" value=""/>
              <button
                type="button"
                class="btn-add-cart"
                onclick="addToCartFromDetail()"
                style="width:100%;"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
                  <line x1="3" y1="6" x2="21" y2="6"/>
                  <path d="M16 10a4 4 0 01-8 0"/>
                </svg>
                Add to Cart
              </button>
            </form>

            {{-- Order Now: isolated buy-now flow, does NOT touch the cart --}}
            <form method="POST" action="{{ route('cart.orderNow', $product->id) }}" id="order-now-form">
              @csrf
              <input type="hidden" name="quantity" id="order-qty" value="1"/>
              <input type="hidden" name="note" id="order-note" value=""/>
              <button type="button" class="btn-order-now" onclick="orderNowFromDetail()" style="width:100%;border:none;cursor:pointer;">
                Order Now →
              </button>
            </form>

          @endauth
        </div>
      @else
        <div class="detail-notice" style="margin-bottom:0;">
          This product is currently unavailable. Please check back soon or
          <a href="{{ route('chatbot.respond') }}">contact us</a> for a custom order.
        </div>
      @endif

      {{-- Trust badges --}}
      <div class="detail-trust">
        <div class="trust-item">
          <div class="trust-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
          </div>
          <span class="trust-label">Secure Payments</span>
        </div>
        <div class="trust-item">
          <div class="trust-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
              <polyline points="9,22 9,12 15,12 15,22"/>
            </svg>
          </div>
          <span class="trust-label">Local Pickup Available</span>
        </div>
        <div class="trust-item">
          <div class="trust-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
            </svg>
          </div>
          <span class="trust-label">Handcrafted Quality</span>
        </div>
        <div class="trust-item">
          <div class="trust-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
          </div>
          <span class="trust-label">6-Month Installment</span>
        </div>
      </div>

    </div>
  </div>

  {{-- Related Products --}}
  @if($relatedProducts->count() > 0)
    <div class="detail-related">
      <h2 class="detail-related-title">More <span>{{ $product->category }}</span> Pieces</h2>
      <div class="product-grid">
        @foreach($relatedProducts as $related)
          <div class="product-card-v2">
            <div class="product-card-v2-img"
                 onclick="window.location='{{ route('product.show', $related->id) }}'">
              <img
                src="{{ $related->image_url }}"
                alt="{{ $related->product_name }}"
                loading="lazy"
                onerror="this.onerror=null;this.src='{{ asset('images/products/placeholder.jpg') }}';"
              />
              <button class="quick-view-btn"
                      onclick="event.stopPropagation();openQuickView({{ $related->id }})">
                Quick View
              </button>
            </div>
            <div class="product-card-v2-info">
              <div class="product-cat">{{ $related->category }}</div>
              <a href="{{ route('product.show', $related->id) }}" class="product-name-link">
                <div class="product-name">{{ $related->product_name }}</div>
              </a>
              <div class="product-footer" style="margin-top:10px;">
                <div class="product-price">
                  <span class="price-now">{{ $related->formatted_price }}</span>
                </div>
                <a href="{{ route('product.show', $related->id) }}" class="btn-view-details">
                  View Details
                </a>
              </div>
            </div>
          </div>
        @endforeach
      </div>
    </div>

    {{-- Quick View Modal for related products --}}
    <div class="modal-overlay" id="quickViewModal" onclick="closeQuickViewOutside(event)">
      <div class="modal quick-view-modal" id="quickViewContent"></div>
    </div>
  @endif

</div>
@endsection

@push('scripts')
<script>
/* ── IMAGE GALLERY ── */
function switchDetailImage(url, thumbEl) {
  const mainImg = document.getElementById('detail-main-img');
  if (mainImg) {
    mainImg.style.opacity = '0';
    setTimeout(() => {
      mainImg.src = url;
      mainImg.style.opacity = '1';
    }, 200);
  }
  document.querySelectorAll('.detail-thumb').forEach(t => t.classList.remove('active'));
  thumbEl.classList.add('active');
}

/* ── QUANTITY CONTROL ── */
function changeQty(direction) {
  const input = document.getElementById('qty-input');
  if (!input) return;
  const max = parseInt(input.max) || 99;
  const val = parseInt(input.value) || 1;
  const next = Math.min(max, Math.max(1, val + direction));
  input.value = next;
  // Keep hidden cart qty in sync
  const cartQty = document.getElementById('cart-qty');
  if (cartQty) cartQty.value = next;
}

/* ── ADD TO CART ── */
function addToCartFromDetail() {
  const qty  = document.getElementById('qty-input')?.value || 1;
  const note = document.getElementById('customization-note')?.value || '';
  const cartQtyInput  = document.getElementById('cart-qty');
  const cartNoteInput = document.getElementById('cart-note');
  if (cartQtyInput)  cartQtyInput.value  = qty;
  if (cartNoteInput) cartNoteInput.value = note;
  document.getElementById('cart-form').submit();
}

/* ── ORDER NOW (direct, isolated from cart) ── */
function orderNowFromDetail() {
  const qty  = document.getElementById('qty-input')?.value || 1;
  const note = document.getElementById('customization-note')?.value || '';
  document.getElementById('order-qty').value  = qty;
  document.getElementById('order-note').value = note;
  document.getElementById('order-now-form').submit();
}
</script>
@endpush