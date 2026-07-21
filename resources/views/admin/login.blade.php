@extends('layouts.admin')

@section('title', 'Admin Login — LP Furniture')

@section('content')
<div class="admin-login-wrap">
  <div class="admin-login-card">

    <div class="admin-login-header">
      <div class="admin-login-icon">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="11" width="18" height="11" rx="2"/>
          <path d="M7 11V7a5 5 0 0110 0v4"/>
        </svg>
      </div>
      <h1 class="admin-login-title">Admin Access</h1>
      <p class="admin-login-sub">LP Furniture Management Panel</p>
    </div>

    @if(session('success'))
      <div class="admin-toast admin-toast-success" style="margin:0 0 16px;">{{ session('success') }}</div>
    @endif

    @if($errors->any())
      <div class="admin-toast admin-toast-error" style="margin:0 0 16px;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.login.post') }}">
      @csrf

      <div class="auth-field" style="margin-bottom:16px;">
        <label class="form-label">Admin Email</label>
        <input type="email" name="email" class="form-input"
               value="{{ old('email') }}" required autofocus/>
      </div>

      <div class="auth-field">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" required/>
      </div>

      <button type="submit" class="btn-auth-submit" style="margin-top:22px;">
        Sign In →
      </button>
    </form>

    <p style="text-align:center;margin-top:20px;font-size:12px;color:var(--text-muted);">
      <a href="{{ route('home') }}" style="color:var(--text-muted);">← Back to main site</a>
    </p>

  </div>
</div>
@endsection