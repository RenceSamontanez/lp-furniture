@extends('layouts.admin')

@section('title', 'Review Validation — Admin')
@section('page-title', 'Review Identity Validation')

@section('content')

<a href="{{ route('admin.validations.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to List</a>

<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">{{ $validation->user->first_name }} {{ $validation->user->last_name }}</div>
    <span class="admin-pill {{ $validation->status }}">{{ $validation->status }}</span>
  </div>
  <div class="admin-panel-body">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Email</div>
        <div style="font-size:14px;color:var(--brown-deep);">{{ $validation->user->email }}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Phone</div>
        <div style="font-size:14px;color:var(--brown-deep);">{{ $validation->user->phone_number }}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Submitted</div>
        <div style="font-size:14px;color:var(--brown-deep);">{{ $validation->submitted_at ? $validation->submitted_at->format('M d, Y · h:i A') : '—' }}</div>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Account Created</div>
        <div style="font-size:14px;color:var(--brown-deep);">{{ $validation->user->created_at->format('M d, Y') }}</div>
      </div>
      @if($validation->reviewed_at)
        <div>
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:4px;">Reviewed On</div>
          <div style="font-size:14px;color:var(--brown-deep);">{{ $validation->reviewed_at->format('M d, Y · h:i A') }}</div>
        </div>
      @endif
    </div>

    {{-- Documents --}}
    <div class="admin-doc-grid">
      <div class="admin-doc-card">
        <div class="admin-doc-label">Government ID</div>
        @if($validation->id_photo_path)
          <img src="{{ route('admin.validations.document', ['id' => $validation->id, 'type' => 'id']) }}"
               class="admin-doc-img" alt="Government ID"
               onclick="window.open(this.src, '_blank')"/>
        @else
          <div class="admin-doc-empty">No ID uploaded</div>
        @endif
      </div>
      <div class="admin-doc-card">
        <div class="admin-doc-label">Selfie with ID</div>
        @if($validation->selfie_photo_path)
          <img src="{{ route('admin.validations.document', ['id' => $validation->id, 'type' => 'selfie']) }}"
               class="admin-doc-img" alt="Selfie with ID"
               onclick="window.open(this.src, '_blank')"/>
        @else
          <div class="admin-doc-empty">No selfie uploaded</div>
        @endif
      </div>
    </div>

    @if($validation->status === 'pending')
      <div style="display:flex;gap:12px;margin-top:20px;">
        <form method="POST" action="{{ route('admin.validations.approve', $validation->id) }}" style="flex:1;">
          @csrf
          <button type="submit" class="admin-btn admin-btn-success" style="width:100%;justify-content:center;padding:12px;font-size:14px;"
                  onclick="return confirm('Approve this identity verification?')">
            ✓ Approve Verification
          </button>
        </form>
      </div>

      <form method="POST" action="{{ route('admin.validations.reject', $validation->id) }}" style="margin-top:12px;">
        @csrf
        <label class="form-label">Rejection Reason (required if rejecting)</label>
        <textarea name="rejection_reason" class="form-input" rows="2" style="margin-bottom:10px;"
                  placeholder="e.g. ID photo is blurry, please resubmit with better lighting"></textarea>
        <button type="submit" class="admin-btn admin-btn-danger" style="width:100%;justify-content:center;padding:12px;font-size:14px;"
                onclick="return confirm('Reject this identity verification?')">
          ✗ Reject Verification
        </button>
      </form>
    @elseif($validation->status === 'rejected' && $validation->rejection_reason)
      <div style="margin-top:20px;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:10px;padding:14px;font-size:13px;color:var(--danger);">
        <strong>Rejection reason:</strong> {{ $validation->rejection_reason }}
      </div>
    @endif

  </div>
</div>

@endsection