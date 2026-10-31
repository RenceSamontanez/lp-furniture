<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductImage;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Rules\NoInappropriateTerms;
use App\Rules\NoLinks;
use App\Support\DimensionsFormatter;
use App\Support\ProductPhotos;
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
            ->with('primaryImage', 'materialEstimate')->withCount('orderItems')
            ->when($search, fn($q) => $q->where('product_name', 'like', "%{$search}%"))
            ->when($category !== 'all', fn($q) => $q->where('category', $category))
            ->latest()
            ->paginate(12);

        $categories = Product::select('category')->distinct()->whereNotNull('category')->pluck('category');

        // One Edit modal per product on this page. The old Edit URL lands here as ?edit=ID and opens that product's modal.
        $modalProducts = $products->getCollection()->keyBy('id');
        $editId = (int) $request->get('edit');
        if ($editId > 0 && ! $modalProducts->has($editId) && ($target = Product::with('materialEstimate')->find($editId))) {
            $modalProducts->put($target->id, $target);
        }
        $openModal = old('_modal') ?: ($editId > 0 && $modalProducts->has($editId) ? 'product-edit-' . $editId : ($request->boolean('add') ? 'product-add' : null));

        $woodRows = \App\Models\Inventory::where('material_name', 'like', 'Wood (%')->pluck('material_name');

        return view('admin.products.index', array_merge(
            compact('products', 'categories', 'search', 'category', 'modalProducts', 'openModal', 'woodRows'),
            $this->dropdownOptions()
        ));
    }

    /* ════════════════════════════════
       SHOW CREATE FORM
    ════════════════════════════════ */
    public function create()
    {
        // The Add page is now a modal on the list; the old URL still works and opens it.
        return redirect()->route('admin.products.index', ['add' => 1]);
    }

    /* ════════════════════════════════
       STORE NEW PRODUCT
    ════════════════════════════════ */
    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);
        $validated['is_customizable'] = $request->boolean('is_customizable');
        $validated['is_featured']     = $request->boolean('is_featured');

        $estimate = $this->estimateInput($request);
        unset($validated['est_type'], $validated['est_wood'], $validated['est_varnish'], $validated['est_lacquer'], $validated['est_nails'], $validated['est_sand'], $validated['est_glue']);
        $product = Product::create($validated);
        \App\Support\MaterialEstimator::createProductEstimate($product, $estimate['type'], $estimate['values']);

        if ($request->hasFile('product_image')) {
            $this->setMainPhoto($product, ProductPhotos::store($request->file('product_image')));
        }

        \App\Support\ActivityLog::record('product_added', 'Added product ' . $product->product_name, 'catalog', $product, 'Product: ' . $product->product_name);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product created successfully.');
    }
    /* ════════════════════════════════
       SHOW EDIT FORM
    ════════════════════════════════ */
    public function edit($id)
    {
        Product::findOrFail($id);

        return redirect()->route('admin.products.index', ['edit' => (int) $id]);
    }

    /* ════════════════════════════════
       UPDATE PRODUCT
    ════════════════════════════════ */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        $validated = $this->validateProduct($request);
        $this->assertRoomForExtras($request, $product);
        $validated['is_customizable'] = $request->boolean('is_customizable');
        $validated['is_featured']     = $request->boolean('is_featured');

        $estimate = $this->estimateInput($request);
        unset($validated['est_type'], $validated['est_wood'], $validated['est_varnish'], $validated['est_lacquer'], $validated['est_nails'], $validated['est_sand'], $validated['est_glue']);
        $product->update($validated);
        $this->saveEstimate($product, $estimate);

        if ($request->hasFile('product_image')) {
            $this->setMainPhoto($product, ProductPhotos::store($request->file('product_image')));
        }

        $added = $this->storeExtraImages($request, $product);

        \App\Support\ActivityLog::record('product_edited', 'Edited product ' . $product->product_name, 'catalog', $product, 'Product: ' . $product->product_name);

        return redirect()->route('admin.products.index')
            ->with('success', 'Product updated successfully.' . ($added ? " {$added} extra photo" . ($added > 1 ? 's' : '') . ' added.' : ''));
    }

    /** Makes $path the main photo (replacing the old main row's file when nothing else uses it) and mirrors it in products.product_image. */
    private function setMainPhoto(Product $product, string $path): void
    {
        $main = $product->images()->where('is_primary', true)->first();
        $old  = $main?->image_path;

        if ($main) {
            $main->update(['image_path' => $path]);
        } else {
            ProductImage::create([
                'product_id' => $product->id, 'image_path' => $path,
                'label' => 'Main', 'sort_order' => 1, 'is_primary' => true,
            ]);
        }
        $product->update(['product_image' => $path]);

        if ($old && $old !== $path) {
            ProductPhotos::deleteIfUnused($old);
        }
    }
    /* ════════════════════════════════
       REMOVE ONE EXTRA PHOTO
       Only extras (is_primary = false) can be removed here; the main photo is
       replaced through the normal upload. Same disk pattern as destroy(): the file
       in the product-photos folder goes too, unless another row still uses that name.
    ════════════════════════════════ */
    public function destroyImage($id, $imageId)
    {
        $product = Product::findOrFail($id);
        $image   = $product->images()->where('is_primary', false)->findOrFail($imageId);

        $filename = $image->image_path;
        $image->delete();
        ProductPhotos::deleteIfUnused($filename);
        return redirect()->route('admin.products.edit', $product->id)
            ->with('success', 'Photo removed.');
    }

    /* ════════════════════════════════
       DELETE PRODUCT
    ════════════════════════════════ */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
    
        // Delete the files in the product-photos folder (the old public/images/products backup is never touched)
        $paths = $product->images->pluck('image_path')->push($product->product_image)->filter()->unique();
        foreach ($product->images as $image) {
            $image->delete();
        }
        $product->delete();
        foreach ($paths as $path) {
            ProductPhotos::deleteIfUnused($path);
        }

        \App\Support\ActivityLog::record('product_removed', 'Removed product ' . $product->product_name, 'catalog', 'product', 'Product: ' . $product->product_name);

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

        \App\Support\ActivityLog::record('product_edited', 'Updated the stock of ' . $product->product_name, 'catalog', $product, 'Product: ' . $product->product_name);

        return back()->with('success', 'Stock updated.');
    }

    /**
     * Extra photos from the edit form, saved next to the main image with the same method
     * (product-photos folder, random name; the extension comes from the real file type).
     * At most Product::MAX_EXTRA_IMAGES per product including the ones already there.
     * Returns how many were added.
     */
    private function assertRoomForExtras(Request $request, Product $product): void
    {
        $count = count(array_filter((array) $request->file('extra_images')));
        $existing = $product->extraImages()->count();
        if ($count > 0 && $existing + $count > Product::MAX_EXTRA_IMAGES) {
            $room = max(0, Product::MAX_EXTRA_IMAGES - $existing);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'extra_images' => 'A product can have at most ' . Product::MAX_EXTRA_IMAGES . " extra photos. This one has {$existing}, so you can add {$room} more.",
            ]);
        }
    }

    private function storeExtraImages(Request $request, Product $product): int
    {
        $files = array_values(array_filter((array) $request->file('extra_images')));
        if ($files === []) {
            return 0;
        }

        $order = max(1, (int) $product->images()->max('sort_order'));
        foreach ($files as $file) {
            $path = ProductPhotos::store($file);
            ProductImage::create([
                'product_id' => $product->id, 'image_path' => $path,
                'label' => null, 'sort_order' => ++$order, 'is_primary' => false,
            ]);
        }
        return count($files);
    }

    /** The estimate posted with the form: the type it began from and the six numbers (null values = not posted). */
    private function estimateInput(Request $request): array
    {
        $type = trim((string) $request->input('est_type'));
        $type = \App\Models\MaterialEstimate::where('name', $type)->exists() ? $type : null;

        $values = [];
        foreach (array_keys(\App\Support\MaterialLibrary::FIELDS) as $k) {
            $v = $request->input('est_' . $k);
            if ($v === null || $v === '') {
                return ['type' => $type, 'values' => null];
            }
            $values[$k] = (float) $v;
        }

        return ['type' => $type, 'values' => $values];
    }

    /**
     * Keeps the product's saved numbers unless the form changed them. "Edited" means different from the starter of the type it began from;
     * a form that did not touch the numbers leaves the flag alone (the library may have moved on since).
     */
    private function saveEstimate(Product $product, array $estimate): void
    {
        $existing = $product->materialEstimate;
        if (! $existing) {
            \App\Support\MaterialEstimator::createProductEstimate($product, $estimate['type'], $estimate['values']);
            return;
        }
        if ($estimate['values'] === null) {
            return;
        }

        $unchanged = collect($estimate['values'])->every(fn ($v, $k) => abs($v - (float) $existing->{$k}) < 0.005);
        if ($unchanged) {
            return;
        }
        \App\Support\MaterialEstimator::createProductEstimate($product, $estimate['type'] ?? $existing->type_name, $estimate['values']);
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

            // Materials Needed: the six numbers of one piece. Optional (a missing set means "use the starter").
            'est_type'    => ['nullable', 'string', 'max:120'],
            'est_wood'    => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'est_varnish' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'est_lacquer' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'est_nails'   => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'est_sand'    => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'est_glue'    => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'product_image'  => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120', new \App\Rules\RealJpegOrPng()],

            'extra_images'   => ['nullable', 'array', 'max:' . Product::MAX_EXTRA_IMAGES],
            'extra_images.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120', new \App\Rules\RealJpegOrPng()],
        ], [
            'extra_images.max'         => 'You can add at most ' . Product::MAX_EXTRA_IMAGES . ' extra photos.',
            'extra_images.*.image'     => 'Each extra photo must be a JPG or PNG picture.',
            'extra_images.*.mimes'     => 'Each extra photo must be a JPG or PNG picture.',
            'extra_images.*.mimetypes' => 'Each extra photo must be a JPG or PNG picture.',
            'extra_images.*.max'       => 'Each extra photo must be 5 MB or smaller.',
            'extra_images.*.uploaded'  => 'An extra photo could not be uploaded. It may be larger than 5 MB.',
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
        unset($validated['new_category'], $validated['new_material'], $validated['new_finish'], $validated['extra_images']);

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