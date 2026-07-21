@extends('layouts.app')
@section('title', 'Set New Password — LP Furniture')
@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
@endpush

@section('content')
<div class="auth-wrap">
  <div class="auth-card auth-card--wide">

    <a href="{{ route('login') }}" class="auth-back-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Back to Sign In
    </a>

    <div class="auth-card-header">
      <p class="gallery-eyebrow" style="margin-bottom:8px;">Account Recovery</p>
      <h1 class="auth-title">Set New Password</h1>
      <p class="auth-sub">Choose a strong password for your LP Furniture account.</p>
    </div>

    @if($errors->any())
      <div class="auth-errors">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">⚠ {{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}"/>
      <input type="hidden" name="email" value="{{ $email }}"/>

      <div class="auth-field">
        <label class="form-label">New Password</label>
        <div class="input-password-wrap">
          <input
            type="password" name="password" id="password"
            class="form-input {{ $errors->has('password') ? 'input-error' : '' }}"
            placeholder="8–20 characters"
            maxlength="20" required
            oninput="checkPasswordStrength(this.value); checkRequirements(this.value);"
          />
          <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>

        <div style="margin-top:6px;">
          <div style="height:3px;background:var(--border);border-radius:2px;overflow:hidden;">
            <div id="strength-bar" style="height:100%;width:0%;transition:all .3s;border-radius:2px;"></div>
          </div>
          <span id="strength-label" style="font-size:11px;font-weight:600;margin-top:3px;display:block;"></span>
        </div>

        <div class="password-requirements" id="pw-requirements" style="display:none;">
          <div class="req-item" id="req-length"><div class="req-dot"></div> At least 8 characters</div>
          <div class="req-item" id="req-upper"><div class="req-dot"></div> One uppercase letter</div>
          <div class="req-item" id="req-lower"><div class="req-dot"></div> One lowercase letter</div>
          <div class="req-item" id="req-number"><div class="req-dot"></div> One number</div>
          <div class="req-item" id="req-special"><div class="req-dot"></div> One special character (@#$%!)</div>
        </div>
      </div>

      <div class="auth-field" style="margin-top:16px;">
        <label class="form-label">Confirm New Password</label>
        <div class="input-password-wrap">
          <input
            type="password" name="password_confirmation" id="password_confirmation"
            class="form-input" placeholder="Repeat new password"
            maxlength="20" required oninput="checkPasswordMatch()"
          />
          <button type="button" class="password-toggle" onclick="togglePassword('password_confirmation', this)">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <span id="password-match-hint" style="font-size:11px;font-weight:600;margin-top:6px;display:block;"></span>
      </div>

      <button type="submit" class="btn-auth-submit" style="margin-top:24px;">
        Reset Password →
      </button>
    </form>

  </div>
</div>
@endsection

@push('scripts')
  <script src="{{ asset('assets/js/auth.js') }}"></script>
@endpush