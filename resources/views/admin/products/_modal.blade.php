{{-- Add / Edit product: one large modal (shared x-modal), same fields and rules as the old full page plus "Materials Needed".
     $product = Product (new or saved), $modalId = "product-add" or "product-edit-{id}", $openModal = the modal to reopen after a rejected submit,
     $categories / $materials / $finishes = the select options. Only one modal can be open at a time, so removing an extra photo
     asks inline instead of opening a second modal. --}}
@php
  $mine   = old('_modal') === $modalId;
  $val    = fn (string $k, $default = null) => $mine ? old($k, $default) : $default;
  $err    = fn (string $k) => $mine ? $errors->first($k) : '';
  $formId = $modalId . '-form';
  $new    = ! $product->exists;
  $dimVals = $product->dimension_form_values;

  $est = $product->exists ? $product->materialEstimate : null;
  $estType = $est?->type_name ?? \App\Support\MaterialEstimator::matchType($product->product_name, $product->category);
  $estVals = $est?->values() ?? \App\Support\MaterialEstimator::typeValues($estType);
  $estEdited = $est?->is_edited ?? false;
  if ($mine) { $estType = old('est_type', $estType); $estEdited = $estEdited; }

  $selected = fn (string $field, array|\Illuminate\Support\Collection $options) => $mine ? old($field, $product->{$field}) : $product->{$field};
