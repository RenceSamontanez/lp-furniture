@extends('layouts.admin')

@php($hideGlobalErrorToast = true)

@section('title', ($product->exists ? 'Edit' : 'Add') . ' Product — Admin')
@section('page-title', ($product->exists ? 'Edit' : 'Add') . ' Product')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/guide.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
@endpush

@section('content')

<a href="{{ route('admin.products.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">
  ← Back to Products
</a>

<form method="POST"
      action="{{ $product->exists ? route('admin.products.update', $product->id) : route('admin.products.store') }}"
      enctype="multipart/form-data"
      onsubmit="stripPriceCommasOnSubmit(this)">
  @csrf
  @if($product->exists) @method('PUT') @endif

  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:flex-start;">

    {{-- LEFT: Main details --}}
    <div>
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Product Details</div></div>
        <div class="admin-panel-body">

          <div style="margin-bottom:16px;">
            <label class="form-label">Product Name</label>
            <input type="text" name="product_name" class="form-input" required maxlength="255"
                   value="{{ old('product_name', $product->product_name) }}"/>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
            {{-- CATEGORY: dropdown + "Add New" --}}
            <div>
              <label class="form-label">Category</label>
              <select name="category" id="category-select" class="form-select" required onchange="toggleAddNew('category')">
                @foreach($categories as $cat)
                  <option value="{{ $cat }}" {{ old('category', $product->category) === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                @endforeach
                <option value="__new__" {{ old('category') === '__new__' ? 'selected' : '' }}>+ Add New Category</option>
              </select>
              <div id="category-new-wrap" style="margin-top:8px;display:none;">
                <input type="text" name="new_category" class="form-input" maxlength="100"
                       placeholder="Enter new category name"
                       value="{{ old('new_category') }}"/>
              </div>
            </div>
            <div>
              <label class="form-label">Status</label>
              <select name="product_status" class="form-input" required>
                <option value="available" {{ old('product_status', $product->product_status) === 'available' ? 'selected' : '' }}>Available</option>
                <option value="unavailable" {{ old('product_status', $product->product_status) === 'unavailable' ? 'selected' : '' }}>Unavailable</option>
              </select>
            </div>
          </div>

          <div style="margin-bottom:16px;">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-input" rows="4" maxlength="2000">{{ old('description', $product->description) }}</textarea>
          </div>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
            {{-- MATERIAL: dropdown + "Add New" --}}
            <div>
              <label class="form-label">Material</label>
              <select name="material" id="material-select" class="form-select" required onchange="toggleAddNew('material')">
                @foreach($materials as $mat)
                  <option value="{{ $mat }}" {{ old('material', $product->material) === $mat ? 'selected' : '' }}>{{ $mat }}</option>
                @endforeach
                <option value="__new__" {{ old('material') === '__new__' ? 'selected' : '' }}>+ Add New Material</option>
              </select>
              <div id="material-new-wrap" style="margin-top:8px;display:none;">
                <input type="text" name="new_material" class="form-input" maxlength="100"
                       placeholder="Enter new material name"
                       value="{{ old('new_material') }}"/>
              </div>
            </div>

            {{-- FINISH: dropdown + "Add New" --}}
            <div>
              <label class="form-label">Finish</label>
              <select name="finish" id="finish-select" class="form-select" required onchange="toggleAddNew('finish')">
                @foreach($finishes as $fin)
                  <option value="{{ $fin }}" {{ old('finish', $product->finish) === $fin ? 'selected' : '' }}>{{ $fin }}</option>
                @endforeach
                <option value="__new__" {{ old('finish') === '__new__' ? 'selected' : '' }}>+ Add New Finish</option>
              </select>
              <div id="finish-new-wrap" style="margin-top:8px;display:none;">
                <input type="text" name="new_finish" class="form-input" maxlength="100"
                       placeholder="Enter new finish name"
                       value="{{ old('new_finish') }}"/>
              </div>
            </div>
          </div>

          {{-- SIZE: Length x Width x Height in the unit chosen here. The server converts to cm and
               stores "L x W x H cm" in the one `dimensions` column, as it always has. --}}
          @php($dimVals = $product->dimension_form_values)
          <fieldset class="cd-size-group pf-size">
            <legend class="form-label">Size, length x width x height <span style="font-weight:400;color:var(--text-muted);">(optional)</span></legend>
            <p class="cd-size-hint" id="pf-size-hint">
              <strong>Length</strong> is the longest side across. <strong>Width</strong> is the shorter side across. <strong>Height</strong> is from the floor to the top.
              Choose the unit first: it is stored in centimetres. Allowed: {{ \App\Support\Dimensions::number(\App\Support\Dimensions::MIN_CM) }} to {{ \App\Support\Dimensions::number(\App\Support\Dimensions::MAX_CM) }} cm for each side.
            </p>
            <div class="cd-size-grid">
              @foreach(['length' => 'Length', 'width' => 'Width', 'height' => 'Height'] as $key => $label)
                <div class="cd-size-field">
                  <label for="pf-dim-{{ $key }}">{{ $label }}</label>
                  <input type="text" inputmode="decimal" autocomplete="off" id="pf-dim-{{ $key }}" name="dimension_{{ $key }}"
                         class="form-input @error('dimension_' . $key) is-invalid @enderror"
                         value="{{ old('dimension_' . $key, $dimVals[$key]) }}" aria-describedby="pf-size-hint pf-size-error"/>
                </div>
              @endforeach
              <div class="cd-size-field cd-size-unit">
                <label for="pf-dim-unit">Unit</label>
                <select id="pf-dim-unit" name="dimension_unit" class="form-input" data-prev="{{ old('dimension_unit', 'cm') }}">
                  @foreach(\App\Support\Dimensions::UNITS as $unit => $unitLabel)
                    <option value="{{ $unit }}" @selected(old('dimension_unit', 'cm') === $unit)>{{ $unitLabel }}</option>
                  @endforeach
                </select>
              </div>
            </div>
            <p class="cd-size-error" id="pf-size-error" role="alert">@foreach(['dimension_length', 'dimension_width', 'dimension_height', 'dimension_unit'] as $f)@error($f){{ $message }} @enderror @endforeach</p>

            <details class="cd-measure" id="pf-measure">
              <summary>Need help measuring?</summary>
              <div class="cd-measure-body">
                <p class="cd-measure-links"><a href="{{ route('guide') }}" target="_blank" rel="noopener" data-no-loading>Read the full guide</a></p>
                @include('guide._measure')
              </div>
            </details>
          </fieldset>

        </div>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Pricing &amp; Production</div></div>
        <div class="admin-panel-body">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
              <label class="form-label">Regular Price (₱)</label>
              <input type="text" inputmode="decimal" name="price" id="price-input" class="form-input price-formatted" required
                     oninput="formatPriceInput(this)"
                     value="{{ old('price', $product->price) }}"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Max ₱999,999</div>
            </div>
            <div>
              <label class="form-label">Sale Price (₱) <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
              <input type="text" inputmode="decimal" name="sale_price" id="sale-price-input" class="form-input price-formatted"
                     oninput="formatPriceInput(this)"
                     placeholder="Leave blank if not on sale"
                     value="{{ old('sale_price', $product->sale_price) }}"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Must be less than regular price</div>
            </div>
            <div>
              <label class="form-label">Units Available to Build</label>
              <input type="number" name="stock_quantity" min="0" class="form-input" required
                     value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}"/>
              <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                Current production capacity — every piece is handcrafted to order, this isn't warehouse stock.
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Options</div></div>
        <div class="admin-panel-body" style="display:flex;gap:24px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="is_featured" value="1" style="width:16px;height:16px;accent-color:var(--gold);"
                   {{ old('is_featured', $product->is_featured) ? 'checked' : '' }}/>
            <span style="font-size:13.5px;color:#3D3129;">Featured on homepage</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" name="is_customizable" value="1" style="width:16px;height:16px;accent-color:var(--gold);"
                   {{ old('is_customizable', $product->is_customizable ?? true) ? 'checked' : '' }}/>
            <span style="font-size:13.5px;color:#3D3129;">Customizable (customers can request changes)</span>
          </label>
        </div>
      </div>
    </div>

    {{-- RIGHT: Image + Submit --}}
    <div>
      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Product Image</div></div>
        <div class="admin-panel-body">

          <div class="admin-product-image-preview">
           <img id="image-preview"
                 src="{{ $product->exists ? $product->image_url : asset('images/products/placeholder.jpg') }}"
                 alt="Preview"
                 onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
          </div>

          <label class="admin-image-upload-btn">
            <input type="file" name="product_image" accept="image/jpeg,image/png,image/jpg" onchange="previewProductImage(this)" style="display:none;"/>
            Choose Image
          </label>
          <p style="font-size:11px;color:var(--text-muted);margin-top:8px;">JPG or PNG · Max 5MB</p>
        </div>
      </div>

      <button type="submit" class="admin-btn admin-btn-gold" style="width:100%;justify-content:center;padding:13px;font-size:14px;">
        {{ $product->exists ? 'Update Product' : 'Create Product' }}
      </button>
    </div>

  </div>
</form>

{{-- ══════════════════════════════════════════
     VALIDATION ERROR MODAL — generic: works for any
     validation failure on this form (links, profanity,
     price cap, required fields, ...). Auto-opens on load
     when $errors is non-empty; auto-dismisses after 5s.
═══════════════════════════════════════════ --}}
<div class="admin-modal-overlay" id="validation-error-modal-overlay" onclick="closeValidationErrorModalOnBackdrop(event)">
  <div class="admin-modal admin-modal-centered">
    <div class="admin-modal-body">
      <div class="admin-modal-icon admin-modal-icon--danger">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
      </div>
      <h3 class="admin-modal-title-centered">Please fix the following</h3>
      <ul class="admin-modal-error-list" id="validation-error-modal-list"></ul>

      <div class="admin-modal-footer">
        <button type="button" class="admin-btn admin-btn-gold" style="flex:1;" onclick="closeValidationErrorModal()">OK</button>
      </div>
      <div class="admin-modal-countdown" id="validation-error-modal-countdown"></div>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script>
function previewProductImage(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = (e) => {
    document.getElementById('image-preview').src = e.target.result;
  };
  reader.readAsDataURL(file);
}

/* ── CATEGORY / MATERIAL / FINISH — "select + add new" ── */
function toggleAddNew(field) {
  const select = document.getElementById(field + '-select');
  const wrap   = document.getElementById(field + '-new-wrap');
  const input  = wrap.querySelector('input');
  const isNew  = select.value === '__new__';
  wrap.style.display = isNew ? 'block' : 'none';
  if (!isNew) input.value = '';
}

document.addEventListener('DOMContentLoaded', function () {
  ['category', 'material', 'finish'].forEach(toggleAddNew);

  // Apply comma formatting to whatever value was rendered server-side
  // (existing product price, or an old() value after a failed submit)
  document.querySelectorAll('.price-formatted').forEach(formatPriceInput);

  @if($errors->any())
    openValidationErrorModal(@json($errors->all()));
  @endif
});

/* ── VALIDATION ERROR MODAL ── */
let validationErrorTimer = null;
let validationErrorCountdownInterval = null;

function openValidationErrorModal(messages) {
  const list = document.getElementById('validation-error-modal-list');
  list.innerHTML = '';
  messages.forEach(msg => {
    const li = document.createElement('li');
    li.textContent = msg;
    list.appendChild(li);
  });

  document.getElementById('validation-error-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';

  let secondsLeft = 5;
  const countdown = document.getElementById('validation-error-modal-countdown');
  countdown.textContent = `Auto-closing in ${secondsLeft}s`;

  validationErrorCountdownInterval = setInterval(() => {
    secondsLeft -= 1;
    if (secondsLeft > 0) {
      countdown.textContent = `Auto-closing in ${secondsLeft}s`;
    }
  }, 1000);

  validationErrorTimer = setTimeout(closeValidationErrorModal, 5000);
}

function closeValidationErrorModal() {
  document.getElementById('validation-error-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
  clearTimeout(validationErrorTimer);
  clearInterval(validationErrorCountdownInterval);
}

function closeValidationErrorModalOnBackdrop(e) {
  if (e.target.id === 'validation-error-modal-overlay') closeValidationErrorModal();
}

/* ── PRICE / SALE PRICE — live comma formatting ── */
function formatPriceInput(input) {
  let raw = input.value.replace(/[^0-9.]/g, '');

  // Keep only the first decimal point
  const firstDot = raw.indexOf('.');
  if (firstDot !== -1) {
    raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '');
  }

  const [intPart, decPart] = raw.split('.');
  const formattedInt = intPart ? Number(intPart).toLocaleString('en-US') : '';
  input.value = decPart !== undefined ? formattedInt + '.' + decPart : formattedInt;
}

function stripPriceCommasOnSubmit(form) {
  form.querySelectorAll('.price-formatted').forEach(input => {
    input.value = input.value.replace(/,/g, '');
  });
}
</script>
@endpush

@push('scripts')
<script>
// Switching the unit converts the numbers already typed, so they keep meaning the same size.
// The server converts again from what is posted; this is only a convenience.
(function () {
  var factors = @json(\App\Support\Dimensions::FACTORS);
  var unit = document.getElementById('pf-dim-unit');
  if (!unit) { return; }
  var inputs = ['length', 'width', 'height'].map(function (k) { return document.getElementById('pf-dim-' + k); });
  unit.addEventListener('change', function () {
    var from = unit.getAttribute('data-prev') || 'cm';
    var to = unit.value;
    inputs.forEach(function (input) {
      var raw = input.value.trim().replace(',', '.');
      if (raw === '' || isNaN(Number(raw))) { return; }
      var converted = Number(raw) * factors[from] / factors[to];
      input.value = String(Math.round(converted * 100) / 100);
    });
    unit.setAttribute('data-prev', to);
  });
})();
</script>
@endpush
