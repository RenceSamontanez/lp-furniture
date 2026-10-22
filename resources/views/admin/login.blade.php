@extends('layouts.admin')

@section('title', 'Admin Login — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/password-toggle.css') }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/password-toggle.js') }}"></script>
@endpush

@section('content')
<div class="admin-login-wrap">

  {{-- LEFT: Branding panel (desktop only) --}}
  <div class="admin-login-brand-panel">
    <div class="admin-login-brand-content">
      <div class="admin-login-brand-logo">LP <em>Admin</em></div>
      <h1 class="admin-login-brand-title">Manage your business, all in one place.</h1>
      <p class="admin-login-brand-sub">Orders, inventory, customers, and payments — everything you need to run LP Furniture, in a single dashboard.</p>

      <div class="admin-login-brand-features">
        <div class="admin-login-brand-feature">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
          Track and manage every order
        </div>
        <div class="admin-login-brand-feature">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Verify customer identities securely
        </div>
        <div class="admin-login-brand-feature">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
          Manage products and inventory
        </div>
      </div>
    </div>

    <div class="admin-login-brand-footer">
      &copy; {{ date('Y') }} LP Furniture. Handcrafted with care in San Juan, Batangas.
    </div>
  </div>

  {{-- RIGHT: Login form panel --}}
  <div class="admin-login-form-panel">
    <div class="admin-login-card">

      <div class="admin-login-header">
        <div class="admin-login-icon">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="11" width="18" height="11" rx="2"/>
            <path d="M7 11V7a5 5 0 0110 0v4"/>
          </svg>
        </div>
        <h1 class="admin-login-title">Admin Sign In</h1>
        <p class="admin-login-sub">Enter your credentials to access the dashboard.</p>
      </div>


      @if($errors->any())
        <div class="admin-toast admin-toast-error" style="margin:0 0 16px;">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('admin.login.post') }}">
        @csrf

        <div class="admin-login-field">
          <label class="form-label">Admin Email</label>
          <div class="admin-login-input-wrap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" name="email" class="admin-login-input"
                   value="{{ old('email') }}" required autofocus placeholder="admin@lpfurniture.com"/>
          </div>
        </div>

        <div class="admin-login-field">
          <label class="form-label">Password</label>
          <div class="admin-login-input-wrap">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" name="password" id="admin-password" class="admin-login-input" data-has-toggle required placeholder="••••••••"/>
            <x-password-toggle for="admin-password"/>
          </div>
        </div>

        <button type="submit" class="admin-login-submit-btn">
          Sign In
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
      </form>

      <a href="{{ route('home') }}" class="admin-login-back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back to main site
      </a>

    </div>
  </div>

</div>
@endsection