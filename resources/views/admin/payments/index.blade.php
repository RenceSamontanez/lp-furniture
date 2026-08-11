@extends('layouts.admin')

@section('title', 'Payment Verification — Admin')
@section('page-title', 'GCash Payments')

@section('content')

<div class="admin-filter-tabs">
  <a href="{{ route('admin.payments.index', ['status'=>'pending']) }}" class="admin-filter-tab {{ $status==='pending'?'active':'' }}">Pending</a>
  <a href="{{ route('admin.payments.index', ['status'=>'paid']) }}" class="admin-filter-tab {{ $status==='paid'?'active':'' }}">Verified</a>
  <a href="{{ route('admin.payments.index', ['status'=>'failed']) }}" class="admin-filter-tab {{ $status==='failed'?'active':'' }}">Rejected</a>
  <a href="{{ route('admin.payments.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($payments->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        <p>No {{ $status !== 'all' ? $status : '' }} GCash payments found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Amount</th><th>Reference #</th><th>Status</th><th>Receipt</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($payments as $payment)
            <tr>
              <td data-label="Order #">{{ $payment->order->order_number ?? 'N/A' }}</td>
              <td data-label="Customer">{{ $payment->order->user->first_name ?? 'N/A' }} {{ $payment->order->user->last_name ?? '' }}</td>
              <td data-label="Amount">₱{{ number_format($payment->amount, 2) }}</td>
              <td data-label="Reference #">{{ $payment->gcash_reference_number ?? '—' }}</td>
              <td data-label="Status"><span class="admin-pill {{ $payment->payment_status }}">{{ $payment->payment_status }}</span></td>

              <td data-label="Receipt">
                @if($payment->receipt_image_path)
                  <button type="button" class="admin-btn admin-btn-outline"
                          onclick="openReceiptModal('{{ route('admin.payments.receipt', $payment->id) }}')">
                    View Receipt
                  </button>
                @else
                  <span class="admin-pill rejected">No Receipt</span>
                @endif
              </td>

              <td data-label="Actions">
                @if($payment->payment_status === 'pending')
                  <div class="admin-payment-row-actions">
                    <form method="POST" action="{{ route('admin.payments.approve', $payment->id) }}" style="display:inline;">
                      @csrf
                      <button type="submit" class="admin-btn admin-btn-success" onclick="return confirm('Verify this payment and confirm the order?')">
                        ✓ Verify
                      </button>
                    </form>
                    <button type="button" class="admin-btn admin-btn-danger"
                            onclick="openRejectModal('{{ route('admin.payments.reject', $payment->id) }}')">
                      ✕ Reject
                    </button>
                  </div>
                @elseif($payment->verifier)
                  <div style="font-size:11.5px;color:var(--text-muted);line-height:1.5;">
                    {{ $payment->payment_status === 'paid' ? 'Verified' : 'Rejected' }} by
                    <strong style="color:var(--brown-deep);">{{ $payment->verifier->first_name }} {{ $payment->verifier->last_name }}</strong>
                    <br>{{ $payment->verified_at?->format('M d, Y · h:i A') }}
                  </div>
                @else
                  <span style="color:var(--text-muted);font-size:12px;">—</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $payments->links() }}</div>

{{-- ══════════════════════════════════════════
     RECEIPT VIEW MODAL — with zoom toggle
═══════════════════════════════════════════ --}}
<div class="admin-modal-overlay" id="receipt-modal-overlay" onclick="closeReceiptModalOnBackdrop(event)">
  <div class="admin-modal admin-receipt-modal">
    <div class="admin-modal-header">
      <button type="button" class="admin-modal-back" onclick="closeReceiptModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Back
      </button>
      <span class="admin-modal-title">GCash Receipt</span>
    </div>
    <div class="admin-modal-body admin-receipt-modal-body">
      <img src="" id="receipt-modal-img" class="admin-receipt-modal-img" alt="GCash Receipt" onclick="toggleReceiptZoom()"/>
      <p class="admin-receipt-zoom-hint">Click the image to zoom in/out</p>
    </div>
  </div>
</div>

