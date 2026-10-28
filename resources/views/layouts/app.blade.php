@php
  // Root-absolute paths ("/images/luma/..."), never relative and never tied to APP_URL's host or scheme, so the widget works
  // from any page nesting, host name (local, LAN IP, tunnel) and under a sub-folder install.
  $lpPath  = fn (string $p) => parse_url(asset($p), PHP_URL_PATH);
  $lpRoute = fn (string $name) => route($name, [], false);
  // Version query string follows the file's modified time, so an edited main.js / luma.css is never served from a stale cache.
  $lpVer   = fn (string $p) => $lpPath($p) . '?v=' . (@filemtime(public_path($p)) ?: 1);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'LP Furniture')</title>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/site-nav.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/site-footer.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/fonts.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/customer-type.css') }}"/>
<link rel="stylesheet" href="{{ $lpVer('assets/css/luma.css') }}"/>
@stack('styles')
</head>
<body class="lp-type" data-nav="light">

<!-- ═══ NAV (floating glass bar; see public/assets/css/site-nav.css) ═══ -->
@php $navIs = fn (string ...$p) => request()->routeIs(...$p); @endphp
<nav class="site-nav" id="site-nav" aria-label="Main">
  <a href="{{ route('home') }}" class="sn-brand">LP <em>Furniture</em></a>

  <button type="button" class="lp-btn lp-btn--glass-outline lp-btn--icon sn-burger" id="sn-burger"
          aria-label="Open menu" aria-expanded="false" aria-controls="sn-links">
    <span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span>
  </button>

  <div class="sn-links" id="sn-links">
    <a href="{{ route('home') }}"    class="sn-link" @if($navIs('home')) aria-current="page" @endif>Home</a>
    <a href="{{ route('catalog') }}" class="sn-link" @if($navIs('catalog', 'product.*')) aria-current="page" @endif>Catalog</a>

    @guest
      @include('partials.luma-nav-item')
      <a href="{{ route('login') }}"    class="lp-btn lp-btn--glass-outline sn-cta">Login</a>
      <a href="{{ route('register') }}" class="lp-btn lp-btn--glass-primary sn-cta">Register</a>
    @else
      @if(Auth::user()->is_validated)
        <a href="{{ route('design.index') }}" class="sn-link" @if($navIs('design.*')) aria-current="page" @endif>Customize</a>
      @endif

      @include('partials.luma-nav-item')

      {{-- Cart --}}
      <a href="{{ route('cart.index') }}" class="sn-link sn-icon" @if($navIs('cart.*')) aria-current="page" @endif>
        @php $cartCount = Auth::user()->cartItems()->sum('quantity'); @endphp
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <circle cx="9"  cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.99-1.73L23 6H6"/>
        </svg>
        <span class="sn-label">Cart</span>@if($cartCount > 0) <span class="sn-sr">({{ $cartCount }} {{ $cartCount == 1 ? 'item' : 'items' }})</span>@endif
        @if($cartCount > 0)
          <span class="cart-badge" aria-hidden="true">{{ $cartCount > 9 ? '9+' : $cartCount }}</span>
        @endif
      </a>

      {{-- Notifications --}}
      <a href="{{ route('notifications.index') }}" class="sn-link sn-icon" @if($navIs('notifications.*')) aria-current="page" @endif>
        @php $unreadCount = Auth::user()->notifications()->where('is_read', false)->count(); @endphp
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        <span class="sn-label">Notifications</span>@if($unreadCount > 0) <span class="sn-sr">(unread)</span>@endif
        @if($unreadCount > 0)
          <span class="notif-dot" aria-hidden="true"></span>
        @endif
      </a>

      <a href="{{ route('feedback.index') }}" class="sn-link" @if($navIs('feedback.*')) aria-current="page" @endif>Reviews</a>

      {{-- My Account --}}
      <a href="{{ route('profile') }}" class="sn-link" @if($navIs('profile*')) aria-current="page" @endif>My Account</a>
    @endguest
  </div>
</nav>

<!-- ═══ SESSION ALERTS (toasts) ═══ -->
@include('partials.alerts', ['toastNav' => true])

<!-- ═══ PAGE CONTENT ═══ -->
<main>
@yield('content')
</main>

@include('partials.site-footer')

<!-- ═══ CHATBOT (Luma) ═══ -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="luma-sp" viewBox="0 0 24 24"><path d="M12 1.5c.6 5.4 3.6 9 9.5 10.5-5.9 1.5-8.9 5.1-9.5 10.5C11.4 17.1 8.4 13.5 2.5 12 8.4 10.5 11.4 6.9 12 1.5z" fill="currentColor"/></symbol>
  <symbol id="luma-send" viewBox="0 0 24 24"><path d="M4 12l16-8-6 16-3-7z" fill="currentColor"/></symbol>
