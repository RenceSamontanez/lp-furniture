@extends('layouts.admin')

@section('title', ($product->exists ? 'Edit' : 'Add') . ' Product — Admin')
@section('page-title', ($product->exists ? 'Edit' : 'Add') . ' Product')

@section('content')

<a href="{{ route('admin.products.index') }}" class="admin-btn admin-btn-outline" style="margin-bottom:20px;">
  ← Back to Products
</a>

@if($errors->any())
  <div class="admin-toast admin-toast-error" style="margin:0 0 20px;">
    @foreach($errors->all() as $error)
      <div>{{ $error }}</div>
    @endforeach
  </div>
@endif

<form method="POST"
      action="{{ $product->exists ? route('admin.products.update', $product->id) : route('admin.products.store') }}"
      enctype="multipart/form-data">
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
            <div>
              <label class="form-label">Category</label>
              <input type="text" name="category" class="form-input" maxlength="100"
                     placeholder="e.g. Table, Chair, Cabinet"
                     value="{{ old('category', $product->category) }}"/>
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

          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
              <label class="form-label">Material</label>
              <input type="text" name="material" class="form-input" maxlength="100"
                     placeholder="e.g. Narra, Mahogany"
                     value="{{ old('material', $product->material) }}"/>
            </div>
            <div>
              <label class="form-label">Finish</label>
              <input type="text" name="finish" class="form-input" maxlength="100"
                     placeholder="e.g. Natural varnish"
                     value="{{ old('finish', $product->finish) }}"/>
            </div>
            <div>
              <label class="form-label">Dimensions</label>
              <input type="text" name="dimensions" class="form-input" maxlength="150"
                     placeholder="e.g. 180 x 90 x 75 cm"
                     value="{{ old('dimensions', $product->dimensions) }}"/>
            </div>
          </div>

        </div>
      </div>

      <div class="admin-panel">
        <div class="admin-panel-header"><div class="admin-panel-title">Pricing &amp; Stock</div></div>
        <div class="admin-panel-body">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
            <div>
              <label class="form-label">Regular Price (₱)</label>
              <input type="number" name="price" step="0.01" min="0" class="form-input" required
                     value="{{ old('price', $product->price) }}"/>
            </div>
            <div>
              <label class="form-label">Sale Price (₱) <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
              <input type="number" name="sale_price" step="0.01" min="0" class="form-input"
                     placeholder="Leave blank if not on sale"
                     value="{{ old('sale_price', $product->sale_price) }}"/>
            </div>
            <div>
              <label class="form-label">Stock Quantity</label>
              <input type="number" name="stock_quantity" min="0" class="form-input" required
                     value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}"/>
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
</script>
@endpush