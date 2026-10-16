@extends('layouts.app')

@section('title', 'LP Furniture — Handcrafted Wood Furniture')
@section('hide_site_footer', '1')

@section('content')

<!-- ═══ HERO ═══ -->
<div class="hero-v2">
  <div class="hero-v2-bg-fallback"></div>

  <div class="hero-v2-bg-slides">
    <div class="hero-v2-slide active" style="background-image:url('{{ asset('images/hero/hero-bg.jpg') }}');"></div>
    @if(file_exists(public_path('images/hero/hero-bg-2.jpg')))
      <div class="hero-v2-slide" style="background-image:url('{{ asset('images/hero/hero-bg-2.jpg') }}');"></div>
    @endif
    @if(file_exists(public_path('images/hero/hero-bg-3.jpg')))
      <div class="hero-v2-slide" style="background-image:url('{{ asset('images/hero/hero-bg-3.jpg') }}');"></div>
    @endif
  </div>

  <div class="hero-v2-overlay"></div>

  <div class="hero-v2-content">
    <p class="hero-v2-tag">Handcrafted Since 2020 · San Juan, Batangas</p>
    <h1 class="hero-v2-title">Crafting Comfort<br/>and <em>Elegance</em> for Every Home</h1>
    <p class="hero-v2-sub">Premium custom wood furniture, handcrafted by skilled artisans. From your living room to your office — built to last, made for you.</p>

    <div class="hero-v2-actions">
      <a href="{{ route('catalog') }}" class="btn-hero gold">Shop Now</a>
      @auth
        <a href="{{ route('design.index') }}" class="btn-hero outline-light">Customize Furniture</a>
      @else
        <a href="{{ route('login') }}" class="btn-hero outline-light">Customize Furniture</a>
      @endauth
      <a href="#footer-section" class="btn-hero text-light">Contact Us</a>
    </div>
  </div>
</div>

<!-- ═══ FEATURED FURNITURE COLLECTION ═══ -->
<div class="section">
  <div class="section-head">
    <div>
      <h2 class="section-title">Featured <span>Collection</span></h2>
      <p class="section-sub">Our most loved pieces, handpicked for quality and design.</p>
    </div>
    <a href="{{ route('catalog') }}" class="section-link">View all →</a>
  </div>

  <!-- Category filter pills -->
  @if($categories->count() > 0)
  <div class="filter-bar">
    <a href="{{ route('catalog') }}" class="filter-chip active">All</a>
    @foreach($categories as $cat)
      <a href="{{ route('catalog', ['category' => $cat]) }}" class="filter-chip">{{ $cat }}</a>
    @endforeach
  </div>
  @endif

  <div class="product-grid">
    @forelse($featuredProducts as $product)
      <div class="product-card-v2">
        <div class="product-card-v2-img" onclick="openQuickView({{ $product->id }})">
          <img
            src="{{ $product->image_url }}"
            alt="{{ $product->product_name }} — {{ $product->category }} furniture by LP Furniture"
            loading="lazy"
            onerror="this.onerror=null;this.src='{{ asset('images/products/placeholder.jpg') }}';"
          />


          @if($product->on_sale)
            <span class="badge-sale">-{{ $product->discount_percent }}%</span>
          @endif

          <button class="quick-view-btn" onclick="event.stopPropagation();openQuickView({{ $product->id }})">
            Quick View
          </button>
        </div>

        <div class="product-card-v2-info">
          <div class="product-cat">{{ $product->category }}</div>
          <a href="{{ route('product.show', $product->id) }}" class="product-name-link">
            <div class="product-name">{{ $product->product_name }}</div>
          </a>
          <div class="product-desc">{{ Str::limit($product->description, 65) }}</div>

          <div class="product-footer">
            <div class="product-price">
              @if($product->on_sale)
                <span class="price-old">{{ $product->formatted_price }}</span>
                <span class="price-now">{{ $product->formatted_sale_price }}</span>
              @else
                <span class="price-now">{{ $product->formatted_price }}</span>
              @endif
            </div>
            <a href="{{ route('product.show', $product->id) }}" class="btn-view-details">View Details</a>
          </div>
        </div>
      </div>
    @empty
      <div class="empty-state">
        <div class="emoji">🪑</div>
        <h3>No featured products yet</h3>
        <p>Check back soon!</p>
      </div>
    @endforelse
  </div>
</div>

