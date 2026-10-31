@extends('layouts.admin')

@section('title', 'Materials Inventory — Admin')
@section('page-title', 'Materials Inventory')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-materials.css') }}?v={{ @filemtime(public_path('assets/css/admin-materials.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
  <script src="{{ asset('assets/js/admin-materials.js') }}?v={{ @filemtime(public_path('assets/js/admin-materials.js')) }}"></script>
@endpush

@section('content')

<p class="mt-sub">The system subtracts an estimate of the materials from this list whenever an order is confirmed, and puts them back if the order is cancelled. Customers can still order when something runs short, so use this page to decide when to restock.</p>

<div class="mt-counts">
  <div><b>{{ $stats['total'] }}</b><span>Materials</span></div>
  <div class="ok"><b>{{ $stats['in'] }}</b><span>In Stock</span></div>
  <div class="lo"><b>{{ $stats['low'] }}</b><span>Low Stock</span></div>
  <div class="sh"><b>{{ $stats['short'] }}</b><span>Short</span></div>
</div>

<div class="mt-tools">
  <input type="search" class="form-input" id="mt-search" placeholder="Search materials" aria-label="Search materials" data-filter-rows="#mt-rows"/>
  <span class="mt-gap"></span>
  <a href="{{ route('admin.material-estimates.index') }}" class="lp-btn lp-btn--secondary">Material Estimates</a>
  <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="material-add">Add Material</button>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($materials->isEmpty())
      <div class="admin-empty"><p>No materials yet. Add the first one.</p></div>
    @else
      <table class="admin-table">
        <thead><tr><th>Material</th><th class="mt-r">Remaining</th><th>Low Stock At</th><th>Status</th><th>Lasts About</th><th>Actions</th></tr></thead>
        <tbody id="mt-rows">
          @foreach($materials as $material)
            @php $tone = \App\Support\MaterialStock::chipTone($material); @endphp
            <tr data-search="{{ mb_strtolower($material->material_name) }}">
              <td data-label="Material"><div style="font-weight:600;color:#2A1810;">{{ $material->material_name }}</div></td>
              <td data-label="Remaining" class="mt-r">
                @if((float) $material->material_quantity < 0)
                  <span class="mt-short">{{ \App\Support\MaterialStock::remainingText($material) }}</span>
                @else
                  <b class="mt-num">{{ \App\Support\MaterialStock::number($material->material_quantity) }}</b> {{ $material->unit }}
                @endif
              </td>
              <td data-label="Low Stock At" class="mt-num">{{ \App\Support\MaterialStock::number($material->low_stock_threshold) }} {{ $material->unit }}</td>
              <td data-label="Status"><span class="mt-chip {{ $tone }}">{{ \App\Support\MaterialStock::chipLabel($material) }}</span></td>
              <td data-label="Lasts About">{{ \App\Support\MaterialStock::lastsAbout($material, $used30[$material->id] ?? 0) }}</td>
              <td data-label="Actions">
                <div class="mt-acts">
                  <button type="button" class="lp-btn lp-btn--primary lp-btn--chip" data-lp-modal-open="material-restock-{{ $material->id }}">Restock</button>
                  <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="material-edit-{{ $material->id }}">Edit</button>
                  <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-lp-modal-open="material-remove-{{ $material->id }}">Remove</button>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<p class="mt-note"><b>How "Lasts About" works:</b> it divides what is left by the average amount used per day over the last 30 days. It is a guide, not a promise.</p>

@include('admin.inventory._modal', ['material' => new \App\Models\Inventory(), 'modalId' => 'material-add'])
@foreach($materials as $material)
  @include('admin.inventory._modal', ['material' => $material, 'modalId' => 'material-edit-' . $material->id])
  @include('admin.inventory._restock-modal', ['material' => $material])
  <x-modal :id="'material-remove-' . $material->id" title="Remove Material">
    <form method="POST" id="material-remove-{{ $material->id }}-form" action="{{ route('admin.inventory.destroy', $material->id) }}">
      @csrf
      @method('DELETE')
      <p style="margin:0;">Remove <b>{{ $material->material_name }}</b> from the inventory? Past usage records stay in Reports.</p>
    </form>
    <x-slot:footer>
      <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Keep It</button>
      <button type="submit" form="material-remove-{{ $material->id }}-form" class="lp-btn lp-btn--danger" data-lp-confirm>Remove Material</button>
    </x-slot:footer>
  </x-modal>
@endforeach

@endsection