@endphp
<x-modal :id="$modalId" :title="$new ? 'Add Product' : 'Edit Product'" size="wide" :data-guard="true" :data-open-on-load="($openModal ?? null) === $modalId" :data-force-dirty="$mine">
  <form method="POST" id="{{ $formId }}" data-modal-form data-product-form data-new="{{ $new ? 1 : 0 }}" data-edited="{{ $estEdited ? 1 : 0 }}"
        action="{{ $new ? route('admin.products.store') : route('admin.products.update', $product->id) }}" enctype="multipart/form-data"
        onsubmit="stripPriceCommasOnSubmit(this)" novalidate>
    @csrf
    @if(! $new) @method('PUT') @endif
    <input type="hidden" name="_modal" value="{{ $modalId }}">

    <div class="mt-pgrid">
      <div class="am-field span2 {{ $err('product_name') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-name">Product Name</label>
        <input type="text" id="{{ $formId }}-name" name="product_name" class="form-input" maxlength="255" data-required placeholder="e.g. Narra Altar Table"
               value="{{ $val('product_name', $product->product_name) }}"/>
        <p class="am-err" data-client="Enter the product name.">{{ $err('product_name') }}</p>
      </div>

      <div class="am-field {{ $err('category') || $err('new_category') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-cat">Category</label>
        @php $catNow = $selected('category', $categories); @endphp
        <select name="category" id="{{ $formId }}-cat" class="form-input" data-add-new data-required>
          @foreach($categories as $cat)<option value="{{ $cat }}" @selected($catNow === $cat)>{{ $cat }}</option>@endforeach
          <option value="__new__" @selected($mine && old('category') === '__new__')>+ Add New Category</option>
        </select>
        <div data-new-wrap style="margin-top:8px;display:none;">
          <input type="text" name="new_category" class="form-input" maxlength="100" placeholder="Enter new category name" value="{{ $val('new_category') }}"/>
        </div>
        <div class="am-help">A new category starts from Other / Unknown unless the name matches a furniture type.</div>
        <p class="am-err" data-client="Choose a category.">{{ $err('category') ?: $err('new_category') }}</p>
      </div>

      <div class="am-field {{ $err('product_status') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-status">Status</label>
        <select name="product_status" id="{{ $formId }}-status" class="form-input" data-required>
          <option value="available" @selected($val('product_status', $product->product_status ?: 'available') === 'available')>Available</option>
          <option value="unavailable" @selected($val('product_status', $product->product_status ?: 'available') === 'unavailable')>Unavailable</option>
        </select>
        <p class="am-err">{{ $err('product_status') }}</p>
      </div>

      <div class="am-field {{ $err('material') || $err('new_material') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-mat">Wood</label>
        @php $matNow = $selected('material', $materials); @endphp
        <select name="material" id="{{ $formId }}-mat" class="form-input" data-add-new data-required>
          @foreach($materials as $mat)<option value="{{ $mat }}" @selected($matNow === $mat)>{{ $mat }}</option>@endforeach
          <option value="__new__" @selected($mine && old('material') === '__new__')>+ Add New Material</option>
        </select>
        <div data-new-wrap style="margin-top:8px;display:none;">
          <input type="text" name="new_material" class="form-input" maxlength="100" placeholder="Enter new material name" value="{{ $val('new_material') }}"/>
        </div>
        <p class="am-err" data-client="Choose the wood.">{{ $err('material') ?: $err('new_material') }}</p>
      </div>

      <div class="am-field {{ $err('finish') || $err('new_finish') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-fin">Finish</label>
        @php $finNow = $selected('finish', $finishes); @endphp
        <select name="finish" id="{{ $formId }}-fin" class="form-input" data-add-new data-required>
          @foreach($finishes as $fin)<option value="{{ $fin }}" @selected($finNow === $fin)>{{ $fin }}</option>@endforeach
          <option value="__new__" @selected($mine && old('finish') === '__new__')>+ Add New Finish</option>
        </select>
        <div data-new-wrap style="margin-top:8px;display:none;">
          <input type="text" name="new_finish" class="form-input" maxlength="100" placeholder="Enter new finish name" value="{{ $val('new_finish') }}"/>
        </div>
        <p class="am-err" data-client="Choose the finish.">{{ $err('finish') ?: $err('new_finish') }}</p>
      </div>

      <div class="am-field am-full {{ $err('description') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-desc">Description</label>
        <textarea id="{{ $formId }}-desc" name="description" class="form-input" rows="3" maxlength="2000">{{ $val('description', $product->description) }}</textarea>
        <p class="am-err">{{ $err('description') }}</p>
      </div>

      <div class="am-field {{ $err('price') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-price">Price (₱)</label>
        <input type="text" inputmode="decimal" id="{{ $formId }}-price" name="price" class="form-input price-formatted" data-required oninput="formatPriceInput(this)" placeholder="0"
               value="{{ $val('price', $product->price) }}"/>
        <div class="am-help">Max ₱999,999</div>
        <p class="am-err" data-client="Enter the price.">{{ $err('price') }}</p>
      </div>
      <div class="am-field {{ $err('sale_price') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-sale">Sale Price (₱) <span style="font-weight:400;">(Optional)</span></label>
        <input type="text" inputmode="decimal" id="{{ $formId }}-sale" name="sale_price" class="form-input price-formatted" oninput="formatPriceInput(this)" placeholder="Leave blank if not on sale"
               value="{{ $val('sale_price', $product->sale_price) }}"/>
        <div class="am-help">Must be less than the regular price.</div>
        <p class="am-err">{{ $err('sale_price') }}</p>
      </div>
      <div class="am-field {{ $err('stock_quantity') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-stock">Stock</label>
        <input type="number" min="0" id="{{ $formId }}-stock" name="stock_quantity" class="form-input" data-required value="{{ $val('stock_quantity', $product->stock_quantity ?? 0) }}"/>
        <div class="am-help">Units available to build. Pieces are handcrafted to order, so this is capacity, not warehouse stock.</div>
        <p class="am-err" data-client="Enter the stock.">{{ $err('stock_quantity') }}</p>
      </div>

      {{-- SIZE: Length x Width x Height in the unit chosen here. The server converts to cm and stores "L x W x H cm". --}}
      <fieldset class="cd-size-group pf-size am-full">
        <legend class="form-label">Size, Length x Width x Height <span style="font-weight:400;color:var(--text-muted);">(Optional)</span></legend>
        <p class="cd-size-hint" id="{{ $formId }}-size-hint">
          <strong>Length</strong> is the longest side across. <strong>Width</strong> is the shorter side across. <strong>Height</strong> is from the floor to the top.
          Choose the unit first: it is stored in centimetres. Allowed: {{ \App\Support\Dimensions::number(\App\Support\Dimensions::MIN_CM) }} to {{ \App\Support\Dimensions::number(\App\Support\Dimensions::MAX_CM) }} cm for each side.
        </p>
        <div class="cd-size-grid">
          @foreach(['length' => 'Length', 'width' => 'Width', 'height' => 'Height'] as $key => $label)
            <div class="cd-size-field">
              <label for="{{ $formId }}-dim-{{ $key }}">{{ $label }}</label>
              <input type="text" inputmode="decimal" autocomplete="off" id="{{ $formId }}-dim-{{ $key }}" name="dimension_{{ $key }}" data-dim="{{ $key }}"
                     class="form-input {{ $err('dimension_' . $key) ? 'is-invalid' : '' }}"
                     value="{{ $val('dimension_' . $key, $dimVals[$key]) }}" aria-describedby="{{ $formId }}-size-hint {{ $formId }}-size-error"/>
            </div>
          @endforeach
          <div class="cd-size-field cd-size-unit">
            <label for="{{ $formId }}-dim-unit">Unit</label>
            <select id="{{ $formId }}-dim-unit" name="dimension_unit" class="form-input" data-dim-unit data-prev="{{ $val('dimension_unit', 'cm') }}">
              @foreach(\App\Support\Dimensions::UNITS as $unit => $unitLabel)
                <option value="{{ $unit }}" @selected($val('dimension_unit', 'cm') === $unit)>{{ $unitLabel }}</option>
              @endforeach
            </select>
          </div>
        </div>
        <p class="cd-size-error" id="{{ $formId }}-size-error" role="alert">@foreach(['dimension_length', 'dimension_width', 'dimension_height', 'dimension_unit'] as $f){{ $err($f) }} @endforeach</p>
        <details class="cd-measure">
          <summary>Need help measuring?</summary>
          <div class="cd-measure-body">
            <p class="cd-measure-links"><a href="{{ route('guide') }}" target="_blank" rel="noopener" data-no-loading>Read the full guide</a></p>
            @include('guide._measure')
          </div>
        </details>
      </fieldset>

      {{-- PHOTOS --}}
      <div class="am-field am-full {{ $err('product_image') ? 'is-invalid' : '' }}">
        <label class="form-label" for="{{ $formId }}-photo">Photo</label>
        <div class="mt-photos">
          <img data-photo-preview src="{{ $new ? \App\Support\ProductPhotos::placeholderUrl() : $product->image_url }}" alt="Preview" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
          <div>
            <input type="file" id="{{ $formId }}-photo" name="product_image" accept="image/jpeg,image/png,image/jpg" class="form-input" data-photo-input/>
            <div class="am-help">JPG or PNG, max 5 MB.</div>
          </div>
        </div>
        <p class="am-err">{{ $err('product_image') }}</p>
      </div>

      @if(! $new)
        @php
          $extras = $product->extraImages()->get();
          $extraRoom = max(0, \App\Models\Product::MAX_EXTRA_IMAGES - $extras->count());
        @endphp
        <div class="am-field am-full {{ $err('extra_images') || $err('extra_images.0') ? 'is-invalid' : '' }}" id="extra-photos-{{ $product->id }}">
          <label class="form-label">Extra Photos</label>
          <div class="am-help" style="margin:0 0 10px;">Other angles of the same piece. The photo above stays the first one. Up to {{ \App\Models\Product::MAX_EXTRA_IMAGES }} extra photos, JPG or PNG, 5 MB each.</div>
          @if($extras->isNotEmpty())
            <ul class="mt-extra-list">
              @foreach($extras as $i => $extra)
                <li>
                  <img src="{{ $extra->url }}" alt="Extra photo {{ $i + 1 }}" loading="lazy" onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                  <div data-inline-confirm style="margin-top:6px;">
                    <button type="button" class="lp-btn lp-btn--danger lp-btn--chip" data-ic-ask>Remove Photo</button>
                    <span data-ic-row hidden style="display:none;gap:6px;flex-wrap:wrap;">
                      <button type="submit" form="remove-photo-form-{{ $extra->id }}" class="lp-btn lp-btn--danger lp-btn--chip">Yes, Remove</button>
                      <button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-ic-keep>Keep</button>
                    </span>
                  </div>
                </li>
              @endforeach
            </ul>
          @endif
          @if($extraRoom > 0)
            <label for="{{ $formId }}-extra" class="form-label">Add Photos ({{ $extraRoom }} more allowed)</label>
            <input type="file" id="{{ $formId }}-extra" name="extra_images[]" multiple accept="image/jpeg,image/png" class="form-input"/>
            <div class="am-help">Photos are added when you press Save Product.</div>
          @else
            <div class="am-help">This product has the most extra photos allowed. Remove one to add another.</div>
          @endif
          <p class="am-err">{{ $err('extra_images') ?: $err('extra_images.0') }}</p>
        </div>
      @endif

      <div class="am-field am-full" style="display:flex;gap:24px;flex-wrap:wrap;">
        <label class="am-check"><input type="checkbox" name="is_featured" value="1" @checked($mine ? old('is_featured') : $product->is_featured)/> Featured on homepage</label>
        <label class="am-check"><input type="checkbox" name="is_customizable" value="1" @checked($mine ? old('is_customizable') : ($product->is_customizable ?? true))/> Customizable (customers can request changes)</label>
      </div>
    </div>

    {{-- MATERIALS NEEDED: what one piece takes off the inventory when an order is confirmed --}}
    <div class="mt-est" style="margin-top:16px;">
      <h3>Materials Needed <span class="mt-chip {{ $estEdited ? 'edit' : 'start' }}" data-mt-tag>{{ $estEdited ? 'Edited, from ' : 'Starter: ' }}{{ $estType }}</span></h3>
      <p class="why">What one piece uses from your inventory. This is a starting guess from the closest furniture type. Change any number you know better.</p>
      <div class="mt-warn" data-mt-warn></div>
      <input type="hidden" name="est_type" value="{{ $estType }}">
      <div class="mt-grid3">
        @foreach(\App\Support\MaterialLibrary::FIELDS as $k => [$label, $unit])
          <div class="am-field {{ $err('est_' . $k) ? 'is-invalid' : '' }}">
            <label class="form-label" for="{{ $formId }}-est-{{ $k }}">{{ $label }} ({{ $unit }})</label>
            <input type="number" inputmode="decimal" step="any" min="0" id="{{ $formId }}-est-{{ $k }}" name="est_{{ $k }}" class="form-input" value="{{ $val('est_' . $k, $estVals[$k]) }}"/>
            <p class="am-err">{{ $err('est_' . $k) }}</p>
          </div>
        @endforeach
      </div>
      <p style="margin:10px 0 0;"><button type="button" class="lp-btn lp-btn--secondary lp-btn--chip" data-mt-reset>Reset To Starter</button></p>
    </div>
  </form>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Cancel</button>
    <button type="submit" form="{{ $formId }}" class="lp-btn lp-btn--primary" data-lp-confirm>Save Product</button>
  </x-slot:footer>
</x-modal>
