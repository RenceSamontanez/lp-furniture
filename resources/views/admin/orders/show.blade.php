@extends('layouts.admin')

@section('title', 'Order Details — Admin')
@section('page-title', 'Order ' . $order->order_number)

@section('content')

<a href="{{ route('admin.orders.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Orders</a>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

  <div>
    {{-- Items --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Order Items</div>
        <span class="admin-pill {{ $order->order_status }}">{{ str_replace('_',' ',$order->order_status) }}</span>
      </div>
      <div class="admin-panel-body" style="padding:0;">
        <table class="admin-table">
          <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
          <tbody>
            @foreach($order->items as $item)
              <tr>
                <td data-label="Product">{{ $item->product->product_name ?? 'N/A' }}</td>
                <td data-label="Qty">{{ $item->quantity }}</td>
                <td data-label="Price">₱{{ number_format($item->price, 2) }}</td>
                <td data-label="Subtotal">₱{{ number_format($item->price * $item->quantity, 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    {{-- Customer instructions --}}
    @if($order->customization_note)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Customer Instructions</div></div>
        <div class="admin-panel-body" style="font-size:13.5px;color:var(--text-muted);">
          {{ $order->customization_note }}
        </div>
      </div>
    @endif

    {{-- Admin notes --}}
    @if($order->notes)
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Admin Notes</div></div>
        <div class="admin-panel-body" style="font-size:13.5px;color:var(--text-muted);">
          {{ $order->notes }}
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
          {{ $order->user->first_name }} {{ $order->user->last_name }}
        </div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:2px;">{{ $order->user->email }}</div>
        <div style="font-size:13px;color:var(--text-muted);">{{ $order->user->phone_number }}</div>
      </div>
    </div>

    {{-- Order summary --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Summary</div></div>
      <div class="admin-panel-body">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Delivery Option</span>
          <span style="text-transform:capitalize;">{{ $order->delivery_option }}</span>
        </div>

        @if($order->delivery_option === 'delivery' && $order->delivery_address)
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;padding:8px;background:var(--cream);border-radius:8px;">
            {{ $order->delivery_address }}
          </div>
        @endif

        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Delivery Fee</span>
          <span>₱{{ number_format($order->delivery_fee, 2) }}</span>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;margin-bottom:8px;">
          <span style="color:var(--text-muted);">Production Status</span>
          <span class="admin-pill {{ $order->production_status }}" style="font-size:10px;">{{ str_replace('_',' ',$order->production_status) }}</span>
        </div>

        <div style="display:flex;justify-content:space-between;font-size:16px;font-weight:700;color:var(--brown-deep);padding-top:10px;border-top:2px solid var(--brown-deep);margin-top:10px;">
          <span>Total</span>
          <span>₱{{ number_format($order->total_amount, 2) }}</span>
        </div>
      </div>
    </div>

    {{-- Update status --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Update Status</div></div>
      <div class="admin-panel-body">
        @if($order->statusUpdatedBy)
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border);">
            Last updated by <strong style="color:var(--brown-deep);">{{ $order->statusUpdatedBy->first_name }} {{ $order->statusUpdatedBy->last_name }}</strong>
            on {{ $order->status_updated_at?->format('M d, Y · h:i A') }}
          </div>
        @endif
        <form method="POST" action="{{ route('admin.orders.updateStatus', $order->id) }}">
          @csrf
          <select name="order_status" class="form-input" style="margin-bottom:12px;">
            @foreach(['pending','confirmed','in_production','ready','out_for_delivery','completed','cancelled'] as $s)
              <option value="{{ $s }}" {{ $order->order_status === $s ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
            @endforeach
          </select>
          <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:11px;">
            Update Status
          </button>
        </form>
      </div>
    </div>
  </div>

</div>

@endsection