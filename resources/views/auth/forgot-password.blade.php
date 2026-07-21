@extends('layouts.app')
@section('title', 'Forgot Password — LP Furniture')
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
      <h1 class="auth-title">Forgot Password?</h1>
      <p class="auth-sub">Enter your email address and we'll send you a secure link to reset your password.</p>
    </div>

    @if(session('success'))
      <div class="auth-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
      <div class="auth-errors">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">⚠ {{ $error }}</div>
        @endforeach
      </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
      @csrf

      <div class="auth-field">
        <label class="form-label">Email Address</label>
        <input type="email" name="email"
          class="form-input {{ $errors->has('email') ? 'input-error' : '' }}"
          value="{{ old('email') }}" placeholder="juan@gmail.com"
          maxlength="150" required autofocus/>
      </div>

      <div class="auth-info-box" style="margin-top:16px;">
        A reset link will be sent to this address. Check your spam folder if you don't see it within a few minutes.
      </div>

      <button type="submit" class="btn-auth-submit" style="margin-top:20px;">
        Send Reset Link →
      </button>
    </form>

    <p class="auth-switch" style="margin-top:20px;">
      Remembered it? <a href="{{ route('login') }}" class="auth-link">Sign in here</a>
    </p>

  </div>
</div>
@endsection