@extends('layouts.admin')

@section('title', ($material->exists ? 'Edit' : 'Add') . ' Material — Admin')
@section('page-title', ($material->exists ? 'Edit' : 'Add') . ' Material')

@section('content')

<a href="{{ route('admin.inventory.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">← Back to Inventory</a>

@if($errors->any())
  <div class="admin-toast admin-toast-error" style="margin:0 0 20px;">
    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
  </div>
@endif

<div class="admin-panel" style="max-width:520px;">
  <div class="admin-panel-header"><div class="admin-panel-title">Material Details</div></div>
  <div class="admin-panel-body">
    <form method="POST" action="{{ $material->exists ? route('admin.inventory.update', $material->id) : route('admin.inventory.store') }}">
      @csrf
      @if($material->exists) @method('PUT') @endif

      <div style="margin-bottom:16px;">
        <label class="form-label">Material Name</label>
        <input type="text" name="material_name" class="form-input" required maxlength="255"
               placeholder="e.g. Narra Wood Planks, Wood Varnish, Nails"
               value="{{ old('material_name', $material->material_name) }}"/>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
        <div>
          <label class="form-label">Current Quantity</label>
          <input type="number" step="0.01" name="material_quantity" class="form-input" required min="0"
                 value="{{ old('material_quantity', $material->material_quantity) }}"/>
        </div>
        <div>
          <label class="form-label">Unit</label>
          <input type="text" name="unit" class="form-input" required maxlength="50"
                 placeholder="e.g. pcs, kg, liters, sheets"
                 value="{{ old('unit', $material->unit) }}"/>
        </div>
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label">Low Stock Threshold</label>
        <input type="number" step="0.01" name="low_stock_threshold" class="form-input" required min="0"
               value="{{ old('low_stock_threshold', $material->low_stock_threshold ?? 10) }}"/>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
          Status automatically switches to "Low Stock" when quantity falls at or below this number.
        </div>
      </div>

      <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:14px;">
        {{ $material->exists ? 'Update Material' : 'Add Material' }}
      </button>
    </form>
  </div>
</div>

@endsection