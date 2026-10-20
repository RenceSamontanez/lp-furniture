@extends('layouts.focus')

@section('title', 'Verify Your Identity — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/validation.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}"/>
@endpush

@section('content')
@php
  // is_validated on the user record is the single source of truth.
  // A validation record might be missing/stale, but is_validated is
  // what every other part of the app (cart, checkout, product page)
  // actually checks — so this page must agree with that.
  $isVerified = Auth::user()->is_validated;
  $isPending  = !$isVerified && isset($validation) && $validation->status === 'pending';
  $showForm   = !$isVerified && !$isPending;
  $forService = $forService ?? false; // true when reached from the Other Services request page
@endphp

<div class="validation-wrap">
  <div class="validation-container">

    @if($isVerified)
      <div class="val-status-banner approved">
        <div class="val-status-icon">✓</div>
        <div class="val-status-text">
          <h3>Identity Verified</h3>
          <p>{{ $forService ? 'Your account is fully verified. You can now send your service request.' : 'Your account is fully verified. You can browse and place orders.' }}</p>
        </div>
      </div>
    @elseif($isPending)
      <div class="val-status-banner pending">
        <div class="val-status-icon">⏳</div>
        <div class="val-status-text">
          <h3>Under Review</h3>
          <p>Submitted {{ $validation->submitted_at ? $validation->submitted_at->format('M d, Y') : '' }}. Review usually completes within 24 hours.</p>
        </div>
      </div>
    @elseif(isset($validation) && $validation->status === 'rejected')
      <div class="val-status-banner rejected">
        <div class="val-status-icon">✗</div>
        <div class="val-status-text">
          <h3>Verification Rejected</h3>
          <p>{{ $validation->rejection_reason ?? 'Please resubmit your documents.' }}</p>
        </div>
      </div>
    @endif

    <div class="val-top">
      <div>
        <h1 class="val-title">Verify Your Identity</h1>
        <p class="val-subtitle">{{ $forService ? 'One-time check before you send a service request. Documents are kept private and secure.' : 'One-time check before your first order. Documents are kept private and secure.' }}</p>
      </div>
      @if($showForm)
        <div class="val-requirements">
          <div class="val-req-chip"><strong>Government ID</strong>Front side, valid & clear</div>
          <div class="val-req-chip"><strong>Selfie with ID</strong>Face and ID visible</div>
          <div class="val-req-chip"><strong>Max 5MB</strong>JPG or PNG only</div>
        </div>
      @endif
    </div>

    @if($forService && $showForm)
      <x-notice variant="info" title="Important" style="margin-bottom:16px;">
        We need to check your ID before you can send a service request. This is a one-time check, and it is not an order.
        <x-slot:actions><a href="#val-form-card" class="lp-btn lp-btn--primary">Continue ID check</a></x-slot:actions>
      </x-notice>
    @endif

    @if($errors->any())
      <div class="val-alerts">
        @if($errors->any())
          <div class="auth-errors">
            @foreach($errors->all() as $error)
              <div class="auth-error-item">{{ $error }}</div>
            @endforeach
          </div>
        @endif
      </div>
    @endif

    @if($isVerified)

      <div class="val-empty-state">
        <div class="val-empty-icon">✅</div>
        @if($forService)
          <p>Your identity is verified. You can now send your service request.</p>
          <a href="{{ route('services.request') }}" class="lp-btn lp-btn--primary" style="margin-top:20px;">Continue to your service request</a>
        @else
        <p>Your identity is verified. Browse the catalog and place orders with confidence.</p>
        <a href="{{ route('catalog') }}" class="btn btn-gold" style="margin-top:20px;display:inline-flex;">Start Shopping →</a>
        @endif
      </div>

    @elseif($isPending)

      <div class="val-empty-state">
        <div class="val-empty-icon">⏳</div>
        <p>Your documents were submitted{{ $validation->submitted_at ? ' on ' . $validation->submitted_at->format('M d, Y') : '' }}. Admin will review within 24 hours.</p>
        @if($forService)
          <p>When your ID is approved, open Other Services again to send your service request.</p>
          <a href="{{ route('home') }}" class="lp-btn lp-btn--primary" style="margin-top:20px;">Back to home</a>
        @else
        <a href="{{ route('home') }}" class="btn btn-gold" style="margin-top:20px;display:inline-flex;">Browse Catalog →</a>
        @endif
      </div>

    @elseif($showForm)

      {{-- Terms modal --}}
      <div class="val-modal-overlay" id="val-terms-modal" role="dialog" aria-modal="true" aria-labelledby="val-modal-title">
        <div class="val-modal">
          <div class="val-modal-header">
            <h2 id="val-modal-title">Identity Verification Agreement</h2>
            <p>Please read before submitting your documents.</p>
          </div>
          <div class="val-modal-body">
            <div class="val-modal-section">
              <h3>Why we need your ID</h3>
              <p>LP Furniture requires identity verification to protect buyers and sellers from fraud, unauthorized account use, and chargebacks — especially for cash-on-delivery and installment orders.</p>
            </div>
            <div class="val-modal-section">
              <h3>How we use your information</h3>
              <ul>
                <li>Confirm that you are a real, accountable buyer</li>
                <li>Match your account to the person placing orders</li>
                <li>Review documents once for verification purposes only</li>
                <li>Store files securely on our private servers</li>
              </ul>
            </div>
            <div class="val-modal-section">
              <h3>What we do not do</h3>
              <ul>
                <li>We do not sell or share your ID with third parties</li>
                <li>We do not use your documents for marketing</li>
                <li>We do not publish or display your ID publicly</li>
              </ul>
            </div>
            <div class="val-modal-section">
              <h3>Your responsibility</h3>
              <p>By proceeding, you confirm that the documents you submit are genuine, belong to you, and that the information provided is accurate.</p>
            </div>
          </div>
          <div class="val-modal-footer">
            <label class="val-modal-check">
              <input type="checkbox" id="val-modal-terms-check" onchange="handleModalTermsChange(this)"/>
              <span>I have read and agree to the identity verification terms. I confirm my documents are genuine and belong to me.</span>
            </label>
            <button type="button" class="btn-val-modal-agree" id="val-modal-agree-btn" disabled onclick="acceptValidationTerms()">
              I Agree &amp; Continue
            </button>
          </div>
        </div>
      </div>

      <div class="val-form-card is-locked" id="val-form-card">
        <form method="POST" action="{{ route('validation.submit') }}" enctype="multipart/form-data" id="validation-form">
          @csrf

          <div class="val-upload-grid">
            <div class="val-upload-card" id="card-id">
              <input type="file" name="id_photo" class="val-file-input" accept="image/jpeg,image/png,image/jpg" onchange="handleFileUpload(this, 'id')"/>
              <img class="val-preview-thumb" id="preview-id" src="" alt="ID preview"/>
              <div class="val-upload-icon" id="icon-id">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <rect x="2" y="5" width="20" height="14" rx="2"/>
                  <circle cx="8" cy="11" r="2"/>
                  <path d="M14 9h4M14 12h4M14 15h2"/>
                </svg>
              </div>
              <div class="val-upload-label">Government ID</div>
              <div class="val-upload-hint">Passport, driver's license, SSS, PhilHealth, or voter's ID</div>
              <div class="val-upload-status"></div>
            </div>

            <div class="val-upload-card" id="card-selfie">
              <input type="file" name="selfie_photo" class="val-file-input" accept="image/jpeg,image/png,image/jpg" onchange="handleFileUpload(this, 'selfie')"/>
              <img class="val-preview-thumb" id="preview-selfie" src="" alt="Selfie preview"/>
              <div class="val-upload-icon" id="icon-selfie">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
              </div>
              <div class="val-upload-label">Selfie with ID</div>
              <div class="val-upload-hint">Hold your ID beside your face — both must be clearly visible</div>
              <div class="val-upload-status"></div>
            </div>
          </div>

          <div class="val-form-footer">
            <p class="val-privacy">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0110 0v4"/>
              </svg>
              Documents stored securely. Never shared with third parties.
            </p>
            <button type="submit" class="btn-val-submit" id="val-submit-btn" disabled>Submit for Verification</button>
          </div>
        </form>
      </div>

    @endif

  </div>
</div>
@endsection

@push('scripts')
  @if(isset($validation) && $validation->status === 'rejected')
    <script>sessionStorage.removeItem('lp_validation_terms_accepted');</script>
  @endif
  <script src="{{ asset('assets/js/validation.js') }}"></script>
@endpush