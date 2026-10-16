<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Admin — LP Furniture')</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}"/>
@stack('styles')
</head>
<body class="admin-body">

@if(Auth::guard('admin')->check() && Auth::guard('admin')->user()->role === 'admin')
<div class="admin-shell">

  {{-- Sidebar --}}
  <aside class="admin-sidebar" id="admin-sidebar">
    <div class="admin-sidebar-brand">
      <span>LP <em>Admin</em></span>
      <button class="admin-sidebar-close" id="admin-sidebar-close" aria-label="Close menu">✕</button>
    </div>

    <nav class="admin-nav">

      <div class="admin-nav-group-label">Overview</div>
      <a href="{{ route('admin.dashboard') }}" class="admin-nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>
        Dashboard
      </a>
      <a href="{{ route('admin.notifications.index') }}" class="admin-nav-link {{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        Notifications
        @php $adminUnread = \App\Models\Notification::where('user_id', Auth::guard('admin')->id())->where('is_read', false)->count(); @endphp
        @if($adminUnread > 0)<span class="admin-nav-badge">{{ $adminUnread }}</span>@endif
      </a>

      <div class="admin-nav-group-label">Operations</div>

      <a href="{{ route('admin.products.index') }}" class="admin-nav-link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27,6.96 12,12.01 20.73,6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        Products
      </a>

      <a href="{{ route('admin.orders.index') }}" class="admin-nav-link {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
        Orders
      </a>

      <a href="{{ route('admin.installments.index') }}" class="admin-nav-link {{ request()->routeIs('admin.installments.*') ? 'active' : '' }}">
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
  Installments
</a>
<a href="{{ route('admin.inventory.index') }}" class="admin-nav-link {{ request()->routeIs('admin.inventory.*') ? 'active' : '' }}">
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
  Materials Inventory
</a>

<a href="{{ route('admin.chatbot.index') }}" class="admin-nav-link {{ request()->routeIs('admin.chatbot.*') ? 'active' : '' }}">
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
  Chatbot Training
</a>

<a href="{{ route('admin.settings.contact.edit') }}" class="admin-nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
  Contact Settings
</a>

      <div class="admin-nav-group-label">Customers</div>
      <a href="{{ route('admin.validations.index') }}" class="admin-nav-link {{ request()->routeIs('admin.validations.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Identity Validation
        @php $pendingCount = \App\Models\UserValidation::where('status','pending')->count(); @endphp
        @if($pendingCount > 0)<span class="admin-nav-badge">{{ $pendingCount }}</span>@endif
      </a>
      <a href="{{ route('admin.customers.index') }}" class="admin-nav-link {{ request()->routeIs('admin.customers.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        Customers
      </a>
      <a href="{{ route('admin.custom-orders.index') }}" class="admin-nav-link {{ request()->routeIs('admin.custom-orders.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        Custom Orders
        @php $customSubmittedCount = \App\Models\CustomDesignRequest::where('pipeline_status','submitted')->count(); @endphp
        @if($customSubmittedCount > 0)<span class="admin-nav-badge">{{ $customSubmittedCount }}</span>@endif
      </a>
      <a href="{{ route('admin.design-requests.index') }}" class="admin-nav-link {{ request()->routeIs('admin.design-requests.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>
        AI Design Requests
        @php $designPendingCount = \App\Models\CustomDesignRequest::where('status','approved')->count(); @endphp
        @if($designPendingCount > 0)<span class="admin-nav-badge">{{ $designPendingCount }}</span>@endif
      </a>


      <div class="admin-nav-group-label">Staff</div>
      <a href="{{ route('admin.employees.index') }}" class="admin-nav-link {{ request()->routeIs('admin.employees.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
        Employees
      </a>

      <a href="{{ route('admin.reports') }}" class="admin-nav-link {{ request()->routeIs('admin.reports') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Reports
      </a>
      

      <div class="admin-nav-group-label">Feedback</div>
      <a href="{{ route('admin.reviews.index') }}" class="admin-nav-link {{ request()->routeIs('admin.reviews.*') ? 'active' : '' }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
        Reviews
        @php $pendingReviews = \App\Models\Feedback::where('is_approved', false)->count(); @endphp
        @if($pendingReviews > 0)<span class="admin-nav-badge">{{ $pendingReviews }}</span>@endif
      </a>


    </nav>

    <form method="POST" action="{{ route('admin.logout') }}" class="admin-sidebar-signout">
      @csrf
      <button type="submit">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </button>
    </form>
  </aside>

  <div class="admin-overlay" id="admin-overlay"></div>

  {{-- Main content --}}
  <main class="admin-main">
    <div class="admin-topbar">
      <button class="admin-burger" id="admin-burger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
      <div class="admin-topbar-title">@yield('page-title', 'Dashboard')</div>
      <div class="admin-topbar-user">
        <span class="admin-user-fullname">{{ Auth::guard('admin')->user()->first_name }} {{ Auth::guard('admin')->user()->last_name }}</span>
        <div class="admin-topbar-avatar">
          {{ strtoupper(substr(Auth::guard('admin')->user()->first_name,0,1) . substr(Auth::guard('admin')->user()->last_name,0,1)) }}
        </div>
      </div>
    </div>

    @include('partials.alerts', ['toastValidation' => !($hideGlobalErrorToast ?? false)])

    <div class="admin-content">
      @yield('content')
    </div>
  </main>

</div>
@else
  @include('partials.alerts')
  @yield('content')
@endif

<script src="{{ asset('assets/js/admin.js') }}"></script>
@stack('scripts')
</body>
</html>