@extends('layouts.app')

@section('title', 'Catalog — LP Furniture')

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/catalog.css') }}?v={{ @filemtime(public_path('assets/css/catalog.css')) ?: 1 }}"/>
<noscript><style>.lp-pcard__qv{display:none}</style></noscript>
@endpush

@section('content')
@php
  // Every link keeps the other filters (category, search, max price, sort, Luma type), so they work together.
  $keep = ['category' => request('category'), 'search' => request('search'), 'max_price' => request('max_price'),
           'sort' => $sort !== 'newest' ? $sort : null, 'type' => $type];
  $url = fn (array $over = []) => route('catalog', array_filter(array_merge($keep, $over), fn ($v) => filled($v)));
  $filtered = filled(request('category')) || filled(request('search')) || filled(request('max_price')) || !empty($type);
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

    <div class="cx-floor lp-pgrid">
      @forelse($products as $i => $product)
        @php
          $cBadges = [];
          if ($product->on_sale) $cBadges[] = ['text' => '-' . $product->discount_percent . '%', 'kind' => 'sale'];
          if ($badge = $badges[$product->id] ?? null) $cBadges[] = ['text' => title_case($badge), 'kind' => 'info'];
        @endphp
        <x-product-card :product="$product" :badges="$cBadges" :lazy="$i >= 6" />
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