</svg>
<div class="chatbot-fab" id="chatbot-fab">
  <button type="button" class="luma-fab-btn" id="chatbot-fab-btn" aria-label="Open chat with Luma" aria-controls="chatbot-window" aria-expanded="false">
    <span class="luma-ring"><span class="luma-face"><img src="{{ $lpPath('images/luma/luma-smiling.png') }}" width="500" height="500" alt="" decoding="async"></span></span>
  </button>
  <span class="luma-fab-tip" id="luma-fab-tip" aria-hidden="true">Ask Luma</span>
  <button type="button" class="luma-fab-x" id="luma-fab-x" aria-label="Hide Luma (bring her back from the Assistant link in the menu)"><span aria-hidden="true">&times;</span></button>
</div>
{{-- Drop zone that appears while dragging the chatbot: dragging
     the bubble here dismisses it, same as Messenger's chat heads --}}
<div class="chatbot-dismiss-zone" id="chatbot-dismiss-zone">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
    <line x1="18" y1="6" x2="6" y2="18"/>
    <line x1="6" y1="6" x2="18" y2="18"/>
  </svg>
</div>
<div class="chatbot-window" id="chatbot-window" role="region" aria-label="Chat with Luma" data-pose="greeting">
  <div class="chat-header">
    <div class="luma-ring luma-ring--head"><span class="luma-face"><img src="{{ $lpPath('images/luma/luma-smiling.png') }}" width="500" height="500" alt="" decoding="async"></span></div>
    <div class="chat-header-text">
      <h4>Luma <svg class="luma-spark" aria-hidden="true"><use href="#luma-sp"/></svg></h4>
      <p>Shop Assistant</p>
    </div>
    <div class="luma-pill" id="luma-pill"><i aria-hidden="true"></i><span id="luma-pill-text">Available 24/7</span></div>
    <button type="button" onclick="toggleChatbot()" class="chat-close" aria-label="Close chat"><span aria-hidden="true">&times;</span></button>
  </div>
  <div class="luma-stage" id="luma-stage">
    <div class="luma-pic" aria-hidden="true">
      <img class="on" id="luma-pose-a" src="{{ $lpPath('images/luma/luma-greeting.png') }}" width="500" height="500" alt="">
      <img id="luma-pose-b" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" width="500" height="500" alt="">
    </div>
    <div class="luma-status" id="luma-status" aria-hidden="true">Hi there!</div>
  </div>
  <div class="chat-body" id="chat-body"></div>
  <div class="chat-suggestions" id="chat-suggestions" aria-label="Quick questions"></div>
  <div class="chat-input-row">
    <input class="chat-input" id="chat-input" placeholder="Ask Luma anything..." aria-label="Type your question" maxlength="500" autocomplete="off" onkeydown="if(event.key==='Enter')sendChat()"/>
    <button type="button" class="chat-send lp-btn lp-btn--primary" onclick="sendChat()" aria-label="Send message"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><use href="#luma-send"/></svg></button>
  </div>
</div>

<!-- ═══ GLOBAL CONFIG FOR JS ═══ -->
<script>
  const APP = {
    csrfToken: "{{ csrf_token() }}",
    chatbotUrl: "{{ $lpRoute('chatbot.respond') }}",
    csrfUrl: "{{ $lpRoute('csrf.refresh') }}",
    isLoggedIn: {{ Auth::check() ? 'true' : 'false' }},
    loginUrl: "{{ $lpRoute('login') }}",
    registerUrl: "{{ $lpRoute('register') }}",
    catalogUrl: "{{ $lpRoute('catalog') }}",
    cartUrl: "{{ $lpRoute('cart.index') }}",
    checkoutUrl: "{{ $lpRoute('checkout') }}",
    ordersUrl: "{{ $lpRoute('orders.index') }}",
    validationUrl: "{{ $lpRoute('validation.show') }}",
    designUrl: "{{ $lpRoute('design.index') }}",
    forgotPasswordUrl: "{{ $lpRoute('password.request') }}",
    homeUrl: "{{ $lpRoute('home') }}",
    lumaPoseBase: "{{ $lpPath('images/luma') }}",
    lumaHours: @json(config('luma.hours') ? config('luma.hours') + ['tz' => config('luma.timezone', 'Asia/Manila')] : null),
  };
</script>

<script src="{{ $lpVer('assets/main.js') }}"></script>
<script src="{{ $lpVer('assets/js/site-nav.js') }}" defer></script>
<script src="{{ asset('assets/js/lp-modal.js') }}"></script>
@stack('scripts')

@include('partials.co-loading')

</body>
</html>