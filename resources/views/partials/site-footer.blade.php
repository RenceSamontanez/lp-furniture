{{-- Shared customer footer, rebuilt from the approved design docs/footer/footer-prototype-v11.html.
     Styles: public/assets/css/site-footer.css. Data: App\Support\FooterData (Contact Settings first, config/shop.php second). --}}
@php $f = \App\Support\FooterData::build(); @endphp
<footer class="sf" id="footer-section" data-nav="dark">
  <div class="sf__glow" aria-hidden="true"></div>

  <div class="sf__top">
    @if($f['visit'])
      <section class="sf__visit" aria-label="Visit our shop">
        <h2>Visit our shop</h2>
        <div class="sf__map"><iframe title="Map showing LP Furniture shop location" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="{{ $f['visit']['embed'] }}"></iframe></div>
        @if($f['visit']['address'])<address>{{ $f['visit']['address'] }}</address>@endif
        @if($f['visit']['landmarks'])<p class="sf__near"><b>Nearby:</b> {{ $f['visit']['landmarks'] }}</p>@endif
        <a class="sf__open" href="{{ $f['visit']['open'] }}" target="_blank" rel="noopener noreferrer" aria-label="Open in Google Maps (opens in a new tab)">Open in Google Maps</a>
      </section>
    @endif

    @if($f['phone'] || $f['email'] || $f['facebook'] || $f['tiktok'])
      <section class="sf__contact" aria-label="Contact">
        @if($f['phone'] || $f['email'])
          <h2>Contact us</h2>
          <div class="sf__rows">
            @if($f['phone'])
              <a class="sf__row" href="{{ $f['phone']['href'] }}"><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/></svg></i><span><b>{{ $f['phone']['display'] }}</b><small>Call or text</small></span></a>
            @endif
            @if($f['email'])
              <a class="sf__row" href="mailto:{{ $f['email'] }}"><i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7.5l8.5 6 8.5-6"/></svg></i><span><b>{{ $f['email'] }}</b><small>Email</small></span></a>
            @endif
          </div>
        @endif
        @if($f['facebook'] || $f['tiktok'])
          <h2 class="sf__sochead">Social media</h2>
          <div class="sf__soc">
            @if($f['facebook'])
              <a class="sf__btn" href="{{ $f['facebook'] }}" target="_blank" rel="noopener noreferrer" aria-label="Facebook (opens in a new tab)">Facebook <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
            @endif
            @if($f['tiktok'])
              <a class="sf__btn" href="{{ $f['tiktok'] }}" target="_blank" rel="noopener noreferrer" aria-label="TikTok (opens in a new tab)">TikTok <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17L17 7M9 7h8v8"/></svg></a>
            @endif
          </div>
        @endif
      </section>
    @endif

    <section class="sf__paycol" aria-label="Payment methods">
      <h2>Ways to pay</h2>
      <ul class="sf__pay">
        @foreach($f['payments'] as $p)
          <li>@if($p['logo'])<img src="{{ $p['logo'] }}" alt="{{ $p['label'] }}" loading="lazy">@else<span>{{ $p['label'] }}</span>@endif</li>
        @endforeach
        <li class="sf__more">+ more ways to pay</li>
      </ul>
    </section>
  </div>

  <div class="sf__legal">
    <div>
      @if($f['policies'])
        <ul class="sf__pol" aria-label="Policies">
          @foreach($f['policies'] as $p)<li><a href="{{ $p['url'] }}">{{ $p['label'] }}</a></li>@endforeach
        </ul>
      @endif
      <span>&copy; {{ now()->year }} LP Furniture. All rights reserved.</span>
    </div>
  </div>

  <div class="sf__wordwrap">
    <div class="sf__word" aria-hidden="true"><span>LP <em>Furniture</em></span></div>
    <div class="sf__frost" aria-hidden="true"></div>
  </div>
</footer>