<!-- ═══ BEST SELLING PRODUCTS CAROUSEL ═══ -->
<div class="section bestsellers-section">
  <div class="section-head">
    <div>
      <h2 class="section-title">Trending <span>This Month</span></h2>
      <p class="section-sub">Our most ordered pieces — trusted by hundreds of happy customers.</p>
    </div>
    <a href="{{ route('catalog') }}" class="section-link">Shop all →</a>
  </div>

  <div class="carousel-wrapper">
    <button class="carousel-btn carousel-btn-prev" onclick="carouselScroll('bestsellers', -1)" aria-label="Previous">&#8249;</button>

    <div class="carousel-track" id="bestsellers">
      @foreach($bestSellers as $product)
        <div class="carousel-card">
          <div class="carousel-card-img" onclick="openQuickView({{ $product->id }})">
            <img
              src="{{ $product->image_url }}"
              alt="{{ $product->product_name }}"
              loading="lazy"
              onerror="this.onerror=null;this.src='{{ asset('images/products/placeholder.jpg') }}';"
            />

            @if($product->order_items_count > 0)
              <span class="badge-ordered">🔥 {{ $product->order_items_count }} Ordered</span>
            @else
              <span class="badge-new">✨ New Arrival</span>
            @endif

            @if($product->on_sale)
              <span class="badge-sale">-{{ $product->discount_percent }}%</span>
            @endif

            <button class="quick-view-btn" onclick="event.stopPropagation();openQuickView({{ $product->id }})">
              Quick View
            </button>
          </div>

          <div class="carousel-card-info">
            <div class="product-cat">{{ $product->category }}</div>
            <a href="{{ route('product.show', $product->id) }}" class="product-name-link">
              <div class="product-name">{{ $product->product_name }}</div>
            </a>
            <div class="product-footer" style="margin-top:12px;">
              <div class="product-price">
                @if($product->on_sale)
                  <span class="price-old">{{ $product->formatted_price }}</span>
                  <span class="price-now">{{ $product->formatted_sale_price }}</span>
                @else
                  <span class="price-now">{{ $product->formatted_price }}</span>
                @endif
              </div>
              <a href="{{ route('product.show', $product->id) }}" class="btn-view-details">Order Now</a>
            </div>
          </div>
        </div>
      @endforeach
    </div>

    <button class="carousel-btn carousel-btn-next" onclick="carouselScroll('bestsellers', 1)" aria-label="Next">&#8250;</button>
  </div>

  <!-- Dot indicators -->
  <div class="carousel-dots" id="bestsellers-dots"></div>
</div>


<!-- QUICK VIEW MODAL -->
<div class="modal-overlay" id="quickViewModal" onclick="closeQuickViewOutside(event)">
  <div class="modal quick-view-modal" id="quickViewContent">
    <!-- Filled dynamically by JS -->
  </div>
</div>



<!-- ═══ SECTION 4: INTERIOR INSPIRATION GALLERY ═══ -->
@if(count($galleryImages) > 0)
<div class="section gallery-section" id="gallery-section">

  <!-- Section header -->
  <div class="gallery-header">
    <div class="gallery-header-text">
      <p class="gallery-eyebrow">Interior Inspiration</p>
      <h2 class="section-title">See Our Furniture<br/><span>In Real Homes</span></h2>
      <p class="gallery-sub">Every piece finds its perfect place. From cozy living rooms to professional offices — our furniture tells a story in every space it enters.</p>
    </div>
    <a href="{{ route('catalog') }}" class="btn btn-outline">
      Shop These Looks →
    </a>
  </div>

  <!-- Gallery grid — up to 10 images, empty slots simply don't render -->
  <div class="gallery-grid" id="gallery-grid">
    @foreach($galleryImages as $loopIndex => $image)
      <div
        class="gallery-item {{ $loopIndex === 0 ? 'gallery-item-wide' : '' }} {{ $loopIndex === 3 ? 'gallery-item-tall' : '' }}"
        onclick="openLightbox({{ $loopIndex }})"
        data-parallax="{{ $loopIndex % 2 === 0 ? '-20' : '20' }}"
      >
        <img
          src="{{ $image['url'] }}"
          alt="{{ $image['label'] }} — LP Furniture San Juan Batangas"
          loading="lazy"
          onerror="this.closest('.gallery-item').style.display='none';"
        />

        {{-- Real Home badge — set is_real to true per image when ready --}}
        @if($image['is_real'])
          <span class="gallery-badge-real">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
            Real Home
          </span>
        @endif

        <!-- Hover overlay with story -->
        <div class="gallery-overlay">
          <div class="gallery-overlay-content">
            <p class="gallery-story">"{{ $image['story'] }}"</p>
            <div class="gallery-label-row">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                <polyline points="9,22 9,12 15,12 15,22"/>
              </svg>
              <span>{{ $image['label'] }}</span>
            </div>
          </div>
          <div class="gallery-zoom-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <circle cx="11" cy="11" r="8"/>
              <path d="M21 21l-4.35-4.35M11 8v6M8 11h6"/>
            </svg>
          </div>
        </div>
      </div>
    @endforeach
  </div>

