<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Product;
use App\Support\Luma\ProductFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 20 part D: product cards and price ranges come from the live catalog; nothing is invented.
 */
class LumaProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.fallback.phone' => null, 'shop.fallback.email' => null, 'shop.fallback.facebook_url' => null, 'shop.fallback.shop_address' => null]);   // Luma tests start with no contact details, as before

        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
    }

    private function product(string $name, string $category, float $price, array $over = []): Product
    {
        return Product::create(array_merge([
            'product_name' => $name, 'category' => $category, 'price' => $price, 'stock_quantity' => 3,
            'product_status' => 'available', 'material' => 'Gmelina', 'finish' => 'Natural',
        ], $over));
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    private function doors(): void
    {
        $this->product('Panel Door', 'Doors', 5000);
        $this->product('Carved Door', 'Doors', 18000);
        $this->product('Flush Door', 'Doors', 7500);
    }

    /* ───────────── price range ───────────── */

    public function test_the_price_range_is_the_lowest_and_highest_price_of_that_type(): void
    {
        $this->doors();
        $this->product('Narra Dining Table', 'Tables', 99999); // another type: never counted

        $reply = $this->ask('What is the price range of doors?');

        $this->assertStringContainsString('Doors range from PHP 5,000 to PHP 18,000.', $reply['response']);
        $this->assertCount(2, $reply['cards']);
        $this->assertSame(['Panel Door', 'Carved Door'], array_column($reply['cards'], 'name'));
        $this->assertStringNotContainsString('99,999', $reply['response']);
    }

    public function test_the_range_uses_the_sale_price_while_a_sale_is_on(): void
    {
        $this->product('Panel Door', 'Doors', 5000, ['sale_price' => 4000]);
        $this->product('Carved Door', 'Doors', 18000, ['sale_price' => 15000]);
        $this->product('Flush Door', 'Doors', 7500, ['sale_price' => 9000]); // not a real discount: ignored

        $reply = $this->ask('price range of doors');

        $this->assertStringContainsString('Doors range from PHP 4,000 to PHP 15,000.', $reply['response']);
        $this->assertSame('PHP 4,000', $reply['cards'][0]['price_label']);
        $this->assertSame('PHP 5,000', $reply['cards'][0]['old_price_label']);
        $this->assertSame('PHP 15,000', $reply['cards'][1]['price_label']);

        $flush = ProductFinder::cards(Product::where('product_name', 'Flush Door')->get())[0];
        $this->assertSame('PHP 7,500', $flush['price_label']);
        $this->assertNull($flush['old_price_label']);
    }

    public function test_one_product_is_said_to_be_one_product(): void
    {
        $this->product('Panel Door', 'Doors', 5000);

        $reply = $this->ask('what is the price range of doors?');

        $this->assertStringContainsString('just one door', $reply['response']);
        $this->assertStringContainsString('PHP 5,000', $reply['response']);
        $this->assertStringNotContainsString('range from', $reply['response']);
        $this->assertCount(1, $reply['cards']);
    }

    public function test_no_products_of_that_type_falls_back_politely_with_contact_and_customize(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-111-2222', 'email' => 'shop@lp.test']);
        $this->product('Narra Dining Table', 'Tables', 12000);

        $reply = $this->ask('what is the price range of doors?');

        $this->assertStringContainsString("don't have any doors", $reply['response']);
        $this->assertStringContainsString('Customize page', $reply['response']);
        $this->assertStringContainsString('0917 111 2222', $reply['response']);
        $this->assertSame([], $reply['cards']);
        $this->assertSame(route('design.index', [], false), $reply['buttons'][0]['url']);
        $this->assertSame(['phone' => '0917 111 2222', 'email' => 'shop@lp.test'], $reply['contact']);
        $this->assertDoesNotMatchRegularExpression('/PHP\s?\d/', $reply['response']);
    }

    public function test_unavailable_products_are_never_shown_or_counted(): void
    {
        $this->doors();
        $this->product('Hidden Door', 'Doors', 100, ['product_status' => 'unavailable']);

        $reply = $this->ask('price range of doors');

        $this->assertStringContainsString('PHP 5,000 to PHP 18,000', $reply['response']);
        $this->assertNotContains('Hidden Door', array_column($reply['cards'], 'name'));
    }

    public function test_the_range_with_no_type_covers_the_whole_catalog(): void
    {
        $this->doors();
        $this->product('Stool', 'Stools', 900);

        $reply = $this->ask('what is your price range');

        $this->assertStringContainsString('from PHP 900 to PHP 18,000', $reply['response']);
        $this->assertSame(['Stool', 'Carved Door'], array_column($reply['cards'], 'name'));
    }

    /* ───────────── cards ───────────── */

    public function test_a_price_question_shows_at_most_four_cards_and_a_button_to_the_filtered_catalog(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->product("Door {$i}", 'Doors', 1000 * $i);
        }
        $this->product('Round Table', 'Tables', 4000);

        $reply = $this->ask('how much are your doors?');

        $this->assertCount(4, $reply['cards']);
        foreach ($reply['cards'] as $card) {
            $this->assertStringStartsWith('Door ', $card['name']);
            $this->assertMatchesRegularExpression('#^/product/\d+$#', $card['url']);
            $this->assertMatchesRegularExpression('/^PHP [\d,]+$/', $card['price_label']);
        }
        $this->assertSame([['label' => 'View more products', 'url' => '/catalog?type=door']], $reply['buttons']);
    }

    public function test_the_view_more_button_opens_the_catalog_filtered_to_that_type(): void
    {
        $this->doors();
        $this->product('Narra Dining Table', 'Tables', 12000);

        $url = $this->ask('price of doors')['buttons'][0]['url'];

        $page = $this->get($url)->assertOk();
        $page->assertSee('Panel Door')->assertSee('Carved Door')->assertSee('Showing doors');
        $page->assertDontSee('Narra Dining Table');

        // an unknown or hostile type is ignored, not used
        $this->get('/catalog?type=' . urlencode('<script>alert(1)</script>'))->assertOk()->assertSee('Narra Dining Table')->assertDontSee('<script>alert(1)</script>', false);
        $this->get('/catalog?type=bed')->assertOk()->assertDontSee('Panel Door')->assertDontSee('Narra Dining Table');
    }

    public function test_a_type_is_matched_by_category_or_name_but_a_door_count_is_not_a_door(): void
    {
        $this->product('4-Door Wardrobe Cabinet', 'Cabinet', 22000);
        $this->product('Oak Entrance Door', 'Interior', 9000);
        $this->product('TV Console Cabinet', 'Cabinet', 13500);

        $doors = ProductFinder::available('door');
        $this->assertSame(['Oak Entrance Door'], $doors->pluck('product_name')->all());

        $this->assertSame(['4-Door Wardrobe Cabinet'], ProductFinder::available('wardrobe')->pluck('product_name')->all());
        $this->assertEqualsCanonicalizing(['4-Door Wardrobe Cabinet', 'TV Console Cabinet'], ProductFinder::available('cabinet')->pluck('product_name')->all());
        $this->assertSame(['TV Console Cabinet'], ProductFinder::available('tv stand')->pluck('product_name')->all());
    }

    public function test_do_you_have_questions_show_cards_for_that_type_and_say_it_is_made_to_order(): void
    {
        $this->doors();

        $reply = $this->ask('do you have ready made doors?');

        $this->assertCount(3, $reply['cards']);
        $this->assertStringContainsString('made to order', $reply['response']);
        $this->assertSame('/catalog?type=door', $reply['buttons'][0]['url']);

        $none = $this->ask('do you have stools?');
        $this->assertSame([], $none['cards']);
        $this->assertStringContainsString("don't have any stools", $none['response']);
    }

    public function test_a_type_on_its_own_shows_that_type(): void
    {
        $this->doors();

        $reply = $this->ask('doors');

        $this->assertCount(3, $reply['cards']);
        $this->assertSame('/catalog?type=door', $reply['buttons'][0]['url']);
    }

    public function test_a_general_price_question_shows_featured_and_newest_products(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->product("Piece {$i}", 'Tables', 1000 * $i, ['is_featured' => $i === 2]);
        }

        $reply = $this->ask('how much do your products cost');

        $this->assertCount(4, $reply['cards']);
        $this->assertSame('Piece 2', $reply['cards'][0]['name']); // featured first
        $this->assertSame('/catalog', $reply['buttons'][0]['url']);
        $this->assertStringContainsString('written quote', $reply['response']);
    }

    public function test_a_budget_gives_cards_within_it(): void
    {
        $this->product('Cheap Stool', 'Stools', 900);
        $this->product('Fine Door', 'Doors', 8000);
        $this->product('Grand Door', 'Doors', 18000);

        $reply = $this->ask('I have 10000 pesos for a door');

        $this->assertSame(['Fine Door'], array_column($reply['cards'], 'name'));
        $this->assertSame('/catalog?type=door&max_price=10000', $reply['buttons'][0]['url']);

        $any = $this->ask('my budget is 9,000');
        $this->assertEqualsCanonicalizing(['Fine Door', 'Cheap Stool'], array_column($any['cards'], 'name'));

        // a small bare number is not a budget
        $this->assertSame([], $this->ask('give me 2 xyzzy')['cards']);
    }

    /* ───────────── Tagalog ───────────── */

    public function test_tagalog_phrasing_works_for_cards_and_ranges(): void
    {
        $this->doors();
        $this->product('Narra Mesa', 'Tables', 12000);

        $price = $this->ask('magkano po ang pinto?');
        $this->assertSame('tl', $price['lang']);
        $this->assertCount(3, $price['cards']);
        $this->assertStringContainsString('mga pinto', $price['response']);
        $this->assertStringNotContainsString('Walang anuman', $price['response']);   // no sign-off line
        $this->assertSame('Tingnan ang iba pang produkto', $price['buttons'][0]['label']);
        $this->assertSame('/catalog?type=door', $price['buttons'][0]['url']);

        $range = $this->ask('ano po ang saklaw ng presyo ng pinto');
        $this->assertStringContainsString('mula PHP 5,000 hanggang PHP 18,000', $range['response']);
        $this->assertCount(2, $range['cards']);

        $have = $this->ask('meron ba kayong mesa po');
        $this->assertSame(['Narra Mesa'], array_column($have['cards'], 'name'));

        $none = $this->ask('meron ba kayong aparador');
        $this->assertSame([], $none['cards']);
        $this->assertStringContainsString('Customize page', $none['response']);
    }

    /* ───────────── safety ───────────── */

    public function test_product_names_are_sent_as_plain_text_and_escaped_by_the_widget(): void
    {
        $this->product('<img src=x onerror=alert(1)> Door', 'Doors', 5000);

        $reply = $this->ask('price of doors');

        $this->assertSame('<img src=x onerror=alert(1)> Door', $reply['cards'][0]['name']);

        $widget = file_get_contents(public_path('assets/main.js'));
        $this->assertStringContainsString('chatEsc(c.name)', $widget);
        $this->assertStringContainsString('chatEsc(chatSafeUrl(c.url))', $widget);
        $this->assertStringContainsString('chatEsc(b.label)', $widget);
        $this->assertStringNotContainsString('${c.name}', $widget);
    }

    public function test_a_javascript_url_is_not_clickable_in_the_widget(): void
    {
        $widget = file_get_contents(public_path('assets/main.js'));

        // only same-site paths and http(s) links are allowed; anything else becomes "#"
        $this->assertStringContainsString("return /^https?:\\/\\//i.test(url) ? url : '#';", $widget);
        $this->assertStringContainsString("url.charAt(0) === '/' && url.charAt(1) !== '/'", $widget);
    }
}
