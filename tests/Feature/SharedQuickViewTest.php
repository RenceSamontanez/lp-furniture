<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Polish sweep part 2: one glass Quick view on the catalog, home and product pages; the old modal is gone. */
class SharedQuickViewTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name): Product
    {
        return Product::create([
            'product_name' => $name, 'category' => 'Chairs', 'price' => 2500,
            'stock_quantity' => 5, 'product_status' => 'available',
        ]);
    }

    public function test_every_page_with_a_quick_view_uses_the_same_markup_and_files(): void
    {
        $a = $this->product('Oak Chair');
        $b = $this->product('Pine Chair');

        $pages = [
            'catalog' => $this->get('/catalog'),
            'home'    => $this->get('/'),
            'product' => $this->get(route('product.show', $a->id)),
        ];

        foreach ($pages as $name => $res) {
            $html = $res->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="cxMod"'), "$name: one shared dialog");
            $this->assertStringContainsString('role="dialog"', $html, $name);
            $this->assertStringContainsString('aria-modal="true"', $html, $name);
            $this->assertStringContainsString('id="cxMore"', $html, "$name: the single View full details button");
            $this->assertStringContainsString('assets/css/quickview.css', $html, $name);
            $this->assertStringContainsString('assets/js/quickview.js', $html, $name);
            $this->assertStringNotContainsString('quickViewModal', $html, "$name: old modal gone");
            $this->assertStringNotContainsString('openQuickView', $html, "$name: old handler gone");
        }

        $this->assertStringContainsString('data-quick-view="' . $a->id . '"', $pages['home']->getContent());
        $this->assertStringContainsString('data-quick-view="' . $b->id . '"', $pages['product']->getContent());
    }

    public function test_old_quick_view_code_and_json_keys_are_gone(): void
    {
        $main = file_get_contents(public_path('assets/main.js'));
        $css = file_get_contents(public_path('assets/style.css'));
        foreach (['openQuickView', 'closeQuickView', 'qvSlide', 'quickViewModal'] as $needle) {
            $this->assertStringNotContainsString($needle, $main);
        }
        $this->assertStringNotContainsString('.qv-', $css);
        $this->assertStringNotContainsString('.quick-view-modal', $css);
        $this->assertFileDoesNotExist(public_path('assets/js/catalog-quickview.js'));

        $p = $this->product('Oak Chair');
        $j = $this->getJson("/product/{$p->id}/quick-view")->assertOk()->json();
        $this->assertArrayNotHasKey('images', $j);
        $this->assertArrayNotHasKey('image_url', $j);
        $this->assertArrayHasKey('gallery_images', $j);
    }
}
