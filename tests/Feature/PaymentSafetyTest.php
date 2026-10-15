<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * Stage 0 payment safety: atomic paid update, cancelled orders never
 * flipped to confirmed, Stripe webhook event-id idempotency.
 *
 * Runs only on the MySQL scratch database (see tests/TestCase.php),
 * inside a transaction that is rolled back after every test, so no
 * rows are left behind. The scratch database's structure is cloned from
 * furniture_db (the live schema has columns no migration creates, so a
 * migrate:fresh database can't place an order yet — Stage 1's M0
 * migration closes that gap).
 * Both gateways are faked: Stripe's HTTP client is replaced with an
 * in-memory one, PayMongo goes through Http::fake(). No real request
 * to either gateway is ever made.
 */
class PaymentSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private const STRIPE_WEBHOOK_SECRET   = 'whsec_test_fake_secret';
    private const PAYMONGO_WEBHOOK_SECRET = 'whsec_paymongo_fake_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret'              => 'sk_test_fake',
            'services.stripe.webhook'             => self::STRIPE_WEBHOOK_SECRET,
            'services.paymongo.secret_key'        => 'sk_test_fake',
            'services.paymongo.webhook_secret'    => self::PAYMONGO_WEBHOOK_SECRET,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function makeCustomer(): User
    {
        $user = new User();
        $user->forceFill([
            'first_name'     => 'Pay',
            'last_name'      => 'Tester',
            'email'          => 'pay' . bin2hex(random_bytes(4)) . '@example.test',
            'password'       => 'not-a-real-password',
            'role'           => 'customer',
            'is_validated'   => true,
            'account_status' => 'active',
        ])->save();

        return $user;
    }

    private function makeProduct(float $price = 1000.00, int $stock = 10): Product
    {
        return Product::create([
            'product_name'   => 'Test Chair',
            'price'          => $price,
            'stock_quantity' => $stock,
            'product_status' => 'available',
        ]);
    }

    /** Places a real catalog order through the checkout route. */
    private function placeCatalogOrder(User $user, string $method = 'stripe', string $type = 'full', ?int $months = null, ?int $freq = null, float $price = 1000.00): Order
    {
        $product = $this->makeProduct($price);

        DB::table('cart_items')->insert([
            'user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->post(route('order.place'), array_filter([
            'delivery_option'       => 'pickup',
            'payment_type'          => $type,
            'payment_method'        => $method,
            'installment_duration'  => $months,
            'installment_frequency' => $freq,
        ], fn ($v) => $v !== null))->assertRedirect();

        $order = Order::where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($method, $order->payment->payment_method);

        return $order;
    }

    private function stripeSession(Order $order, string $sessionId, ?int $amountCentavos = null): array
    {
        return [
            'object'         => 'checkout.session',
            'id'             => $sessionId,
            'payment_status' => 'paid',
            'amount_total'   => $amountCentavos ?? (int) round($order->payment->amount * 100),
            'payment_intent' => 'pi_' . $sessionId,
            'customer'       => null,
            'metadata'       => ['order_id' => (string) $order->id],
        ];
    }

    private function stripeWebhook(string $eventId, array $session, string $type = 'checkout.session.completed', ?string $secret = null)
    {
        $json = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => $type,
            'data' => ['object' => $session],
        ]);
        $t   = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", $secret ?? self::STRIPE_WEBHOOK_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}",
            'CONTENT_TYPE'          => 'application/json',
        ], $json);
    }

    private function fakeStripeSessions(array $sessionsById): object
    {
        $client = new class($sessionsById) implements ClientInterface {
            public array $requests = [];

            public function __construct(private array $sessions) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = $method . ' ' . $absUrl;

                foreach ($this->sessions as $id => $body) {
                    if (str_contains($absUrl, $id)) {
                        return [json_encode($body), 200, []];
                    }
                }

                return [json_encode(['error' => ['message' => 'No such session']]), 404, []];
            }
        };

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    private function fakePaymongoIntent(string $intentId, int $amountCentavos, string $status = 'succeeded'): void
    {
        Http::fake([
            "api.paymongo.com/v1/payment_intents/{$intentId}" => Http::response([
                'data' => ['attributes' => ['status' => $status, 'amount' => $amountCentavos]],
            ], 200),
        ]);
    }

    private function paymongoWebhook(string $intentId)
    {
        $json = json_encode(['data' => ['attributes' => [
            'type' => 'payment.paid',
            'data' => ['attributes' => ['payment_intent_id' => $intentId]],
        ]]]);
        $t   = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::PAYMONGO_WEBHOOK_SECRET);

        return $this->call('POST', '/webhook/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=",
            'CONTENT_TYPE'            => 'application/json',
        ], $json);
    }

    private function paidNotifications(Order $order): int
    {
        return Notification::where('user_id', $order->user_id)
            ->where('title', 'Payment Successful')
            ->count();
    }

    private function assertConfirmedAndPaid(Order $order): void
    {
        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertNotNull($order->payment->paid_at);
    }

    /* ───────────── catalog regression: normal paid flows ───────────── */

    public function test_stripe_full_payment_via_webhook_confirms_catalog_order(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');

        $this->stripeWebhook('evt_full_1', $this->stripeSession($order, 'cs_full_1'))
            ->assertOk();

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
        $this->assertSame('pi_cs_full_1', $order->payment->stripe_payment_intent_id);
        $this->assertSame('1000.00', (string) $order->payment->amount);
    }

    public function test_stripe_installment_payment_confirms_and_keeps_schedule(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'installment', 2, 15);

        $this->assertSame('500.00', (string) $order->payment->amount, 'downpayment is exactly 50%');
        $this->assertSame(4, InstallmentSchedule::where('order_id', $order->id)->count());

        $this->stripeWebhook('evt_inst_1', $this->stripeSession($order, 'cs_inst_1'))->assertOk();

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
        $this->assertSame(
            '500.00',
            number_format(InstallmentSchedule::where('order_id', $order->id)->sum('amount_due'), 2, '.', '')
        );
    }

    public function test_gcash_full_payment_via_redirect_confirms_catalog_order(): void
    {
        $user  = $this->makeCustomer();
        $order = $this->placeCatalogOrder($user, 'gcash', 'full');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_gc_full']);
        $this->fakePaymongoIntent('pi_gc_full', 100000);

        $this->actingAs($user)
            ->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
    }

    public function test_gcash_installment_payment_via_redirect_confirms_catalog_order(): void
    {
        $user  = $this->makeCustomer();
        $order = $this->placeCatalogOrder($user, 'gcash', 'installment', 1, 30);
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_gc_inst']);
        $this->fakePaymongoIntent('pi_gc_inst', 50000);

        $this->actingAs($user)
            ->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
        $this->assertSame(1, InstallmentSchedule::where('order_id', $order->id)->count());
    }

    /* ───────────── Stage 0 #3: webhook event-id idempotency ───────────── */

    public function test_duplicate_stripe_event_id_is_ignored(): void
    {
        $order   = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');
        $session = $this->stripeSession($order, 'cs_dup_1');

        $this->stripeWebhook('evt_dup_1', $session)->assertOk()->assertJson(['status' => 'received']);
        $this->assertSame(1, $this->paidNotifications($order));

        $row = StripeWebhookEvent::where('stripe_event_id', 'evt_dup_1')->firstOrFail();
        $this->assertTrue($row->processed);
        $this->assertSame('checkout.session.completed', $row->event_type);

        // Put the payment back to pending so the ONLY thing that can stop
        // a second confirmation is the event-id check, not the paid check.
        DB::table('payments')->where('order_id', $order->id)->update(['payment_status' => 'pending']);
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'pending']);

        $this->stripeWebhook('evt_dup_1', $session)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, StripeWebhookEvent::where('stripe_event_id', 'evt_dup_1')->count());
        $this->assertSame(1, $this->paidNotifications($order), 'duplicate event id must not notify again');
        $this->assertSame('pending', $order->fresh()->order_status, 'duplicate event id must not re-confirm');
    }

    public function test_unprocessed_event_is_handled_when_stripe_retries(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');

        // An earlier attempt recorded the event but died before finishing.
        StripeWebhookEvent::create([
            'stripe_event_id' => 'evt_retry_1',
            'event_type'      => 'checkout.session.completed',
            'payload'         => [],
            'processed'       => false,
        ]);

        $this->stripeWebhook('evt_retry_1', $this->stripeSession($order, 'cs_retry_1'))->assertOk();

        $this->assertConfirmedAndPaid($order);
        $this->assertTrue(StripeWebhookEvent::where('stripe_event_id', 'evt_retry_1')->value('processed') == 1);
    }

    public function test_different_event_id_for_an_already_paid_order_does_not_notify_twice(): void
    {
        $order   = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');
        $session = $this->stripeSession($order, 'cs_two_events');

        $this->stripeWebhook('evt_a', $session)->assertOk();
        $this->stripeWebhook('evt_b', $session, 'checkout.session.async_payment_succeeded')->assertOk();

        $this->assertSame(1, $this->paidNotifications($order));
        $this->assertSame(2, StripeWebhookEvent::count());
    }

    public function test_webhook_with_bad_signature_is_rejected_and_not_recorded(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');

        $this->stripeWebhook('evt_forged', $this->stripeSession($order, 'cs_forged'), 'checkout.session.completed', 'whsec_wrong')
            ->assertStatus(400);

        $this->assertSame(0, StripeWebhookEvent::count());
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame('pending', $order->fresh()->payment->payment_status);
    }

    /* ───────────── Stage 0 #1: atomic paid update ───────────── */

    public function test_redirect_plus_webhook_for_same_stripe_order_notifies_once(): void
    {
        $user    = $this->makeCustomer();
        $order   = $this->placeCatalogOrder($user, 'stripe', 'full');
        $session = $this->stripeSession($order, 'cs_both_1');
        $client  = $this->fakeStripeSessions(['cs_both_1' => $session]);

        // The customer's browser returns first…
        $this->actingAs($user)
            ->get(route('payment.stripe.success', $order->id) . '?session_id=cs_both_1')
            ->assertRedirect(route('payment.confirmed', $order->id));

        // …then Stripe's webhook for the same payment arrives.
        $this->stripeWebhook('evt_both_1', $session)->assertOk();

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
        $this->assertNotEmpty($client->requests, 'the redirect path went through the fake Stripe client');
    }

    public function test_redirect_plus_webhook_for_same_gcash_order_notifies_once(): void
    {
        $user  = $this->makeCustomer();
        $order = $this->placeCatalogOrder($user, 'gcash', 'full');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_both_gc']);
        $this->fakePaymongoIntent('pi_both_gc', 100000);

        $this->actingAs($user)
            ->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));

        $this->paymongoWebhook('pi_both_gc')->assertOk();

        $this->assertConfirmedAndPaid($order);
        $this->assertSame(1, $this->paidNotifications($order));
    }

    public function test_conditional_update_lets_only_one_of_two_simultaneous_confirmations_through(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');

        // Call the atomic step directly, twice, bypassing the early
        // "already paid" read — this is exactly what two requests that
        // both read 'pending' before either wrote would each execute.
        $method = new ReflectionMethod(PaymentController::class, 'recordPaymentArrived');
        $method->setAccessible(true);
        $controller = app(PaymentController::class);
        $fields = ['stripe_payment_intent_id' => 'pi_race', 'stripe_charge_id' => 'pi_race', 'stripe_customer_id' => null];

        $first  = $method->invoke($controller, $order->id, $fields, 'Paid!', 'race test');
        $second = $method->invoke($controller, $order->id, $fields, 'Paid!', 'race test');

        $this->assertSame('confirmed', $first);
        $this->assertSame('already_paid', $second);
        $this->assertSame(1, $this->paidNotifications($order));
    }

    /* ───────────── Stage 0 #2: cancelled orders ───────────── */

    public function test_stripe_payment_on_cancelled_order_is_recorded_but_not_confirmed(): void
    {
        Log::spy();

        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'cancelled']);

        $this->stripeWebhook('evt_cancel_1', $this->stripeSession($order, 'cs_cancel_1'))->assertOk();

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status, 'a cancelled order must never become confirmed');
        $this->assertSame('paid', $order->payment->payment_status, 'the money arrived, so it is recorded');
        $this->assertNotNull($order->payment->paid_at);
        $this->assertSame(0, $this->paidNotifications($order), 'no "order confirmed" message for a cancelled order');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'cancelled') && str_contains($message, $order->order_number))
            ->once();
    }

    public function test_gcash_payment_on_cancelled_order_is_recorded_but_not_confirmed(): void
    {
        Log::spy();

        $user  = $this->makeCustomer();
        $order = $this->placeCatalogOrder($user, 'gcash', 'full');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_cancel_gc']);
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'cancelled']);
        $this->fakePaymongoIntent('pi_cancel_gc', 100000);

        // The customer must be told about it, not shown "order confirmed".
        $this->actingAs($user)
            ->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('orders.show', $order->id))
            ->assertSessionHas('error');

        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame(0, $this->paidNotifications($order));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'cancelled'))
            ->once();
    }

    public function test_stripe_redirect_on_cancelled_order_does_not_show_confirmed_page(): void
    {
        $user    = $this->makeCustomer();
        $order   = $this->placeCatalogOrder($user, 'stripe', 'full');
        $session = $this->stripeSession($order, 'cs_cancel_redirect');
        $this->fakeStripeSessions(['cs_cancel_redirect' => $session]);
        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'cancelled']);

        $this->actingAs($user)
            ->get(route('payment.stripe.success', $order->id) . '?session_id=cs_cancel_redirect')
            ->assertRedirect(route('orders.show', $order->id))
            ->assertSessionHas('error');

        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    /* ───────────── Stage 0 #4: amount checks unchanged ───────────── */

    public function test_stripe_amount_mismatch_is_still_refused(): void
    {
        $order = $this->placeCatalogOrder($this->makeCustomer(), 'stripe', 'full');

        $this->stripeWebhook('evt_amt', $this->stripeSession($order, 'cs_amt', 100))->assertOk();

        $order->refresh();
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('pending', $order->payment->payment_status);
        $this->assertSame(0, $this->paidNotifications($order));
    }

    public function test_gcash_amount_mismatch_is_still_refused(): void
    {
        $user  = $this->makeCustomer();
        $order = $this->placeCatalogOrder($user, 'gcash', 'full');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_amt_gc']);
        $this->fakePaymongoIntent('pi_amt_gc', 100);

        $this->actingAs($user)
            ->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.gcash.paymongo', $order->id));

        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame('pending', $order->fresh()->payment->payment_status);
    }
}
