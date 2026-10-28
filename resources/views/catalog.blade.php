@extends('layouts.app')

@section('title', 'Catalog — LP Furniture')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: 1 }}"/>
<noscript><style>.cx-qv,.quick-view-btn{display:none}</style></noscript>
@endpush

@section('content')
@php
  // Every link keeps the other filters (category, search, max price, sort, Luma type), so they work together.
  $keep = ['category' => request('category'), 'search' => request('search'), 'max_price' => request('max_price'),
           'sort' => $sort !== 'newest' ? $sort : null, 'type' => $type];
  $url = fn (array $over = []) => route('catalog', array_filter(array_merge($keep, $over), fn ($v) => filled($v)));
  $filtered = filled(request('category')) || filled(request('search')) || filled(request('max_price')) || !empty($type);
  $placeholder = asset('images/products/placeholder.jpg');
  $shapes = ['a', 'b', 'c'];
@endphp

<div class="cx-shell" data-nav="light">
  <aside class="cx-idx" aria-label="Catalog filters">
    <h1>Catalog</h1>
    <p class="cx-lede">Ready-made pieces from our workshop in San Juan, Batangas.</p>

    <ul class="cx-cats">
      <li><a href="{{ $url(['category' => null]) }}" @if(!request('category')) aria-current="true" @endif>All <small>{{ $totalCount }}</small></a></li>
      @foreach($categories as $cat)
        <li><a href="{{ $url(['category' => $cat]) }}" @if(request('category') === $cat) aria-current="true" @endif>{{ title_case($cat) }} <small>{{ $categoryCounts[$cat] }}</small></a></li>
      @endforeach
    </ul>

    <form method="GET" action="{{ route('catalog') }}" class="cx-tools">
      @if(request('category'))<input type="hidden" name="category" value="{{ request('category') }}">@endif
      @if(!empty($type))<input type="hidden" name="type" value="{{ $type }}">@endif
      <label class="cx-field"><span>Search</span>
        <input type="search" name="search" placeholder="Search furniture" value="{{ request('search') }}" autocomplete="off"></label>
      <label class="cx-field"><span>Highest Price (₱)</span>
        <input type="number" name="max_price" min="0" step="any" inputmode="decimal" placeholder="Any" value="{{ request('max_price') }}"></label>
      <label class="cx-field"><span>Sort by</span>
        <select name="sort">
          <option value="newest" @selected($sort === 'newest')>Newest first</option>
          <option value="price_asc" @selected($sort === 'price_asc')>Price, low to high</option>
          <option value="price_desc" @selected($sort === 'price_desc')>Price, high to low</option>
        </select></label>
      <div class="cx-actions">
        <button type="submit" class="lp-btn lp-btn--primary">Apply</button>
        @if($filtered || $sort !== 'newest')
          <a href="{{ route('catalog') }}" class="lp-link">Clear Filters</a>
        @endif
      </div>
    </form>

    <div class="cx-custom"><b>Need a different size?</b>We build to your measurements.<br>
      <a href="{{ route('design.index') }}">Request a custom piece</a></div>
  </aside>

  <section class="cx-main" aria-label="Products">
    @if(!empty($type))
      <p class="cx-note">Showing {{ config('luma.types.' . $type . '.en') }} &middot;
        <a href="{{ $url(['type' => null]) }}" class="lp-link">Show all</a></p>
    @endif

    @if($products->isNotEmpty())
      <p class="cx-count" aria-live="polite">{{ $products->count() === 1 ? '1 piece' : $products->count() . ' pieces' }}</p>
    @endif

    <div class="cx-floor">
      @forelse($products as $i => $product)
        @php
          $img = $product->image_url;
          $badge = $badges[$product->id] ?? null;
        @endphp
        <article class="cx-card {{ $shapes[$i % 3] }}">
          <div class="cx-pic">
            <img src="{{ $img }}" alt="{{ $product->product_name }}" @if($i >= 6) loading="lazy" @endif decoding="async"
                 onerror="this.onerror=null;this.src='{{ $placeholder }}';">
            @if($badge)<span class="cx-badge">{{ $badge }}</span>@endif
            <button type="button" class="cx-qv" data-quick-view="{{ $product->id }}" data-name="{{ $product->product_name }}"
                    aria-label="Quick view: {{ $product->product_name }}">Quick View</button>
          </div>
          <div class="cx-cap">
            <h3><a href="{{ route('product.show', $product->id) }}">{{ title_case($product->product_name) }}</a></h3>
            <p class="cx-k">{{ title_case($product->category) }}</p>
            <p class="cx-cost"><span class="cx-now">{{ $product->on_sale ? $product->formatted_sale_price : $product->formatted_price }}</span>@if($product->on_sale)<s class="cx-was">{{ $product->formatted_price }}</s>@endif</p>
            @if(filled($product->description))<p class="cx-d">{{ $product->description }}</p>@endif
            <a href="{{ route('product.show', $product->id) }}" class="lp-btn lp-btn--secondary cx-vd">View Details</a>
          </div>
        </article>
      @empty
        <div class="cx-empty">
          @if($filtered)
            <b>Nothing matches these filters</b>
            No pieces fit this category, search or price. Clear the filters to see everything we have.<br>
            <a href="{{ route('catalog') }}" class="lp-btn lp-btn--primary">Clear Filters</a>
          @else
            <b>No pieces listed yet</b>
            Please check back soon, or ask us to build one to your size.
          @endif
        </div>
      @endforelse
    </div>
  </section>
</div>

@include('partials.quick-view')
@endsection

