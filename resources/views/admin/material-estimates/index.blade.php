@extends('layouts.admin')

@section('title', 'Material Estimates — Admin')
@section('page-title', 'Material Estimates')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-materials.css') }}?v={{ @filemtime(public_path('assets/css/admin-materials.css')) }}"/>
@endpush
@push('scripts')
  <script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
  <script src="{{ asset('assets/js/admin-materials.js') }}?v={{ @filemtime(public_path('assets/js/admin-materials.js')) }}"></script>
@endpush

@section('content')

<p class="mt-sub">How much of each material one piece of furniture uses. The numbers already include extra for mistakes (about 18% more wood and double the finish, nails, sandpaper and glue), so they err on the safe side. Edit any row when you learn the real number.</p>

<div class="mt-tools">
  <a href="{{ route('admin.inventory.index') }}" class="lp-btn lp-btn--secondary">Back To Inventory</a>
  <input type="search" class="form-input" placeholder="Search furniture type" aria-label="Search furniture type" data-filter-rows="#mt-est-rows"/>
  <span class="mt-gap"></span>
  <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="estimate-add">Add Furniture Type</button>
</div>

<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    <table class="admin-table">
      <thead><tr><th>Furniture Type</th>@foreach(\App\Support\MaterialLibrary::FIELDS as $k => [$label, $unit])<th class="mt-r">{{ $k === 'nails' ? 'Nails & Screws' : ($k === 'sand' ? 'Sandpaper' : $label . ' (' . ($k === 'wood' ? 'sqft' : 'L') . ')') }}</th>@endforeach<th>Products</th><th>Actions</th></tr></thead>
      <tbody id="mt-est-rows">
        @foreach($types as $t)
          @php $n = (int) ($usage[$t->name] ?? 0); @endphp
          <tr data-search="{{ mb_strtolower($t->name) }}">
            <td data-label="Furniture Type"><b>{{ $t->name }}</b>@if($t->is_edited) <span class="mt-chip edit" style="margin-left:6px;">Edited</span>@endif</td>
            @foreach(\App\Support\MaterialLibrary::FIELDS as $k => [$label, $unit])
              <td data-label="{{ $label }}" class="mt-r mt-num">{{ \App\Support\MaterialStock::number($t->{$k}) }}</td>
            @endforeach
            <td data-label="Products">{{ $n > 0 ? $n . ' ' . ($n === 1 ? 'product' : 'products') : 'None yet' }}</td>
            <td data-label="Actions"><div class="mt-acts"><button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-lp-modal-open="estimate-edit-{{ $t->id }}">Edit</button></div></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>

@include('admin.material-estimates._modal', ['type' => new \App\Models\MaterialEstimate(), 'modalId' => 'estimate-add'])
@foreach($types as $t)
  @include('admin.material-estimates._modal', ['type' => $t, 'modalId' => 'estimate-edit-' . $t->id])
@endforeach

@endsection
