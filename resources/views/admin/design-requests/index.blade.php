@extends('layouts.admin')

@section('title', 'AI Design Requests — Admin')
@section('page-title', 'AI Design Requests')

@section('content')

<div class="admin-filter-tabs" style="margin-bottom:16px;">
  <a href="{{ route('admin.design-requests.index', ['status'=>'approved']) }}" class="admin-filter-tab {{ $status==='approved'?'active':'' }}">Awaiting Follow-up</a>
  <a href="{{ route('admin.design-requests.index', ['status'=>'declined']) }}" class="admin-filter-tab {{ $status==='declined'?'active':'' }}">Declined</a>
  <a href="{{ route('admin.design-requests.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
</div>

@if(session('success'))
  <div class="admin-toast admin-toast-success" style="margin:0 0 20px;">{{ session('success') }}</div>
@endif

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($requests->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>
        <p>No design requests here.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Design</th><th>Customer</th><th>Details</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($requests as $req)
            @php $selected = $req->suggestions->firstWhere('is_selected', true) ?? $req->suggestions->first(); @endphp
            <tr>
              <td data-label="Design">
                <div class="admin-product-thumb">
                  @if($selected)
                    <img src="{{ $selected->image_path }}" alt="Selected design"
                         onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                  @else
                    <img src="{{ asset('images/products/placeholder.jpg') }}" alt="No image"/>
                  @endif
                </div>
              </td>
              <td data-label="Customer">
                <div style="font-weight:600;color:#2A1810;">{{ $req->user->first_name }} {{ $req->user->last_name }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $req->user->email }}</div>
              </td>
              <td data-label="Details" style="max-width:320px;">
                <div style="font-size:12.5px;color:#3D3129;margin-bottom:4px;">{{ Str::limit($req->description, 120) }}</div>
                <div style="font-size:11px;color:var(--text-muted);">
                  @if($req->furniture_type)<span>{{ $req->furniture_type }}</span> · @endif
                  @if($req->preferred_material)<span>{{ $req->preferred_material }}</span> · @endif
                  @if($req->dimensions)<span>{{ $req->dimensions }}</span>@endif
                </div>
                @if($req->admin_contacted_at)
                  <div style="font-size:11px;color:var(--success);margin-top:4px;">✓ Contacted {{ $req->admin_contacted_at->diffForHumans() }}</div>
                @endif
              </td>
              <td data-label="Submitted">{{ $req->created_at->format('M d, Y') }}</td>
              <td data-label="Status">
                <span class="admin-pill {{ $req->status === 'declined' ? 'rejected' : 'approved' }}">
                  {{ ucfirst($req->status) }}
                </span>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="{{ route('design.show', $req->id) }}" target="_blank" class="admin-btn admin-btn-outline">View</a>
                  @if($req->status === 'approved')
                    <form method="POST" action="{{ route('admin.design-requests.contacted', $req->id) }}">
                      @csrf
                      <button type="submit" class="admin-btn admin-btn-success">Mark Contacted</button>
                    </form>
                    <button type="button" class="admin-btn admin-btn-danger"
                            onclick="openDesignRejectModal({{ $req->id }}, @js($req->furniture_type ?: 'this request'))">
                      Reject
                    </button>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $requests->appends(request()->query())->links() }}</div>

{{-- ══════════════════════════════════════════
     REJECT MODAL — optional note sent to the customer
═══════════════════════════════════════════ --}}
<div class="admin-modal-overlay" id="design-reject-modal-overlay" onclick="closeDesignRejectModalOnBackdrop(event)">
  <div class="admin-modal">
    <div class="admin-modal-header">
      <button type="button" class="admin-modal-back" onclick="closeDesignRejectModal()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        Back
      </button>
      <span class="admin-modal-title" id="design-reject-modal-title">Reject Request</span>
    </div>
    <div class="admin-modal-body">
      <p class="admin-modal-desc">Optionally tell the customer why — this note is sent to them directly.</p>
      <form method="POST" id="design-reject-form">
        @csrf
        <textarea name="admin_notes" class="form-input" rows="3" maxlength="1000"
                  placeholder="e.g. This design requires materials outside what we currently work with."></textarea>
        <div class="admin-modal-footer">
          <button type="button" class="admin-btn admin-btn-outline" onclick="closeDesignRejectModal()">Cancel</button>
          <button type="submit" class="admin-btn admin-btn-danger">Reject &amp; Notify Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
function openDesignRejectModal(id, label) {
  document.getElementById('design-reject-form').action = '/admin/design-requests/' + id + '/reject';
  document.getElementById('design-reject-modal-title').textContent = 'Reject "' + label + '"';
  document.getElementById('design-reject-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeDesignRejectModal() {
  document.getElementById('design-reject-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeDesignRejectModalOnBackdrop(e) {
  if (e.target.id === 'design-reject-modal-overlay') closeDesignRejectModal();
}
</script>
@endpush
