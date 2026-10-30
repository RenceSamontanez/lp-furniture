{{-- Add / Edit material: one mid-size modal (shared x-modal). Posts to the existing store / update routes; the server validates.
     $material = Inventory (new or saved), $modalId = "material-add" or "material-edit-{id}", $openModal = the modal to reopen after a rejected submit. --}}
@php
  $mine   = old('_modal') === $modalId;                       // errors and typed values belong to this modal only
  $val    = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err    = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId = $modalId . '-form';
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
               placeholder="e.g. Narra Wood Planks, Wood Varnish, Nails"
               value="{{ $val('material_name', $material->material_name) }}"/>
        <p class="am-err" data-client="Enter the material name.">{{ $err('material_name') }}</p>
      </div>

      <div class="am-field {{ $err('material_quantity') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-qty">Current Quantity</label>
        <input type="number" inputmode="decimal" step="0.01" min="0" id="{{ $formId }}-qty" name="material_quantity" class="form-input" data-required
               value="{{ $val('material_quantity', $material->exists ? $material->material_quantity : null) }}"/>
        <p class="am-err" data-client="Enter the current quantity.">{{ $err('material_quantity') }}</p>
      </div>

      <div class="am-field {{ $err('unit') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-unit">Unit</label>
        <input type="text" id="{{ $formId }}-unit" name="unit" class="form-input" maxlength="50" data-required
               placeholder="e.g. pcs, kg, liters, sheets"
               value="{{ $val('unit', $material->unit) }}"/>
        <p class="am-err" data-client="Enter the unit.">{{ $err('unit') }}</p>
      </div>

      <div class="am-field am-full {{ $err('low_stock_threshold') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-threshold">Low Stock Threshold</label>
        <input type="number" inputmode="decimal" step="0.01" min="0" id="{{ $formId }}-threshold" name="low_stock_threshold" class="form-input" data-required
               value="{{ $val('low_stock_threshold', $material->exists ? $material->low_stock_threshold : 10) }}"/>
        <div class="am-help">Status automatically switches to "Low Stock" when quantity falls at or below this number.</div>
        <p class="am-err" data-client="Enter the low stock threshold.">{{ $err('low_stock_threshold') }}</p>
      </div>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>Save Material</button>
  </x-slot:footer>
</x-modal>
