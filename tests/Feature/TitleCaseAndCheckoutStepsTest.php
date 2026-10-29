<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\TitleCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\Concerns\GivesDeliveryDetails;
use Tests\TestCase;

/**
 * Customer text pass (docs/checkout/checkout-steps-v3.html): Title Case helper, no arrows on buttons, "Back" wording and the
 * desktop checkout step rows. Admin, emails and print pages stay as they were.
 */
class TitleCaseAndCheckoutStepsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;
    use GivesDeliveryDetails;

    /* ───────────── helper ───────────── */

    public function test_small_words_stay_lowercase_unless_first_or_last(): void
    {
        $this->assertSame('Continue to Delivery', TitleCase::apply('continue to delivery'));
        $this->assertSame('Back to Cart', TitleCase::apply('Back To Cart'));
        $this->assertSame('Mark All as Read', TitleCase::apply('mark all as read'));
        $this->assertSame('Personal Information', TitleCase::apply('personal information'));
        $this->assertSame('Arm Table Chair', TitleCase::apply('arm table chair'));
        $this->assertSame('The Wood We Work With', TitleCase::apply('the wood we work with'));
        $this->assertSame('Things to Look At', TitleCase::apply('things to look at'));   // last word is capitalised
    }

    public function test_the_word_after_a_colon_starts_a_new_phrase(): void
    {
        $this->assertSame('Order LP-2026-00003: In Production', TitleCase::apply('Order LP-2026-00003: in production'));
        $this->assertSame('Order LP-2026-00003: In Production', TitleCase::apply('Order LP-2026-00003: In Production'));
        $this->assertSame('Order LP-2026-00005: Cancelled', TitleCase::apply('order LP-2026-00005: cancelled'));
    }

    public function test_brands_acronyms_units_digits_and_numbers_are_left_alone(): void
    {
        $this->assertSame('Pay with GCash via PayMongo', TitleCase::apply('pay with gcash via paymongo'));
        $this->assertSame('Upload ID or PDF', TitleCase::apply('upload id or pdf'));
        $this->assertSame('LP Furniture', TitleCase::apply('LP Furniture'));
        $this->assertSame('Study Table 120 x 60 cm', TitleCase::apply('study table 120 x 60 cm'));
        $this->assertSame('Order LP-2026-00012 Placed', TitleCase::apply('order LP-2026-00012 placed'));
        $this->assertSame('Email me@example.com Now', TitleCase::apply('email me@example.com now'));
        $this->assertSame('Visit https://example.com/a-b Today', TitleCase::apply('visit https://example.com/a-b today'));
        $this->assertSame('Close &times;', TitleCase::apply('close &times;'));
    }

    public function test_helper_is_idempotent_and_handles_empty_and_html_characters(): void
    {
        foreach (['continue to delivery', 'Pick-up at shop', 'door-to-door delivery', "don't miss out", 'a & b', 'tom & jerry\'s <b>desk</b>'] as $in) {
            $once = TitleCase::apply($in);
            $this->assertSame($once, TitleCase::apply($once), $in);
        }
        $this->assertSame('', TitleCase::apply(''));
        $this->assertSame('', TitleCase::apply(null));
        $this->assertSame('Door-to-Door Delivery', TitleCase::apply('door-to-door delivery'));
        // plain text in, plain text out: escaping is the caller's job
        $this->assertStringContainsString('<b>', TitleCase::apply('a <b>desk</b>'));
        $this->assertSame('&lt;b&gt;Hi&lt;/b&gt;', e('<b>Hi</b>'));
    }

    public function test_tagalog_and_accented_names(): void
    {
        $this->assertSame('Kahoy Na Lawaan', TitleCase::apply('kahoy na lawaan'));
        $this->assertSame('Mesa Ni Samontañez', TitleCase::apply('mesa ni samontañez'));
        $this->assertSame('Ñora Cabinet', TitleCase::apply('ñora cabinet'));
    }

    public function test_blade_directive_and_function_escape_and_title_case(): void
    {
        $this->assertSame('Arm Chair', title_case('arm chair'));
        $out = Blade::render('@titlecase($t)', ['t' => '<i>arm chair</i> sale']);
        $this->assertSame(e(TitleCase::apply('<i>arm chair</i> sale')), $out);
    }

    /* ───────────── names show in Title Case, stored values stay ───────────── */

    public function test_product_and_category_names_display_in_title_case_but_are_stored_as_typed(): void
    {
        $p = Product::create(['product_name' => 'narra arm chair', 'category' => 'dining chairs', 'material' => 'narra wood', 'price' => 3500, 'stock_quantity' => 3, 'product_status' => 'available']);

        $html = $this->get(route('product.show', $p->id))->assertOk()->getContent();
        $this->assertStringContainsString('Narra Arm Chair', $html);
        $this->assertStringContainsString('Dining Chairs', $html);

        $this->assertSame('narra arm chair', DB::table('products')->where('id', $p->id)->value('product_name'));
        $this->assertSame('dining chairs', DB::table('products')->where('id', $p->id)->value('category'));
    }

    /* ───────────── no arrows on buttons ───────────── */

    public function test_no_customer_button_or_button_link_contains_an_arrow(): void
    {
        $root = base_path('resources/views');
        $skip = ['admin', 'emails', 'mail'];
        $bad = [];
        $arrow = '/(→|←|&rarr;|&larr;|&#8594;|&#8592;|&#x2192;|&#x2190;|&raquo;|&laquo;)/u';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            $rel = substr($path, strlen($root) + 1);
            if (substr($path, -10) !== '.blade.php' || in_array(explode('/', $rel)[0], $skip, true) || str_contains($rel, 'print')) {
                continue;
            }
            $src = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
            preg_match_all('#<button\b[^>]*>.*?</button>|<a\b[^>]*class="[^"]*(?:btn|button)[^"]*"[^>]*>.*?</a>#si', $src, $m);
            foreach ($m[0] as $el) {
                if (preg_match($arrow, $el)) {
                    $bad[] = $rel . ' :: ' . trim(preg_replace('/\s+/', ' ', strip_tags($el)));
                }
            }
        }
        $this->assertSame([], $bad, "Arrows left on buttons:\n" . implode("\n", $bad));
    }

    /* ───────────── back wording ───────────── */

    public function test_page_level_back_buttons_say_just_back(): void
    {
        $link = Blade::render('<x-back-link href="/orders" label="My Orders" />');
        $this->assertStringContainsString('<span>Back</span>', $link);
        $this->assertStringContainsString('aria-label="Back to My Orders"', $link);
        $this->assertStringNotContainsString('&larr;', $link);

        $step = Blade::render('<x-back-link named type="button" label="review" />');
        $this->assertStringContainsString('<span>Back to Review</span>', $step);
        $this->assertStringNotContainsString('&larr;', $step);
    }

    public function test_orders_page_back_button_says_back(): void
    {
        $user = $this->makeUser();
        $html = $this->actingAs($user)->get(route('orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('<span>Back</span>', $html);
    }

    /* ───────────── checkout, desktop rows ───────────── */

    private function checkoutFor($user)
    {
        $product = Product::create(['product_name' => 'Chair', 'category' => 'Chairs', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);

        return $this->actingAs($user)->get(route('checkout'))->assertOk()->getContent();
    }

    public function test_each_desktop_step_has_a_back_and_continue_row_with_the_right_labels(): void
    {
        $user = $this->giveDeliveryDetails($this->makeUser());
        $html = $this->checkoutFor($user);

        preg_match_all('#<div class="ckn-actions">(.*?)</div>#s', $html, $rows);
        $this->assertCount(5, $rows[1]);

        $expected = [
            ['Back to Cart', 'Continue to Delivery'],
            ['Back to Review', 'Continue to Payment Type'],
            ['Back to Delivery Option', 'Continue to Payment Method'],
            ['Back to Payment Type', 'Continue to Special Instructions'],
            ['Back to Payment Method', 'Continue to Place Order'],
        ];
        foreach ($rows[1] as $i => $row) {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($row)));
            $this->assertSame($expected[$i][0] . ' ' . $expected[$i][1], $text);
            $this->assertLessThan(strpos($row, 'ckn-continue-btn'), strpos($row, 'ckn-back-btn'), 'Back comes first');
        }
    }

    public function test_summary_has_no_duplicate_back_to_cart_and_place_order_stays(): void
    {
        $user = $this->giveDeliveryDetails($this->makeUser());
        $html = $this->checkoutFor($user);

        $summary = substr($html, strpos($html, 'class="checkout-summary"'));
        $summary = substr($summary, 0, strpos($summary, '</form>'));
        $this->assertStringNotContainsString('Back to Cart', $summary);
        $this->assertStringNotContainsString('lp-btn--back', $summary);
        $this->assertStringContainsString('id="place-order-btn"', $summary);
        $this->assertStringContainsString('id="ckn-ready-tag"', $summary);
        $this->assertStringContainsString('role="status"', $summary);
    }

    public function test_mobile_flow_keeps_its_screens_and_back_buttons_with_words_only(): void
    {
        $user = $this->giveDeliveryDetails($this->makeUser());
        $html = $this->checkoutFor($user);

        $this->assertSame(0, substr_count($html, 'checkout-screen-backrow'), 'no Back row above the step title any more');
        $this->assertSame(6, substr_count($html, 'checkout-screen-prev"'), 'six Back buttons, one per screen, inside the footers');
        foreach (['Next: Delivery Option', 'Next: Payment Type', 'Next: Payment Method', 'Next: Instructions', 'Review Order', 'Place Order'] as $label) {
            $this->assertStringContainsString('>' . $label . '</button>', $html, $label);
        }
        foreach (['Back to Cart', 'Back to Review', 'Back to Delivery Option', 'Back to Payment Type', 'Back to Payment Method', 'Back to Special Instructions'] as $label) {
            $this->assertStringContainsString('<span>' . $label . '</span>', $html, $label);
        }
        $this->assertDoesNotMatchRegularExpression('/→|←|&rarr;|&larr;/u', substr($html, strpos($html, 'ckn-preparing'), strpos($html, 'Need Help?') - strpos($html, 'ckn-preparing')));
    }

    public function test_checkout_loads_the_guard_and_the_toast_accepts_a_title(): void
    {
        $user = $this->giveDeliveryDetails($this->makeUser());
        $html = $this->checkoutFor($user);
        $this->assertStringContainsString('assets/js/checkout-guard.js', $html);
        $this->assertStringContainsString('assets/js/title-case.js', $html);

        $js = file_get_contents(public_path('assets/js/alerts.js'));
        $this->assertStringContainsString('function show(type, message, title)', $js);
        $this->assertStringContainsString("You can't place your order yet", file_get_contents(public_path('assets/js/checkout-guard.js')));
    }

    public function test_server_still_rejects_an_incomplete_order(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => null, 'phone_e164' => null, 'phone_country' => null])->save();
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe',
        ])->assertSessionHasErrors('contact_phone');

        $this->assertSame(0, DB::table('orders')->where('user_id', $user->id)->count());
    }

    /* ───────────── admin: no customer back component (its own typography is AdminTypographyTest) ───────────── */

    public function test_admin_pages_do_not_get_the_customer_back_button(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->get('/admin/orders')->assertOk()->getContent();
        $this->assertStringNotContainsString('lp-btn--back', $html);
        $this->assertStringNotContainsString('<span>Back</span>', $html);
    }
}