</div>

@endif

<!-- LIGHTBOX -->
<div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightboxOutside(event)">
  <div class="lightbox-inner">
    <button class="lightbox-close" onclick="closeLightbox()">✕</button>
    <button class="lightbox-arrow lightbox-prev" onclick="lightboxSlide(-1)">&#8249;</button>
    <div class="lightbox-img-wrap">
      <img id="lightbox-img" src="" alt="Interior inspiration — LP Furniture"/>
      <div class="lightbox-label" id="lightbox-label"></div>
      <div class="lightbox-story" id="lightbox-story"></div>
    </div>
    <button class="lightbox-arrow lightbox-next" onclick="lightboxSlide(1)">&#8250;</button>
    <div class="lightbox-dots" id="lightbox-dots"></div>
  </div>
</div>


<!-- ═══ SECTION 5: HOW IT WORKS ═══ -->
<div class="section process-section">

  <div class="process-header">
    <p class="gallery-eyebrow">Simple and Transparent</p>
    <h2 class="section-title">How Ordering <span>Works</span></h2>
    <p class="process-sub">From browsing to delivery — here is exactly what to expect when you order from LP Furniture.</p>
  </div>

  <div class="process-track">

    <!-- Step 1 -->
    <div class="process-step" data-step="1">
      <div class="process-icon-wrap">
        <div class="process-icon">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="8" width="28" height="24" rx="3" stroke="currentColor" stroke-width="2"/>
            <path d="M6 14h28" stroke="currentColor" stroke-width="2"/>
            <rect x="11" y="19" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/>
            <path d="M21 21h8M21 24h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="process-connector"></div>
      </div>
      <div class="process-content">
        <div class="process-num">01</div>
        <h3 class="process-title">Browse the Catalog</h3>
        <p class="process-desc">Explore our full range of handcrafted furniture. Filter by category, price, or material. Use the Quick View to preview details without leaving the page.</p>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="process-step" data-step="2">
      <div class="process-icon-wrap">
        <div class="process-icon">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M8 12h3l2 14h14l2-10H13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="18" cy="29" r="2" stroke="currentColor" stroke-width="1.5"/>
            <circle cx="26" cy="29" r="2" stroke="currentColor" stroke-width="1.5"/>
          </svg>
        </div>
        <div class="process-connector"></div>
      </div>
      <div class="process-content">
        <div class="process-num">02</div>
        <h3 class="process-title">Place Your Order</h3>
        <p class="process-desc">Add items to your cart and proceed to checkout. Choose between pickup or delivery, add customization notes, and select your preferred payment method.</p>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="process-step" data-step="3">
      <div class="process-icon-wrap">
        <div class="process-icon">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="8" y="18" width="10" height="14" rx="1" stroke="currentColor" stroke-width="2"/>
            <path d="M18 25h6a2 2 0 002-2v-3a2 2 0 00-2-2h-6" stroke="currentColor" stroke-width="2"/>
            <path d="M13 18v-4a7 7 0 0114 0v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M28 22h2a2 2 0 012 2v2a2 2 0 01-2 2h-2" stroke="currentColor" stroke-width="1.5"/>
          </svg>
        </div>
        <div class="process-connector"></div>
      </div>
      <div class="process-content">
        <div class="process-num">03</div>
        <h3 class="process-title">Confirm Payment</h3>
        <p class="process-desc">Pay securely via Stripe card payment, GCash QR, or cash on pickup. Installment plans up to 6 months are available and subject to admin approval.</p>
      </div>
    </div>

    <!-- Step 4 -->
    <div class="process-step" data-step="4">
      <div class="process-icon-wrap">
        <div class="process-icon">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 32V20l8-8 8 8v12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            <rect x="17" y="24" width="6" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/>
            <path d="M8 20l12-11 12 11" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            <path d="M20 10V8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
        <div class="process-connector"></div>
      </div>
      <div class="process-content">
        <div class="process-num">04</div>
        <h3 class="process-title">We Build It</h3>
        <p class="process-desc">Our skilled craftsmen get to work. You can track production status in real time — from pending to in production to ready. We update you at every stage.</p>
      </div>
    </div>

    <!-- Step 5 — no connector after last step -->
    <div class="process-step" data-step="5">
      <div class="process-icon-wrap">
        <div class="process-icon">
          <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="18" width="20" height="14" rx="2" stroke="currentColor" stroke-width="2"/>
            <path d="M26 22h2a6 6 0 010 12h-2" stroke="currentColor" stroke-width="2"/>
            <path d="M6 24h20" stroke="currentColor" stroke-width="1.5"/>
            <circle cx="12" cy="30" r="2" stroke="currentColor" stroke-width="1.5"/>
            <circle cx="20" cy="30" r="2" stroke="currentColor" stroke-width="1.5"/>
            <path d="M11 18v-3a9 9 0 0118 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </div>
      </div>
      <div class="process-content">
        <div class="process-num">05</div>
        <h3 class="process-title">Pickup or Delivery</h3>
        <p class="process-desc">Pick up your furniture at our shop in San Juan, Batangas for free, or choose door-to-door delivery. You get a notification the moment it is ready.</p>
      </div>
    </div>

  </div>
  
