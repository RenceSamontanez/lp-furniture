@extends('layouts.admin')

@section('title', 'Customers — Admin')
@section('page-title', 'Customers')

@section('content')

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['total'] }}</div><div class="admin-stat-label">Total Customers</div></div>
  </div>
  <div class="admin-stat-card accent-green">
    <div class="admin-stat-icon green">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['verified'] }}</div><div class="admin-stat-label">Verified</div></div>
  </div>
  <div class="admin-stat-card accent-red">
    <div class="admin-stat-icon red">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['unverified'] }}</div><div class="admin-stat-label">Unverified</div></div>
  </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
  <form method="GET" action="{{ route('admin.customers.index') }}" style="display:flex;gap:8px;">
    <input type="text" name="search" class="form-input" placeholder="Search name or email..." value="{{ $search }}" style="max-width:260px;"/>
    <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  </form>

  <div class="admin-filter-tabs" style="margin-bottom:0;">
    <a href="{{ route('admin.customers.index', ['filter'=>'all']) }}" class="admin-filter-tab {{ $filter==='all'?'active':'' }}">All</a>
    <a href="{{ route('admin.customers.index', ['filter'=>'verified']) }}" class="admin-filter-tab {{ $filter==='verified'?'active':'' }}">Verified</a>
    <a href="{{ route('admin.customers.index', ['filter'=>'unverified']) }}" class="admin-filter-tab {{ $filter==='unverified'?'active':'' }}">Unverified</a>
  </div>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($customers->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        <p>No customers found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Orders</th><th>Total Spent</th><th>Status</th><th>Joined</th><th></th></tr></thead>
        <tbody>
          @foreach($customers as $customer)
            <tr>
              <td data-label="Name"><div style="font-weight:600;color:#2A1810;">{{ $customer->first_name }} {{ $customer->last_name }}</div></td>
              <td data-label="Email">{{ $customer->email }}</td>
              <td data-label="Phone">{{ $customer->phone_number ?? '—' }}</td>
              <td data-label="Orders">{{ $customer->orders_count }}</td>
              <td data-label="Total Spent">₱{{ number_format($customer->total_spent, 2) }}</td>
              <td data-label="Status">
                <span class="admin-pill {{ $customer->is_validated ? 'approved' : 'pending' }}">
                  {{ $customer->is_validated ? 'Verified' : 'Unverified' }}
                </span>
              </td>
              <td data-label="Joined">{{ $customer->created_at->format('M d, Y') }}</td>
              <td data-label="">
                <a href="{{ route('admin.customers.show', $customer->id) }}" class="admin-btn admin-btn-gold">View</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $customers->appends(request()->query())->links() }}</div>

@endsection