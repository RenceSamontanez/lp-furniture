@extends('layouts.app')

@section('title', 'Catalog — LP Furniture')

@section('content')

<div class="page-header">
  <h1>Our <em style="font-family:var(--font-display);font-style:italic;color:var(--gold);">Catalog</em></h1>
  <p>All pieces handcrafted from quality Philippine wood. Custom sizes available.</p>
</div>

<div class="section" style="padding-top:32px;">

  <!-- Category filter pills -->
  <div class="filter-bar">
    <a href="{{ route('catalog') }}" class="filter-chip {{ !request('category') ? 'active' : '' }}">All</a>
    @foreach($categories as $cat)
      <a href="{{ route('catalog', ['category' => $cat]) }}" class="filter-chip {{ request('category') === $cat ? 'active' : '' }}">{{ $cat }}</a>
    @endforeach
  </div>

  <!-- Search + price filter -->
  <form method="GET" action="{{ route('catalog') }}" class="catalog-filters">
    @if(request('category'))
      <input type="hidden" name="category" value="{{ request('category') }}">
    @endif
    @if(!empty($type))
      <input type="hidden" name="type" value="{{ $type }}">
    @endif
    <input type="text" name="search" class="form-input" placeholder="Search furniture..." value="{{ request('search') }}" style="max-width:280px;">
    <input type="number" name="max_price" class="form-input" placeholder="Max price (₱)" value="{{ request('max_price') }}" style="max-width:180px;">
    <button type="submit" class="btn btn-outline">Filter</button>
    @if(request('search') || request('max_price'))
      <a href="{{ route('catalog', request('category') ? ['category' => request('category')] : []) }}" class="link-text" style="align-self:center;">Clear</a>
    @endif
  </form>

  @if(!empty($type))
    <p style="margin-top:16px;font-size:14px;">Showing {{ config('luma.types.' . $type . '.en') }} &middot;
      <a href="{{ route('catalog', array_filter(['category' => request('category'), 'max_price' => request('max_price')])) }}" class="link-text">Show all</a></p>
  @endif

  <div class="product-grid" style="margin-top:24px;">
    @forelse($products as $product)
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
        <h3>No products found</h3>
        <p>Try a different category or search term.</p>
      </div>
    @endforelse
  </div>
</div>

<!-- QUICK VIEW MODAL -->
<div class="modal-overlay" id="quickViewModal" onclick="closeQuickViewOutside(event)">
  <div class="modal quick-view-modal" id="quickViewContent"></div>
</div>

@endsection