@extends('layouts.admin')

@section('title', 'Other Services — Admin')
@section('page-title', 'Other Services')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php $labels = \App\Models\ServiceInquiry::STATUSES; @endphp

<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
  <a href="{{ route('admin.service-inquiries.create') }}" class="admin-btn admin-btn-gold">New ticket</a>
</div>

<div class="co-counts">
  <a href="{{ route('admin.service-inquiries.index', array_filter(['q' => $search])) }}" class="co-count {{ !$status && !$ready ? 'active' : '' }}">
    <div class="co-count-num">{{ $total }}</div>
    <div class="co-count-label">All tickets</div>
  </a>
  <a href="{{ route('admin.service-inquiries.index', array_filter(['ready' => 1, 'q' => $search])) }}" class="co-count {{ $ready ? 'active' : '' }}">
    <div class="co-count-num">{{ $readyCount }}</div>
    <div class="co-count-label">Ready to start ({{ $readyCount }})</div>
  </a>
  @foreach($labels as $key => $label)
    <a href="{{ route('admin.service-inquiries.index', array_filter(['status' => $key, 'q' => $search])) }}" class="co-count {{ $status === $key ? 'active' : '' }}">
      <div class="co-count-num">{{ $counts[$key] ?? 0 }}</div>
      <div class="co-count-label">{{ $label }}</div>
    </a>
  @endforeach
</div>

<form method="GET" action="{{ route('admin.service-inquiries.index') }}" class="co-search">
  @if($status)<input type="hidden" name="status" value="{{ $status }}"/>@endif
  @if($ready)<input type="hidden" name="ready" value="1"/>@endif
  <input type="search" name="q" value="{{ $search }}" class="form-input" placeholder="Search by name, reference or phone"/>
  <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  @if($search !== '')
    <a href="{{ route('admin.service-inquiries.index', array_filter(['status' => $status])) }}" class="admin-btn admin-btn-outline">Clear</a>
  @endif
</form>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($tickets->isEmpty())
      <div class="admin-empty"><p>No tickets match.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Reference</th><th>Customer</th><th>Service</th><th>Call time</th><th>Received</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($tickets as $t)
            <tr>
              <td data-label="Reference">
                <div style="font-weight:600;color:#2A1810;">{{ $t->reference_number }}</div>
                @if($t->created_by_admin_id)<div style="font-size:11.5px;color:var(--text-muted);">Entered by staff</div>@endif
              </td>
              <td data-label="Customer">
                <div style="font-weight:600;color:#2A1810;">{{ $t->name }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $t->phone }}@if(!$t->user_id) · No account @endif</div>
              </td>
              <td data-label="Service">
                <div>{{ $t->serviceLabel() }}</div>
                @if($t->furniture_type)<div style="font-size:11.5px;color:var(--text-muted);">{{ $t->furniture_type }}</div>@endif
              </td>
              <td data-label="Call time">{{ $t->callTimeSummary() ?? 'Any time' }}</td>
              <td data-label="Received">{{ $t->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</td>
              <td data-label="Status"><span class="co-chip co-chip--{{ $t->status }}">{{ $t->statusLabel() }}</span></td>
              <td data-label="">
                <a href="{{ route('admin.service-inquiries.show', $t->id) }}" class="admin-btn admin-btn-outline">Open</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $tickets->links() }}</div>
</div>
@endsection
