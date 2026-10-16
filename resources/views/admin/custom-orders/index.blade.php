@extends('layouts.admin')

@section('title', 'Custom Orders — Admin')
@section('page-title', 'Custom Orders')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php $labels = \App\Support\CustomOrderPipeline::LABELS; @endphp

<div class="co-counts">
  <a href="{{ route('admin.custom-orders.index', array_filter(['q' => $search])) }}" class="co-count {{ !$status ? 'active' : '' }}">
    <div class="co-count-num">{{ $total }}</div>
    <div class="co-count-label">All requests</div>
  </a>
  @foreach($labels as $key => $label)
    @if(($counts[$key] ?? 0) > 0 || $status === $key || in_array($key, ['submitted', 'contacting', 'unreachable']))
      <a href="{{ route('admin.custom-orders.index', array_filter(['status' => $key, 'q' => $search])) }}" class="co-count {{ $status === $key ? 'active' : '' }}">
        <div class="co-count-num">{{ $counts[$key] ?? 0 }}</div>
        <div class="co-count-label">{{ $label }}</div>
      </a>
    @endif
  @endforeach
</div>

<form method="GET" action="{{ route('admin.custom-orders.index') }}" class="co-search">
  @if($status)<input type="hidden" name="status" value="{{ $status }}"/>@endif
  <input type="search" name="q" value="{{ $search }}" class="form-input" placeholder="Search by customer name, email or phone"/>
  <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  @if($search !== '')
    <a href="{{ route('admin.custom-orders.index', array_filter(['status' => $status])) }}" class="admin-btn admin-btn-outline">Clear</a>
  @endif
</form>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($requests->isEmpty())
      <div class="admin-empty"><p>No custom order requests match.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Design</th><th>Customer</th><th>Contact</th><th>Call window</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($requests as $req)
            <tr>
              <td data-label="Design">
                <div style="font-weight:600;color:#2A1810;">{{ $req->title }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">
                  {{ $req->request_mode === 'modified' ? 'With changes' : 'Exact design' }}
                  @if(!$req->selected_suggestion_id) · No image generated @endif
                </div>
              </td>
              <td data-label="Customer">
                <div style="font-weight:600;color:#2A1810;">{{ $req->user->display_name }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $req->user->email }}</div>
              </td>
              <td data-label="Contact">
                <div style="font-size:12.5px;">{{ $req->contact_phone }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $req->contact_email }}</div>
              </td>
              <td data-label="Call window">{{ $req->callWindowLabel() }}</td>
              <td data-label="Submitted">{{ $req->submitted_at?->format('M d, Y h:i A') }}</td>
              <td data-label="Status">
                <span class="co-chip co-chip--{{ $req->pipeline_status }}">{{ $labels[$req->pipeline_status] ?? $req->pipeline_status }}</span>
              </td>
              <td data-label="">
                <a href="{{ route('admin.custom-orders.show', $req->id) }}" class="admin-btn admin-btn-outline">Open</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $requests->links() }}</div>
</div>
@endsection
