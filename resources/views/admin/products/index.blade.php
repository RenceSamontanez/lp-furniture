@extends('layouts.admin')

@section('title', 'Products — Admin')
@section('page-title', 'Products')

@push('styles')
  <link rel="stylesheet" href="{{ asset('assets/css/guide.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/design.css') }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-modals.css') }}?v={{ @filemtime(public_path('assets/css/admin-modals.css')) }}"/>
  <link rel="stylesheet" href="{{ asset('assets/css/admin-materials.css') }}?v={{ @filemtime(public_path('assets/css/admin-materials.css')) }}"/>
@endpush

@section('content')

<p class="mt-sub">Every product carries its own material estimate. New products start from the closest furniture type and you can change the numbers when you add or edit them.</p>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <form method="GET" action="{{ route('admin.products.index') }}" style="display:flex;gap:8px;flex:1;min-width:260px;">
    <input type="text" name="search" class="form-input" placeholder="Search products..." value="{{ $search }}" style="max-width:280px;"/>
    <select name="category" class="form-input" style="max-width:180px;" onchange="this.form.submit()">
      <option value="all">All Categories</option>
      @foreach($categories as $cat)
        <option value="{{ $cat }}" {{ $category === $cat ? 'selected' : '' }}>{{ $cat }}</option>
      @endforeach
    </select>
    <button type="submit" class="admin-btn admin-btn-outline">Search</button>
  </form>

  <button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="product-add">Add Product</button>
</div>


<div class="admin-panel">
  <div class="admin-panel-body" style="padding:0;">
    @if($products->isEmpty())
      <div class="admin-empty">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/>
          <polyline points="3.27,6.96 12,12.01 20.73,6.96"/>
        </svg>
        <p>No products found.</p>
      </div>
    @else
      <table class="admin-table">
        <thead><tr><th>Image</th><th>Product</th><th>Category</th><th>Wood</th><th>Price</th><th>Materials Estimate</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($products as $product)
            <tr>
              <td data-label="Image">
                <div class="admin-product-thumb">
                  <img src="{{ $product->image_url }}"
                       alt="{{ $product->product_name }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
              </td>
              <td data-label="Product">
                <div style="font-weight:600;color:#2A1810;">{{ $product->product_name }}</div>
              </td>
              <td data-label="Category">{{ $product->category ?? '—' }}</td>
              <td data-label="Wood">{{ $product->material ?: '—' }}</td>
              <td data-label="Price">
                @if($product->sale_price)
                  <span style="text-decoration:line-through;color:#A69A8A;font-size:12px;">₱{{ number_format($product->price, 2) }}</span><br>
                  <span style="color:var(--danger);font-weight:700;">₱{{ number_format($product->sale_price, 2) }}</span>
                @else
                  ₱{{ number_format($product->price, 2) }}
                @endif
              </td>
              <td data-label="Materials Estimate">
                @php $pe = $product->materialEstimate; @endphp
                @if($pe)<span class="mt-chip {{ $pe->is_edited ? 'edit' : 'start' }}">{{ $pe->is_edited ? 'Edited' : 'Starter' }}</span> <span class="mt-sub-line" style="display:inline;">{{ $pe->type_name }}</span>@else<span class="mt-sub-line">Starter on first use</span>@endif
              </td>
              <td data-label="Stock">
                <form method="POST" action="{{ route('admin.products.stock', $product->id) }}" class="admin-quick-stock-form">
                  @csrf
                  @method('POST')
                  <input type="number" name="stock_quantity" value="{{ $product->stock_quantity }}" min="0" class="admin-quick-stock-input"/>
                  <button type="submit" class="admin-quick-stock-btn" title="Update stock">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>
                  </button>
                </form>
                @if($product->stock_quantity <= 5)
                  <div style="font-size:10px;color:var(--danger);font-weight:700;margin-top:3px;">Low stock</div>
                @endif
              </td>
              <td data-label="Status">
                <span class="admin-pill {{ $product->product_status === 'available' ? 'approved' : 'rejected' }}">
                  @titlecase($product->product_status)
                </span>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <button type="button" class="admin-btn admin-btn-outline" data-lp-modal-open="product-edit-{{ $product->id }}">Edit</button>
                  <button type="button" class="admin-btn admin-btn-danger"
                          onclick="openDeleteProductModal(@js(route('admin.products.destroy', $product->id)), @js($product->product_name), {{ (int) $product->order_items_count }})">
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div style="margin-top:16px;">{{ $products->appends(request()->query())->links() }}</div>

@include('admin.products._modal', ['product' => new \App\Models\Product(), 'modalId' => 'product-add'])
@foreach($modalProducts as $product)
  @include('admin.products._modal', ['product' => $product, 'modalId' => 'product-edit-' . $product->id])
  @foreach($product->extraImages()->get() as $extra)
    <form id="remove-photo-form-{{ $extra->id }}" method="POST" action="{{ route('admin.products.images.destroy', [$product->id, $extra->id]) }}">@csrf @method('DELETE')</form>
  @endforeach
@endforeach

<script type="application/json" id="mt-matcher">@json(\App\Support\MaterialEstimator::matcherData())</script>
<script type="application/json" id="mt-woods">@json($woodRows)</script>