{{-- ══════════════════════════════════════════
     REJECT PAYMENT MODAL — reason selection
═══════════════════════════════════════════ --}}
<div class="admin-modal-overlay" id="reject-modal-overlay" onclick="closeRejectModalOnBackdrop(event)">
  <div class="admin-modal">
    <div class="admin-modal-header">
      <button type="button" class="admin-modal-back" onclick="closeRejectModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Back
      </button>
      <span class="admin-modal-title">Reject Payment</span>
    </div>
    <div class="admin-modal-body">
      <p class="admin-modal-desc">Select a reason for rejecting this payment. The customer will see this message.</p>

      <form method="POST" id="reject-payment-form">
        @csrf

        <div class="admin-reject-reason-list">
          <label class="admin-reject-reason-item">
            <input type="radio" name="reason_choice" value="Receipt is not visible" onchange="handleReasonChange(this)"/>
            <span>Receipt is not visible</span>
          </label>
          <label class="admin-reject-reason-item">
            <input type="radio" name="reason_choice" value="Receipt is incorrect" onchange="handleReasonChange(this)"/>
            <span>Receipt is incorrect</span>
          </label>
          <label class="admin-reject-reason-item">
            <input type="radio" name="reason_choice" value="Receipt is missing" onchange="handleReasonChange(this)"/>
            <span>Receipt is missing</span>
          </label>
          <label class="admin-reject-reason-item">
            <input type="radio" name="reason_choice" value="other" onchange="handleReasonChange(this)"/>
            <span>Other reason</span>
          </label>
        </div>

        <div class="admin-reject-custom-wrap" id="reject-custom-wrap">
          <label class="form-label">Custom message (optional)</label>
          <textarea name="custom_reason" id="reject-custom-textarea" class="form-input" rows="3"
                    placeholder="Type your own reason for this rejection..."></textarea>
        </div>

        <input type="hidden" name="rejection_reason" id="rejection_reason_final" value=""/>

        <div class="admin-modal-footer">
          <button type="button" class="admin-btn admin-btn-outline" onclick="closeRejectModal()">Cancel</button>
          <button type="submit" class="admin-btn admin-btn-danger" id="reject-submit-btn" disabled>
            Confirm Rejection
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
/* ══════════════════════════════════════════
   RECEIPT VIEW MODAL
══════════════════════════════════════════ */
function openReceiptModal(url) {
  const img = document.getElementById('receipt-modal-img');
  img.src = url;
  img.classList.remove('zoomed');
  document.getElementById('receipt-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeReceiptModal() {
  document.getElementById('receipt-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeReceiptModalOnBackdrop(e) {
  if (e.target.id === 'receipt-modal-overlay') closeReceiptModal();
}

function toggleReceiptZoom() {
  document.getElementById('receipt-modal-img').classList.toggle('zoomed');
}

/* ══════════════════════════════════════════
   REJECT PAYMENT MODAL
══════════════════════════════════════════ */
function openRejectModal(actionUrl) {
  document.getElementById('reject-payment-form').action = actionUrl;

  // Reset form state
  document.querySelectorAll('input[name="reason_choice"]').forEach(r => r.checked = false);
  document.getElementById('reject-custom-textarea').value = '';
  document.getElementById('reject-custom-wrap').classList.remove('visible');
  document.getElementById('rejection_reason_final').value = '';
  document.getElementById('reject-submit-btn').disabled = true;

  document.getElementById('reject-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
  document.getElementById('reject-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeRejectModalOnBackdrop(e) {
  if (e.target.id === 'reject-modal-overlay') closeRejectModal();
}

function handleReasonChange(radio) {
  const customWrap = document.getElementById('reject-custom-wrap');
  const isOther = radio.value === 'other';
  customWrap.classList.toggle('visible', isOther);

  updateFinalReason();
}

function updateFinalReason() {
  const selected = document.querySelector('input[name="reason_choice"]:checked');
  const customText = document.getElementById('reject-custom-textarea').value.trim();
  const finalInput = document.getElementById('rejection_reason_final');
  const submitBtn = document.getElementById('reject-submit-btn');

  if (!selected) {
    finalInput.value = '';
    submitBtn.disabled = true;
    return;
  }

  if (selected.value === 'other') {
    finalInput.value = customText;
    submitBtn.disabled = customText.length === 0;
  } else {
    finalInput.value = selected.value;
    submitBtn.disabled = false;
  }
}

document.getElementById('reject-custom-textarea')?.addEventListener('input', updateFinalReason);

/* Close either modal with Escape key */
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeReceiptModal();
    closeRejectModal();
  }
});
</script>
@endpush