<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\HeroRails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** The home hero's two product rails: what they show, what they hide, and where the cards link. */
class HomeHeroTest extends TestCase
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

    public function test_newest_and_best_sellers_appear_and_cards_link_to_product_pages(): void
    {
        $made = [];
        foreach (['A', 'B', 'C', 'D'] as $n) {
            $made[$n] = $this->product("Chair {$n}");
            $this->sell($made[$n], 1);
        }
        $this->sell($made['B'], 3);

        $rails = HeroRails::get();

        $this->assertCount(4, $rails['best']);
        $this->assertSame('Chair B', $rails['best']->first()['name'], 'most ordered first');
        $this->assertCount(4, $rails['fresh']);
        $this->assertSame('New', $rails['fresh']->first()['tag']);
        $this->assertSame('Best seller', $rails['best']->first()['tag']);

        foreach ($rails['best']->merge($rails['fresh']) as $card) {
            $this->get($card['url'])->assertOk();
        }

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Best sellers', $html);
        $this->assertStringContainsString('Hot · New', $html);
        $this->assertStringContainsString(route('product.show', $made['B']->id), $html);
        // each list is repeated once for the loop, the repeat is hidden from assistive tech
        $this->assertSame(8, substr_count($html, 'class="is-clone" aria-hidden="true"'));
    }

    public function test_hidden_products_never_appear(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $p = $this->product("Shown {$n}");
            $this->sell($p);
        }
        $hidden = $this->product('Hidden Secret Table', ['product_status' => 'unavailable']);
        $this->sell($hidden, 9);

        $rails = HeroRails::get();
        $names = $rails['best']->merge($rails['fresh'])->pluck('name');
        $this->assertNotContains('Hidden Secret Table', $names);
        $this->get('/')->assertOk()->assertDontSee('Hidden Secret Table');
    }

    public function test_a_rail_with_fewer_than_three_products_is_omitted(): void
    {
        $a = $this->product('One');
        $b = $this->product('Two');
        $this->sell($a);
        $this->sell($b);

        $rails = HeroRails::get();
        $this->assertCount(0, $rails['best'], 'two sold products: rail not shown');
        $this->assertCount(0, $rails['fresh']);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('Best sellers', $html);
        $this->assertStringNotContainsString('Hot · New', $html);
        // the AI Showcase and the three buttons are still there
        $this->assertStringContainsString('Imagine it. We build it.', $html);
        $this->assertStringContainsString('Shop Now', $html);
    }

    public function test_best_sellers_rail_is_empty_when_nothing_was_ever_ordered(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            $this->product("Fresh {$n}");
        }

        $rails = HeroRails::get();
        $this->assertCount(0, $rails['best'], 'never pad Best sellers with products that did not sell');
        $this->assertCount(3, $rails['fresh']);
    }

    public function test_home_page_with_no_products_still_renders(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Crafting Comfort', $html);
        $this->assertStringNotContainsString('lp-rails', $html);
        $this->assertStringContainsString('AI sample', $html);
    }

    public function test_hero_buttons_and_ai_tiles_go_to_customize(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('catalog') . '" class="lp-btn lp-btn--glass-primary', $html);
        // guests are sent to sign in first, exactly as the old button did
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'href="' . route('login') . '"'));

        $customer = $this->makeUser();
        $html = $this->actingAs($customer)->get('/')->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(6, substr_count($html, 'href="' . route('design.index') . '"'), 'button + 4 tiles + Design yours');
    }

    public function test_the_hamburger_is_in_the_markup_and_wired(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('id="sn-burger"', $html);
        $this->assertStringContainsString('aria-controls="sn-links"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('aria-label="Open menu"', $html);
    }
}
