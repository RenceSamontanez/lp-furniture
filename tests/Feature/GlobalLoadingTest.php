<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 15 part C: the loading behaviour comes from both layouts, once, on
 * every page, including the payment pages and checkout that never had it.
 */
class GlobalLoadingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const SCRIPT = 'assets/js/co-loading.js';

    private function assertLoadedOnce(string $html, string $what): void
    {
        $this->assertSame(1, substr_count($html, self::SCRIPT), "script included exactly once on {$what}");
        $this->assertSame(1, substr_count($html, 'id="lp-pay-overlay"'), "overlay markup once on {$what}");
        $this->assertSame(1, substr_count($html, 'id="lp-progress"'), "progress bar markup once on {$what}");
    }

    public function test_the_payment_pages_and_checkout_have_it_now(): void
    {
        $c = $this->makeUser();
        $order = $this->makeOrder($c, ['total_amount' => 1000]);
        $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'stripe']);

        $this->assertLoadedOnce($this->actingAs($c)->get(route('payment.stripe', $order->id))->assertOk()->getContent(), 'the Stripe page');

        $order->payment->update(['payment_method' => 'gcash']);
        $gcash = $this->actingAs($c)->get(route('payment.gcash.paymongo', $order->id))->assertOk();
        $this->assertLoadedOnce($gcash->getContent(), 'the GCash page');
        $gcash->assertSee("Processing your payment. Please don't close or refresh this page.", false);

        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $c->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->assertLoadedOnce($this->actingAs($c)->get(route('checkout'))->assertOk()->getContent(), 'checkout');
    }

    public function test_catalog_profile_orders_and_the_admin_pages_have_it_once(): void
    {
        $c = $this->makeUser();
        $admin = $this->makeUser('admin');

        foreach (['catalog' => route('catalog'), 'orders' => route('orders.index'), 'profile' => route('profile'), 'home' => route('home')] as $name => $url) {
            $this->assertLoadedOnce($this->actingAs($c)->get($url)->assertOk()->getContent(), $name);
        }
        foreach (['dashboard' => route('admin.dashboard'), 'orders' => route('admin.orders.index'), 'products' => route('admin.products.index')] as $name => $url) {
            $this->assertLoadedOnce($this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent(), 'admin ' . $name);
        }
        \Illuminate\Support\Facades\Auth::guard('web')->logout();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->assertLoadedOnce($this->get(route('admin.login'))->assertOk()->getContent(), 'the admin login');
        $this->assertLoadedOnce($this->get(route('login'))->assertOk()->getContent(), 'the customer login');
    }

    public function test_no_view_includes_the_partial_a_second_time(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (!$file->isFile() || str_ends_with($file->getPathname(), 'partials' . DIRECTORY_SEPARATOR . 'co-loading.blade.php')) {
                continue;
            }
            $in = str_contains($file->getPathname(), 'layouts' . DIRECTORY_SEPARATOR);
            if (str_contains(file_get_contents($file->getPathname()), "partials.co-loading") && !$in) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'only the two layouts include the loading partial');
    }

    public function test_payment_and_checkout_links_ask_for_loading_feedback(): void
    {
        $c = $this->makeUser();
        $order = $this->makeOrder($c, ['total_amount' => 1000]);
        $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'stripe']);

        $this->actingAs($c)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee('data-loading="Opening payment..." data-loading-overlay="payment"', false);

        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $c->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->actingAs($c)->get(route('cart.index'))->assertOk()->assertSee('data-loading="Opening checkout..."', false);
    }

    public function test_the_overlay_asks_people_not_to_close_the_page(): void
    {
        $this->get(route('catalog'))->assertOk()
            ->assertSee("Processing your payment. Please don't close or refresh this page.", false)
            ->assertSee('role="alertdialog"', false);

        $css = file_get_contents(public_path('assets/css/co-loading.css'));
        foreach (['.lp-overlay', '.lp-progress', 'button.co-loading:disabled', '--co-bg', 'prefers-reduced-motion'] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function test_the_existing_double_submit_guards_are_untouched(): void
    {
        $checkout = file_get_contents(resource_path('views/checkout.blade.php'));
        $this->assertStringContainsString("document.getElementById('checkout-form').addEventListener('submit'", $checkout);

        $js = file_get_contents(public_path('assets/js/co-loading.js'));
        $this->assertStringContainsString("form.getAttribute('data-submitting') === '1'", $js);
        $this->assertStringContainsString('data-co-hidden', $js);   // a named button keeps its value
        $this->assertStringContainsString('e.defaultPrevented', $js);
    }

    public function test_the_script_behaves_correctly_in_a_fake_dom(): void
    {
        exec('node --version 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('node is not available');
        }

        $output = [];
        exec('node ' . escapeshellarg(base_path('tests/js/loading.test.cjs')) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('co-loading.js ok', implode("\n", $output));
    }
}
