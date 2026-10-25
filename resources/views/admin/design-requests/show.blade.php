@extends('layouts.admin')

@section('title', 'Design Request — Admin')
@section('page-title', 'Design Request Details')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-ideas.css') }}"/>
@endpush

@section('content')

<a href="{{ route('admin.design-requests.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Design Requests</a>


@if($designRequest->pipeline_status !== null)
  <div class="admin-toast admin-toast-success" style="margin:0 0 20px;">
    This request was sent through the customer form. Work it in
    <a href="{{ route('admin.custom-orders.show', $designRequest->id) }}" style="font-weight:700;">Custom Orders</a>.
  </div>
@endif

@php
  $selected = $designRequest->suggestions->firstWhere('is_selected', true) ?? $designRequest->suggestions->first();
@endphp

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

  <div>
    {{-- Customer's request --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Customer's Request</div>
        <span class="admin-pill {{ $designRequest->status === 'declined' ? 'rejected' : 'approved' }}">
          {{ ucfirst($designRequest->status) }}
        </span>
      </div>
      <div class="admin-panel-body">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);margin-bottom:6px;">Prompt</div>
        <div style="font-size:13.5px;color:#2A1810;line-height:1.6;white-space:pre-line;margin-bottom:18px;padding:12px 14px;background:var(--cream);border-radius:10px;">{{ $designRequest->description }}</div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Furniture Type</div>
            <div style="font-size:13px;color:#2A1810;font-weight:500;">{{ $designRequest->furniture_type_label }}</div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Preferred Material</div>
            <div style="font-size:13px;color:#2A1810;font-weight:500;">{{ $designRequest->preferred_material ?: '—' }}</div>
          </div>
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:3px;">Dimensions</div>
            <div style="font-size:13px;color:#2A1810;font-weight:500;">{{ $designRequest->dimensions_label ?: '—' }}</div>
          </div>
        </div>
      </div>
    </div>

    {{-- Reference image — inspiration photo the customer uploaded,
         distinct from the AI-generated result below. Only shown
         when one was actually provided (this field is optional). --}}
    @if($designRequest->reference_image)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Customer's Reference Photo</div></div>
        <div class="admin-panel-body">
          @include('admin.custom-orders._reference-photo', ['r' => $designRequest])
        </div>
      </div>
    @endif

    {{-- Generated / selected design --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Generated Design</div></div>
      <div class="admin-panel-body">
        @if($selected)
          <div style="max-width:360px;border-radius:12px;overflow:hidden;border:1px solid var(--border);">
            <img src="{{ $selected->displayUrl() }}" alt="Generated design" style="width:100%;display:block;"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
          </div>
          @if($designRequest->suggestions->count() > 1)
            <div style="font-size:12px;color:var(--text-muted);margin-top:8px;">
              {{ $designRequest->suggestions->count() }} variations were generated for this request — showing the {{ $selected->is_selected ? 'customer-selected' : 'first' }} one.
            </div>
          @endif
        @else
          {{-- Reaching 'approved'/'declined' with zero suggestions
               only happens one way right now: the customer used "Send
               my request to the team without an image" after
               generation failed (selectDesign() requires a real
               suggestion_id, so there's no other path here) — labeled
               explicitly so this doesn't read as a bug or a missing
               image admin needs to go find. --}}
          <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#FFF3CD;border-radius:8px;font-size:13px;color:#6B4E00;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><strong>No image generated.</strong> The customer chose to send this request to the team without picking a design image.</span>
          </div>
        @endif
      </div>
    </div>

    {{-- Admin notes, if this was declined --}}
    @if($designRequest->admin_notes)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Note Sent to Customer</div></div>
        <div class="admin-panel-body" style="font-size:13.5px;color:var(--text-muted);">
          {{ $designRequest->admin_notes }}
        </div>
      </div>
    @endif
  </div>

  <div>
    {{-- Customer info --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Customer</div></div>
      <div class="admin-panel-body">
        <div style="font-size:14px;font-weight:600;color:var(--brown-deep);margin-bottom:4px;">
          {{ $designRequest->user->first_name }} {{ $designRequest->user->last_name }}
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:2px;">{{ $designRequest->user->email }}</div>
        <div style="font-size:13px;color:var(--text-muted);">{{ $designRequest->user->phone_number }}</div>
      </div>
    </div>

    {{-- Submission details --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Submission</div></div>
      <div class="admin-panel-body">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Submitted</span>
          <span>{{ $designRequest->created_at->format('M d, Y · h:i A') }}</span>
        </div>
        @if($designRequest->admin_contacted_at)
          <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--success);">
            <span>Contacted</span>
            <span>{{ $designRequest->admin_contacted_at->format('M d, Y · h:i A') }}</span>
          </div>
        @endif
      </div>
    </div>

    {{-- Actions — same actions as the list view, repeated here for
         when an admin is already deep in one request and wants to
         act without navigating back. List view keeps its own copy
         too, for fast triage across many requests without opening
         each one. --}}
    @if($designRequest->status === 'approved' && $designRequest->pipeline_status === null)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Actions</div></div>
        <div class="admin-panel-body">
          <form method="POST" action="{{ route('admin.design-requests.contacted', $designRequest->id) }}" style="margin-bottom:10px;">
            @csrf
            <button type="submit" class="admin-btn admin-btn-success" style="width:100%;justify-content:center;">Mark as Contacted</button>
          </form>

          <form method="POST" action="{{ route('admin.design-requests.reject', $designRequest->id) }}">
            @csrf
            <textarea name="admin_notes" class="form-input" rows="3" maxlength="1000"
                      placeholder="Optional note sent to the customer explaining why…" style="margin-bottom:10px;"></textarea>
            <button type="submit" class="admin-btn admin-btn-danger" style="width:100%;justify-content:center;">Reject &amp; Notify Customer</button>
          </form>
        </div>
      </div>
    @endif
  </div>

</div>

@endsection
