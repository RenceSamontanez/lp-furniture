@extends('layouts.app')

@section('title', 'GCash Payment — LP Furniture')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/payment.css') }}"/>
@endpush

@section('content')
<div class="payment-wrap">
  <div class="payment-container">

    {{-- Back --}}
    <a href="{{ route('orders.show', $order->id) }}" class="payment-back-link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      Back to My Order
    </a>

    {{-- Header --}}
    <div class="payment-header">
      <p class="gallery-eyebrow" style="margin-bottom:6px;">Manual Payment</p>
      <h1 class="payment-title">GCash Payment</h1>
      <p class="payment-subtitle">Send payment via GCash, then upload your receipt for verification.</p>
    </div>

    {{-- Order reference bar --}}
    <div class="payment-ref-bar">
      <div>
        <div class="payment-ref-label">Order Reference</div>
        <div class="payment-ref-value">{{ $order->order_number }}</div>
      </div>
      <div class="payment-ref-amount">
        <div class="payment-ref-amount-label">{{ $order->payment->payment_type === 'installment' ? 'Downpayment Due (50%)' : 'Amount Due' }}</div>
        <div class="payment-ref-amount-value">₱{{ number_format($order->payment->amount, 2) }}</div>
      </div>
    </div>

    @if($order->payment->payment_type === 'installment')
      <div class="auth-info-box" style="margin-bottom:20px;">
        This order total is ₱{{ number_format($order->total_amount, 2) }}. You're paying the 50% downpayment now — the remaining balance is split into installments you can review on your order page.
      </div>
    @endif

    {{-- Errors --}}
    @if($errors->any())
      <div class="auth-errors" style="margin-bottom:20px;">
        @foreach($errors->all() as $error)
          <div class="auth-error-item">⚠ {{ $error }}</div>
        @endforeach
      </div>
    @endif

    {{-- GCash card --}}
    <div class="gcash-card">

      <div class="gcash-card-header">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
        <div class="gcash-card-header-text">
          <h3>Pay with GCash</h3>
          <p>Scan the QR code or send to the account below</p>
        </div>
      </div>

      <div class="gcash-card-body">

        {{-- Steps --}}
        <div class="gcash-steps">
          <div class="gcash-step">
            <div class="gcash-step-num">1</div>
            <div class="gcash-step-text">Open your <strong>GCash app</strong> and scan the QR code below, or send directly to the account number shown.</div>
          </div>
          <div class="gcash-step">
            <div class="gcash-step-num">2</div>
            <div class="gcash-step-text">Send the <strong>exact amount</strong> shown — ₱{{ number_format($order->payment->amount, 2) }}.</div>
          </div>
          <div class="gcash-step">
            <div class="gcash-step-num">3</div>
            <div class="gcash-step-text">Upload a screenshot of your receipt below and enter the <strong>reference number</strong> from the transaction.</div>
          </div>
        </div>

        {{-- QR + account info --}}
        <div class="gcash-qr-layout">
          <div class="gcash-qr-box">
            @if(config('services.gcash.qr_image') && file_exists(public_path('images/' . config('services.gcash.qr_image'))))
              <img src="{{ asset('images/' . config('services.gcash.qr_image')) }}" alt="GCash QR Code" style="width:100%;height:100%;object-fit:contain;"/>
            @else
              <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                <line x1="14" y1="14" x2="14" y2="21"/><line x1="21" y1="14" x2="21" y2="21"/><line x1="14" y1="17.5" x2="21" y2="17.5"/>
              </svg>
            @endif
          </div>

          <div class="gcash-account-info">
            <div class="gcash-hash-badge">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
              Ref {{ $qrHash }}
            </div>

            <div class="gcash-info-row">
              <span class="gcash-info-label">Account Name</span>
              <span class="gcash-info-value">{{ config('services.gcash.account_name') ?? 'Contact us for GCash details' }}</span>
            </div>
            <div class="gcash-info-row">
              <span class="gcash-info-label">GCash Number</span>
              <span class="gcash-info-value">{{ config('services.gcash.account_number') ?? '—' }}</span>
            </div>
            <div class="gcash-info-row">
              <span class="gcash-info-label">Amount to Send</span>
              <span class="gcash-info-value amount">₱{{ number_format($order->payment->amount, 2) }}</span>
            </div>
          </div>
        </div>

        <div class="gcash-warning">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span>Add "{{ $qrHash }}" as your GCash payment note if possible — it helps us match your receipt to this order faster, though it's optional.</span>
        </div>

        {{-- Upload form --}}
        <form method="POST" action="{{ route('payment.gcash.upload') }}" enctype="multipart/form-data" id="gcash-upload-form">
          @csrf
          <input type="hidden" name="order_id" value="{{ $order->id }}"/>

          <div class="gcash-upload-section">
            <label class="gcash-upload-label">Upload Receipt Screenshot</label>
            <div class="gcash-upload-zone" id="gcash-upload-zone">
              <input type="file" name="receipt_image" id="gcash-receipt-input" class="gcash-upload-file-input"
                     accept="image/jpeg,image/png,image/jpg" required onchange="previewGcashReceipt(this)"/>
              <img src="" alt="Receipt preview" class="gcash-receipt-preview" id="gcash-receipt-preview"/>
              <div class="gcash-upload-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              </div>
              <div class="gcash-upload-text" id="gcash-upload-text">Tap to upload your GCash receipt</div>
              <div class="gcash-upload-hint">JPG or PNG · Max 5MB</div>
            </div>
          </div>

          <div class="gcash-ref-field">
            <label class="form-label">GCash Reference Number</label>
            <input type="text" name="reference_no" class="form-input" required minlength="8" maxlength="100"
                   placeholder="e.g. 1234567890123" value="{{ old('reference_no') }}"/>
          </div>

          <button type="submit" class="btn-submit-gcash">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
            Submit Receipt for Verification
          </button>
        </form>

      </div>
    </div>

    <a href="{{ route('orders.show', $order->id) }}" class="stripe-back-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M19 12H5M12 19l-7-7 7-7"/>
      </svg>
      View My Order
    </a>

  </div>
</div>
@endsection

@push('scripts')
<script>
function previewGcashReceipt(input) {
  const file = input.files[0];
  if (!file) return;

  const zone = document.getElementById('gcash-upload-zone');
  const preview = document.getElementById('gcash-receipt-preview');
  const text = document.getElementById('gcash-upload-text');

  const reader = new FileReader();
  reader.onload = (e) => {
    preview.src = e.target.result;
    zone.classList.add('uploaded');
    text.textContent = file.name;
  };
  reader.readAsDataURL(file);
}
</script>
@endpush
