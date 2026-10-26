@extends('layouts.admin')

@section('title', 'Customer Details — Admin')
@section('page-title', $customer->first_name . ' ' . $customer->last_name)

@section('content')

<a href="{{ route('admin.customers.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Customers</a>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Order History</div></div>
      <div class="admin-panel-body" style="padding:0;">
        @if($orders->isEmpty())
          <div class="admin-empty" style="padding:30px;"><p>No orders yet.</p></div>
        @else
          <table class="admin-table">
            <thead><tr><th>Order #</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
            <tbody>
              @foreach($orders as $order)
                <tr>
                  <td data-label="Order #">{{ $order->order_number }}</td>
                  <td data-label="Total">₱{{ number_format($order->total_amount, 2) }}</td>
                  <td data-label="Status"><span class="admin-pill {{ $order->order_status }}">{{ str_replace('_',' ',$order->order_status) }}</span></td>
                  <td data-label="Date">{{ $order->created_at->format('M d, Y') }}</td>
                  <td data-label=""><a href="{{ route('admin.orders.show', $order->id) }}" class="admin-btn admin-btn-outline">View</a></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

    <div class="admin-panel" id="name-history" style="margin-top:20px;">
      <div class="admin-panel-header"><div class="admin-panel-title">Name changes</div></div>
      <div class="admin-panel-body" style="padding:0;">
        @if($nameChanges->isEmpty())
          <div class="admin-empty" style="padding:24px;"><p>This customer has not changed their name.</p></div>
        @else
          <p style="margin:0;padding:14px 20px 0;font-size:13px;color:#6B5D52;">Changing a name does not reset identity verification. Check that the new name still matches the ID photo.</p>
          <table class="admin-table">
            <thead><tr><th>When</th><th>Old name</th><th>New name</th><th>Verified then</th></tr></thead>
            <tbody>
              @foreach($nameChanges as $c)
                <tr>
                  <td data-label="When">{{ $c->created_at->format('M d, Y g:i A') }}</td>
                  <td data-label="Old name">{{ $c->oldName() }}</td>
                  <td data-label="New name">{{ $c->newName() }}</td>
                  <td data-label="Verified then">{{ $c->was_verified ? 'Yes' : 'No' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>
  </div>

  <div>
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Customer Info</div></div>
      <div class="admin-panel-body">
        <div style="font-size:16px;font-weight:700;color:#2A1810;margin-bottom:4px;">{{ $customer->first_name }} {{ $customer->last_name }}</div>
        <div style="font-size:13px;color:#6B5D52;margin-bottom:2px;">{{ $customer->email }}</div>
        <div style="font-size:13px;color:#6B5D52;margin-bottom:16px;">{{ $customer->phone_number ?? 'No phone on file' }}</div>

        <div style="padding-top:14px;border-top:1px solid #E8DFD3;">
          <div class="admin-info-row"><span>Status</span>
            <span class="admin-pill {{ $customer->is_validated ? 'approved' : 'pending' }}">{{ $customer->is_validated ? 'Verified' : 'Unverified' }}</span>
          </div>
          <div class="admin-info-row"><span>Total Orders</span><strong>{{ $orders->count() }}</strong></div>
          <div class="admin-info-row"><span>Total Spent</span><strong>₱{{ number_format($totalSpent, 2) }}</strong></div>
          <div class="admin-info-row"><span>Joined</span><strong>{{ $customer->created_at->format('M d, Y') }}</strong></div>
          <div class="admin-info-row"><span>Email Verified</span><strong>{{ $customer->email_verified_at ? 'Yes' : 'No' }}</strong></div>
        </div>
      </div>
    </div>

    @if(!$customer->is_validated)
      <a href="{{ route('admin.validations.index') }}" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:12px;">
        Review Identity Validation
      </a>
    @endif
  </div>

</div>

@endsection