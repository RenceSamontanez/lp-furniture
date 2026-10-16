@extends('layouts.app')
@section('title', 'Sign In — LP Furniture')
@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
@endpush

@section('content')
<div class="auth-wrap">
  <div class="auth-card auth-card--wide">

    <a href="{{ route('home') }}" class="auth-back-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Back to Home
    </a>

    <div class="auth-card-header">
      <p class="gallery-eyebrow" style="margin-bottom:8px;">Welcome Back</p>
      <h1 class="auth-title">Sign In</h1>
      <p class="auth-sub">Access your orders, cart, and account.</p>
    </div>

    <a href="{{ route('auth.google') }}" class="btn-google">
      <svg width="18" height="18" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.2L39.3 6C35.1 2.3 29.8 0 24 0 14.6 0 6.6 5.4 2.6 13.3l7.5 5.8C11.8 13.1 17.4 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.4 5.5-4.9 7.2l7.5 5.8c4.4-4.1 7.2-10.1 7.2-17.5z"/>
        <path fill="#FBBC05" d="M10.1 28.9c-.5-1.4-.8-2.9-.8-4.4s.3-3 .8-4.4L2.6 14.3C.9 17.6 0 21.2 0 25s.9 7.4 2.6 10.7l7.5-5.8z"/>
        <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.2 1.5-5 2.4-8.4 2.4-6.6 0-12.2-3.6-14.9-9l-7.5 5.8C6.6 42.6 14.6 48 24 48z"/>
      </svg>
      Continue with Google
    </a>

    <div class="auth-divider"><span>or sign in with email</span></div>


    {{-- Bounced here from a protected route (auth middleware's
         redirect()->guest() sets url.intended) — could mean the
         session expired, or they just tried a protected page
         without ever logging in. Can't tell which, so the
         message stays neutral and honest either way. --}}
    @if(session()->has('url.intended') && !$errors->any())
      <div class="auth-notice">Please log in to continue.</div>
    @endif

    @if($errors->any())
      <div class="auth-errors">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">⚠ {{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('login.post') }}" autocomplete="off">
      @csrf
      <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response"/>

      <div class="auth-field">
        <label class="form-label">Email Address</label>
        <input type="email" name="email"
          class="form-input {{ $errors->has('email') ? 'input-error' : '' }}"
          value="{{ old('email') }}" placeholder="juan@gmail.com"
          maxlength="150" required autofocus/>
      </div>

      <div class="auth-field" style="margin-top:16px;">
        <label class="form-label">Password</label>
        <div class="input-password-wrap">
          <input type="password" name="password" id="login-password"
            class="form-input" placeholder="Your password"
            maxlength="100" required/>
          <button type="button" class="password-toggle"
                  onclick="togglePassword('login-password', this)" aria-label="Show">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="auth-row" style="margin-top:14px; justify-content:flex-end;">
        <a href="{{ route('password.request') }}" class="auth-link">Forgot password?</a>
      </div>

      <button type="submit" class="btn-auth-submit" style="margin-top:22px;">
        Sign In →
      </button>
    </form>

    {{-- Prominent "No account?" call to action --}}
    <div class="auth-no-account">
      No account yet?
      <a href="{{ route('register') }}" class="auth-no-account-link">Create one — it's free →</a>
    </div>

  </div>
</div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/auth.js') }}"></script>
  <script src="https://www.google.com/recaptcha/api.js?render={{ config('services.recaptcha.site_key') }}"></script>
  <script>
    const RECAPTCHA_SITE_KEY = '{{ config('services.recaptcha.site_key') }}';

    // Wait for reCAPTCHA to be ready
    grecaptcha.ready(function() {
      console.log('reCAPTCHA v3 ready');
    });

    // Intercept form submission
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.querySelector('form[action="{{ route('login.post') }}"]');
      if (!form) return;

      form.addEventListener('submit', function(e) {
        e.preventDefault(); // Stop normal form submit

        const submitBtn = form.querySelector('[type="submit"]');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Verifying...';
        }

        // Generate a FRESH token right at submit moment
        grecaptcha.execute(RECAPTCHA_SITE_KEY, { action: 'login' })
          .then(function(token) {
            document.getElementById('g-recaptcha-response').value = token;
            form.submit(); // Now submit with fresh token
          })
          .catch(function(err) {
            console.error('reCAPTCHA error:', err);
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Sign In →';
            }
            alert('Security check failed. Please refresh the page and try again.');
          });
      });
    });
  </script>
@endpush