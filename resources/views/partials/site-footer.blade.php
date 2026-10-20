{{-- Shared customer footer (home and every inner page; not the focus pages or the admin). Styles: public/assets/css/site-footer.css.
     Every contact/social value is shown only when it is really set in Admin > Contact Settings. --}}
@php
  $bs         = \App\Models\BusinessSetting::current();
  $phone      = $bs->publicPhone();
  $email      = $bs->publicEmail();
  $fbUrl      = $bs->publicFacebookUrl();
  $ttUrl      = $bs->publicTiktokUrl();
  $hours      = \App\Support\ShopHours::label();
  $hasContact = $phone || $email || $hours;
@endphp
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="sf-fb" viewBox="0 0 24 24"><path d="M14 8h2.2V4.8H13.5C10.9 4.8 9.6 6.4 9.6 8.9V11H7.4v3.2h2.2V21h3.5v-6.8h2.6l.5-3.2h-3.1V9.3c0-.9.4-1.3 1.2-1.3z" fill="currentColor"/></symbol>
  <symbol id="sf-tt" viewBox="0 0 24 24"><path d="M16.6 3c.3 2.2 1.6 3.6 3.9 3.8v3.1c-1.4.1-2.7-.3-3.9-1.1v6.1c0 5.8-6.3 7.6-9.1 3.5-1.7-2.5-.7-6.9 3.5-7.4v3.3c-.4.1-.8.2-1.1.4-1 .6-1.1 2-.2 2.7.9.8 2.4.2 2.7-.9.1-.3.1-.6.1-1V3z" fill="currentColor"/></symbol>
  <symbol id="sf-ar" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</svg>
<footer class="sf" id="footer-section" data-nav="dark" aria-label="Site footer">
  <div class="sf__in">
    <div class="sf__top">
      <div class="sf__brand">
        <div class="sf__logo">LP <em>Furniture</em></div>
        <p class="sf__about">Handcrafted custom wood furniture, made to order in San Juan, Batangas.</p>
        @if($fbUrl || $ttUrl)
          <h3 class="sf__h">Follow us</h3>
          <div class="sf__follow">
            @if($fbUrl)
              <a class="lp-btn lp-btn--glass-outline sf__soc" href="{{ $fbUrl }}" target="_blank" rel="noopener noreferrer" aria-label="LP Furniture on Facebook (opens in a new tab)"><svg class="lp-btn-icon" aria-hidden="true"><use href="#sf-fb"/></svg>Facebook</a>
            @endif
            @if($ttUrl)
              <a class="lp-btn lp-btn--glass-outline sf__soc" href="{{ $ttUrl }}" target="_blank" rel="noopener noreferrer" aria-label="LP Furniture on TikTok (opens in a new tab)"><svg class="lp-btn-icon" aria-hidden="true"><use href="#sf-tt"/></svg>TikTok</a>
            @endif
          </div>
        @endif
      </div>

      <div class="sf__col" role="navigation" aria-label="Explore">
        <h3 class="sf__h">Explore</h3>
        <a class="lp-link lp-link--on-dark" href="{{ route('home') }}">Home</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('catalog') }}">Catalog</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('design.index') }}">Customize</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('services.request') }}">Other services</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('feedback.index') }}">Reviews</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('orders.index') }}">Track an order</a>
      </div>

      @if($hasContact)
        <div class="sf__col">
          <h3 class="sf__h">Contact</h3>
          @if($phone)<a class="lp-link lp-link--on-dark" href="{{ $bs->phone_href }}">{{ $phone }}</a>@endif
          @if($email)<a class="lp-link lp-link--on-dark" href="mailto:{{ $email }}">{{ $email }}</a>@endif
          @if($hours)<p class="sf__line">{{ $hours }}</p>@endif
        </div>
      @endif

      <div class="sf__col" role="navigation" aria-label="Policies">
        <h3 class="sf__h">Policies</h3>
        <a class="lp-link lp-link--on-dark" href="{{ route('terms') }}">Terms and Conditions</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('services.terms') }}">Other Services terms and refunds</a>
        <a class="lp-link lp-link--on-dark" href="{{ route('guide') }}">How to request a custom design</a>
        <h3 class="sf__h sf__h--gap">We accept</h3>
        <div class="sf__pay"><span>GCash</span><span>Cards</span></div>
      </div>
    </div>

    <div class="sf__cta">
      <div><b>Have a piece in mind?</b><span>Tell us what you need and our team will call you.</span></div>
      <a class="lp-btn lp-btn--glass-primary" href="{{ route('services.request') }}">Send a request <svg class="lp-btn-icon" aria-hidden="true"><use href="#sf-ar"/></svg></a>
    </div>

    <div class="sf__bot">
      <span>&copy; {{ date('Y') }} LP Furniture. All rights reserved.</span>
      <span>Chat any time with Assistant in the menu: Luma, our 24-hour shop assistant.</span>
    </div>
  </div>
</footer>
