@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/home-hero.css') }}?v={{ @filemtime(public_path('assets/css/home-hero.css')) ?: 1 }}"/>
@endpush
@push('scripts')
<script src="{{ asset('assets/js/home-hero.js') }}?v={{ @filemtime(public_path('assets/js/home-hero.js')) ?: 1 }}" defer></script>
@endpush

@php
  // "Customize" goes where it always went: the design page for signed-in customers, the login page for guests.
  $customizeUrl = auth()->check() ? route('design.index') : route('login');
  $aiTiles = collect(config('hero.ai_images', []))->take(4)->values()->map(function ($t, $i) {
      $t['exists'] = is_file(public_path('images/hero-ai/' . $t['file']));
      $t['url']    = asset('images/hero-ai/' . $t['file']);
      $t['glyph']  = config('hero.ai_glyphs.' . $i, 'sofa');
      return $t;
  });
  $railBest  = $heroRails['best']  ?? collect();
  $railFresh = $heroRails['fresh'] ?? collect();
  $rails = [
      ['best',  $railBest,  'down', 'Best sellers', 'h-crown'],
      ['fresh', $railFresh, 'up',   'Hot · New',    'h-fire'],
  ];
@endphp

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="h-sp" viewBox="0 0 24 24"><path d="M12 1.5c.6 5.4 3.6 9 9.5 10.5-5.9 1.5-8.9 5.1-9.5 10.5C11.4 17.1 8.4 13.5 2.5 12 8.4 10.5 11.4 6.9 12 1.5z" fill="currentColor"/></symbol>
  <symbol id="h-ar" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="h-bag" viewBox="0 0 24 24"><path d="M5 8h14l-1 12H6zM9 8V6a3 3 0 016 0v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="h-ph" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
  <symbol id="h-fire" viewBox="0 0 24 24"><path d="M12 2c1 4 5 6 5 11a5 5 0 01-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-4-1-6 1-10z" fill="currentColor"/></symbol>
  <symbol id="h-crown" viewBox="0 0 24 24"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-2 11H5z" fill="currentColor"/></symbol>
  <symbol id="g-sofa" viewBox="0 0 64 64"><path d="M10 28a6 6 0 016-6h32a6 6 0 016 6v14H10zM6 34a4 4 0 018 0v10H6zM50 34a4 4 0 018 0v10h-8zM14 44v6M50 44v6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="g-table" viewBox="0 0 64 64"><path d="M8 24h48M12 24l-2 26M52 24l2 26M20 24v8M44 24v8" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="g-bed" viewBox="0 0 64 64"><path d="M6 20v32M58 34v18M6 40h52M6 34h52v6M12 34v-6a4 4 0 014-4h12a4 4 0 014 4v6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="g-cab" viewBox="0 0 64 64"><path d="M14 10h36v40H14zM14 30h36M32 10v40M26 20v2M38 20v2M26 40v2M38 40v2M18 50v6M46 50v6" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="g-chair" viewBox="0 0 64 64"><path d="M22 12h20l-2 22H24zM18 36h28v7H18zM22 43l-4 12M42 43l4 12M32 43v12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
</svg>

<!-- ═══ HERO ═══ -->
<div class="hero-v2 lp-hero" data-nav="dark">
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

  <div class="lp-hero__stage">
    <div class="hero-v2-content lp-hero__copy">
      <p class="hero-v2-tag">Handcrafted Since 2020 · San Juan, Batangas</p>
      <h1 class="hero-v2-title">Crafting Comfort<br/>and <em>Elegance</em> for Every Home</h1>
      <p class="hero-v2-sub">Premium custom wood furniture, handcrafted by skilled artisans. From your living room to your office — built to last, made for you.</p>

      <div class="hero-v2-actions lp-hero__ctas">
        <a href="{{ route('catalog') }}" class="lp-btn lp-btn--glass-primary lp-hero__cta"><svg class="lp-btn-icon" aria-hidden="true"><use href="#h-bag"/></svg>Shop Now</a>
        <a href="{{ $customizeUrl }}" class="lp-btn lp-btn--glass-outline lp-hero__cta"><svg class="lp-btn-icon" aria-hidden="true"><use href="#h-sp"/></svg>Customize Furniture</a>
        <a href="#footer-section" class="lp-btn lp-btn--glass-quiet lp-hero__cta"><svg class="lp-btn-icon" aria-hidden="true"><use href="#h-ph"/></svg>Contact Us</a>
      </div>
    </div>

    {{-- Desktop only (>= 1100px): hidden by CSS below that, so their lazy images never download there. --}}
    <div class="lp-hero__desk">
      <aside class="lp-ai" aria-labelledby="lp-ai-title">
        <span class="lp-ai__chip"><svg aria-hidden="true"><use href="#h-sp"/></svg>AI Showcase</span>
        <h2 id="lp-ai-title" class="lp-ai__title">Imagine it. We build it.</h2>
        <p class="lp-ai__text">Describe a piece and our AI shows it to you. Like it? Turn it into a real order.</p>
        <div class="lp-ai__grid">
          @foreach($aiTiles as $tile)
            @if($tile['exists'])
              <a class="lp-ai__tile" href="{{ $customizeUrl }}">
                <img src="{{ $tile['url'] }}" alt="{{ $tile['alt'] }}. Opens the Customize page." loading="lazy" decoding="async" width="800" height="600">
                <b>AI sample</b>
              </a>
            @else
              <a class="lp-ai__tile lp-ai__tile--empty" href="{{ $customizeUrl }}" aria-label="AI sample (placeholder). Opens the Customize page.">
                <svg class="lp-ai__glyph" aria-hidden="true"><use href="#g-{{ $tile['glyph'] }}"/></svg>
                <b>AI sample</b>
              </a>
            @endif
          @endforeach
        </div>
        <div class="lp-ai__foot"><span>Samples made with AI</span><a class="lp-ai__link" href="{{ $customizeUrl }}">Design yours <svg aria-hidden="true"><use href="#h-ar"/></svg></a></div>
      </aside>

      @if($railBest->isNotEmpty() || $railFresh->isNotEmpty())
      <div class="lp-rails">
        @foreach($rails as [$key, $cards, $dir, $label, $icon])
          @continue($cards->isEmpty())
          <section class="lp-rail lp-rail--{{ $dir }}" aria-labelledby="lp-rail-{{ $key }}">
            <h2 class="lp-rail__h" id="lp-rail-{{ $key }}"><svg aria-hidden="true"><use href="#{{ $icon }}"/></svg>{{ $label }}</h2>
            <div class="lp-rail__view">
              <ul class="lp-rail__track">
                @foreach([false, true] as $clone)
                  @foreach($cards as $c)
                    <li @if($clone) class="is-clone" aria-hidden="true" @endif>
                      <a class="lp-pc" href="{{ $c['url'] }}" @if($clone) tabindex="-1" @endif>
                        <span class="lp-pc__im">
                          @if($c['image'])
                            <img src="{{ $c['image'] }}" alt="" loading="lazy" decoding="async" width="300" height="300" onerror="this.remove()">
                          @else
                            <svg class="lp-pc__glyph" aria-hidden="true"><use href="#g-chair"/></svg>
                          @endif
                          <span class="lp-pc__tag">{{ $c['tag'] }}</span>
                        </span>
                        <span class="lp-pc__tx"><span class="lp-pc__nm">{{ $c['name'] }}</span><span class="lp-pc__pr">{{ $c['price'] }}</span></span>
                      </a>
                    </li>
                  @endforeach
                @endforeach
              </ul>
            </div>
          </section>
        @endforeach
      </div>
      @endif
    </div>
  </div>
</div>
