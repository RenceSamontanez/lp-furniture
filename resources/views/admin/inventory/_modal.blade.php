{{-- Add / Edit material: one mid-size modal (shared x-modal). Posts to the store / update routes; the server validates.
     $material = Inventory (new or saved), $modalId = "material-add" or "material-edit-{id}", $openModal = the modal to reopen after a rejected submit.
     Edit: "Correct The Count" is saved as a correction (a movement with the difference), never as a restock. --}}
@php
  $mine   = old('_modal') === $modalId;                       // errors and typed values belong to this modal only
  $val    = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err    = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId = $modalId . '-form';
  $unit   = $val('unit', $material->unit ?: 'sqft');
  $units  = collect(\App\Http\Controllers\Admin\InventoryController::UNITS)->when($unit && ! in_array($unit, \App\Http\Controllers\Admin\InventoryController::UNITS, true), fn ($c) => $c->push($unit));
@endphp
<x-modal :id="$modalId" :title="$material->exists ? 'Edit Material' : 'Add Material'" size="mid" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="{{ $formId }}" data-modal-form action="{{ $material->exists ? route('admin.inventory.update', $material->id) : route('admin.inventory.store') }}" novalidate>
    @csrf
    @if($material->exists) @method('PUT') @endif
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <div class="am-grid">
      <div class="am-field am-full {{ $err('material_name') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-name">Material Name</label>
        <input type="text" id="{{ $formId }}-name" name="material_name" class="form-input" maxlength="255" data-required
               placeholder="e.g. Wood (Narra)"
               value="{{ $val('material_name', $material->material_name) }}"/>
        <p class="am-err" data-client="Enter the material name.">{{ $err('material_name') }}</p>
      </div>

      <div class="am-field {{ $err('unit') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-unit">Unit</label>
        <select id="{{ $formId }}-unit" name="unit" class="form-input" data-required>
          @foreach($units as $u)<option value="{{ $u }}" @selected($unit === $u)>{{ $u }}</option>@endforeach
        </select>
        <p class="am-err" data-client="Choose the unit.">{{ $err('unit') }}</p>
      </div>

      <div class="am-field {{ $err('low_stock_threshold') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-threshold">Low Stock At</label>
        <input type="number" inputmode="decimal" step="0.01" min="0" id="{{ $formId }}-threshold" name="low_stock_threshold" class="form-input" data-required
               value="{{ $val('low_stock_threshold', $material->exists ? $material->low_stock_threshold : 10) }}"/>
        <p class="am-err" data-client="Enter the low stock number.">{{ $err('low_stock_threshold') }}</p>
      </div>

      <div class="am-field am-full {{ $err('material_quantity') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-qty">{{ $material->exists ? 'Correct The Count' : 'Starting Quantity' }}</label>
        <input type="number" inputmode="decimal" step="0.01" id="{{ $formId }}-qty" name="material_quantity" class="form-input" data-required
               value="{{ $val('material_quantity', $material->exists ? $material->material_quantity : 0) }}"/>
        <div class="am-help">{{ $material->exists ? 'Only change this if a physical count shows something different. It is saved as a correction, not a restock.' : 'How much you have right now.' }}</div>
        <p class="am-err" data-client="Enter the quantity.">{{ $err('material_quantity') }}</p>
      </div>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>Save Material</button>
  </x-slot:footer>
</x-modal>
