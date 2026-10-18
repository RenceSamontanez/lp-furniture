@extends('layouts.admin')

@section('title', 'Cancellations & Refunds — Admin')
@section('page-title', 'Cancellations & Refunds')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-custom-orders.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-service-inquiries.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-refunds.css') }}"/>
@endpush

@section('content')
<div class="co-page">
@php
  $labels = \App\Models\ServiceRefundRequest::STATUSES;
  $keep   = fn (array $extra) => array_filter(array_merge(['q' => $search, 'work' => $work, 'method' => $method, 'status' => $status], $extra));
@endphp

<p class="si-hint" style="margin:0 0 14px;">Only Other Services cancellation and refund requests are listed here. Refunds are paid outside the system: this screen records them.</p>

<div class="co-counts">
  <a href="{{ route('admin.refunds.index', $keep(['status' => null])) }}" class="co-count {{ !$status ? 'active' : '' }}">
    <div class="co-count-num">{{ $total }}</div>
    <div class="co-count-label">All requests</div>
  </a>
  @foreach($labels as $key => $label)
    <a href="{{ route('admin.refunds.index', $keep(['status' => $key])) }}" class="co-count {{ $status === $key ? 'active' : '' }}">
      <div class="co-count-num">{{ $counts[$key] ?? 0 }}</div>
      <div class="co-count-label">{{ $label }}</div>
    </a>
  @endforeach
</div>

<form method="GET" action="{{ route('admin.refunds.index') }}" class="rf-filters">
  @if($status)<input type="hidden" name="status" value="{{ $status }}"/>@endif
  <label class="rf-sr" for="rf-work">Work state</label>
  <select name="work" id="rf-work" class="form-input" onchange="this.form.submit()">
    <option value="">Any work state</option>
    @foreach(\App\Support\ServiceWorkState::LABELS as $k => $label)<option value="{{ $k }}" @selected($work === $k)>{{ $label }}</option>@endforeach
  </select>
  <label class="rf-sr" for="rf-method">Refund method</label>
  <select name="method" id="rf-method" class="form-input" onchange="this.form.submit()">
    <option value="">Any method</option>
    @foreach(\App\Models\ServiceRefundRequest::METHODS as $k => $label)<option value="{{ $k }}" @selected($method === $k)>{{ $label }}</option>@endforeach
  </select>
  <label class="rf-sr" for="rf-q">Search</label>
  <input type="search" name="q" id="rf-q" value="{{ $search }}" class="form-input rf-q" placeholder="Reference, invoice number, customer name or phone"/>
  <button type="submit" class="lp-btn lp-btn--secondary">Search</button>
  @if($search !== '' || $work || $method)
    <a href="{{ route('admin.refunds.index', array_filter(['status' => $status])) }}" class="lp-btn lp-btn--secondary">Clear</a>
  @endif
</form>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($requests->isEmpty())
      <div class="admin-empty"><p>No requests match.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Reference</th><th>Customer</th><th>Paid</th><th>Work</th><th>Refund by</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($requests as $r)
            <tr @class(['rf-overdue' => $r->isOverdue()])>
              <td data-label="Reference">
                <div style="font-weight:600;color:#2A1810;">{{ $r->reference }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $r->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') }}</div>
              </td>
              <td data-label="Customer">
                <div style="font-weight:600;color:#2A1810;">{{ $r->inquiry->name }}</div>
                <div style="font-size:11.5px;color:var(--text-muted);">{{ $r->contact_phone }}@if($r->invoice) · {{ $r->invoice->invoice_number }}@endif</div>
              </td>
              <td data-label="Paid">₱{{ number_format((float) $r->amount_paid_snapshot, 2) }}</td>
              <td data-label="Work">{{ \App\Support\ServiceWorkState::label($r->work_state_snapshot) }}</td>
              <td data-label="Refund by">
                {{ $r->methodLabel() ?? '—' }}
                @if($r->refund_method === 'gcash')<div class="rf-mask" style="font-size:11.5px;color:var(--text-muted);">{{ $r->maskedGcash() }}</div>@endif
                @if($r->refund_due_at && in_array($r->status, ['approved', 'refund_problem'], true))
                  <div style="font-size:11.5px;color:var(--text-muted);">due {{ $r->refund_due_at->timezone('Asia/Manila')->format('M d') }}</div>
                @endif
                @if($r->isOverdue())<span class="rf-overdue-tag">Overdue</span>@endif
              </td>
              <td data-label="Status"><span class="co-chip co-chip--{{ in_array($r->status, ['declined', 'closed'], true) ? 'rf-' . $r->status : $r->status }}">{{ $r->statusLabel() }}</span></td>
              <td data-label=""><a href="{{ route('admin.refunds.show', $r->id) }}" class="lp-btn lp-btn--secondary">Open</a></td>
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
