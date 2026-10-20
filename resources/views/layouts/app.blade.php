<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'LP Furniture')</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/css/luma.css') }}"/>
@stack('styles')
</head>
<body>

<!-- ═══ NAV ═══ -->
<nav>
  <a href="{{ route('home') }}" class="nav-brand">LP <span>Furniture</span></a>

  <button class="nav-burger" id="nav-burger" onclick="toggleMobileNav()" aria-label="Menu">
    <span></span><span></span><span></span>
  </button>

  <div class="nav-links" id="nav-links">
    <a href="{{ route('home') }}"    class="nav-btn ghost">Home</a>
    <a href="{{ route('catalog') }}" class="nav-btn ghost">Catalog</a>

    @guest
      @include('partials.luma-nav-item')
      <a href="{{ route('login') }}"    class="nav-btn ghost">Login</a>
      <a href="{{ route('register') }}" class="nav-btn primary">Register</a>
    @else
      @if(Auth::user()->is_validated)
        <a href="{{ route('design.index') }}" class="nav-btn ghost">Customize</a>
      @endif

      @include('partials.luma-nav-item')

      {{-- Cart --}}
      <a href="{{ route('cart.index') }}" class="nav-icon" data-label="Cart" aria-label="Cart">
        @php $cartCount = Auth::user()->cartItems()->sum('quantity'); @endphp
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <circle cx="9"  cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 001.99-1.73L23 6H6"/>
        </svg>
        @if($cartCount > 0)
          <span class="cart-badge">{{ $cartCount > 9 ? '9+' : $cartCount }}</span>
        @endif
      </a>

      {{-- Notifications --}}
      <a href="{{ route('notifications.index') }}" class="nav-icon" data-label="Notifications" aria-label="Notifications">
        @php $unreadCount = Auth::user()->notifications()->where('is_read', false)->count(); @endphp
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        @if($unreadCount > 0)
          <span class="notif-dot"></span>
        @endif
      </a>

      <a href="{{ route('feedback.index') }}" class="nav-btn ghost">Reviews</a>

      {{-- My Account --}}
      <a href="{{ route('profile') }}" class="nav-btn ghost" aria-label="My Account">My Account</a>
    @endguest
</div>
</nav>
<div class="nav-overlay" id="nav-overlay" onclick="toggleMobileNav()"></div>

<!-- Dark overlay behind mobile menu -->
<div class="nav-overlay" id="nav-overlay" onclick="toggleMobileNav()"></div>

<!-- ═══ SESSION ALERTS (toasts) ═══ -->
@include('partials.alerts')

<!-- ═══ PAGE CONTENT ═══ -->
<main>
@yield('content')
</main>

@unless(View::hasSection('hide_site_footer'))
<footer class="lp-footer">
  <span>&copy; {{ date('Y') }} LP Furniture</span>
  <span class="lp-footer-links">
    <a href="{{ route('guide') }}">How to request a custom design</a>
    <a href="{{ route('terms') }}">Terms and Conditions</a>
  </span>
</footer>
@endunless

<!-- ═══ CHATBOT (Luma) ═══ -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="luma-sp" viewBox="0 0 24 24"><path d="M12 1.5c.6 5.4 3.6 9 9.5 10.5-5.9 1.5-8.9 5.1-9.5 10.5C11.4 17.1 8.4 13.5 2.5 12 8.4 10.5 11.4 6.9 12 1.5z" fill="currentColor"/></symbol>
  <symbol id="luma-send" viewBox="0 0 24 24"><path d="M4 12l16-8-6 16-3-7z" fill="currentColor"/></symbol>
</svg>
<div class="chatbot-fab" id="chatbot-fab">
  <button type="button" class="luma-fab-btn" id="chatbot-fab-btn" aria-label="Open chat with Luma" aria-controls="chatbot-window" aria-expanded="false">
    <span class="luma-ring"><span class="luma-face"><img src="{{ asset('images/luma/luma-smiling.png') }}" width="500" height="500" alt="" decoding="async"></span></span>
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
    <div class="luma-ring luma-ring--head"><span class="luma-face"><img src="{{ asset('images/luma/luma-smiling.png') }}" width="500" height="500" alt="" decoding="async"></span></div>
    <div class="chat-header-text">
      <h4>Luma <svg class="luma-spark" aria-hidden="true"><use href="#luma-sp"/></svg></h4>
      <p>Shop Assistant</p>
    </div>
    <div class="luma-pill" id="luma-pill"><i aria-hidden="true"></i><span id="luma-pill-text">Available 24/7</span></div>
    <button type="button" onclick="toggleChatbot()" class="chat-close" aria-label="Close chat"><span aria-hidden="true">&times;</span></button>
  </div>
  <div class="luma-stage" id="luma-stage">
    <div class="luma-pic" aria-hidden="true">
      <img class="on" id="luma-pose-a" src="{{ asset('images/luma/luma-greeting.png') }}" width="500" height="500" alt="">
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

<!-- ═══ TOAST ═══ -->
<div class="toast" id="toast"></div>

<!-- ═══ GLOBAL CONFIG FOR JS ═══ -->
<script>
  const APP = {
    csrfToken: "{{ csrf_token() }}",
    chatbotUrl: "{{ route('chatbot.respond') }}",
    isLoggedIn: {{ Auth::check() ? 'true' : 'false' }},
    loginUrl: "{{ route('login') }}",
    registerUrl: "{{ route('register') }}",
    catalogUrl: "{{ route('catalog') }}",
    cartUrl: "{{ route('cart.index') }}",
    checkoutUrl: "{{ route('checkout') }}",
    ordersUrl: "{{ route('orders.index') }}",
    validationUrl: "{{ route('validation.show') }}",
    designUrl: "{{ route('design.index') }}",
    forgotPasswordUrl: "{{ route('password.request') }}",
    homeUrl: "{{ route('home') }}",
    lumaPoseBase: "{{ asset('images/luma') }}",
    lumaHours: @json(config('luma.hours') ? config('luma.hours') + ['tz' => config('luma.timezone', 'Asia/Manila')] : null),
  };
</script>

<script src="{{ asset('assets/main.js') }}"></script>
<script src="{{ asset('assets/js/lp-modal.js') }}"></script>
@stack('scripts')

@include('partials.co-loading')

</body>
</html>