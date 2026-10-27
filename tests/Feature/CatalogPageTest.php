<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\HeroRails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** The redesigned catalog (filters, sort, badges) and the JSON the glass Quick view reads. */
class CatalogPageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function product(string $name, array $over = []): Product
    {
        return Product::create($over + [
            'product_name' => $name, 'category' => 'Chairs', 'price' => 2500,
            'stock_quantity' => 5, 'product_status' => 'available',
        ]);
    }

    private function sell(Product $p, int $times = 1): void
    {
        $order = $this->makeOrder($this->makeUser(), ['total_amount' => 2500]);
        for ($i = 0; $i < $times; $i++) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $p->id, 'product_name' => $p->product_name,
                'quantity' => 1, 'unit_price' => 2500, 'subtotal' => 2500,
            ]);
        }
    }

    /** @return list<string> product names in the order the page lists them */
    private function names(string $url): array
    {
        $products = $this->get($url)->assertOk()->viewData('products');

        return $products->pluck('product_name')->all();
    }

    private function seedMix(): void
    {
        $this->product('Narra Table', ['category' => 'Tables', 'price' => 9000, 'created_at' => now()->subDays(3)]);
        $this->product('Oak Chair', ['category' => 'Chairs', 'price' => 3000, 'created_at' => now()->subDays(2)]);
        $this->product('Pine Chair', ['category' => 'Chairs', 'price' => 6000, 'created_at' => now()->subDays(1)]);
        $this->product('Hidden Chair', ['category' => 'Chairs', 'price' => 100, 'product_status' => 'unavailable']);
    }

    public function test_catalog_lists_only_available_products_newest_first(): void
    {
        $this->seedMix();

        $this->assertSame(['Pine Chair', 'Oak Chair', 'Narra Table'], $this->names('/catalog'));
    }

    public function test_category_search_and_max_price_filters_still_work_and_combine(): void
    {
        $this->seedMix();

        $this->assertSame(['Pine Chair', 'Oak Chair'], $this->names('/catalog?category=Chairs'));
        $this->assertSame(['Narra Table'], $this->names('/catalog?search=narra'));
        $this->assertSame(['Oak Chair', ], $this->names('/catalog?max_price=3500'));
        $this->assertSame(['Oak Chair'], $this->names('/catalog?category=Chairs&max_price=4000&search=oak'));
        $this->assertSame([], $this->names('/catalog?category=Tables&max_price=100'));
    }

    public function test_sort_orders_by_price_and_works_with_filters(): void
    {
        $this->seedMix();

        $this->assertSame(['Oak Chair', 'Pine Chair', 'Narra Table'], $this->names('/catalog?sort=price_asc'));
        $this->assertSame(['Narra Table', 'Pine Chair', 'Oak Chair'], $this->names('/catalog?sort=price_desc'));
        $this->assertSame(['Pine Chair', 'Oak Chair', 'Narra Table'], $this->names('/catalog?sort=newest'));
        $this->assertSame(['Pine Chair', 'Oak Chair'], $this->names('/catalog?category=Chairs&sort=price_desc'));
        $this->assertSame(['Oak Chair'], $this->names('/catalog?max_price=6000&sort=price_asc&search=oak'));
        // an unknown sort never reaches the query: it falls back to newest
        $this->assertSame(['Pine Chair', 'Oak Chair', 'Narra Table'], $this->names('/catalog?sort=price;drop'));
    }

    public function test_price_sort_uses_the_sale_price_when_a_sale_is_on(): void
    {
        $this->product('Cheap Chair', ['price' => 5000]);
        $this->product('Sale Chair', ['price' => 8000, 'sale_price' => 2000]);

        $this->assertSame(['Sale Chair', 'Cheap Chair'], $this->names('/catalog?sort=price_asc'));
    }

    public function test_luma_type_filter_still_works_with_sort(): void
    {
        $this->product('Panel Door', ['category' => 'Doors', 'price' => 7000]);
        $this->product('Cheap Door', ['category' => 'Doors', 'price' => 3000]);
        $this->product('Oak Chair', ['category' => 'Chairs', 'price' => 100]);

        $this->assertSame(['Cheap Door', 'Panel Door'], $this->names('/catalog?type=door&sort=price_asc'));
        $this->assertSame(['Oak Chair'], $this->names('/catalog?type=nonsense&max_price=500'), 'unknown types are ignored');
    }

    public function test_category_index_shows_real_counts_and_the_current_one(): void
    {
        $this->seedMix();

        $html = $this->get('/catalog?category=Chairs')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-current="true"\s*>\s*Chairs\s*<small>2<\/small>/', $html);
        $this->assertMatchesRegularExpression('/>All <small>3<\/small>/', $html);
        $this->assertStringContainsString('Tables', $html);
    }

    public function test_empty_result_explains_and_offers_clear_filters(): void
    {
        $this->seedMix();

        $this->get('/catalog?search=zzzz')->assertOk()
            ->assertSee('Nothing matches these filters')->assertSee('Clear filters');
    }

    public function test_filter_links_keep_the_other_filters(): void
    {
        $this->seedMix();

        $html = $this->get('/catalog?search=chair&max_price=5000&sort=price_asc')->assertOk()->getContent();
        $this->assertStringContainsString('category=Tables', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*category=Tables[^"]*sort=price_asc[^"]*"/', html_entity_decode($html));
    }

    public function test_badges_appear_only_when_hero_rails_says_so(): void
    {
        // fewer than three products: HeroRails hides its rails, so no badge at all
        $this->product('A');
        $this->product('B');
        $this->assertStringNotContainsString('class="cx-badge">', $this->get('/catalog')->getContent());

        // ten products, only the newest eight are "New" and only an ordered one is "Best seller"
        $made = [];
        for ($i = 1; $i <= 10; $i++) {
            $made[$i] = $this->product("Chair {$i}", ['created_at' => now()->subDays(20 - $i)]);
        }
        $this->sell($made[10], 2);
        $this->sell($made[9], 1);
        $this->sell($made[8], 1);

        $rails = HeroRails::get();
        $expected = [];
        foreach (['fresh', 'best'] as $rail) {
            foreach ($rails[$rail] as $card) {
                $expected[$card['id']] = $card['tag'];
            }
        }
        $badges = $this->get('/catalog')->assertOk()->viewData('badges');
        $this->assertSame($expected, $badges);
        $this->assertSame('Best seller', $badges[$made[10]->id]);
        $this->assertArrayNotHasKey($made[1]->id, $badges, 'older than the eight newest');

        $html = $this->get('/catalog')->getContent();
        $this->assertSame(count($expected), substr_count($html, 'class="cx-badge">'));
    }

    public function test_quick_view_returns_gallery_in_order_and_the_details(): void
    {
        $p = $this->product('Narra Table', [
            'category' => 'Tables', 'price' => 18500, 'material' => 'Narra', 'finish' => 'Natural varnish',
            'dimensions' => '180 x 90 x 76 cm', 'description' => 'Seats six.', 'product_image' => 'legacy.jpg',
        ]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'extra-two.jpg', 'sort_order' => 2, 'is_primary' => false]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'main.jpg', 'sort_order' => 0, 'is_primary' => true]);
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'extra-one.jpg', 'sort_order' => 1, 'is_primary' => false]);

        $j = $this->getJson("/product/{$p->id}/quick-view")->assertOk()->json();

        $this->assertSame(
            [asset('storage/main.jpg'), asset('storage/extra-one.jpg'), asset('storage/extra-two.jpg')],
            $j['gallery_images']
        );
        $this->assertArrayNotHasKey('images', $j);
        $this->assertArrayNotHasKey('image_url', $j);
        $this->assertSame('Narra Table', $j['name']);
        $this->assertSame('₱18,500.00', $j['price_display']);
        $this->assertSame('Narra', $j['material']);
        $this->assertSame('Natural varnish', $j['finish']);
        $this->assertSame(['length' => 180, 'width' => 90, 'height' => 76], array_map('intval', $j['size_cm']));
        $this->assertEquals(70.9, $j['size_inches']['length']);
        $this->assertSame(route('product.show', $p->id), $j['detail_url']);
        $this->assertSame('Seats six.', $j['description']);
    }

    public function test_quick_view_with_no_extras_returns_only_the_main_photo(): void
    {
        $p = $this->product('Plain Chair');
        ProductImage::create(['product_id' => $p->id, 'image_path' => 'only.jpg', 'sort_order' => 0, 'is_primary' => true]);

        $this->getJson("/product/{$p->id}/quick-view")->assertOk()
            ->assertJsonPath('gallery_images', [asset('storage/only.jpg')]);
    }

    public function test_quick_view_omits_empty_details_and_uses_the_placeholder_for_no_photo(): void
    {
        $p = $this->product('Bare Stool', ['material' => '', 'finish' => null, 'dimensions' => null, 'description' => null]);

        $j = $this->getJson("/product/{$p->id}/quick-view")->assertOk()->json();

        $this->assertNull($j['material']);
        $this->assertNull($j['finish']);
        $this->assertNull($j['size_cm']);
        $this->assertNull($j['size_inches']);
        $this->assertNull($j['dimensions']);
        $this->assertNull($j['badge']);
        $this->assertSame([asset('images/products/placeholder.jpg')], $j['gallery_images']);
    }

    public function test_quick_view_hides_unavailable_products(): void
    {
        $p = $this->product('Hidden', ['product_status' => 'unavailable']);

        $this->getJson("/product/{$p->id}/quick-view")->assertNotFound();
    }

    public function test_catalog_cards_carry_the_quick_view_hook_and_a_real_price_tag(): void
    {
        $p = $this->product('Oak Chair', ['price' => 3000]);

        $html = $this->get('/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('data-quick-view="' . $p->id . '"', $html);
        $this->assertStringContainsString('<span>₱3,000.00</span>', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringNotContainsString('Add to cart', $html);
    }
}
