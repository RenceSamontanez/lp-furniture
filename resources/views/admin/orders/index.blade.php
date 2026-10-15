@extends('layouts.admin')

@section('title', 'Orders — Admin')
@section('page-title', 'Orders')

@section('content')

<div class="admin-filter-tabs">
  <a href="{{ route('admin.orders.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
  <a href="{{ route('admin.orders.index', ['status'=>'pending']) }}" class="admin-filter-tab {{ $status==='pending'?'active':'' }}">Pending</a>
  <a href="{{ route('admin.orders.index', ['status'=>'confirmed']) }}" class="admin-filter-tab {{ $status==='confirmed'?'active':'' }}">Confirmed</a>
  <a href="{{ route('admin.orders.index', ['status'=>'in_production']) }}" class="admin-filter-tab {{ $status==='in_production'?'active':'' }}">In Production</a>
  <a href="{{ route('admin.orders.index', ['status'=>'ready']) }}" class="admin-filter-tab {{ $status==='ready'?'active':'' }}">Ready</a>
  <a href="{{ route('admin.orders.index', ['status'=>'out_for_delivery']) }}" class="admin-filter-tab {{ $status==='out_for_delivery'?'active':'' }}">Out for Delivery</a>
  <a href="{{ route('admin.orders.index', ['status'=>'completed']) }}" class="admin-filter-tab {{ $status==='completed'?'active':'' }}">Completed</a>
  <a href="{{ route('admin.orders.index', ['status'=>'cancelled']) }}" class="admin-filter-tab {{ $status==='cancelled'?'active':'' }}">Cancelled</a>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($orders->isEmpty())
      <div class="admin-empty"><p>No orders found.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Order #</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
          @foreach($orders as $order)
            <tr>
              <td data-label="Order #">{{ $order->order_number }}
                @if($order->design_agreement_id)
                  <span class="admin-pill confirmed" style="font-size:10px;margin-left:6px;">Customized</span>
                  @if($order->isOverdueUnpaid())<span class="admin-pill rejected" style="font-size:10px;margin-left:4px;">Overdue, unpaid</span>@endif
                @endif
              </td>
              <td data-label="Customer">{{ $order->user->first_name }} {{ $order->user->last_name }}</td>
              <td data-label="Items">{{ $order->items->count() }} item(s)</td>
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

<div style="margin-top:16px;">{{ $orders->links() }}</div>

@endsection