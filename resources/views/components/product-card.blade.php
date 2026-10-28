{{--
  The one light product card (catalog, Featured Collection, Best Seller carousel, related pieces on the product page).
  Spec: docs/cards/landing-cards-v2.html. Styles: public/assets/css/product-card.css (loaded by layouts/app).
  The price lives ONLY in the hanging tag (a sale shows the sale price and the old price struck below it).
  $badges: list of ['text' => ..., 'kind' => 'sale'|'info'] shown top-left, first one on top (bottom-left on narrow cards).
  Extra attributes (for example class="carousel-card") go on the <article>.
--}}
@props(['product', 'badges' => [], 'cta' => 'View Details', 'lazy' => true])
@php
  $now = $product->on_sale ? $product->formatted_sale_price : $product->formatted_price;
  $priceLabel = 'Price ' . $now . ($product->on_sale ? ', was ' . $product->formatted_price : '');
  $placeholder = asset('images/products/placeholder.jpg');
  $url = route('product.show', $product->id);
@endphp
<article {{ $attributes->class(['lp-pcard']) }} data-product-id="{{ $product->id }}">
  <div class="lp-pcard__media" data-quick-view="{{ $product->id }}" data-name="{{ $product->product_name }}">
    <img src="{{ $product->image_url }}" alt="{{ $product->product_name }}" @if($lazy) loading="lazy" @endif decoding="async"
         onerror="this.onerror=null;this.src='{{ $placeholder }}';">
    @if(count($badges))
      <div class="lp-pcard__badges">
        @foreach($badges as $b)
          <span class="lp-pcard__badge lp-pcard__badge--{{ $b['kind'] ?? 'info' }}">{{ $b['text'] }}</span>
        @endforeach
      </div>
    @endif
    <p class="lp-pcard__tag" role="group" aria-label="{{ $priceLabel }}">
      <span class="lp-pcard__price" aria-hidden="true">{{ $now }}</span>
      @if($product->on_sale)<s class="lp-pcard__was" aria-hidden="true">{{ $product->formatted_price }}</s>@endif
    </p>
    <button type="button" class="lp-pcard__qv" data-quick-view="{{ $product->id }}" data-name="{{ $product->product_name }}"
            aria-label="Quick view: {{ $product->product_name }}">Quick View</button>
  </div>
  <div class="lp-pcard__body">
    <p class="lp-pcard__cat">{{ title_case($product->category) }}</p>
    <h3 class="lp-pcard__title"><a href="{{ $url }}">{{ title_case($product->product_name) }}</a></h3>
    <p class="lp-pcard__desc">{{ filled($product->description) ? $product->description : '' }}</p>
    <a href="{{ $url }}" class="lp-btn lp-btn--secondary lp-pcard__btn">{{ $cta }}</a>
  </div>
</article>
