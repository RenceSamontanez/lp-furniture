<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Product cards keep the price on its own line above View Details; the shared Back button leaves space below it. */
class CardPriceAndBackSpacingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_card_has_the_price_in_its_own_line_before_the_button(): void
    {
        Product::create(['product_name' => 'Big Dresser', 'category' => 'Dressers', 'price' => 124500, 'stock_quantity' => 3, 'product_status' => 'available']);

        $html = $this->get('/catalog')->assertOk()->getContent();
        $this->assertStringNotContainsString('class="cx-tag"', $html);
        $this->assertMatchesRegularExpression('#<p class="cx-cost"><span class="cx-now">₱124,500\.00</span></p>\s*<a [^>]*class="lp-btn lp-btn--secondary cx-vd">View Details</a>#', $html);
    }

    public function test_home_card_footers_stack_the_price_above_the_button_at_every_width(): void
    {
        $css = file_get_contents(public_path('assets/style.css'));
        $this->assertMatchesRegularExpression('/\.product-footer \{ display: flex !important; flex-direction: column !important;/', $css);
    }

    public function test_shared_back_button_has_space_below_and_sits_at_the_left_edge(): void
    {
        $css = file_get_contents(public_path('assets/css/customer-type.css'));
        $this->assertStringContainsString('.lp-type .lp-btn--back { display: flex; width: fit-content; margin: 0 auto 24px 0; }', $css);
        $this->assertStringContainsString('.lp-type [class*="card"] .lp-btn--back', $css);
        $this->assertStringContainsString('margin-bottom: 20px', $css);
    }
}