{{-- ══════════════════════════════════════════
     DELETE PRODUCT MODAL — variant (warning/danger)
     is set at open time by openDeleteProductModal()
═══════════════════════════════════════════ --}}
<div class="admin-modal-overlay" id="delete-product-modal-overlay" onclick="closeDeleteProductModalOnBackdrop(event)">
  <div class="admin-modal admin-modal-centered">
    <div class="admin-modal-body">
      <div class="admin-modal-icon" id="delete-product-modal-icon">
        <svg id="delete-product-modal-icon-svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"></svg>
      </div>
      <h3 class="admin-modal-title-centered" id="delete-product-modal-title"></h3>
      <p class="admin-modal-desc" id="delete-product-modal-desc" style="text-align:center;"></p>

      <form method="POST" id="delete-product-form">
        @csrf
        @method('DELETE')
        <div class="admin-modal-footer">
          <button type="button" class="admin-btn admin-btn-outline" onclick="closeDeleteProductModal()">Cancel</button>
          <button type="submit" class="admin-btn admin-btn-danger">Delete Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('assets/js/admin-modal-forms.js') }}?v={{ @filemtime(public_path('assets/js/admin-modal-forms.js')) }}"></script>
<script src="{{ asset('assets/js/admin-materials.js') }}?v={{ @filemtime(public_path('assets/js/admin-materials.js')) }}"></script>
<script>
/* Product modals: price commas, the "+ Add New" selects, the photo preview, inline photo removal and the size-unit converter. */
function formatPriceInput(input) {
  var raw = input.value.replace(/[^0-9.]/g, '');
  var firstDot = raw.indexOf('.');
  if (firstDot !== -1) { raw = raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, ''); }
  var parts = raw.split('.');
  var formattedInt = parts[0] ? Number(parts[0]).toLocaleString('en-US') : '';
  input.value = parts[1] !== undefined ? formattedInt + '.' + parts[1] : formattedInt;
}
function stripPriceCommasOnSubmit(form) {
  form.querySelectorAll('.price-formatted').forEach(function (i) { i.value = i.value.replace(/,/g, ''); });
}
(function () {
  var factors = @json(\App\Support\Dimensions::FACTORS);
  document.querySelectorAll('form[data-product-form]').forEach(function (form) {
    form.querySelectorAll('.price-formatted').forEach(formatPriceInput);
    form.querySelectorAll('select[data-add-new]').forEach(function (sel) {
      var wrap = sel.parentNode.querySelector('[data-new-wrap]');
      function sync() { var n = sel.value === '__new__'; wrap.style.display = n ? 'block' : 'none'; if (!n) { wrap.querySelector('input').value = ''; } }
      sel.addEventListener('change', sync); sync();
    });
    var photo = form.querySelector('[data-photo-input]');
    if (photo) { photo.addEventListener('change', function () {
      var f = photo.files[0]; if (!f) { return; }
      var rd = new FileReader(); rd.onload = function (e) { form.querySelector('[data-photo-preview]').src = e.target.result; }; rd.readAsDataURL(f);
    }); }
    form.querySelectorAll('[data-inline-confirm]').forEach(function (box) {
      var ask = box.querySelector('[data-ic-ask]'), row = box.querySelector('[data-ic-row]');
      ask.addEventListener('click', function () { ask.style.display = 'none'; row.hidden = false; row.style.display = 'flex'; });
      box.querySelector('[data-ic-keep]').addEventListener('click', function () { row.hidden = true; row.style.display = 'none'; ask.style.display = ''; });
    });
    var unit = form.querySelector('[data-dim-unit]');
    if (unit) { unit.addEventListener('change', function () {
      var from = unit.getAttribute('data-prev') || 'cm', to = unit.value;
      form.querySelectorAll('[data-dim]').forEach(function (input) {
        var raw = input.value.trim().replace(',', '.');
        if (raw === '' || isNaN(Number(raw))) { return; }
        input.value = String(Math.round(Number(raw) * factors[from] / factors[to] * 100) / 100);
      });
      unit.setAttribute('data-prev', to);
    }); }
  });
})();
/* ══════════════════════════════════════════
   DELETE PRODUCT MODAL
   Two visual variants depending on whether the
   product has order history:
   - order history exists : amber/warning treatment,
     mentions the exact order count (deletion is safe,
     orders keep their own snapshot — just informing)
   - no order history : red/danger treatment
     (no safety net, plain irreversible delete)
══════════════════════════════════════════ */
function openDeleteProductModal(actionUrl, productName, orderCount) {
  const form  = document.getElementById('delete-product-form');
  const icon  = document.getElementById('delete-product-modal-icon');
  const svg   = document.getElementById('delete-product-modal-icon-svg');
  const title = document.getElementById('delete-product-modal-title');
  const desc  = document.getElementById('delete-product-modal-desc');

  form.action = actionUrl;
  title.textContent = `Delete "${productName}"?`;

  if (orderCount > 0) {
    icon.classList.remove('admin-modal-icon--danger');
    icon.classList.add('admin-modal-icon--warning');
    svg.innerHTML = '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>';
    desc.textContent = `This product has ${orderCount} past order${orderCount === 1 ? '' : 's'}. Deleting it will remove it from the catalog, but existing orders will keep their own record of it.`;
  } else {
    icon.classList.remove('admin-modal-icon--warning');
    icon.classList.add('admin-modal-icon--danger');
    svg.innerHTML = '<polyline points="3,6 5,6 21,6"/><path d="M19,6v14a2,2 0 01-2,2H7a2,2 0 01-2-2V6m3,0V4a2,2 0 012-2h4a2,2 0 012,2v2"/>';
    desc.textContent = 'This will permanently delete the product from the catalog. This action cannot be undone.';
  }

  document.getElementById('delete-product-modal-overlay').classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeDeleteProductModal() {
  document.getElementById('delete-product-modal-overlay').classList.remove('show');
  document.body.style.overflow = '';
}

function closeDeleteProductModalOnBackdrop(e) {
  if (e.target.id === 'delete-product-modal-overlay') closeDeleteProductModal();
}
</script>
@endpush