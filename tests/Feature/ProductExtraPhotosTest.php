<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Product extra photos (admin edit page), the gallery_images / size_cm / size_inches helpers,
 * and the product detail page when optional fields are missing.
 * Uploads land in public/images/products like the main image; the files these tests create are removed afterwards.
 */
class ProductExtraPhotosTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    /** @var list<string> */
    private array $madeFiles = [];

    protected function tearDown(): void
    {
        foreach (ProductImage::pluck('image_path') as $name) {
            $this->madeFiles[] = $name;
        }
        foreach (array_unique($this->madeFiles) as $name) {
            $path = public_path('images/products/' . $name);
            if (str_starts_with($name, 'tst_') || preg_match('/^[A-Za-z0-9]{20}\.[a-z]+$/', $name)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function admin()
    {
        return $this->makeUser('admin');
    }

    private function product(array $over = []): Product
    {
        return Product::create(array_merge([
            'product_name' => 'Narra Table', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural',
            'price' => 5000, 'stock_quantity' => 2, 'product_status' => 'available',
        ], $over));
    }

    private function payload(Product $p, array $over = []): array
    {
        return array_merge([
            'product_name' => $p->product_name, 'category' => $p->category, 'material' => $p->material ?? 'Narra',
            'finish' => $p->finish ?? 'Natural', 'price' => '5000', 'stock_quantity' => '2', 'product_status' => 'available',
        ], $over);
    }

    private function update(Product $p, array $over = [])
    {
        return $this->actingAs($this->admin(), 'admin')->put(route('admin.products.update', $p->id), $this->payload($p, $over));
    }

    private function extra(Product $p, int $order, string $name = null): ProductImage
    {
        $name ??= 'tst_' . uniqid() . '.jpg';
        $this->madeFiles[] = $name;

        return ProductImage::create(['product_id' => $p->id, 'image_path' => $name, 'sort_order' => $order, 'is_primary' => false]);
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const WEBP = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    /** Real (tiny) PNG files, no GD needed. $kb pads the file past its picture data to reach a size. */
    private function photos(int $n, int $kb = 0): array
    {
        return array_map(function ($i) use ($kb) {
            $bytes = base64_decode(self::PNG) . str_repeat('0', $kb * 1024);

            return UploadedFile::fake()->createWithContent("p{$i}.png", $bytes);
        }, range(1, $n));
    }

    /* ───────────── uploading ───────────── */

    public function test_extra_photos_are_saved_after_the_main_photo(): void
    {
        $p = $this->product();

        $this->update($p, ['extra_images' => $this->photos(2)])->assertSessionHasNoErrors();

        $rows = $p->extraImages()->get();
        $this->assertCount(2, $rows);
        $this->assertSame([2, 3], $rows->pluck('sort_order')->all());
        $this->assertFalse($rows[0]->is_primary);
        foreach ($rows as $r) {
            $this->assertFileExists(public_path('images/products/' . $r->image_path));
        }
    }

    public function test_a_webp_photo_is_accepted(): void
    {
        $p = $this->product();

        $this->update($p, ['extra_images' => [UploadedFile::fake()->createWithContent('a.webp', base64_decode(self::WEBP))]])->assertSessionHasNoErrors();

        $this->assertSame(1, $p->extraImages()->count());
        $this->assertStringEndsWith('.webp', $p->extraImages()->first()->image_path);
    }

    public function test_an_oversized_photo_is_refused(): void
    {
        $p = $this->product();

        $this->update($p, ['extra_images' => $this->photos(1, 6000)])->assertSessionHasErrors('extra_images.0');

        $this->assertSame(0, $p->extraImages()->count());
    }

    public function test_a_file_that_is_not_a_picture_is_refused_even_if_named_jpg(): void
    {
        $p = $this->product();
        // A real UploadedFile, not the fake(): the fake reports a type from the file name, which would hide the check.
        $tmp = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($tmp, 'this is plain text, not an image');
        $fake = new UploadedFile($tmp, 'fake.jpg', null, null, true);

        $this->update($p, ['extra_images' => [$fake]])->assertSessionHasErrors('extra_images.0');

        $pdf = UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf');
        $this->update($p, ['extra_images' => [$pdf]])->assertSessionHasErrors('extra_images.0');
        @unlink($tmp);
        $this->assertSame(0, $p->extraImages()->count());
    }

    public function test_more_than_five_in_one_go_is_refused_and_nothing_else_is_saved(): void
    {
        $p = $this->product();

        $this->update($p, ['product_name' => 'Renamed', 'extra_images' => $this->photos(6)])->assertSessionHasErrors('extra_images');

        $this->assertSame(0, $p->extraImages()->count());
        $this->assertSame('Narra Table', $p->fresh()->product_name);
    }

    public function test_the_five_photo_limit_counts_the_ones_already_there(): void
    {
        $p = $this->product();
        foreach (range(2, 4) as $o) {
            $this->extra($p, $o);
        }

        $this->update($p, ['product_name' => 'Renamed', 'extra_images' => $this->photos(3)])->assertSessionHasErrors('extra_images');

        $this->assertSame(3, $p->extraImages()->count());
        $this->assertSame('Narra Table', $p->fresh()->product_name, 'nothing is half-saved');

        $this->update($p, ['extra_images' => $this->photos(2)])->assertSessionHasNoErrors();
        $this->assertSame(5, $p->extraImages()->count());
    }

    public function test_a_product_with_only_the_old_image_column_keeps_that_photo_first(): void
    {
        $p = $this->product(['product_image' => 'legacy-main.jpg']);

        $this->update($p, ['extra_images' => $this->photos(1)])->assertSessionHasNoErrors();

        $gallery = $p->fresh()->gallery_images;
        $this->assertCount(2, $gallery);
        $this->assertStringEndsWith('legacy-main.jpg', $gallery[0]);
        $this->assertSame(1, $p->images()->where('is_primary', true)->count());
    }

    /* ───────────── removing ───────────── */

    public function test_removing_an_extra_photo_deletes_the_row_and_its_file(): void
    {
        $p = $this->product();
        $this->update($p, ['extra_images' => $this->photos(2)]);
        $row = $p->extraImages()->first();
        $file = public_path('images/products/' . $row->image_path);
        $this->assertFileExists($file);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.products.images.destroy', [$p->id, $row->id]))
            ->assertRedirect(route('admin.products.edit', $p->id));

        $this->assertDatabaseMissing('product_images', ['id' => $row->id]);
        $this->assertFileDoesNotExist($file);
        $this->assertSame(1, $p->extraImages()->count());
    }

    public function test_the_file_stays_when_something_else_still_uses_it(): void
    {
        $p = $this->product();
        $a = $this->extra($p, 2, 'tst_shared.jpg');
        $this->extra($p, 3, 'tst_shared.jpg');
        file_put_contents(public_path('images/products/tst_shared.jpg'), 'x');

        $this->actingAs($this->admin(), 'admin')->delete(route('admin.products.images.destroy', [$p->id, $a->id]));

        $this->assertFileExists(public_path('images/products/tst_shared.jpg'));
    }

    public function test_the_main_photo_and_other_products_photos_cannot_be_removed_here(): void
    {
        $p = $this->product();
        $main = ProductImage::create(['product_id' => $p->id, 'image_path' => 'tst_main.jpg', 'sort_order' => 1, 'is_primary' => true]);
        $other = $this->extra($this->product(['product_name' => 'Other']), 2);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->delete(route('admin.products.images.destroy', [$p->id, $main->id]))->assertNotFound();
        $this->actingAs($admin, 'admin')->delete(route('admin.products.images.destroy', [$p->id, $other->id]))->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $main->id]);
        $this->assertDatabaseHas('product_images', ['id' => $other->id]);
    }

    public function test_only_an_admin_can_remove_a_photo(): void
    {
        $p = $this->product();
        $row = $this->extra($p, 2);

        $this->delete(route('admin.products.images.destroy', [$p->id, $row->id]))->assertRedirect();
        $this->assertDatabaseHas('product_images', ['id' => $row->id]);
    }

    /* ───────────── helpers on the model ───────────── */

    public function test_gallery_images_lists_the_main_photo_first_then_extras_in_order(): void
    {
        $p = $this->product();
        $this->extra($p, 4, 'tst_c.jpg');
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'tst_main.jpg', 'sort_order' => 1, 'is_primary' => true]);
        $this->extra($p, 2, 'tst_a.jpg');
        $this->extra($p, 3, 'tst_b.jpg');

        $names = array_map('basename', $p->fresh()->gallery_images);

        $this->assertSame(['tst_main.jpg', 'tst_a.jpg', 'tst_b.jpg', 'tst_c.jpg'], $names);
    }

    public function test_gallery_images_is_empty_for_a_product_with_no_photo(): void
    {
        $this->assertSame([], $this->product()->gallery_images);
    }

    public function test_size_helpers_are_null_when_there_is_no_size(): void
    {
        $p = $this->product(['dimensions' => null]);
        $this->assertNull($p->size_cm);
        $this->assertNull($p->size_inches);

        $p = $this->product(['dimensions' => 'made to order']);
        $this->assertNull($p->size_cm);
        $this->assertNull($p->size_inches);
    }

    public function test_size_helpers_return_cm_and_inches_to_one_decimal(): void
    {
        $p = $this->product(['dimensions' => '120 x 60 x 75 cm']);

        $this->assertSame(['length' => 120.0, 'width' => 60.0, 'height' => 75.0], $p->size_cm);
        $this->assertSame(['length' => 47.2, 'width' => 23.6, 'height' => 29.5], $p->size_inches);
    }

    /* ───────────── pages ───────────── */

    public function test_detail_page_shows_material_finish_and_size_when_present(): void
    {
        $p = $this->product(['dimensions' => '120 x 60 x 75 cm']);

        $this->get(route('product.show', $p->id))->assertOk()
            ->assertSee('Material')->assertSee('Narra')
            ->assertSee('Finish')->assertSee('Natural')
            ->assertSee('120 x 60 x 75 cm (47.2 x 23.6 x 29.5 in)', false);
    }

    public function test_detail_page_hides_empty_rows_and_still_renders_with_no_photo(): void
    {
        $p = $this->product(['material' => null, 'finish' => null, 'dimensions' => null]);

        $this->get(route('product.show', $p->id))->assertOk()
            ->assertDontSee('detail-spec-label">Material', false)
            ->assertDontSee('detail-spec-label">Finish', false)
            ->assertDontSee('Size (L x W x H)')
            ->assertDontSee('detail-thumbs', false);
    }

    public function test_detail_page_shows_thumbnails_only_with_more_than_one_photo(): void
    {
        $p = $this->product();
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'tst_main.jpg', 'sort_order' => 1, 'is_primary' => true]);
        $this->get(route('product.show', $p->id))->assertOk()->assertDontSee('detail-thumbs', false);

        $this->extra($p, 2, 'tst_two.jpg');
        $this->get(route('product.show', $p->id))->assertOk()->assertSee('detail-thumbs', false)->assertSee('tst_two.jpg');
    }

    public function test_admin_forms_render_for_a_bare_product_and_for_one_with_photos(): void
    {
        $admin = $this->admin();
        $bare = $this->product(['material' => null, 'finish' => null, 'dimensions' => null]);

        $this->actingAs($admin, 'admin')->get(route('admin.products.create'))->assertOk()->assertDontSee('Extra Photos');
        $this->actingAs($admin, 'admin')->get(route('admin.products.edit', $bare->id))->assertOk()
            ->assertSee('Extra Photos')->assertSee('5 more allowed');

        $this->extra($bare, 2);
        $this->actingAs($admin, 'admin')->get(route('admin.products.edit', $bare->id))->assertOk()
            ->assertSee('4 more allowed')->assertSee('Remove photo');
        $this->actingAs($admin, 'admin')->get(route('admin.products.index'))->assertOk();
    }

    public function test_the_edit_form_keeps_typed_values_after_a_photo_error(): void
    {
        $p = $this->product();

        $this->actingAs($this->admin(), 'admin')->from(route('admin.products.edit', $p->id))
            ->put(route('admin.products.update', $p->id), $this->payload($p, ['description' => 'Kept text', 'extra_images' => $this->photos(6)]))
            ->assertRedirect(route('admin.products.edit', $p->id))
            ->assertSessionHasErrors('extra_images')
            ->assertSessionHasInput('description', 'Kept text');
    }
}
