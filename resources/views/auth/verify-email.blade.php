@extends('layouts.app')

@section('title', 'Verify Your Email — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
@endpush

@section('content')
<div class="auth-wrap">
  <div class="auth-card">

    <a href="{{ route('home') }}" class="auth-back-btn">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Home
    </a>

    <div class="verify-icon">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
        <polyline points="22,6 12,13 2,6"/>
      </svg>
    </div>

    <div class="auth-card-header auth-card-header--center">
      <h1 class="auth-title">Check Your Email</h1>
      <p class="auth-sub">
        We sent a link to <strong>{{ Auth::user()->email }}</strong>. Check spam if it's not there.
      </p>
    </div>


    <div class="auth-verify-actions">
      <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="btn-auth-submit">Resend Email</button>
      </form>

      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn-auth-secondary">Use Different Account</button>
      </form>
    </div>

  </div>
</div>
@endsection
