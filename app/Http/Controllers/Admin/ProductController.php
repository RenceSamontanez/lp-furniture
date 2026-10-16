<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductImage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Rules\NoInappropriateTerms;
use App\Rules\NoLinks;
use App\Support\DimensionsFormatter;
use Blaspsoft\Blasp\Rules\Profanity;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /* ════════════════════════════════
       LIST PRODUCTS
    ════════════════════════════════ */
    public function index(Request $request)
    {
        $search   = $request->get('search');
        $category = $request->get('category', 'all');

        $products = Product::query()
            ->withCount('orderItems')
            ->when($search, fn($q) => $q->where('product_name', 'like', "%{$search}%"))
            ->when($category !== 'all', fn($q) => $q->where('category', $category))
            ->latest()
            ->paginate(12);

        $categories = Product::select('category')->distinct()->whereNotNull('category')->pluck('category');

        return view('admin.products.index', compact('products', 'categories', 'search', 'category'));
    }

    /* ════════════════════════════════
       SHOW CREATE FORM
    ════════════════════════════════ */
    public function create()
    {
        return view('admin.products.form', array_merge(
            ['product' => new Product(), 'lengthVal' => null, 'widthVal' => null, 'heightVal' => null],
            $this->dropdownOptions()
        ));
    }

    /* ════════════════════════════════
       STORE NEW PRODUCT
    ════════════════════════════════ */
    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);
        $validated['is_customizable'] = $request->boolean('is_customizable');
        $validated['is_featured']     = $request->boolean('is_featured');
    
        $product = Product::create($validated);
    
        if ($request->hasFile('product_image')) {
            $filename = $this->storeImage($request);
    
            // Keep the plain column in sync too, as a fallback
            $product->update(['product_image' => $filename]);
    
            // Create the primary gallery image — this is what image_url()
            // actually checks first
            ProductImage::create([
                'product_id'  => $product->id,
                'image_path'  => $filename,
                'label'       => 'Main',
                'sort_order'  => 1,
                'is_primary'  => true,
            ]);
        }
    
        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }

    /* ════════════════════════════════
       SHOW EDIT FORM
    ════════════════════════════════ */
    public function edit($id)
    {
        $product = Product::findOrFail($id);
        return view('admin.products.form', array_merge(
            compact('product'),
            $this->dropdownOptions()
        ));
    }

    /* ════════════════════════════════
       UPDATE PRODUCT
    ════════════════════════════════ */
    public function update(Request $request, $id)
{
    $product = Product::findOrFail($id);
    $validated = $this->validateProduct($request);
    $validated['is_customizable'] = $request->boolean('is_customizable');
    $validated['is_featured']     = $request->boolean('is_featured');

    if ($request->hasFile('product_image')) {
        $filename = $this->storeImage($request);

        // Find the existing primary gallery image, if any
        $primaryImage = $product->images()->where('is_primary', true)->first();

        // Delete the OLD physical file before replacing the reference
        $oldFilename = $primaryImage->image_path ?? $product->product_image;
        if ($oldFilename) {
            $oldPath = public_path('images/products/' . $oldFilename);
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        if ($primaryImage) {
            // Update the existing primary gallery row
            $primaryImage->update(['image_path' => $filename]);
        } else {
            // No gallery image existed yet — create one
            ProductImage::create([
                'product_id'  => $product->id,
                'image_path'  => $filename,
                'label'       => 'Main',
                'sort_order'  => 1,
                'is_primary'  => true,
            ]);
        }

        // Keep the plain column in sync too, as a fallback
        $validated['product_image'] = $filename;
    }

    $product->update($validated);

    return redirect()->route('admin.products.index')
        ->with('success', 'Product updated successfully.');
}

    /* ════════════════════════════════
       DELETE PRODUCT
    ════════════════════════════════ */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
    
        // Delete all gallery image files, not just the main one
        foreach ($product->images as $image) {
            $path = public_path('images/products/' . $image->image_path);
            if (file_exists($path)) {
                @unlink($path);
            }
            $image->delete();
        }
    
        if ($product->product_image) {
            $mainPath = public_path('images/products/' . $product->product_image);
            if (file_exists($mainPath)) {
                @unlink($mainPath);
            }
        }
    
        $product->delete();
    
        return back()->with('success', 'Product deleted.');
    }

    /* ════════════════════════════════
       QUICK STOCK UPDATE
    ════════════════════════════════ */
    public function updateStock(Request $request, $id)
    {
        $request->validate([
            'stock_quantity' => 'required|integer|min:0',
        ]);

        $product = Product::findOrFail($id);
        $product->update(['stock_quantity' => $request->stock_quantity]);

        return back()->with('success', 'Stock updated.');
    }

    /* ════════════════════════════════
       IMAGE STORAGE
       Saves directly into public/images/products so it matches
       the existing storefront's asset('images/products/...') paths
       — no Laravel storage disk / symlink involved, intentionally,
       to stay consistent with how product images already work.
    ════════════════════════════════ */
    private function storeImage(Request $request): string
    {
        $file     = $request->file('product_image');
        $filename = Str::random(20) . '.' . $file->getClientOriginalExtension();

        $destination = public_path('images/products');
        if (!file_exists($destination)) {
            mkdir($destination, 0755, true);
        }

        $file->move($destination, $filename);

        return $filename;
    }

    /* ════════════════════════════════
       DROPDOWN OPTIONS — distinct existing values for the
       Category / Material / Finish "select + add new" fields
    ════════════════════════════════ */
    private function dropdownOptions(): array
    {
        return [
            'categories' => Product::select('category')->distinct()->whereNotNull('category')->pluck('category'),
            'materials'  => Product::select('material')->distinct()->whereNotNull('material')->pluck('material'),
            'finishes'   => Product::select('finish')->distinct()->whereNotNull('finish')->pluck('finish'),
        ];
    }

    /* ════════════════════════════════
       VALIDATION RULES
       Matches the confirmed products table schema exactly:
       no on_sale / discount_percent columns exist — those are
       computed accessors on the Product model, derived from
       sale_price, not stored fields.

       Category/material/finish come from a dropdown; "__new__"
       is the sentinel for the "+ Add New" option, resolved below
       from the paired new_category/new_material/new_finish input.
       Those "Add New" inputs get the same NoLinks + Profanity
       checks as the free-text fields, per the same requirement.
    ════════════════════════════════ */
    private function validateProduct(Request $request): array
    {
        $noLinks = new NoLinks();

        $validated = $request->validate([
            'product_name'   => ['required', 'string', 'max:255', $noLinks, Profanity::make(), new NoInappropriateTerms()],
            'description'    => ['nullable', 'string', 'max:2000', $noLinks, Profanity::make(), new NoInappropriateTerms()],

            'category'       => ['required', 'string', 'max:100'],
            'new_category'   => ['required_if:category,__new__', 'nullable', 'string', 'max:100', $noLinks, Profanity::make()],

            'material'       => ['required', 'string', 'max:100'],
            'new_material'   => ['required_if:material,__new__', 'nullable', 'string', 'max:100', $noLinks, Profanity::make()],

            'finish'         => ['required', 'string', 'max:100'],
            'new_finish'     => ['required_if:finish,__new__', 'nullable', 'string', 'max:100', $noLinks, Profanity::make()],

            'price'          => ['required', 'numeric', 'min:0', 'max:999999'],
            'sale_price'     => ['nullable', 'numeric', 'min:0', 'max:999999', 'lt:price'],
            'stock_quantity' => ['required', 'integer', 'min:0'],

            'dimension_length' => ['nullable'],
            'dimension_width'  => ['nullable'],
            'dimension_height' => ['nullable'],
            'dimension_unit'   => ['nullable', 'string', 'max:4'],

            'product_status' => ['required', 'in:available,unavailable'],
            'product_image'  => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ], [
            'new_category.required_if' => 'Please enter a name for the new category.',
            'new_material.required_if' => 'Please enter a name for the new material.',
            'new_finish.required_if'   => 'Please enter a name for the new finish.',
            'price.max'                => 'Price cannot exceed ₱999,999.',
            'sale_price.max'           => 'Sale price cannot exceed ₱999,999.',
            'sale_price.lt'            => 'Sale price must be less than the regular price.',
        ], [
            'product_name' => 'product name',
            'new_category' => 'new category',
            'new_material' => 'new material',
            'new_finish'   => 'new finish',
        ]);

        // Resolve the "+ Add New" sentinel into the actual value to store
        $validated['category'] = $validated['category'] === '__new__' ? $validated['new_category'] : $validated['category'];
        $validated['material'] = $validated['material'] === '__new__' ? $validated['new_material'] : $validated['material'];
        $validated['finish']   = $validated['finish'] === '__new__' ? $validated['new_finish'] : $validated['finish'];
        unset($validated['new_category'], $validated['new_material'], $validated['new_finish']);

        // Length x Width x Height in the chosen unit, converted to cm here and stored in the
        // single column as "L x W x H cm", the same form as before. All three or none.
        $size = \App\Support\Dimensions::fromInput(
            $request->input('dimension_length'), $request->input('dimension_width'),
            $request->input('dimension_height'), $request->input('dimension_unit')
        );
        if ($size['errors'] !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($size['errors']);
        }
        unset($validated['dimension_length'], $validated['dimension_width'], $validated['dimension_height'], $validated['dimension_unit']);

        $validated['dimensions'] = $size['values']
            ? DimensionsFormatter::combine($size['values']['length_cm'], $size['values']['width_cm'], $size['values']['height_cm'])
            : null;

        return $validated;
    }
}