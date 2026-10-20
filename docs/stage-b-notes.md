# Stage B notes (catalog redesign prep)

## Part 0: what the project already had (checked before changing anything)

**a) Material, Finish, Size.**
`products` already has `material` (string), `finish` (string) and `dimensions` (one text column, stored as `"L x W x H cm"`, e.g. `120 x 60 x 75 cm`). There are no separate length/width/height columns. The admin create/edit form already has Material and Finish (a dropdown of existing values plus "Add new", max 100, required) and a Length / Width / Height group with a unit selector (cm, inch, ft, m, mm). The server converts to cm (`App\Support\Dimensions::fromInput`, 2 to 600 cm per side, all three or none) and stores the single text. The public product page (`product/show.blade.php`) already shows Material, Finish and "Size (L x W x H)" as `120 x 60 x 75 cm (47.2 x 23.6 x 29.5 in)` and hides each row when empty. `Product::dimensions_cm`, `dimension_form_values` and `dimensions_label` already read the text.
**Decision:** no new columns and no migration. The single `dimensions` text stays the source of truth. New `size_cm` / `size_inches` helpers read it.

**b) Photos.**
There is a multi-image table `product_images` (`id, product_id` FK cascade, `image_path`, `label`, `sort_order`, `is_primary`, timestamps) and a legacy `products.product_image` column. Files are saved straight into `public/images/products/` (random name, no storage disk) and served with `asset('images/products/...')`. Before this work the admin could only upload one image (jpg/png, 5 MB), which created the `is_primary` row. The public page already showed a thumbnail strip when a product had several rows, but nothing in the admin could create more than one. Deleting a product unlinks its files from disk.
**Decision:** reuse `product_images` (no second system, no migration).

**c) Catalog (report only).** `GET /catalog` -> `ProductController@catalog` -> `resources/views/catalog.blade.php`. Filters: `category`, `max_price`, `search` (product name), and `type` (Luma's furniture type). No pagination (`->get()`). Categories are the distinct `category` values of available products. Quick view is `GET /product/{id}/quick-view` (JSON: name, price, material, finish, `dimensions` label, images).

**d) Best seller / New (report only).** `App\Support\HeroRails`: "Best seller" = available products with at least one order item, ordered by order-item count; "New" = newest available products. Each rail holds 8 and is hidden when it has fewer than 3.

**e) Contact settings / hours (report only).** Business settings (`BusinessSetting`, edited at `/admin/settings/contact`, `BusinessSettingController`) hold phone, email and Facebook URL. There is no address or hours field there. Shop hours are in `config/luma.php` (`hours`: 08:00 to 17:00, Monday to Friday, Asia/Manila). Only "San Juan, Batangas" is known as an address.

**f) Footers (report only).** Two: the slim `lp-footer` in `layouts/app.blade.php` (copyright, guide and terms links; hidden when a page sets the `hide_site_footer` section) and the full `site-footer` inside `home.blade.php` (the home page hides the slim one). `layouts/focus.blade.php` (login, register, etc.) has none.

## Part 1: what was added

* `Product::MAX_EXTRA_IMAGES` (5), `extraImages()` relation, `gallery_images` (list of URLs, main photo first, then extras by `sort_order`), `size_cm` (alias of `dimensions_cm`), `size_inches` (cm / 2.54, one decimal, calculated on read, never stored). Both size helpers return `null` with no usable size.
* Admin edit page: "Extra Photos" panel (not on the create page): add up to 5 (jpg, png, webp, 5 MB each, checked by real file content), thumbnails, "Remove" with the shared `.lp-modal` confirmation. Route `DELETE /admin/products/{id}/images/{image}` (`admin.products.images.destroy`).
* Stored like the main image: `public/images/products/`, random name, extension from the real file type. Removing an extra deletes the row and its file (the same pattern as deleting a product), but the file is kept if another row or product still uses the same file name. Only extras can be removed there; the main photo cannot.
* A product whose only photo is in the old `product_image` column gets a main `product_images` row for that same file (no copy) the first time an extra is added, so the main photo always stays first.
* The product page now builds its main image and thumbnails from `gallery_images`.
* Material and Finish stay required in the admin form as they were. Everything renders with them empty (older or imported rows).
