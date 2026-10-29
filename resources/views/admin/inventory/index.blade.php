@extends('layouts.admin')

@section('title', 'Materials Inventory — Admin')
@section('page-title', 'Materials Inventory')

@section('content')

<div class="admin-stats-grid">
  <div class="admin-stat-card">
    <div class="admin-stat-icon gold">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['total'] }}</div><div class="admin-stat-label">Total Materials</div></div>
  </div>
  <div class="admin-stat-card accent-red">
    <div class="admin-stat-icon red">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['low_stock'] }}</div><div class="admin-stat-label">Low Stock</div></div>
  </div>
  <div class="admin-stat-card accent-blue">
    <div class="admin-stat-icon blue">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
    </div>
    <div><div class="admin-stat-value">{{ $stats['out_of_stock'] }}</div><div class="admin-stat-label">Out of Stock</div></div>
  </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
  <div class="admin-filter-tabs" style="margin-bottom:0;">
    <a href="{{ route('admin.inventory.index', ['status'=>'all']) }}" class="admin-filter-tab {{ $status==='all'?'active':'' }}">All</a>
    <a href="{{ route('admin.inventory.index', ['status'=>'in_stock']) }}" class="admin-filter-tab {{ $status==='in_stock'?'active':'' }}">In Stock</a>
    <a href="{{ route('admin.inventory.index', ['status'=>'low_stock']) }}" class="admin-filter-tab {{ $status==='low_stock'?'active':'' }}">Low Stock</a>
    <a href="{{ route('admin.inventory.index', ['status'=>'out_of_stock']) }}" class="admin-filter-tab {{ $status==='out_of_stock'?'active':'' }}">Out of Stock</a>
  </div>
  <a href="{{ route('admin.inventory.create') }}" class="admin-btn admin-btn-gold">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Material
  </a>
</div>


<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($materials->isEmpty())
      <div class="admin-empty"><p>No materials found.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Material</th><th>Quantity</th><th>Threshold</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($materials as $material)
            <tr>
              <td data-label="Material"><div style="font-weight:600;color:#2A1810;">{{ $material->material_name }}</div></td>
              <td data-label="Quantity">
                <form method="POST" action="{{ route('admin.inventory.adjust', $material->id) }}" class="admin-quick-stock-form">
                  @csrf
                  <input type="number" step="0.01" name="quantity" value="{{ $material->material_quantity }}" min="0" class="admin-quick-stock-input" style="width:70px;"/>
                  <span style="font-size:11px;color:var(--text-muted);">{{ $material->unit }}</span>
                  <button type="submit" class="admin-quick-stock-btn" title="Update quantity">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                  </button>
                </form>
              </td>
              <td data-label="Threshold">{{ $material->low_stock_threshold }} {{ $material->unit }}</td>
              <td data-label="Status">
                <span class="admin-pill {{ $material->inventory_status === 'in_stock' ? 'approved' : ($material->inventory_status === 'low_stock' ? 'pending' : 'rejected') }}">
                  @titlecase(str_replace('_',' ',$material->inventory_status))
                </span>
              </td>
              <td data-label="Last Updated">{{ \Carbon\Carbon::parse($material->last_updated)->diffForHumans() }}</td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="{{ route('admin.inventory.edit', $material->id) }}" class="admin-btn admin-btn-outline">Edit</a>
                  <form method="POST" action="{{ route('admin.inventory.destroy', $material->id) }}" onsubmit="return confirm('Remove this material record?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="admin-btn admin-btn-danger">Remove</button>
                  </form>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $materials->appends(request()->query())->links() }}</div>

@endsection