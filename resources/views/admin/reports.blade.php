@extends('layouts.admin')

@section('title', 'Reports — Admin')
@section('page-title', 'Reports & Analytics')

@section('content')

{{-- Top stat cards --}}
<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">₱{{ number_format($totalRevenue, 0) }}</div>
      <div class="admin-stat-label">Total Revenue (All Time)</div>
    </div>
  </div>

  <div class="admin-stat-card accent-green">
    <div class="admin-stat-icon green">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23,6 13.5,15.5 8.5,10.5 1,18"/><polyline points="17,6 23,6 23,12"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">
        ₱{{ number_format($thisMonthRevenue, 0) }}
        <span style="font-size:12px;font-weight:700;color:{{ $revenueChangePercent >= 0 ? 'var(--success)' : 'var(--danger)' }};">
          {{ $revenueChangePercent >= 0 ? '↑' : '↓' }} {{ abs($revenueChangePercent) }}%
        </span>
      </div>
      <div class="admin-stat-label">This Month vs Last Month</div>
    </div>
  </div>

  <div class="admin-stat-card accent-blue">
    <div class="admin-stat-icon blue">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $totalCustomers }}</div>
      <div class="admin-stat-label">Total Customers · +{{ $newCustomersThisMonth }} This Month</div>
    </div>
  </div>

  <div class="admin-stat-card accent-purple">
    <div class="admin-stat-icon purple">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div>
      <div class="admin-stat-value">{{ $verifiedCustomers }}</div>
      <div class="admin-stat-label">Verified Customers</div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:20px;align-items:flex-start;">

  {{-- LEFT COLUMN --}}
  <div>

    {{-- Catalog vs customized (new figures only; the totals elsewhere on this page are unchanged) --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Catalog vs Customized Orders</div>
        <a href="{{ route('admin.orders.index', ['type' => 'customized']) }}" class="admin-btn admin-btn-outline">View Customized</a>
      </div>
      <div class="admin-panel-body" style="padding:0;">
        <table class="admin-table">
          <thead><tr><th>Type</th><th>Orders</th><th>Order Value</th><th>Received</th></tr></thead>
          <tbody>
            @foreach(['catalog' => 'Catalog', 'customized' => 'Customized'] as $key => $label)
              <tr>
                <td data-label="Type">{{ $label }}</td>
                <td data-label="Orders">{{ $orderSplit[$key]['orders'] }}</td>
                <td data-label="Order Value">₱{{ number_format($orderSplit[$key]['revenue'], 2) }}</td>
                <td data-label="Received">₱{{ number_format($orderSplit[$key]['paid'], 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
        <p style="font-size:11.5px;color:var(--text-muted);margin:0;padding:10px 20px;">Cancelled orders are excluded, like the revenue figures on this page.</p>
      </div>
    </div>

    {{-- Revenue Bar Chart --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Monthly Revenue (Last 6 Months)</div></div>
      <div class="admin-panel-body">
        @php $maxRevenue = max(array_column($revenueByMonth, 'total')) ?: 1; @endphp
        <div class="admin-chart-bars">
          @foreach($revenueByMonth as $m)
            @php $heightPct = $m['total'] > 0 ? max(6, ($m['total'] / $maxRevenue) * 100) : 4; @endphp
            <div class="admin-chart-bar-col">
              <div class="admin-chart-bar-value">₱{{ number_format($m['total'] / 1000, 0) }}k</div>
              <div class="admin-chart-bar-track">
                <div class="admin-chart-bar-fill" style="height:{{ $heightPct }}%;"></div>
              </div>
              <div class="admin-chart-bar-label">{{ $m['label'] }}</div>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    {{-- Orders by Status --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Orders by Status</div></div>
      <div class="admin-panel-body">
        @php
          $statusColors = [
            'pending' => '#D69E2E', 'confirmed' => '#1D5A9E', 'in_production' => '#1D5A9E',
            'ready' => '#1D5A9E', 'out_for_delivery' => '#1D5A9E', 'completed' => '#1B7A46', 'cancelled' => '#C0392B',
          ];
          $totalOrdersCount = $ordersByStatus->sum() ?: 1;
        @endphp
        <div style="display:flex;flex-direction:column;gap:10px;">
          @foreach($ordersByStatus as $statusKey => $count)
            <div>
              <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
                <span style="text-transform:capitalize;color:#3D3129;font-weight:600;">{{ str_replace('_',' ',$statusKey) }}</span>
                <span style="color:#8A7B6C;">{{ $count }}</span>
              </div>
              <div class="admin-bar-track">
                <div class="admin-bar-fill" style="width:{{ ($count / $totalOrdersCount) * 100 }}%; background:{{ $statusColors[$statusKey] ?? '#8A7B6C' }};"></div>
              </div>
            </div>
          @endforeach
        </div>
      </div>
    </div>

    {{-- Orders by Category --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Orders by Category</div></div>
      <div class="admin-panel-body">
        @if($ordersByCategory->isEmpty())
          <p style="font-size:13px;color:var(--text-muted);">No category data yet.</p>
        @else
          @php $maxCat = $ordersByCategory->max('total') ?: 1; @endphp
          <div style="display:flex;flex-direction:column;gap:10px;">
            @foreach($ordersByCategory as $cat)
              <div>
                <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px;">
                  <span style="color:#3D3129;font-weight:600;">{{ $cat->category }}</span>
                  <span style="color:#8A7B6C;">{{ $cat->total }} orders</span>
                </div>
                <div class="admin-bar-track">
                  <div class="admin-bar-fill" style="width:{{ ($cat->total / $maxCat) * 100 }}%; background:var(--gold);"></div>
                </div>
              </div>
            @endforeach
          </div>
        @endif
      </div>
    </div>

  </div>

  {{-- RIGHT COLUMN --}}
  <div>

    {{-- Best Sellers --}}
    <div class="admin-panel">
      <div class="admin-panel-header"><div class="admin-panel-title">Best-Selling Products</div></div>
      <div class="admin-panel-body" style="padding:0;">
        @if($bestSellers->isEmpty())
          <div class="admin-empty" style="padding:30px;"><p>No sales data yet.</p></div>
        @else
          @foreach($bestSellers as $index => $product)
            <div style="display:flex;align-items:center;gap:12px;padding:14px 20px;border-bottom:1px solid #F0E9DD;">
              <div style="width:24px;height:24px;border-radius:50%;background:var(--gold);color:#2A1810;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                {{ $index + 1 }}
              </div>
              <div class="admin-product-thumb" style="width:40px;height:40px;">
                <img src="{{ $product->photo_url }}"
                     onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';" alt="{{ $product->product_name }}"/>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-size:13px;font-weight:600;color:#2A1810;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $product->product_name }}</div>
                <div style="font-size:11.5px;color:#8A7B6C;">{{ $product->total_sold }} sold</div>
              </div>
            </div>
          @endforeach
        @endif
      </div>
    </div>

    {{-- Low Stock Alert --}}
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div class="admin-panel-title">Low Stock Alert</div>
        <a href="{{ route('admin.products.index') }}" class="admin-btn admin-btn-outline">Manage</a>
      </div>
      <div class="admin-panel-body" style="padding:0;">
        @if($lowStockProducts->isEmpty())
          <div class="admin-empty" style="padding:30px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 6L9 17l-5-5"/></svg>
            <p>All products well-stocked.</p>
          </div>
        @else
          @foreach($lowStockProducts as $product)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid #F0E9DD;">
              <span style="font-size:13px;color:#2A1810;font-weight:600;">{{ $product->product_name }}</span>
              <span class="admin-pill rejected">{{ $product->stock_quantity }} left</span>
            </div>
          @endforeach
        @endif
      </div>
    </div>

  </div>

</div>

@endsection