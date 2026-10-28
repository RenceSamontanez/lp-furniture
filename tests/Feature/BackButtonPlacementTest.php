<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\Concerns\GivesDeliveryDetails;
use Tests\TestCase;

/** Back buttons: one per page on the payment pages, stacked above the primary button in the phone checkout footer, beside Continue on desktop. */
class BackButtonPlacementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;
    use GivesDeliveryDetails;

    private function payPage(string $method)
    {
        $c = $this->makeUser();
        $order = $this->makeOrder($c, ['total_amount' => 1000]);
        $this->makePayment($order, ['amount' => 1000, 'payment_method' => $method]);

        return $this->actingAs($c)->get($method === 'stripe' ? route('payment.stripe', $order->id) : route('payment.gcash.paymongo', $order->id))->assertOk()->getContent();
    }

    public function test_stripe_page_has_exactly_one_back_at_the_top(): void
    {
        $html = $this->payPage('stripe');
        $this->assertSame(1, substr_count($html, 'lp-btn--back'));
        $this->assertLessThan(strpos($html, 'payment-header'), strpos($html, 'lp-btn--back'), 'the Back is above the heading');
    }

    public function test_gcash_page_has_one_page_back_and_the_redirect_screen_keeps_its_own(): void
    {
        $html = $this->payPage('gcash');
        $overlayAt = strpos($html, 'id="gcash-redirect-overlay"');
        $page = substr($html, 0, $overlayAt);
        $overlay = substr($html, $overlayAt);

        $this->assertSame(1, substr_count($page, 'lp-btn--back'), 'one Back on the page itself, at the top');
        $this->assertLessThan(strpos($page, 'payment-header'), strpos($page, 'lp-btn--back'));
        $this->assertSame(1, substr_count($overlay, 'lp-btn--back'), 'the redirect screen has its own Back under Continue to GCash');
        $this->assertLessThan(strpos($overlay, 'lp-btn--back'), strpos($overlay, 'id="gcash-continue-btn"'));
    }

    public function test_redirect_screen_back_is_sized_like_continue(): void
    {
        $css = file_get_contents(public_path('assets/css/payment.css'));
        $this->assertMatchesRegularExpression('/\.gcash-redirect-overlay \.gcash-redirect-actions \.btn-submit-gcash,\s*\.gcash-redirect-overlay \.gcash-redirect-actions \.lp-btn--back\s*\{[^}]*width: 100%;[^}]*min-height: 56px/', $css);
        $this->assertStringContainsString('.gcash-redirect-overlay .gcash-redirect-actions { align-items: stretch; gap: 12px; }', $css);
    }

    public function test_phone_footer_has_back_above_the_primary_button_and_no_top_row(): void
    {
        $user = $this->giveDeliveryDetails($this->makeUser());
        $product = Product::create(['product_name' => 'Chair', 'category' => 'Chairs', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $html = $this->actingAs($user)->get(route('checkout'))->assertOk()->getContent();

        $this->assertStringNotContainsString('checkout-screen-backrow', $html);
        preg_match_all('#<div class="checkout-screen-footer">(.*?)</div>#s', $html, $m);
        $this->assertCount(6, $m[1]);
        $labels = ['Back to Cart', 'Back to Review', 'Back to Delivery Option', 'Back to Payment Type', 'Back to Payment Method', 'Back to Special Instructions'];
        foreach ($m[1] as $i => $footer) {
            $this->assertStringContainsString('checkout-screen-prev', $footer);
            $this->assertStringContainsString('<span>' . $labels[$i] . '</span>', $footer);
            $this->assertLessThan(strpos($footer, 'checkout-screen-continue'), strpos($footer, 'checkout-screen-prev'), 'Back is above the primary button');
        }
        // each screen's header now follows the screen opening tag directly (nothing above the title)
        $this->assertSame(6, preg_match_all('#<div class="checkout-screen[^"]*" id="screen-[a-z]+"[^>]*>\s*<div class="checkout-screen-header">#', $html));
    }

    public function test_phone_footer_css_stacks_the_buttons_and_respects_the_safe_area(): void
    {
        $css = file_get_contents(public_path('assets/css/checkout.css'));
        $this->assertStringContainsString('.checkout-screen .checkout-screen-footer {', $css);
        $this->assertStringContainsString('flex-direction: column;', $css);
        $this->assertStringContainsString('env(safe-area-inset-bottom, 0px)', $css);
        $this->assertStringContainsString('.checkout-screen.ck-kb .checkout-screen-footer .checkout-screen-prev { display: none; }', $css);
    }

    public function test_desktop_row_resets_the_auto_margin_that_pushed_continue_right(): void
    {
        $css = file_get_contents(public_path('assets/css/checkout.css'));
        $this->assertMatchesRegularExpression('/\.checkout-screen \.ckn-actions \.ckn-back-btn,\s*\.checkout-screen \.ckn-actions \.ckn-continue-btn\s*\{[^}]*margin: 0;/', $css);
        // the shared rule that caused it is still what other pages use
        $this->assertStringContainsString('.lp-type .lp-btn--back { display: flex; width: fit-content; margin: 0 auto 24px 0; }', file_get_contents(public_path('assets/css/customer-type.css')));
    }
}
