@extends('layouts.focus')

@section('title', 'Create Account — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/password-toggle.css') }}"/>
@endpush

@section('content')
<div class="auth-wrap">
  <div class="auth-card auth-card--wide">

    <x-back-link :href="route('login')" label="Sign in" />

    <div class="auth-card-header">
      <h1 class="auth-title">Create Account</h1>
      <p class="auth-sub">Free to join — browse, order, and track in one place.</p>
    </div>

    <a href="{{ route('auth.google') }}" class="btn-google">
      <svg width="18" height="18" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.2L39.3 6C35.1 2.3 29.8 0 24 0 14.6 0 6.6 5.4 2.6 13.3l7.5 5.8C11.8 13.1 17.4 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.4 5.5-4.9 7.2l7.5 5.8c4.4-4.1 7.2-10.1 7.2-17.5z"/>
        <path fill="#FBBC05" d="M10.1 28.9c-.5-1.4-.8-2.9-.8-4.4s.3-3 .8-4.4L2.6 14.3C.9 17.6 0 21.2 0 25s.9 7.4 2.6 10.7l7.5-5.8z"/>
        <path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.5-5.8c-2.2 1.5-5 2.4-8.4 2.4-6.6 0-12.2-3.6-14.9-9l-7.5 5.8C6.6 42.6 14.6 48 24 48z"/>
      </svg>
      Sign up with Google
    </a>

    <div class="auth-divider"><span>or with email</span></div>

    @if($errors->any())
      <div class="auth-errors">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">{{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('register.post') }}" autocomplete="off">
      @csrf

      <div class="auth-form-row">
        <div>
          <label class="form-label">First Name</label>
          <input
            type="text"
            name="first_name"
            class="form-input {{ $errors->has('first_name') ? 'input-error' : '' }}"
            value="{{ old('first_name') }}"
            placeholder="Juan"
            maxlength="50"
            required
            autofocus
            oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.]/g, '')"
          />
        </div>
        <div>
          <label class="form-label">Last Name</label>
          <input
            type="text"
            name="last_name"
            class="form-input {{ $errors->has('last_name') ? 'input-error' : '' }}"
            value="{{ old('last_name') }}"
            placeholder="Dela Cruz"
            maxlength="50"
            required
            oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.]/g, '')"
          />
        </div>
      </div>

      <div class="auth-form-row">
        <div>
          <label class="form-label">Email</label>
          <input
            type="email"
            name="email"
            class="form-input {{ $errors->has('email') ? 'input-error' : '' }}"
            value="{{ old('email') }}"
            placeholder="juan@gmail.com"
            maxlength="150"
            required
          />
        </div>
        <div>
          <label class="form-label">Phone</label>
          <input
            type="tel"
            name="phone_number"
            class="form-input {{ $errors->has('phone_number') ? 'input-error' : '' }}"
            value="{{ old('phone_number') }}"
            placeholder="09171234567"
            maxlength="13"
            required
            title="Philippine mobile — e.g. 09171234567 or +639171234567"
            oninput="this.value = this.value.replace(/[^\d\+]/g, '')"
          />
        </div>
      </div>

      <div class="auth-form-row">
        <div>
          <label class="form-label">Password</label>
          <div class="input-password-wrap">
            <input
              type="password"
              name="password"
              id="password"
              class="form-input {{ $errors->has('password') ? 'input-error' : '' }}"
              placeholder="8–20 characters"
              maxlength="20"
              required
              oninput="checkPasswordStrength(this.value); checkRequirements(this.value);"
            />
            <x-password-toggle for="password"/>
          </div>

          <div class="password-strength">
            <div class="password-strength-bar">
              <div id="strength-bar"></div>
            </div>
            <span id="strength-label" class="password-strength-label"></span>
          </div>

          <div class="password-requirements" id="pw-requirements">
            <div class="req-item" id="req-length">
              <div class="req-dot"></div> 8+ characters
            </div>
            <div class="req-item" id="req-upper">
              <div class="req-dot"></div> Uppercase
            </div>
            <div class="req-item" id="req-lower">
              <div class="req-dot"></div> Lowercase
            </div>
            <div class="req-item" id="req-number">
              <div class="req-dot"></div> Number
            </div>
            <div class="req-item" id="req-special">
              <div class="req-dot"></div> Special char
            </div>
          </div>
        </div>

        <div>
          <label class="form-label">Confirm Password</label>
          <div class="input-password-wrap">
            <input
              type="password"
              name="password_confirmation"
              id="password_confirmation"
              class="form-input"
              placeholder="Repeat password"
              maxlength="20"
              required
              oninput="checkPasswordMatch()"
            />
            <x-password-toggle for="password_confirmation"/>
          </div>
          <span id="password-match-hint" class="password-match-hint"></span>
        </div>
      </div>

      <p class="auth-terms">
        By signing up you agree to our <a href="{{ route('terms') }}" class="auth-link" target="_blank" rel="noopener">Terms</a> and <a href="#" class="auth-link">Privacy Policy</a>.
      </p>

      <button type="submit" class="btn-auth-submit" style="margin-top:0;">Create Account</button>
    </form>

  </div>
</div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/auth.js') }}"></script>
  <script src="{{ asset('assets/js/password-toggle.js') }}"></script>
@endpush
