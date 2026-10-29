@extends('layouts.admin')

@section('title', 'Admin Login — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/password-toggle.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-login.css') }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/password-toggle.js') }}"></script>
@endpush

@section('content')
@php
  $icon = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
@endphp
<div class="al-bg" aria-hidden="true"></div>
<main class="al-page">

  {{-- LEFT: what the admin is for (hidden under 860px) --}}
  <section class="al-intro" aria-label="About the admin area">
    <p class="al-brand">LP <em>Admin</em></p>
    <h1 class="al-title">Manage your business, all in one place.</h1>
    <p class="al-lead">Orders, inventory, customers, and payments — everything you need to run LP Furniture, in a single dashboard.</p>
    <ul class="al-tiles">
      <li class="al-tile"><i><svg {!! $icon !!}><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg></i><b>Track every order</b><span>From request to delivery, in one list.</span></li>
      <li class="al-tile"><i><svg {!! $icon !!}><path d="M12 3l8 3v6c0 4.5-3.2 7.6-8 9-4.8-1.4-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg></i><b>Verify customers</b><span>Check identities securely.</span></li>
      <li class="al-tile"><i><svg {!! $icon !!}><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></svg></i><b>Manage products</b><span>Prices, photos and availability.</span></li>
    </ul>
    <p class="al-copy">&copy; {{ date('Y') }} LP Furniture. Handcrafted with care in San Juan, Batangas.</p>
  </section>

  {{-- RIGHT: sign in --}}
  <section class="al-card" aria-labelledby="al-heading">
    <div class="al-badge" aria-hidden="true"><svg {!! $icon !!}><rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></div>
    <h2 class="al-heading" id="al-heading">Admin Sign In</h2>
    <p class="al-sub">Enter your credentials to access the dashboard.</p>

    @if($errors->any())
      <x-notice variant="problem" class="al-notice">{{ $errors->first() }}</x-notice>
    @endif

    <form method="POST" action="{{ route('admin.login.post') }}">
      @csrf

      <div class="al-field">
        <label for="admin-email">Admin Email</label>
        <div class="al-input-wrap">
          <svg {!! $icon !!}><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3.5 7.5l8.5 6 8.5-6"/></svg>
          <input type="email" name="email" id="admin-email" class="al-input" value="{{ old('email') }}"
                 required autofocus autocomplete="username" placeholder="admin@lpfurniture.com"/>
        </div>
      </div>

      <div class="al-field">
        <label for="admin-password">Password</label>
        <div class="al-input-wrap">
          <svg {!! $icon !!}><rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>
          <input type="password" name="password" id="admin-password" class="al-input" data-has-toggle
                 required autocomplete="current-password" placeholder="Enter your password"/>
          <x-password-toggle for="admin-password"/>
        </div>
      </div>

      <label class="al-keep" for="admin-remember">
        <input type="checkbox" name="remember" id="admin-remember" value="1" @checked(old('remember'))>
        <span class="al-keep-box" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
        <span class="al-keep-text">Keep me signed in on this device<small>Only this browser stays signed in. Your password is never saved or shown. Don't use this on a shared computer.</small></span>
      </label>

      <button type="submit" class="lp-btn lp-btn--glass-primary lp-btn--block al-submit">
        Sign In
      </button>
    </form>

    <a href="{{ route('home') }}" class="lp-link lp-link--tap al-back">
      Back to main site
    </a>
  </section>

</main>
@endsection