</div>
<!-- ═══ SECTION 6: ABOUT THE SHOP ═══ -->
<div class="about-section" id="about-section">

  <!-- Left side — image carousel -->
  <div class="about-images">
    <div class="about-img-main" id="about-img-main">
      @if(file_exists(public_path('images/about/workshop-1.jpg')))
        <img src="{{ asset('images/about/workshop-1.jpg') }}"
             alt="LP Furniture workshop — San Juan Batangas"
             id="about-main-img"
        />
      @else
        <div class="about-img-placeholder">
          <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" color="rgba(255,255,255,0.3)">
            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
            <polyline points="9,22 9,12 15,12 15,22"/>
          </svg>
          <p>Workshop photos coming soon</p>
        </div>
      @endif
      <div class="about-img-badge"><span>Since 2020</span></div>
    </div>

    <div class="about-thumbs" id="about-thumbs">
      @php
        $aboutPhotos = [
          ['file' => 'workshop-1.jpg', 'label' => 'Our Workshop'],
          ['file' => 'workshop-2.jpg', 'label' => 'Craftsmen at Work'],
          ['file' => 'customer-1.jpg', 'label' => 'Happy Customer'],
          ['file' => 'customer-2.jpg', 'label' => 'Finished Product'],
        ];
        $availablePhotos = array_filter($aboutPhotos, fn($p) =>
          file_exists(public_path('images/about/' . $p['file']))
        );
      @endphp
      @foreach(array_values($availablePhotos) as $i => $photo)
        <div class="about-thumb {{ $i === 0 ? 'active' : '' }}"
             onclick="switchAboutPhoto('{{ asset('images/about/' . $photo['file']) }}', this)">
          <img src="{{ asset('images/about/' . $photo['file']) }}" alt="{{ $photo['label'] }}"
               onerror="this.closest('.about-thumb').style.display='none';"/>
        </div>
      @endforeach
    </div>
  </div>

  <!-- Right side — condensed story -->
  <div class="about-content">

    <p class="gallery-eyebrow">Our Story</p>
    <h2 class="section-title" style="text-align:left;">
      Handcrafted with <span>Heart</span>
    </h2>

    <p class="about-text">
      Founded in 2020 in San Juan, Batangas, LP Furniture started as a small workshop with a simple belief — every Filipino home deserves furniture built to last. Every piece begins with premium Philippine hardwood, shaped by craftsmen who treat each joint and finish as a craft, not a shortcut.
    </p>

    <!-- Compact pillar row — icon + title only, no long descriptions -->
    <div class="about-pillars-compact">
      <div class="pillar-compact">
        <div class="pillar-compact-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div>
          <div class="pillar-compact-title">Mission</div>
          <div class="pillar-compact-text">Furniture built to outlast trends.</div>
        </div>
      </div>

      <div class="pillar-compact">
        <div class="pillar-compact-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 8v4l3 3"/>
          </svg>
        </div>
        <div>
          <div class="pillar-compact-title">Vision</div>
          <div class="pillar-compact-text">Batangas' most trusted furniture maker.</div>
        </div>
      </div>

      <div class="pillar-compact">
        <div class="pillar-compact-icon">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/>
          </svg>
        </div>
        <div>
          <div class="pillar-compact-title">Promise</div>
          <div class="pillar-compact-text">We listen first, build second.</div>
        </div>
      </div>
    </div>

    <!-- Stats row -->
    <div class="about-stats">
      <div class="about-stat">
        <div class="about-stat-num">9</div>
        <div class="about-stat-label">Skilled Workers</div>
      </div>
      <div class="about-stat">
        <div class="about-stat-num">500+</div>
        <div class="about-stat-label">Orders Delivered</div>
      </div>
      <div class="about-stat">
        <div class="about-stat-num">100%</div>
        <div class="about-stat-label">Philippine Wood</div>
      </div>
    </div>

    <a href="{{ route('catalog') }}" class="btn btn-gold about-cta">See Our Work →</a>

  </div>
