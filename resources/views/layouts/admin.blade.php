<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
@include('partials.favicon')
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Admin — LP Furniture')</title>
<link rel="stylesheet" href="{{ asset('assets/css/fonts.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/style.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}"/>
@stack('styles')
<link rel="stylesheet" href="{{ asset('assets/css/admin-type.css') }}"/>
<link rel="stylesheet" href="{{ asset('assets/css/admin-menu.css') }}"/>
</head>
<body class="admin-body admin-type">

@if(Auth::guard('admin')->check() && Auth::guard('admin')->user()->role === 'admin')
<div class="admin-shell">

  {{-- Sidebar --}}
  @include('partials.admin-sidebar')

  <div class="admin-overlay" id="admin-overlay"></div>

  {{-- Main content --}}
  <main class="admin-main">
    <div class="admin-topbar">
      <button type="button" class="admin-burger" id="admin-burger" aria-label="Open menu" aria-controls="admin-sidebar" aria-expanded="false">
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
    <footer class="lp-footer lp-footer--admin">
      <span>&copy; {{ date('Y') }} LP Furniture</span>
    </footer>
  </main>

</div>
@else
  @include('partials.alerts')
  @yield('content')
@endif

<script>window.LP_TITLECASE = @json(config('titlecase'));</script>
<script src="{{ asset('assets/js/title-case.js') }}"></script>
<script src="{{ asset('assets/js/admin.js') }}"></script>
<script src="{{ asset('assets/js/lp-modal.js') }}"></script>
@stack('scripts')
@include('partials.co-loading')
</body>
</html>