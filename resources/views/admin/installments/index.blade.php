@extends('layouts.admin')

@section('title', 'Installments — Admin')
@section('page-title', 'Installment Schedules')

@section('content')

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['pending'] }}</div><div class="admin-stat-label">Upcoming</div></div>
  </div>
  <div class="admin-stat-card accent-red">
    <div class="admin-stat-icon red">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['overdue'] }}</div><div class="admin-stat-label">Overdue</div></div>
  </div>
  <div class="admin-stat-card accent-green">
    <div class="admin-stat-icon green">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['paid'] }}</div><div class="admin-stat-label">Paid</div></div>
  </div>
</div>

<div class="admin-filter-tabs">
  <a href="{{ route('admin.installments.index', ['status'=>'pending']) }}" class="admin-filter-tab {{ $status==='pending'?'active':'' }}">Upcoming</a>
  <a href="{{ route('admin.installments.index', ['status'=>'overdue']) }}" class="admin-filter-tab {{ $status==='overdue'?'active':'' }}">Overdue</a>
  <a href="{{ route('admin.installments.index', ['status'=>'paid']) }}" class="admin-filter-tab {{ $status==='paid'?'active':'' }}">Paid</a>
  <a href="{{ route('admin.installments.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($installments->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        <p>No {{ $status !== 'all' ? $status : '' }} installments found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Installment #</th><th>Amount Due</th><th>Due Date</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($installments as $inst)
            <tr>
              <td data-label="Order #">{{ $inst->order->order_number ?? 'N/A' }}</td>
              <td data-label="Customer">{{ $inst->order->user->first_name ?? 'N/A' }} {{ $inst->order->user->last_name ?? '' }}</td>
              <td data-label="Installment #">#{{ $inst->installment_no }}</td>
              <td data-label="Amount Due">₱{{ number_format($inst->amount_due, 2) }}</td>
              <td data-label="Due Date">{{ \Carbon\Carbon::parse($inst->due_date)->format('M d, Y') }}</td>
              <td data-label="Status"><span class="admin-pill {{ $inst->status === 'paid' ? 'approved' : ($inst->status === 'overdue' ? 'rejected' : 'pending') }}">{{ $inst->status }}</span></td>
              <td data-label="">
                @if($inst->status !== 'paid')
                  <form method="POST" action="{{ route('admin.installments.markPaid', $inst->id) }}"
                        onsubmit="return confirm('Mark this installment as paid?')">
                    @csrf
                    <button type="submit" class="admin-btn admin-btn-success">✓ Mark Paid</button>
                  </form>
                @else
                  <span style="font-size:12px;color:var(--text-muted);">Paid {{ $inst->paid_at ? \Carbon\Carbon::parse($inst->paid_at)->format('M d, Y') : '' }}</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $installments->links() }}</div>

@endsection