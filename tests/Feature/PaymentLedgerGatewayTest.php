<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Stage 8 part A: the Stripe / PayMongo confirmation paths write the
 * payment ledger. Both gateways are faked; no real request is made.
 */
class PaymentLedgerGatewayTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\GivesDeliveryDetails;

    private const STRIPE_SECRET   = 'whsec_test_fake_secret';
    private const PAYMONGO_SECRET = 'whsec_paymongo_fake_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret'           => 'sk_test_fake',
            'services.stripe.webhook'          => self::STRIPE_SECRET,
            'services.paymongo.secret_key'     => 'sk_test_fake',
            'services.paymongo.webhook_secret' => self::PAYMONGO_SECRET,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /* ───────────── fixtures (same shape as PaymentSafetyTest) ───────────── */

    private function customer(): User
    {
        $u = new User();
        $u->forceFill([
            'first_name' => 'Pay', 'last_name' => 'Tester',
            'email' => 'pay' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'not-a-real-password', 'role' => 'customer',
            'is_validated' => true, 'account_status' => 'active',
        ])->save();

        return $this->giveDeliveryDetails($u);
    }

    private function admin(): User
    {
        $u = $this->customer();
        $u->forceFill(['role' => 'admin'])->save();

        return $u;
    }

    private function placeOrder(User $user, string $method = 'stripe', string $type = 'full', ?int $months = null, ?int $freq = null): Order
    {
        $product = Product::create([
            'product_name' => 'Test Chair', 'price' => 1000, 'stock_quantity' => 10, 'product_status' => 'available',
        ]);
        DB::table('cart_items')->insert([
            'user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->post(route('order.place'), array_filter([
            'delivery_option' => 'pickup', 'payment_type' => $type, 'payment_method' => $method,
            'installment_duration' => $months, 'installment_frequency' => $freq,
        ], fn ($v) => $v !== null))->assertRedirect();

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function stripeSession(Order $order, string $id, string $intent): array
    {
        return [
            'object' => 'checkout.session', 'id' => $id, 'payment_status' => 'paid',
            'amount_total' => (int) round($order->payment->amount * 100),
            'payment_intent' => $intent, 'customer' => null,
            'metadata' => ['order_id' => (string) $order->id],
        ];
    }

    private function webhook(string $eventId, array $session)
    {
        $json = json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::STRIPE_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    private function fakeStripe(array $sessionsById): void
    {
        ApiRequestor::setHttpClient(new class($sessionsById) implements ClientInterface {
            public function __construct(private array $sessions) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                foreach ($this->sessions as $id => $body) {
                    if (str_contains($absUrl, $id)) {
                        return [json_encode($body), 200, []];
                    }
                }

                return [json_encode(['error' => ['message' => 'No such session']]), 404, []];
            }
        });
    }

    private function paymongoWebhook(string $intentId)
    {
        $json = json_encode(['data' => ['attributes' => ['type' => 'payment.paid',
            'data' => ['attributes' => ['payment_intent_id' => $intentId]]]]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::PAYMONGO_SECRET);

        return $this->call('POST', '/webhook/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=", 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /* ───────────── ledger rows ───────────── */

    public function test_stripe_redirect_then_webhook_writes_one_ledger_row(): void
    {
        $user = $this->customer();
        $order = $this->placeOrder($user);
        $session = $this->stripeSession($order, 'cs_led_1', 'pi_led_1');
        $this->fakeStripe(['cs_led_1' => $session]);

        $this->actingAs($user)->get(route('payment.stripe.success', $order->id) . '?session_id=cs_led_1')
            ->assertRedirect(route('payment.confirmed', $order->id));
        $this->webhook('evt_led_1', $session)->assertOk();

        $rows = PaymentRecord::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $r = $rows->first();
        $this->assertSame('stripe', $r->method);
        $this->assertSame('pi_led_1', $r->gateway_reference);
        $this->assertSame('1000.00', (string) $r->amount);
        $this->assertNull($r->recorded_by);
        $this->assertSame(now()->toDateString(), $r->paid_on->toDateString());
        $this->assertSame($order->payment->id, $r->payment_id);

        $order->refresh();
        $this->assertSame('1000.00', (string) $order->amount_paid);
        $this->assertSame('confirmed', $order->order_status);
        $this->assertFalse((bool) $order->needs_review);
        $this->assertSame(1, Notification::where('user_id', $user->id)->whereIn('title', ['Payment Successful', 'Payment received and your order is confirmed.'])->count());
    }

    public function test_installment_downpayment_amount_is_the_ledger_amount(): void
    {
        $order = $this->placeOrder($this->customer(), 'stripe', 'installment', 2, 15);

        $this->webhook('evt_led_2', $this->stripeSession($order, 'cs_led_2', 'pi_led_2'))->assertOk();

        $this->assertSame('500.00', (string) PaymentRecord::where('order_id', $order->id)->sole()->amount);
        $this->assertSame('500.00', (string) $order->fresh()->amount_paid);
    }

    public function test_gcash_redirect_then_webhook_writes_one_ledger_row(): void
    {
        $user = $this->customer();
        $order = $this->placeOrder($user, 'gcash');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_led_gc']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_led_gc' => Http::response(
            ['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 100000]]], 200)]);

        $this->actingAs($user)->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));
        $this->paymongoWebhook('pi_led_gc')->assertOk();

        $r = PaymentRecord::where('order_id', $order->id)->sole();
        $this->assertSame('gcash_paymongo', $r->method);
        $this->assertSame('pi_led_gc', $r->gateway_reference);
        $this->assertSame('1000.00', (string) $order->fresh()->amount_paid);
    }

    public function test_cancelled_order_gets_a_ledger_row_stays_cancelled_and_is_flagged(): void
    {
        $order = $this->placeOrder($this->customer());
        $this->admin();
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'cancelled']);

        $this->webhook('evt_led_c', $this->stripeSession($order, 'cs_led_c', 'pi_led_c'))->assertOk();

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('1000.00', (string) $order->amount_paid);
        $this->assertTrue((bool) $order->needs_review);
        $this->assertStringContainsString('cancelled order', $order->review_reason);
    }

    public function test_pending_only_becomes_confirmed_and_advanced_orders_keep_status(): void
    {
        $order = $this->placeOrder($this->customer());
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'in_production']);

        $this->webhook('evt_led_p', $this->stripeSession($order, 'cs_led_p', 'pi_led_p'))->assertOk();

        $order->refresh();
        $this->assertSame('in_production', $order->order_status);
        $this->assertSame('1000.00', (string) $order->amount_paid);
        $this->assertFalse((bool) $order->needs_review);
    }

    /* ───────────── duplicate captures ───────────── */

    public function test_second_different_capture_is_recorded_and_flagged_without_touching_status(): void
    {
        $user = $this->customer();
        $admin = $this->admin();
        $order = $this->placeOrder($user);

        $this->webhook('evt_d_1', $this->stripeSession($order, 'cs_d_1', 'pi_d_1'))->assertOk();
        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        Notification::query()->delete();

        // a second Checkout Session for the same order was also paid
        $this->webhook('evt_d_2', $this->stripeSession($order, 'cs_d_2', 'pi_d_2'))->assertOk();

        $order->refresh();
        $this->assertSame(2, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('2000.00', (string) $order->amount_paid);
        $this->assertSame('confirmed', $order->order_status);
        $this->assertTrue((bool) $order->needs_review);
        $this->assertStringContainsString('duplicate payment', $order->review_reason);
        $this->assertStringContainsString('check for refund', $order->review_reason);

        $this->assertSame(0, Notification::where('user_id', $user->id)->count(), 'the customer is not messaged');
        $n = Notification::where('user_id', $admin->id)->sole();
        $this->assertSame(route('admin.orders.show', $order->id), $n->url);

        // the same duplicate delivered again (different event id) is a no-op
        $this->webhook('evt_d_3', $this->stripeSession($order, 'cs_d_2', 'pi_d_2'))->assertOk();
        $this->assertSame(2, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->count());
    }

    public function test_unique_gateway_reference_is_a_noop_not_an_error(): void
    {
        $order = $this->placeOrder($this->customer());
        PaymentRecord::create([
            'order_id' => $order->id, 'payment_id' => $order->payment->id, 'amount' => 1000,
            'method' => 'stripe', 'gateway_reference' => 'pi_pre', 'paid_on' => now()->toDateString(),
        ]);

        // the capture was already in the ledger (e.g. backfilled): confirming must still succeed
        $this->webhook('evt_u_1', $this->stripeSession($order, 'cs_u_1', 'pi_pre'))->assertOk();

        $order->refresh();
        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('1000.00', (string) $order->amount_paid);
    }
}

