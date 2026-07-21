@extends('layouts.admin')

@section('title', 'Products — Admin')
@section('page-title', 'Products')

@section('content')

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

  <a href="{{ route('admin.products.create') }}" class="admin-btn admin-btn-gold">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Product
  </a>
</div>

@if(session('success'))
  <div class="admin-toast admin-toast-success" style="margin:0 0 20px;">{{ session('success') }}</div>
@endif

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
        <thead><tr><th>Image</th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          @foreach($products as $product)
            <tr>
              <td data-label="Image">
                <div class="admin-product-thumb">
                  <img src="{{ $product->product_image ? asset('images/products/'.$product->product_image) : asset('images/products/placeholder.jpg') }}"
                       alt="{{ $product->product_name }}"
                       onerror="this.src='{{ asset('images/products/placeholder.jpg') }}';"/>
                </div>
              </td>
              <td data-label="Product">
                <div style="font-weight:600;color:#2A1810;">{{ $product->product_name }}</div>
              </td>
              <td data-label="Category">{{ $product->category ?? '—' }}</td>
              <td data-label="Price">
                @if($product->sale_price)
                  <span style="text-decoration:line-through;color:#A69A8A;font-size:12px;">₱{{ number_format($product->price, 2) }}</span><br>
                  <span style="color:var(--danger);font-weight:700;">₱{{ number_format($product->sale_price, 2) }}</span>
                @else
                  ₱{{ number_format($product->price, 2) }}
                @endif
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
                  {{ ucfirst($product->product_status) }}
                </span>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="{{ route('admin.products.edit', $product->id) }}" class="admin-btn admin-btn-outline">Edit</a>
                  <form method="POST" action="{{ route('admin.products.destroy', $product->id) }}"
                        onsubmit="return confirm('Delete this product permanently? This cannot be undone.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="admin-btn admin-btn-danger">Delete</button>
                  </form>
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

@endsection