</div>


<!-- ═══ SECTION 7: FOOTER ═══ -->
<footer id="footer-section" class="site-footer">
  <div class="footer-main">

    <!-- Left: Brand -->
    <div class="footer-col footer-brand-col">
      <div class="footer-brand">LP <span>Furniture</span></div>
      <p class="footer-desc">Handcrafted Philippine hardwood furniture, built to last. Based in San Juan, Batangas since 2020.</p>
      <div class="footer-social">
        <a href="{{ \App\Models\BusinessSetting::current()->facebook_url }}" class="footer-social-icon" aria-label="Facebook" target="_blank" rel="noopener noreferrer">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c5.05-.5 9-4.76 9-9.95z"/></svg>
        </a>
        <a href="#" class="footer-social-icon" aria-label="Instagram">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>
        </a>
        <a href="#" class="footer-social-icon" aria-label="TikTok">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M16.6 5.82s.51.5 0 0A4.278 4.278 0 0115.54 3h-3.09v12.4a2.59 2.59 0 01-2.59 2.5c-1.42 0-2.6-1.16-2.6-2.6 0-1.72 1.66-3.01 3.37-2.48V9.66c-3.45-.46-6.47 2.22-6.47 5.64 0 3.33 2.76 5.7 5.69 5.7 3.15 0 5.69-2.55 5.69-5.7V9.01a7.35 7.35 0 004.26 1.35V7.27s-1.86.09-3.2-1.45z"/></svg>
        </a>
      </div>
    </div>

    <!-- Middle: Quick links -->
    <div class="footer-col">
      <div class="footer-col-title">Quick Links</div>
      <a href="{{ route('home') }}" class="footer-link">Home</a>
      <a href="{{ route('catalog') }}" class="footer-link">Catalog</a>
      <a href="#about-section" class="footer-link">About Us</a>
      @auth
        <a href="{{ route('design.index') }}" class="footer-link">Custom Orders</a>
      @else
        <a href="{{ route('register') }}" class="footer-link">Custom Orders</a>
      @endauth
      <a href="{{ route('feedback.index') }}" class="footer-link">Reviews</a>
    </div>

    <!-- Middle 2: Customer -->
    <div class="footer-col">
      <div class="footer-col-title">My Account</div>
      @auth
        <a href="{{ route('orders.index') }}" class="footer-link">Track My Order</a>
        <a href="{{ route('cart.index') }}" class="footer-link">My Cart</a>
        <a href="{{ route('profile') }}" class="footer-link">My Profile</a>
        <a href="{{ route('validation.show') }}" class="footer-link">Verify Identity</a>
      @else
        <a href="{{ route('login') }}" class="footer-link">Sign In</a>
        <a href="{{ route('register') }}" class="footer-link">Create Account</a>
      @endauth
    </div>

    <!-- Right: Contact -->
    <div class="footer-col">
      <div class="footer-col-title">Visit / Contact</div>
      <div class="footer-contact-item">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span>San Juan, Batangas, Philippines</span>
      </div>
      <div class="footer-contact-item">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        <span>0917-456-7890</span>
      </div>
      <div class="footer-contact-item">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <span>lpfurnitureph@gmail.com</span>
      </div>
      <div class="footer-contact-item">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
        <span>Mon–Sat, 8AM–5PM</span>
      </div>
    </div>

  </div>

  <!-- Bottom bar -->
  <div class="footer-bottom">
    <p>&copy; {{ date('Y') }} LP Furniture. All rights reserved.</p>
    <div class="footer-bottom-links">
      <a href="#">Privacy Policy</a>
      <span class="footer-dot">·</span>
      <a href="{{ route('guide') }}">How to request a custom design</a>
      <span class="footer-dot">·</span>
      <a href="{{ route('terms') }}">Terms and Conditions</a>
    </div>
  </div>
</footer>










@endsection