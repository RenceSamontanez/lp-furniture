<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\StripeCheckoutParams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 14 part A: everything we send to Stripe is checked against
 * Stripe's real limits. The fake client captures the exact parameters.
 */
class StripeParamsTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));   // frozen: expiry maths compares against now
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function customOrder(array $quote = [], ?Carbon $dueAt = null): Order
    {
        $admin = User::where('role', 'admin')->first() ?? $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $q = $this->quoteAndSend($admin, $r->fresh(), $quote);
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertRedirect();
        $this->asCustomer($customer)->post(route('custom.checkout.place', $this->agreementOf($r)->id), ['payment_method' => 'stripe'])->assertRedirect();

        if ($dueAt) {
            CustomDesignRequest::find($r->id)->forceFill(['payment_due_at' => $dueAt])->save();
        }

        return Order::where('user_id', $customer->id)->latest('id')->firstOrFail();
    }

    private function catalogOrder(string $type = 'full'): Order
    {
        $user = $this->flowCustomer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->asCustomer($user)->post(route('order.place'), array_filter([
            'delivery_option' => 'pickup', 'payment_type' => $type, 'payment_method' => 'stripe',
            'installment_duration' => $type === 'installment' ? 2 : null, 'installment_frequency' => $type === 'installment' ? 15 : null,
        ], fn ($v) => $v !== null))->assertRedirect();

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /** Every constraint Stripe enforces on what we send. */
    private function assertStripeAccepts(array $p, Order $order): void
    {
        $now = now()->timestamp;

        // amount: one line item, integer centavos inside Stripe's PHP range
        $this->assertCount(1, $p['line_items']);
        $amount = $p['line_items'][0]['price_data']['unit_amount'];
        $this->assertIsInt($amount);
        $this->assertGreaterThanOrEqual(StripeCheckoutParams::MIN_AMOUNT_CENTAVOS, $amount);
        $this->assertLessThanOrEqual(StripeCheckoutParams::MAX_AMOUNT_CENTAVOS, $amount);
        $this->assertSame((int) round((float) $order->payment->amount * 100), $amount);
        $this->assertSame('php', $p['line_items'][0]['price_data']['currency']);
        $this->assertSame(1, $p['line_items'][0]['quantity']);
        $this->assertSame('payment', $p['mode']);

        // name length
        $this->assertLessThanOrEqual(250, mb_strlen($p['line_items'][0]['price_data']['product_data']['name']));
        $this->assertNotSame('', $p['line_items'][0]['price_data']['product_data']['name']);

        // metadata: at most 50 keys, key <= 40, value <= 500, string values
        $this->assertLessThanOrEqual(50, count($p['metadata']));
        foreach ($p['metadata'] as $k => $v) {
            $this->assertLessThanOrEqual(40, strlen((string) $k));
            $this->assertIsString($v);
            $this->assertLessThanOrEqual(500, strlen($v));
        }
        $this->assertSame((string) $order->id, $p['metadata']['order_id']);

        // absolute URLs, with Stripe's session id template on the success URL
        foreach (['success_url', 'cancel_url'] as $key) {
            $this->assertMatchesRegularExpression('#^https?://[^/\s]+/#', $p[$key], $key . ' must be absolute');
        }
        $this->assertStringContainsString('{CHECKOUT_SESSION_ID}', $p['success_url']);

        // expires_at: absent, or from 31 minutes up to (and including) 23 hours; always under 24 hours
        if (array_key_exists('expires_at', $p)) {
            $this->assertIsInt($p['expires_at']);
            $this->assertGreaterThanOrEqual($now + 31 * 60, $p['expires_at']);
            $this->assertLessThan($now + 24 * 3600, $p['expires_at']);
            $this->assertLessThanOrEqual($now + 23 * 3600, $p['expires_at']);
        }
    }

    private function create(Order $order)
    {
        $this->asCustomer($order->user)->post(route('payment.stripe.checkout', $order->id))->assertRedirect();

        return end($this->stripe->posts);
    }

    public function test_custom_deposit_with_a_far_due_date(): void
    {
        $order = $this->customOrder();                       // deposit, due ~5 days away
        $p = $this->create($order);

        $this->assertStripeAccepts($p, $order);
        $this->assertSame(now()->addHours(23)->timestamp, $p['expires_at']);
        $this->assertSame(1025000, $p['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_custom_full_payment(): void
    {
        $order = $this->customOrder([
            'payment_terms' => 'full', 'deposit_amount' => '', 'installment_duration_months' => '', 'installment_frequency_days' => '',
        ]);
        $p = $this->create($order);

        $this->assertStripeAccepts($p, $order);
        $this->assertSame(2050000, $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertStringNotContainsString('Downpayment', $p['line_items'][0]['price_data']['product_data']['name']);
    }

    public function test_due_date_months_away_is_capped_below_24_hours(): void
    {
        $order = $this->customOrder([], now()->addMonths(4));
        $p = $this->create($order);

        $this->assertStripeAccepts($p, $order);
        $this->assertSame(now()->addHours(23)->timestamp, $p['expires_at']);
    }

    public function test_due_date_40_minutes_away_uses_the_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));   // frozen: the due date is compared against now inside the call
        $order = $this->customOrder([], now()->addMinutes(40));
        $p = $this->create($order);

        $this->assertStripeAccepts($p, $order);
        $this->assertSame(now()->addMinutes(40)->timestamp, $p['expires_at']);
    }

    public function test_due_date_between_23_hours_and_a_day_is_capped(): void
    {
        $order = $this->customOrder([], now()->addHours(23)->addMinutes(30));
        $p = $this->create($order);

        $this->assertStripeAccepts($p, $order);
        $this->assertSame(now()->addHours(23)->timestamp, $p['expires_at']);
    }

    public function test_due_date_under_31_minutes_sends_no_expires_at(): void
    {
        foreach ([30, 20, 5] as $minutes) {
            $order = $this->customOrder([], now()->addMinutes($minutes));
            $p = $this->create($order);

            $this->assertStripeAccepts($p, $order);
            $this->assertArrayNotHasKey('expires_at', $p, "due in $minutes minutes");
        }
    }

    public function test_due_date_exactly_31_minutes_away_is_sent_at_the_minimum(): void
    {
        $now = Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila');

        $this->assertSame($now->copy()->addMinutes(31)->timestamp, StripeCheckoutParams::expiresAt($now->copy()->addMinutes(31), $now));
        $this->assertNull(StripeCheckoutParams::expiresAt($now->copy()->addMinutes(31)->subSecond(), $now));
        $this->assertNull(StripeCheckoutParams::expiresAt(null, $now));
    }

    public function test_expiry_is_always_inside_the_window_for_any_due_date(): void
    {
        $now = Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila');
        foreach ([31, 32, 45, 60, 600, 1380, 1381, 1439, 1440, 1441, 10000, 200000] as $minutes) {
            $exp = StripeCheckoutParams::expiresAt($now->copy()->addMinutes($minutes), $now);

            $this->assertNotNull($exp, "$minutes");
            $this->assertGreaterThanOrEqual($now->copy()->addMinutes(31)->timestamp, $exp);
            $this->assertLessThanOrEqual($now->copy()->addHours(23)->timestamp, $exp);
            $this->assertLessThan($now->copy()->addHours(24)->timestamp, $exp);
        }
    }

    public function test_catalog_full_and_installment_shapes_are_valid_and_unchanged(): void
    {
        foreach (['full', 'installment'] as $type) {
            $order = $this->catalogOrder($type);
            $p = $this->create($order);

            $this->assertStripeAccepts($p, $order);
            $this->assertArrayNotHasKey('expires_at', $p, 'catalog sessions never set an expiry');
            $this->assertSame(['card'], $p['payment_method_types']);
            $this->assertSame(
                'LP Furniture Order ' . $order->order_number . ($type === 'installment' ? ' (Downpayment)' : ''),
                $p['line_items'][0]['price_data']['product_data']['name']
            );
        }
    }

    public function test_amounts_outside_stripes_range_are_refused_before_calling_stripe(): void
    {
        $order = $this->catalogOrder();

        foreach ([StripeCheckoutParams::MIN_AMOUNT_CENTAVOS - 1, 100000000] as $bad) {
            $order->payment->forceFill(['amount' => $bad / 100])->save();
            $this->asCustomer($order->user)->post(route('payment.stripe.checkout', $order->id))
                ->assertRedirect(route('payment.stripe', $order->id))->assertSessionHasErrors('payment');
        }
        $this->assertSame([], $this->stripe->posts, 'Stripe was never called');

        $this->assertNull(StripeCheckoutParams::amountProblem(StripeCheckoutParams::MIN_AMOUNT_CENTAVOS));
        $this->assertNull(StripeCheckoutParams::amountProblem(99999999));
    }

    public function test_long_order_names_are_cut_to_250_characters(): void
    {
        $order = $this->catalogOrder();
        $order->order_number = str_repeat('X', 400);   // in memory only

        $p = StripeCheckoutParams::build($order, $order->user_id);

        $this->assertSame(250, mb_strlen($p['line_items'][0]['price_data']['product_data']['name']));
    }

    public function test_expired_stripe_session_is_replaced_not_reused(): void
    {
        $order = $this->customOrder();
        $this->create($order);
        $this->stripe->sessions['cs_e2e_1']['status'] = 'expired';
        $this->stripe->sessions['cs_e2e_1']['url'] = null;

        $p = $this->create($order->fresh());

        $this->assertCount(2, $this->stripe->posts);
        $this->assertStripeAccepts($p, $order);
    }
}
