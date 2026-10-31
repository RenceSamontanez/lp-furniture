<?php

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * products:import-builtin-photos, the single image_url source, the placeholder and past-order snapshots.
 * The public disk is faked and the "old" folder is a temp directory, so no real photo is touched.
 */
class ProductPhotoImportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->legacy = str_replace('\\', '/', sys_get_temp_dir()) . '/lp-legacy-' . bin2hex(random_bytes(4));
        mkdir($this->legacy, 0777, true);
        config(['maintenance.legacy_product_photos' => $this->legacy]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->legacy . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->legacy);
        parent::tearDown();
    }

    private function oldFile(string $name, int $w = 40): string
    {
        $im = imagecreatetruecolor($w, $w);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), 80, 80));
        imagejpeg($im, $this->legacy . '/' . $name, 90);

        return $this->legacy . '/' . $name;
    }

    private function product(string $name = 'Chair'): Product
    {
        return Product::create(['product_name' => $name, 'category' => 'Chairs', 'price' => 1000, 'stock_quantity' => 3, 'product_status' => 'available']);
    }

    private function row(Product $p, string $path, bool $main, int $sort): ProductImage
    {
        return ProductImage::create(['product_id' => $p->id, 'image_path' => $path, 'sort_order' => $sort, 'is_primary' => $main]);
    }

    /** Narra as on furniture_db: main row = front, extra = back, the legacy column holds a separate admin upload. */
    private function narra(): Product
    {
        foreach (['front.jpg', 'back.jpg', 'U3dh6Dzh0m6llNJzAifX.jpg'] as $f) {
            $this->oldFile($f);
        }
        $p = $this->product('Narra Dining Table');
        $this->row($p, 'front.jpg', true, 1);
        $this->row($p, 'back.jpg', false, 2);
        $p->update(['product_image' => 'U3dh6Dzh0m6llNJzAifX.jpg']);

        return $p;
    }

    private function snapshot(): array
    {
        return [
            ProductImage::orderBy('id')->get(['id', 'product_id', 'image_path', 'is_primary', 'sort_order'])->toArray(),
            Product::orderBy('id')->pluck('product_image', 'id')->all(),
            Storage::disk('public')->allFiles(),
        ];
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->narra();
        $before = $this->snapshot();

        $this->artisan('products:import-builtin-photos')->expectsOutputToContain('DRY RUN')->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_import_repoints_rows_keeps_narras_front_as_main_and_never_touches_the_originals(): void
    {
        $p = $this->narra();
        $hash = array_map('md5_file', glob($this->legacy . '/*'));

        $this->artisan('products:import-builtin-photos', ['--force' => true])->assertSuccessful();

        $rows = $p->images()->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertStringStartsWith('product-photos/', $r->image_path);
            Storage::disk('public')->assertExists($r->image_path);
        }
        $main = $rows->firstWhere('is_primary', true);
        $this->assertSame(1, $rows->where('is_primary', true)->count());
        $this->assertSame(1, (int) $main->sort_order);
        $this->assertSame([1, 2, 3], $rows->pluck('sort_order')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($main->image_path, $p->fresh()->product_image, 'the legacy column mirrors the main photo');
        $this->assertSame($main->url, $p->fresh()->image_url);

        // the main file is a copy of front.jpg: same picture, new random name
        $this->assertNotSame('front.jpg', basename($main->image_path));
        $this->assertSame(array_map('md5_file', glob($this->legacy . '/*')), $hash, 'originals untouched');
        $this->assertCount(3, glob($this->legacy . '/*'));
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->narra();
        $this->artisan('products:import-builtin-photos', ['--force' => true])->assertSuccessful();
        $after = $this->snapshot();

        $this->artisan('products:import-builtin-photos', ['--force' => true])->expectsOutputToContain('0 product(s)')->assertSuccessful();

        $this->assertSame($after, $this->snapshot());
    }

    public function test_a_photo_already_in_product_photos_is_never_overwritten(): void
    {
        $this->oldFile('chair.jpg');
        $admin = $this->product('Admin uploaded');
        Storage::disk('public')->put('product-photos/mine.jpg', 'admin-bytes');
        $this->row($admin, 'product-photos/mine.jpg', true, 1);
        $admin->update(['product_image' => 'product-photos/mine.jpg']);
        $old = $this->product('Old style');
        $this->row($old, 'chair.jpg', true, 1);

        $this->artisan('products:import-builtin-photos', ['--force' => true])->assertSuccessful();

        $this->assertSame('product-photos/mine.jpg', $admin->images()->first()->image_path);
        $this->assertSame('admin-bytes', Storage::disk('public')->get('product-photos/mine.jpg'));
        $this->assertStringStartsWith('product-photos/', $old->images()->first()->image_path);
    }

    public function test_a_missing_old_file_is_reported_and_its_row_left_alone(): void
    {
        $p = $this->product();
        $this->row($p, 'gone.jpg', true, 1);

        $this->artisan('products:import-builtin-photos', ['--force' => true])->expectsOutputToContain('file missing')->assertSuccessful();

        $this->assertSame('gone.jpg', $p->images()->first()->image_path);
    }

    public function test_a_file_that_is_not_a_real_picture_is_skipped(): void
    {
        file_put_contents($this->legacy . '/fake.jpg', 'not a picture');
        $p = $this->product();
        $this->row($p, 'fake.jpg', true, 1);

        $this->artisan('products:import-builtin-photos', ['--force' => true])->expectsOutputToContain('not a real JPEG or PNG')->assertSuccessful();

        $this->assertSame('fake.jpg', $p->images()->first()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_big_old_photo_is_shrunk_to_1600_px(): void
    {
        $this->oldFile('big.jpg', 2400);
        $p = $this->product();
        $this->row($p, 'big.jpg', true, 1);

        $this->artisan('products:import-builtin-photos', ['--force' => true])->assertSuccessful();

        $path = Storage::disk('public')->path($p->images()->first()->image_path);
        $this->assertSame(1600, max(array_slice(getimagesize($path), 0, 2)));
    }

    /* ───────────── what the pages show ───────────── */

    public function test_catalog_admin_list_and_edit_page_show_the_same_main_photo(): void
    {
        $p = $this->product('Same Photo Chair');
        Storage::disk('public')->put('product-photos/main.jpg', 'x');
        Storage::disk('public')->put('product-photos/other.jpg', 'x');
        $this->row($p, 'product-photos/other.jpg', false, 2);
        $this->row($p, 'product-photos/main.jpg', true, 1);
        $p->update(['product_image' => 'product-photos/stale.jpg']);   // a stale legacy value must not be read
        $url = asset('storage/product-photos/main.jpg');

        $this->get(route('catalog'))->assertOk()->assertSee($url, false)->assertDontSee('stale.jpg');
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'admin')->get(route('admin.products.index'))->assertOk()->assertSee($url, false)->assertDontSee('stale.jpg');
        $this->actingAs($admin, 'admin')->get(route('admin.products.index', ['edit' => $p->id]))->assertOk()->assertSee($url, false);
        $this->getJson("/product/{$p->id}/quick-view")->assertJsonPath('gallery_images.0', $url);
    }

    public function test_a_product_without_a_photo_shows_the_placeholder_and_stays_orderable(): void
    {
        $p = $this->product('No Photo Chair');
        $p->update(['product_image' => 'old-name.jpg']);   // the legacy column alone is not a photo
        $ph = asset('images/products/placeholder.jpg');

        $this->assertFalse($p->fresh()->has_photo);
        $this->assertSame($ph, $p->fresh()->image_url);
        $this->get(route('catalog'))->assertOk()->assertSee('No Photo Chair')->assertSee($ph, false)->assertDontSee('old-name.jpg');
        $this->get(route('product.show', $p->id))->assertOk()->assertSee($ph, false);
        $this->getJson("/product/{$p->id}/quick-view")->assertOk()->assertJsonPath('gallery_images', [$ph]);
        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'admin')->get(route('admin.products.index'))->assertOk()->assertSee($ph, false);
        $this->actingAs($admin, 'admin')->get(route('admin.products.index', ['edit' => $p->id]))->assertOk()->assertSee($ph, false);
    }

    public function test_a_past_order_still_shows_its_old_snapshot_image(): void
    {
        $this->oldFile('old-snap.jpg');
        Storage::disk('public')->put('product-photos/new-snap.jpg', 'x');
        $customer = $this->makeUser('customer');
        $order = $this->makeOrder($customer);

        $gone = OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Gone', 'product_image' => 'old-snap.jpg', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1]);
        $newer = OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Newer', 'product_image' => 'product-photos/new-snap.jpg', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1]);
        $lost = OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Lost', 'product_image' => 'deleted-long-ago.jpg', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1]);

        $this->assertSame(asset('images/products/old-snap.jpg'), $gone->image_url);
        $this->assertSame(asset('storage/product-photos/new-snap.jpg'), $newer->image_url);
        $this->assertSame(asset('images/products/placeholder.jpg'), $lost->image_url);

        $this->actingAs($customer)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee(asset('images/products/old-snap.jpg'), false);
    }

    public function test_new_admin_uploads_go_to_the_product_photos_folder(): void
    {
        $p = $this->product();
        $png = \Illuminate\Http\UploadedFile::fake()->createWithContent('p.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->actingAs($this->makeUser('admin'), 'admin')->put(route('admin.products.update', $p->id), [
            'product_name' => 'Chair', 'category' => 'Chairs', 'material' => 'Narra', 'finish' => 'Natural', 'price' => '1000',
            'stock_quantity' => '3', 'product_status' => 'available', 'product_image' => $png,
        ])->assertSessionHasNoErrors();

        $path = $p->fresh()->mainPhoto()->image_path;
        $this->assertMatchesRegularExpression('#^product-photos/[A-Za-z0-9]{20}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
    }
}
