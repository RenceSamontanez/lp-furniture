<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'LP Furniture')</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
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
      <a href="{{ route('login') }}"    class="nav-btn ghost">Login</a>
      <a href="{{ route('register') }}" class="nav-btn primary">Register</a>
    @else
      @if(Auth::user()->is_validated)
        <a href="{{ route('design.index') }}" class="nav-btn ghost">AI Design</a>
      @endif

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

      {{-- Reactivate Chatbot — only visible after user has dismissed it --}}
<button type="button" class="nav-icon nav-chatbot-reactivate" id="nav-chatbot-reactivate"
        onclick="reactivateChatbot()" data-label="Chat" aria-label="Reactivate Chat Assistant"
        style="display:none;">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
    <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>
  </svg>
</button>

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

<!-- ═══ CHATBOT ═══ -->
<div class="chatbot-fab" onclick="toggleChatbot()" id="chatbot-fab" title="Drag me anywhere">💬</div>
{{-- Drop zone that appears while dragging the chatbot — dragging
     the bubble here dismisses it, same as Messenger's chat heads --}}
<div class="chatbot-dismiss-zone" id="chatbot-dismiss-zone">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
    <line x1="18" y1="6" x2="6" y2="18"/>
    <line x1="6" y1="6" x2="18" y2="18"/>
  </svg>
</div>
<div class="chatbot-window" id="chatbot-window">
  <div class="chat-header">
    <div class="chat-header-icon">🪑</div>
    <div class="chat-header-text">
      <h4>LP Furniture Assistant</h4>
      <p>Always here to help</p>
    </div>
    <button onclick="toggleChatbot()" style="background:none;border:none;color:#ccc;font-size:20px;cursor:pointer;margin-left:auto;">✕</button>
  </div>
  <div class="chat-body" id="chat-body"></div>
  <div class="chat-suggestions" id="chat-suggestions"></div>
  <div class="chat-input-row">
    <input class="chat-input" id="chat-input" placeholder="Ask about furniture..." onkeydown="if(event.key==='Enter')sendChat()"/>
    <button class="chat-send" onclick="sendChat()">➤</button>
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
  };
</script>

<script src="{{ asset('assets/main.js') }}"></script>
<script src="{{ asset('assets/js/lp-modal.js') }}"></script>
@stack('scripts')

@include('partials.co-loading')

</body>
</html>