@extends('layouts.app')

@section('title', 'My Profile — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/profile.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
@endpush

@section('content')
@php
  $validation = $user->validation;
  $verifyState = $user->is_validated
    ? 'verified'
    : ($validation && $validation->status === 'pending' ? 'pending' : ($validation && $validation->status === 'rejected' ? 'rejected' : 'unverified'));
  $verifyBadgeClass = $user->is_validated ? 'verified' : ($validation && $validation->status === 'pending' ? 'pending' : 'unverified');
  $verifyBadgeText = $user->is_validated ? 'Verified' : ($validation && $validation->status === 'pending' ? 'Under Review' : 'Not Verified');
@endphp

<div class="profile-wrap">
  <div class="profile-layout">

    {{-- Sidebar --}}
    <aside class="profile-sidebar">
      <div class="profile-user-card">
        <div class="profile-avatar">
          {{ strtoupper(substr($user->first_name, 0, 1) . substr($user->last_name, 0, 1)) }}
        </div>
        <div class="profile-user-meta">
          <div class="profile-user-name">{{ $user->first_name }} {{ $user->last_name }}</div>
          <div class="profile-user-email">{{ $user->email }}</div>
          <span class="profile-badge profile-badge--{{ $verifyBadgeClass }}">{{ $verifyBadgeText }}</span>
        </div>
      </div>

      <nav class="profile-section-nav" aria-label="Profile sections">
        <div class="profile-section-label">Settings</div>
        <button type="button" class="profile-section-btn is-active" data-profile-tab="personal">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Personal Info
        </button>
        <button type="button" class="profile-section-btn" data-profile-tab="security">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          Security
        </button>
        <button type="button" class="profile-section-btn" data-profile-tab="verification">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Verification
          @if(!$user->is_validated && (!$validation || $validation->status !== 'pending'))
            <span class="nav-badge">!</span>
          @endif
        </button>
        <button type="button" class="profile-section-btn" data-profile-tab="account">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          Account
        </button>
      </nav>

      

      <div class="profile-section-nav">
  {{-- Personal, Security, Verification, Account pill tabs --}}
</div>

{{-- Sign Out — OUTSIDE the scrollable pills, always visible --}}
<form method="POST" action="{{ route('logout') }}" class="profile-signout-form">
  @csrf
  <button type="submit" class="profile-signout-btn">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
      <polyline points="16,17 21,12 16,7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    Sign Out
  </button>
</form>
    </aside>

    {{-- Main --}}
    <div class="profile-main">

      {{-- Mobile tabs --}}
      <div class="profile-mobile-tabs" role="tablist">
        <button type="button" class="profile-mobile-tab is-active" data-profile-tab="personal">Personal</button>
        <button type="button" class="profile-mobile-tab" data-profile-tab="security">Security</button>
        <button type="button" class="profile-mobile-tab" data-profile-tab="verification">Verification</button>
        <button type="button" class="profile-mobile-tab" data-profile-tab="account">Account</button>
      </div>

      @if(session('success') || $errors->any())
        <div class="profile-alerts">
          @if(session('success'))
            <div class="auth-success">{{ session('success') }}</div>
          @endif
          @if($errors->any())
            <div class="auth-errors">
              @foreach($errors->all() as $error)
                <div class="auth-error-item">{{ $error }}</div>
              @endforeach
            </div>
          @endif
        </div>
      @endif

      {{-- Panel: Personal Info --}}
      <section class="profile-panel is-active" data-profile-panel="personal">
        <div class="profile-panel-card">
          <div class="profile-panel-head">
            <h1 class="profile-panel-title">Personal Information</h1>
            <p class="profile-panel-desc">Update your name, phone number, and delivery address.</p>
          </div>
          <div class="profile-panel-body">
            <form method="POST" action="{{ route('profile.update') }}">
              @csrf
              @method('PUT')

              <div class="profile-form-grid">
                <div class="profile-field">
                  <label class="form-label">First Name</label>
                  <input type="text" name="first_name" class="form-input"
                    value="{{ old('first_name', $user->first_name) }}" maxlength="50" required
                    oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.]/g, '')"/>
                </div>
                <div class="profile-field">
                  <label class="form-label">Last Name</label>
                  <input type="text" name="last_name" class="form-input"
                    value="{{ old('last_name', $user->last_name) }}" maxlength="50" required
                    oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.]/g, '')"/>
                </div>
              </div>

              <div class="profile-form-grid" style="margin-top:16px;">
                <div class="profile-field">
                  <label class="form-label">Email</label>
                  <div class="profile-display">{{ $user->email }}</div>
                  <p class="profile-field-hint">Email cannot be changed here.</p>
                </div>
                <div class="profile-field">
                  <label class="form-label">Phone Number</label>
                  <input type="tel" name="phone_number" class="form-input"
                    value="{{ old('phone_number', $user->phone_number) }}" maxlength="13"
                    placeholder="09171234567" required
                    oninput="this.value = this.value.replace(/[^\d\+]/g, '')"/>
                </div>
              </div>

              <div class="profile-field" style="margin-top:16px;">
                <label class="form-label">Delivery Address <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <textarea name="address" class="form-input" rows="2" placeholder="Home or office address for deliveries" style="resize:vertical;">{{ old('address', $user->address) }}</textarea>
              </div>

              <div class="profile-form-actions">
                <button type="submit" class="btn btn-gold">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
      </section>

      {{-- Panel: Security --}}
      <section class="profile-panel" data-profile-panel="security">
        <div class="profile-panel-card">
          <div class="profile-panel-head">
            <h1 class="profile-panel-title">Security</h1>
            <p class="profile-panel-desc">Manage your password and account access.</p>
          </div>
          <div class="profile-panel-body">
            @if($user->google_id && !$user->password)
              <div class="profile-security-note">
                You signed in with Google. Your account is secured through Google's authentication — no password is set.
              </div>
            @else
              <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="first_name" value="{{ $user->first_name }}"/>
                <input type="hidden" name="last_name" value="{{ $user->last_name }}"/>
                <input type="hidden" name="phone_number" value="{{ $user->phone_number }}"/>

                <div class="profile-password-grid">
                  <div class="profile-field">
                    <label class="form-label">New Password</label>
                    <div class="input-password-wrap">
                      <input type="password" name="password" id="new-password" class="form-input"
                        placeholder="Min. 8 characters" maxlength="20"
                        oninput="checkPasswordStrength(this.value)"/>
                      <button type="button" class="password-toggle" onclick="togglePassword('new-password', this)" aria-label="Show password">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                    </div>
                    <div class="password-strength" style="margin-top:6px;">
                      <div class="password-strength-bar"><div id="strength-bar"></div></div>
                      <span id="strength-label" class="password-strength-label"></span>
                    </div>
                  </div>
                  <div class="profile-field">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-password-wrap">
                      <input type="password" name="password_confirmation" id="confirm-password" class="form-input"
                        placeholder="Repeat new password" maxlength="20" oninput="checkPasswordMatch()"/>
                      <button type="button" class="password-toggle" onclick="togglePassword('confirm-password', this)" aria-label="Show password">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                    </div>
                    <span id="password-match-hint" class="password-match-hint"></span>
                  </div>
                </div>

                <div class="profile-form-actions">
                  <button type="submit" class="btn btn-primary">Update Password</button>
                </div>
              </form>
            @endif
          </div>
        </div>
      </section>

      {{-- Panel: Verification --}}
      <section class="profile-panel" data-profile-panel="verification">
        <div class="profile-panel-card">
          <div class="profile-panel-head">
            <h1 class="profile-panel-title">Identity Verification</h1>
            <p class="profile-panel-desc">Required to place orders and access all features.</p>
          </div>
          <div class="profile-panel-body">
            <div class="profile-verify-banner">
              <div class="profile-verify-icon profile-verify-icon--{{ $verifyState }}">
                @if($user->is_validated)
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                @elseif($validation && $validation->status === 'pending')
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                @elseif($validation && $validation->status === 'rejected')
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                @else
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                @endif
              </div>
              <div class="profile-verify-content">
                @if($user->is_validated)
                  <h3>Verified</h3>
                  <p>Your identity has been confirmed. You have full access to ordering and checkout.</p>
                @elseif($validation && $validation->status === 'pending')
                  <h3>Under Review</h3>
                  <p>Documents submitted{{ $validation->submitted_at ? ' on ' . $validation->submitted_at->format('M d, Y') : '' }}. Review usually completes within 24 hours.</p>
                @elseif($validation && $validation->status === 'rejected')
                  <h3>Rejected</h3>
                  <p>{{ $validation->rejection_reason ?? 'Please resubmit your documents with clearer images.' }}</p>
                @else
                  <h3>Not Verified</h3>
                  <p>Submit a government ID and selfie to unlock ordering and custom design features.</p>
                @endif
              </div>
            </div>

            @if(!$user->is_validated)
              <div class="profile-verify-steps">
                <div class="profile-verify-step">
                  <div class="profile-verify-step-num">1</div>
                  <p>Upload a valid government ID</p>
                </div>
                <div class="profile-verify-step">
                  <div class="profile-verify-step-num">2</div>
                  <p>Take a clear selfie photo</p>
                </div>
                <div class="profile-verify-step">
                  <div class="profile-verify-step-num">3</div>
                  <p>Wait for admin review</p>
                </div>
              </div>

              <div class="profile-form-actions">
                <a href="{{ route('validation.show') }}" class="btn btn-gold">
                  {{ ($validation && $validation->status === 'pending') ? 'View Submission' : ($validation && $validation->status === 'rejected' ? 'Resubmit Documents' : 'Start Verification →') }}
                </a>
              </div>
            @endif

            <p class="profile-info-note">Documents are stored securely and never shared with third parties.</p>
          </div>
        </div>
      </section>

      {{-- Panel: Account --}}
      <section class="profile-panel" data-profile-panel="account">
        <div class="profile-panel-card">
          <div class="profile-panel-head">
            <h1 class="profile-panel-title">Account Information</h1>
            <p class="profile-panel-desc">Overview of your account status and membership.</p>
          </div>
          <div class="profile-panel-body">
            <div class="profile-stats">
              <div class="profile-stat">
                <div class="profile-stat-label">Account Type</div>
                <div class="profile-stat-value">{{ $user->role }}</div>
              </div>
              <div class="profile-stat">
                <div class="profile-stat-label">Member Since</div>
                <div class="profile-stat-value">{{ $user->created_at->format('M d, Y') }}</div>
              </div>
              <div class="profile-stat">
                <div class="profile-stat-label">Email Status</div>
                <div class="profile-stat-value {{ $user->email_verified_at ? 'profile-stat-value--success' : 'profile-stat-value--danger' }}">
                  {{ $user->email_verified_at ? 'Verified' : 'Unverified' }}
                </div>
              </div>
              <div class="profile-stat">
                <div class="profile-stat-label">Account Status</div>
                <div class="profile-stat-value {{ $user->account_status === 'active' ? 'profile-stat-value--success' : 'profile-stat-value--danger' }}">
                  {{ $user->account_status }}
                </div>
              </div>
              <div class="profile-stat">
                <div class="profile-stat-label">Sign-in Method</div>
                <div class="profile-stat-value profile-stat-value--muted">
                  {{ $user->google_id ? 'Google' : 'Email & Password' }}
                </div>
              </div>
              <div class="profile-stat">
                <div class="profile-stat-label">Identity</div>
                <div class="profile-stat-value {{ $user->is_validated ? 'profile-stat-value--success' : 'profile-stat-value--danger' }}">
                  {{ $user->is_validated ? 'Verified' : 'Unverified' }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>
@endsection

@push('scripts')
  @if($errors->has('password') || $errors->has('password_confirmation'))
    <script>window.profileDefaultPanel = 'security';</script>
  @endif
  <script src="{{ asset('assets/js/auth.js') }}"></script>
  <script src="{{ asset('assets/js/profile.js') }}"></script>
@endpush
