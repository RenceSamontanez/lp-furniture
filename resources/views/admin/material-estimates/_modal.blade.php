{{-- Add / Edit furniture type: one large modal. $type = MaterialEstimate (new or saved), $modalId = "estimate-add" or "estimate-edit-{id}".
     Editing a row never changes a product that already has its own saved estimate. --}}
@php
  $mine   = old('_modal') === $modalId;
  $val    = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err    = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId = $modalId . '-form';
  $base   = $type->exists ? $type->values() : \App\Support\MaterialLibrary::values(\App\Support\MaterialLibrary::OTHER);
@endphp
<x-modal :id="$modalId" :title="$type->exists ? 'Edit Furniture Type' : 'Add Furniture Type'" size="wide" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="{{ $formId }}" data-modal-form action="{{ $type->exists ? route('admin.material-estimates.update', $type->id) : route('admin.material-estimates.store') }}" novalidate>
    @csrf
    @if($type->exists) @method('PUT') @endif
    <input type="hidden" name="_modal" value="{{ $modalId }}">
    <div class="am-grid">
      <div class="am-field am-full {{ $err('name') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-name">Furniture Type</label>
        <input type="text" id="{{ $formId }}-name" name="name" class="form-input" maxlength="120" data-required
               @if($type->exists && $type->isSeeded()) readonly @endif
               value="{{ $val('name', $type->name) }}"/>
        @if($type->exists && $type->isSeeded())<div class="am-help">The name of a starter type stays as it is, because products and custom orders look it up by name.</div>@endif
        <p class="am-err" data-client="Enter the furniture type.">{{ $err('name') }}</p>
      </div>
    </div>
    <div class="mt-est" style="margin-top:14px;">
      <h3>Materials For One Piece <span class="mt-chip {{ $type->exists && $type->is_edited ? 'edit' : 'start' }}">{{ ! $type->exists ? 'New Type' : ($type->is_edited ? 'Edited' : ($type->isSeeded() ? 'Starter Value' : 'Added By Hand')) }}</span></h3>
      <p class="why">Include a little extra for mistakes. Wood is in sqft, the same unit as your inventory.</p>
      <div class="mt-grid3">
        @foreach(\App\Support\MaterialLibrary::FIELDS as $k => [$label, $unit])
          <div class="am-field {{ $err($k) ? 'is-invalid' : '' }}">
            <label class="form-label" for="{{ $formId }}-{{ $k }}">{{ $label }} ({{ $unit }})</label>
            <input type="number" inputmode="decimal" step="any" min="0" id="{{ $formId }}-{{ $k }}" name="{{ $k }}" class="form-input" data-required
                   value="{{ $val($k, $base[$k]) }}"/>
            <p class="am-err" data-client="Enter a number, or 0.">{{ $err($k) }}</p>
          </div>
        @endforeach
      </div>
    </div>
  </form>
  @if($type->exists && $type->isSeeded())
    <form method="POST" id="{{ $modalId }}-reset" action="{{ route('admin.material-estimates.reset', $type->id) }}">@csrf</form>
  @endif
  <x-slot:footer>
    @if($type->exists && $type->isSeeded())
      <button type="submit" form="{{ $modalId }}-reset" class="lp-btn lp-btn--secondary">Reset To Starter</button>
    @endif
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>{{ $type->exists ? 'Save Changes' : 'Save Furniture Type' }}</button>
  </x-slot:footer>
</x-modal>
