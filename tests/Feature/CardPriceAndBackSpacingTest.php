<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Product cards keep the price on its own line above View Details; the shared Back button leaves space below it. */
class CardPriceAndBackSpacingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_card_shows_the_price_only_in_the_hanging_tag(): void
    {
        Product::create(['product_name' => 'Big Dresser', 'category' => 'Dressers', 'price' => 124500, 'stock_quantity' => 3, 'product_status' => 'available']);

        $html = $this->get('/catalog')->assertOk()->getContent();
        $this->assertStringNotContainsString('cx-cost', $html);
        $this->assertStringNotContainsString('cx-now', $html);
        $this->assertSame(1, substr_count($html, '₱124,500.00</span>'), 'the price appears once, inside the tag');
        $this->assertMatchesRegularExpression('#<p class="lp-pcard__tag" role="group" aria-label="Price ₱124,500\.00">\s*<span class="lp-pcard__price" aria-hidden="true">₱124,500\.00</span>\s*</p>#', $html);
        // the button comes after the text, and no price line sits between the description and the button
        $this->assertMatchesRegularExpression('#<p class="lp-pcard__desc">.*?</p>\s*<a [^>]*class="lp-btn lp-btn--secondary lp-pcard__btn">View Details</a>#s', $html);
    }

    public function test_a_sale_product_shows_the_sale_price_with_the_old_price_struck_in_the_tag_and_a_percent_badge(): void
    {
        Product::create(['product_name' => 'Sale Table', 'category' => 'Tables', 'price' => 18500, 'sale_price' => 14800, 'stock_quantity' => 3, 'product_status' => 'available']);

        $html = $this->get('/catalog')->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="Price ₱14,800.00, was ₱18,500.00"', $html);
        $this->assertStringContainsString('<s class="lp-pcard__was" aria-hidden="true">₱18,500.00</s>', $html);
        $this->assertStringContainsString('lp-pcard__badge lp-pcard__badge--sale">-20%</span>', $html);
    }

    public function test_every_light_card_uses_the_one_shared_component_and_the_old_variants_are_gone(): void
    {
        foreach (['home', 'catalog', 'product/show'] as $view) {
            $blade = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('<x-product-card', $blade, $view);
            $this->assertStringNotContainsString('product-card-v2', $blade, $view);
            $this->assertStringNotContainsString('btn-view-details', $blade, $view);
            $this->assertStringNotContainsString('cx-cost', $blade, $view);
        }
        $css = file_get_contents(public_path('assets/style.css')) . file_get_contents(public_path('assets/css/catalog.css'));
        foreach (['product-card-v2', 'carousel-card-info', 'cx-cost', '.cx-card', '.cx-tag', '.product-footer'] as $gone) {
            $this->assertStringNotContainsString($gone, $css, $gone);
        }
    }

    public function test_card_css_pins_the_button_to_the_bottom_and_reserves_two_lines(): void
    {
        $css = file_get_contents(public_path('assets/css/product-card.css'));
        $this->assertStringContainsString('.lp-pcard__btn.lp-btn { margin-top: auto;', $css);
        $this->assertStringContainsString('min-height: 2.4em', $css);
        $this->assertStringContainsString('@container (max-width: 235px)', $css);
    }

    public function test_shared_back_button_has_space_below_and_sits_at_the_left_edge(): void
    {
        $css = file_get_contents(public_path('assets/css/customer-type.css'));
        $this->assertStringContainsString('.lp-type .lp-btn--back { display: flex; width: fit-content; margin: 0 auto 24px 0; }', $css);
        $this->assertStringContainsString('.lp-type [class*="card"] .lp-btn--back', $css);
        $this->assertStringContainsString('margin-bottom: 20px', $css);
    }
}
