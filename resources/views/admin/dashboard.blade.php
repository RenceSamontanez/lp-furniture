@extends('layouts.admin')

@section('title', 'Dashboard — Admin')
@section('page-title', 'Dashboard')

@section('content')

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $stats['pending_validations'] }}</div>
      <div class="admin-stat-label">Pending Validations</div>
    </div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-icon blue">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $stats['pending_orders'] }}</div>
      <div class="admin-stat-label">Pending Orders</div>
    </div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-icon green">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><path d="M16 10a4 4 0 01-8 0"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $stats['total_orders'] }}</div>
      <div class="admin-stat-label">Total Orders</div>
    </div>
  </div>

  <div class="admin-stat-card">
    <div class="admin-stat-icon purple">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $stats['total_customers'] }}</div>
      <div class="admin-stat-label">Total Customers</div>
    </div>
  </div>
</div>

{{-- Pending validations preview --}}
<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">Pending Identity Validations</div>
    <a href="{{ route('admin.validations.index') }}" class="admin-btn admin-btn-outline">View All →</a>
  </div>
  <div class="admin-panel-body" style="padding:0;">
    @if($recentValidations->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 6L9 17l-5-5"/></svg>
        <p>No pending validations. All caught up!</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Customer</th><th>Email</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          @foreach($recentValidations as $v)
            <tr>
              <td data-label="Customer">{{ $v->user->first_name }} {{ $v->user->last_name }}</td>
              <td data-label="Email">{{ $v->user->email }}</td>
              <td data-label="Submitted">{{ $v->submitted_at ? $v->submitted_at->diffForHumans() : '—' }}</td>
              <td data-label=""><a href="{{ route('admin.validations.show', $v->id) }}" class="admin-btn admin-btn-gold">Review</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

{{-- Recent orders preview --}}
<div class="admin-panel">
  <div class="admin-panel-header">
    <div class="admin-panel-title">Recent Orders</div>
    <a href="{{ route('admin.orders.index') }}" class="admin-btn admin-btn-outline">View All →</a>
  </div>
  <div class="admin-panel-body" style="padding:0;">
    @if($recentOrders->isEmpty())
      <div class="admin-empty">
        <p>No orders yet.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($recentOrders as $order)
            <tr>
              <td data-label="Order #">{{ $order->order_number }}</td>
              <td data-label="Customer">{{ $order->user->first_name }} {{ $order->user->last_name }}</td>
              <td data-label="Total">₱{{ number_format($order->total_amount, 2) }}</td>
              <td data-label="Status"><span class="admin-pill {{ $order->order_status }}">{{ str_replace('_',' ',$order->order_status) }}</span></td>
              <td data-label=""><a href="{{ route('admin.orders.show', $order->id) }}" class="admin-btn admin-btn-gold">View</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

@endsection