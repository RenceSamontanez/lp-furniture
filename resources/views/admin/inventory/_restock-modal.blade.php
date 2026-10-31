{{-- Restock: ADDS a delivered amount to the count (note optional) and writes a 'restocked' movement. --}}
@php
  $modalId = 'material-restock-' . $material->id;
  $mine    = old('_modal') === $modalId;
  $err     = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId  = $modalId . '-form';
@endphp
<x-modal :id="$modalId" title="Restock Material" size="mid" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="{{ $formId }}" data-modal-form data-restock data-now="{{ (float) $material->material_quantity }}" data-unit="{{ $material->unit }}"
        action="{{ route('admin.inventory.restock', $material->id) }}" novalidate>
    @csrf
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <div class="mt-ex"><b>{{ $material->material_name }}</b><br>Now: {{ \App\Support\MaterialStock::remainingText($material) }}</div>
    <div class="am-grid">
      <div class="am-field am-full {{ $err('add_quantity') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-add">How Much Are You Adding? ({{ $material->unit }})</label>
        <input type="number" inputmode="decimal" step="0.01" min="0" id="{{ $formId }}-add" name="add_quantity" class="form-input" data-required placeholder="0"
               value="{{ $mine ? old('add_quantity') : '' }}"/>
        <p class="am-err" data-client="Enter how much you are adding.">{{ $err('add_quantity') }}</p>
      </div>
      <div class="am-field am-full {{ $err('note') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-note">Note (Optional)</label>
        <input type="text" id="{{ $formId }}-note" name="note" class="form-input" maxlength="200" placeholder="e.g. Delivered by supplier, Oct 31"
               value="{{ $mine ? old('note') : '' }}"/>
        <p class="am-err">{{ $err('note') }}</p>
      </div>
    </div>
    <p class="mt-after" data-after>After restocking: {{ \App\Support\MaterialStock::remainingText($material) }}</p>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>Add To Stock</button>
  </x-slot:footer>
</x-modal>
