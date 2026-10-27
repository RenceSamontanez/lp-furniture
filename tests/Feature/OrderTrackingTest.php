<?php

namespace Tests\Feature;

use App\Mail\OrderStageMail;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderStages;
use App\Support\RecordPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Order tracking for catalog orders: stage rules on the server, payment gating and auto-confirm (Stripe and PayMongo,
 * webhook first or return first), corrections, cancel, progress updates, photos, notifications and email, and who may see what.
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

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
        Storage::fake('public');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    /** A catalog order of 1000, paid in full by cash (so it can move), at $status. */
    private function order(string $status = 'confirmed', string $option = 'delivery', bool $paid = true, ?User $customer = null): Order
    {
        $customer ??= $this->makeUser();
        $order = $this->makeOrder($customer, ['total_amount' => 1000, 'delivery_option' => $option, 'order_status' => $status]);
        $payment = $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'onsite']);
        \App\Support\OrderTracking::recordPlaced($order);

        if ($paid) {
            PaymentRecord::create([
                'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 1000, 'method' => 'cash', 'paid_on' => now()->toDateString(),
            ]);
            $order->forceFill(['amount_paid' => 1000])->save();
        }

        return $order->fresh();
    }

    private function advance(User $admin, Order $order, array $over = [])
    {
        return $this->actingAs($admin, 'admin')->post(route('admin.orders.stage.advance', $order->id), array_merge([
            'current_status' => $order->order_status, 'confirmed' => '1', 'message' => '',
        ], $over));
    }

    private function png(string $name = 'p.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 200, 200);
    }

    private function placeStripeOrder(User $user, string $type = 'full'): Order
    {
        $product = Product::create(['product_name' => 'Test Chair', 'price' => 1000, 'stock_quantity' => 10, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->post(route('order.place'), array_filter([
            'delivery_option' => 'pickup', 'payment_type' => $type, 'payment_method' => 'stripe',
            'installment_duration' => $type === 'installment' ? 2 : null, 'installment_frequency' => $type === 'installment' ? 15 : null,
        ], fn ($v) => $v !== null))->assertRedirect();

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function stripeSession(Order $order, string $id, string $intent): array
    {
        return [
            'object' => 'checkout.session', 'id' => $id, 'payment_status' => 'paid',
            'amount_total' => (int) round($order->payment->amount * 100), 'payment_intent' => $intent, 'customer' => null,
            'metadata' => ['order_id' => (string) $order->id],
        ];
    }

    private function stripeEvent(string $type, string $eventId, array $object)
    {
        $json = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::STRIPE_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json'], $json);
    }

    private function fakeStripe(array $byFragment): void
    {
        ApiRequestor::setHttpClient(new class($byFragment) implements ClientInterface {
            public function __construct(private array $bodies) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                foreach ($this->bodies as $fragment => $body) {
                    if (str_contains($absUrl, $fragment)) {
                        return [json_encode($body), 200, []];
                    }
                }

                return [json_encode(['error' => ['message' => 'No such object']]), 404, []];
            }
        });
    }

    private function paymongoWebhook(string $intentId)
    {
        $json = json_encode(['data' => ['attributes' => ['type' => 'payment.paid', 'data' => ['attributes' => ['payment_intent_id' => $intentId, 'amount' => 100000]]]]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::PAYMONGO_SECRET);

        return $this->call('POST', '/webhook/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=", 'CONTENT_TYPE' => 'application/json'], $json);
    }

    /* ───────────── stages: delivery and pickup ───────────── */

    public function test_delivery_order_walks_every_stage_one_step_at_a_time(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed', 'delivery');

        foreach (['in_production', 'ready', 'out_for_delivery', 'completed'] as $expected) {
            $this->advance($admin, $order, ['message' => 'Step ' . $expected])->assertRedirect(route('admin.orders.show', $order->id))->assertSessionHas('success');
            $order->refresh();
            $this->assertSame($expected, $order->order_status);
            $this->assertSame($admin->id, (int) $order->status_updated_by);
        }

        $stages = OrderStatusEvent::where('order_id', $order->id)->where('type', 'stage')->orderBy('id')->get();
        $this->assertSame(['in_production', 'ready', 'out_for_delivery', 'completed'], $stages->pluck('to_status')->all());
        $this->assertSame(['confirmed', 'in_production', 'ready', 'out_for_delivery'], $stages->pluck('from_status')->all());
        $this->assertTrue($stages->every(fn ($e) => $e->customer_notified && $e->admin_user_id === $admin->id));
        $this->assertSame(4, Notification::where('user_id', $order->user_id)->where('type', 'order_update')->count());
        Mail::assertSent(OrderStageMail::class, 4);
    }

    public function test_pickup_order_skips_out_for_delivery_and_labels_ready_for_pickup(): void
    {
        $admin = $this->admin();
        $order = $this->order('in_production', 'pickup');

        $this->assertSame(['pending', 'confirmed', 'in_production', 'ready', 'completed'], OrderStages::for($order));

        $this->advance($admin, $order)->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('ready', $order->order_status);
        $this->assertSame('Ready for pickup', OrderStages::labelFor($order));
        $this->assertStringContainsString('pick it up at our shop in', OrderStages::customerCopy($order, 'ready'));

        $this->advance($admin, $order)->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->order_status);   // no out_for_delivery in between
        Mail::assertSent(OrderStageMail::class, fn ($m) => $m->hasSubject('Your LP Furniture order ' . $order->order_number . ' is now Ready for pickup'));
    }

    public function test_customer_wording_for_every_stage_is_exact(): void
    {
        $d = $this->order('pending', 'delivery', false);
        $p = $this->order('pending', 'pickup', false);

        $this->assertSame('We are waiting for your payment. Your order starts as soon as it is received.', OrderStages::customerCopy($d, 'pending'));
        $this->assertSame('We received your payment and confirmed your order. Our craftsmen will start building soon.', OrderStages::customerCopy($d, 'confirmed'));
        $this->assertSame('Our craftsmen have started building your furniture piece.', OrderStages::customerCopy($d, 'in_production'));
        $this->assertSame('Your furniture is finished and checked. We are arranging its delivery.', OrderStages::customerCopy($d, 'ready'));
        $this->assertSame('Your furniture is on its way to your address.', OrderStages::customerCopy($d, 'out_for_delivery'));
        $this->assertSame('Order complete. Thank you! We hope you enjoy your new furniture.', OrderStages::customerCopy($d, 'completed'));
        $this->assertSame('Order complete. Thank you for collecting your furniture!', OrderStages::customerCopy($p, 'completed'));
        $this->assertStringStartsWith('Your furniture is finished and ready. You can pick it up at our shop in ', OrderStages::customerCopy($p, 'ready'));
    }

    public function test_forbidden_moves_are_rejected_and_nothing_changes(): void
    {
        $admin = $this->admin();
        $order = $this->order('in_production', 'delivery');

        // stale view of the order (somebody else already moved it, or a double click)
        $this->advance($admin, $order, ['current_status' => 'confirmed'])->assertSessionHas('error');
        // no confirmation tick
        $this->advance($admin, $order, ['confirmed' => '0'])->assertSessionHas('error');
        // the old free dropdown route is closed for catalog orders, in any direction
        foreach (['completed', 'pending', 'cancelled', 'ready'] as $s) {
            $this->actingAs($admin, 'admin')->post(route('admin.orders.updateStatus', $order->id), ['order_status' => $s])->assertSessionHasErrors('status');
        }

        $this->assertSame('in_production', $order->fresh()->order_status);
        $this->assertSame(0, OrderStatusEvent::where('order_id', $order->id)->where('type', 'stage')->count());
        Mail::assertNothingSent();
    }

    public function test_completed_and_cancelled_orders_have_no_next_step(): void
    {
        $admin = $this->admin();
        foreach (['completed', 'cancelled'] as $s) {
            $order = $this->order($s);
            $this->advance($admin, $order)->assertSessionHas('error');
            $this->assertSame($s, $order->fresh()->order_status);
        }
    }

    public function test_double_submit_moves_the_order_exactly_one_step(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $stale = $order->order_status;

        $this->advance($admin, $order, ['current_status' => $stale])->assertSessionHas('success');
        $this->advance($admin, $order, ['current_status' => $stale])->assertSessionHas('error');   // the second click

        $this->assertSame('in_production', $order->fresh()->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'stage')->count());
        Mail::assertSent(OrderStageMail::class, 1);
    }

    /* ───────────── payment gating and auto-confirm ───────────── */

    public function test_unpaid_pending_order_cannot_move_and_the_page_says_waiting(): void
    {
        $admin = $this->admin();
        $order = $this->order('pending', 'delivery', false);

        $this->advance($admin, $order)->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->order_status);

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))
            ->assertOk()->assertSee('Waiting for payment')->assertSee('Awaiting payment');
    }

    public function test_recording_a_payment_confirms_automatically_with_history_notification_and_email(): void
    {
        $admin = $this->admin();
        $order = $this->order('pending', 'delivery', false);

        app(RecordPayment::class)->record($order->id, $admin, '1000', 'cash', now('Asia/Manila')->toDateString());

        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('title', 'Order confirmed automatically')->count());
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'payment')->count());
        $this->assertSame(1, Notification::where('user_id', $order->user_id)->where('type', 'order_update')->count());
        Mail::assertSent(OrderStageMail::class, fn ($m) => $m->hasSubject('Your LP Furniture order ' . $order->order_number . ' is now Confirmed'));

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()->assertSee('Paid in full')->assertSee('Payment received');
    }

    public function test_down_payment_confirms_but_completing_needs_the_full_total(): void
    {
        $admin = $this->admin();
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer, ['total_amount' => 1000, 'delivery_option' => 'pickup']);
        $this->makePayment($order, ['amount' => 500, 'payment_method' => 'onsite', 'payment_type' => 'installment']);
        \App\Support\OrderTracking::recordPlaced($order);

        app(RecordPayment::class)->record($order->id, $admin, '300', 'cash', now('Asia/Manila')->toDateString());
        $this->assertSame('pending', $order->fresh()->order_status, 'below the down payment: still pending');

        app(RecordPayment::class)->record($order->id, $admin, '200', 'cash', now('Asia/Manila')->toDateString());
        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertSee('Partly paid');

        foreach (['in_production', 'ready'] as $expected) {
            $this->advance($admin, $order)->assertSessionHas('success');
            $order->refresh();
        }
        $this->assertSame('ready', $order->order_status);
        $this->advance($admin, $order)->assertSessionHas('error');
        $this->assertSame('ready', $order->fresh()->order_status);

        app(RecordPayment::class)->record($order->id, $admin, '500', 'cash', now('Asia/Manila')->toDateString());
        $this->advance($admin, $order->fresh())->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    public function test_stripe_return_first_then_webhook_records_once_and_confirms_once(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $session = $this->stripeSession($order, 'cs_t1', 'pi_t1');
        $this->fakeStripe(['cs_t1' => $session]);

        $this->actingAs($user)->get(route('payment.stripe.success', $order->id) . '?session_id=cs_t1')->assertRedirect(route('payment.confirmed', $order->id));
        $this->stripeEvent('checkout.session.completed', 'evt_t1', $session)->assertOk();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('title', 'Order confirmed automatically')->count());
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'payment')->count());
        Mail::assertSent(OrderStageMail::class, 1);
    }

    public function test_stripe_webhook_first_then_return_records_once_and_confirms_once(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $session = $this->stripeSession($order, 'cs_t2', 'pi_t2');
        $this->fakeStripe(['cs_t2' => $session]);

        $this->stripeEvent('checkout.session.completed', 'evt_t2', $session)->assertOk();
        $this->actingAs($user)->get(route('payment.stripe.success', $order->id) . '?session_id=cs_t2')->assertRedirect(route('payment.confirmed', $order->id));
        $this->stripeEvent('checkout.session.completed', 'evt_t2b', $session)->assertOk();   // Stripe sends it again under a new event id

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('1000.00', (string) $order->fresh()->amount_paid);
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('title', 'Order confirmed automatically')->count());
        Mail::assertSent(OrderStageMail::class, 1);
    }

    public function test_payment_intent_succeeded_alone_is_enough_to_record_the_payment(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $session = $this->stripeSession($order, 'cs_t3', 'pi_t3');
        // the webhook endpoint is only subscribed to payment_intent.succeeded: the session is looked up from the intent
        $this->fakeStripe(['checkout/sessions' => ['object' => 'list', 'data' => [$session], 'has_more' => false, 'url' => '/v1/checkout/sessions']]);

        $this->stripeEvent('payment_intent.succeeded', 'evt_t3', ['id' => 'pi_t3', 'object' => 'payment_intent'])->assertOk();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_a_payment_marked_paid_without_a_ledger_row_is_repaired_by_the_next_verified_event(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        // the state the test site showed: payment says paid, ledger empty, order still pending
        Payment::where('order_id', $order->id)->update(['payment_status' => 'paid', 'stripe_payment_intent_id' => 'pi_t4']);
        $session = $this->stripeSession($order, 'cs_t4', 'pi_t4');

        $this->stripeEvent('checkout.session.completed', 'evt_t4', $session)->assertOk();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('1000.00', (string) $order->fresh()->amount_paid);
        $this->assertSame('confirmed', $order->fresh()->order_status);

        $this->stripeEvent('checkout.session.completed', 'evt_t4b', $session)->assertOk();
        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count(), 'a repair runs once');
    }

    public function test_a_paid_session_that_cannot_be_applied_alerts_the_admins_instead_of_a_silent_200(): void
    {
        $this->admin();
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $session = $this->stripeSession($order, 'cs_t5', 'pi_t5');
        $session['amount_total'] = 12345;   // does not match what the order asked for

        $this->stripeEvent('checkout.session.completed', 'evt_t5', $session)->assertOk();

        $this->assertSame(0, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame(1, Notification::where('title', 'Unmatched Payment')->count());
    }

    public function test_paymongo_webhook_first_then_return_records_once_and_confirms_once(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $order->payment->update(['payment_method' => 'gcash', 'paymongo_payment_intent_id' => 'pi_pm1']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_pm1' => Http::response(['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 100000]]], 200)]);

        $this->paymongoWebhook('pi_pm1')->assertOk();
        $this->actingAs($user)->get(route('payment.gcash.paymongo.success', $order->id))->assertRedirect(route('payment.confirmed', $order->id));
        $this->paymongoWebhook('pi_pm1')->assertOk();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('gcash_paymongo', PaymentRecord::where('order_id', $order->id)->value('method'));
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('title', 'Order confirmed automatically')->count());
        Mail::assertSent(OrderStageMail::class, 1);
    }

    public function test_paymongo_return_first_then_webhook_records_once(): void
    {
        $user = $this->makeUser();
        $order = $this->placeStripeOrder($user);
        $order->payment->update(['payment_method' => 'gcash', 'paymongo_payment_intent_id' => 'pi_pm2']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_pm2' => Http::response(['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 100000]]], 200)]);

        $this->actingAs($user)->get(route('payment.gcash.paymongo.success', $order->id))->assertRedirect(route('payment.confirmed', $order->id));
        $this->paymongoWebhook('pi_pm2')->assertOk();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    /* ───────────── corrections ───────────── */

    public function test_correction_goes_back_exactly_one_stage_with_a_reason_and_the_customer_never_hears_of_it(): void
    {
        $admin = $this->admin();
        $order = $this->order('ready', 'delivery');
        $url = route('admin.orders.stage.correct', $order->id);

        $post = fn (array $o = []) => $this->actingAs($admin, 'admin')->post($url, array_merge(['current_status' => $order->fresh()->order_status, 'confirmed' => '1', 'reason' => 'Marked ready by mistake, still sanding'], $o));

        $post(['reason' => 'short'])->assertSessionHas('error');
        $post(['reason' => str_repeat('x', 301)])->assertSessionHas('error');
        $post(['confirmed' => '0'])->assertSessionHas('error');
        $this->assertSame('ready', $order->fresh()->order_status);

        $post()->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('in_production', $order->order_status);

        $event = OrderStatusEvent::where('order_id', $order->id)->where('type', 'correction')->sole();
        $this->assertFalse($event->customer_notified);
        $this->assertSame('Marked ready by mistake, still sanding', $event->note);
        $this->assertSame(0, Notification::where('user_id', $order->user_id)->count());
        Mail::assertNothingSent();

        // customer's page never lists it
        $this->actingAs($order->user)->get(route('orders.show', $order->id))->assertOk()
            ->assertDontSee('Marked ready by mistake')->assertDontSee('Corrected');
        // admin sees it, tagged internal
        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertSee('Marked ready by mistake')->assertSee('Internal only');
    }

    public function test_correction_is_not_allowed_from_pending_or_confirmed(): void
    {
        $admin = $this->admin();
        foreach (['pending', 'confirmed'] as $s) {
            $order = $this->order($s, 'delivery', $s === 'confirmed');
            $this->actingAs($admin, 'admin')->post(route('admin.orders.stage.correct', $order->id), [
                'current_status' => $s, 'confirmed' => '1', 'reason' => 'A perfectly good reason',
            ])->assertSessionHas('error');
            $this->assertSame($s, $order->fresh()->order_status);
        }
    }

    public function test_pickup_correction_from_completed_goes_to_ready_not_out_for_delivery(): void
    {
        $admin = $this->admin();
        $order = $this->order('completed', 'pickup');
        $this->actingAs($admin, 'admin')->post(route('admin.orders.stage.correct', $order->id), [
            'current_status' => 'completed', 'confirmed' => '1', 'reason' => 'Customer had not collected yet',
        ])->assertSessionHas('success');
        $this->assertSame('ready', $order->fresh()->order_status);
    }

    /* ───────────── cancel ───────────── */

    public function test_cancel_works_only_from_pending_or_confirmed_needs_a_reason_and_tells_the_customer(): void
    {
        $admin = $this->admin();
        $bad = $this->order('in_production');
        $this->actingAs($admin, 'admin')->post(route('admin.orders.stage.cancel', $bad->id), ['current_status' => 'in_production', 'confirmed' => '1', 'reason' => 'Customer changed their mind'])->assertSessionHas('error');
        $this->assertSame('in_production', $bad->fresh()->order_status);

        $order = $this->order('confirmed');
        $url = route('admin.orders.stage.cancel', $order->id);
        $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'confirmed', 'confirmed' => '1', 'reason' => ''])->assertSessionHas('error');
        $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'confirmed', 'confirmed' => '0', 'reason' => 'Customer changed their mind'])->assertSessionHas('error');
        $this->assertSame('confirmed', $order->fresh()->order_status);

        $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'confirmed', 'confirmed' => '1', 'reason' => 'Customer changed their mind'])->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('cancelled', $order->order_status);
        $this->assertSame('Customer changed their mind', OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->sole()->note);
        $this->assertSame(1, Notification::where('user_id', $order->user_id)->where('type', 'order_update')->count());
        Mail::assertSent(OrderStageMail::class, fn ($m) => $m->hasSubject('Your LP Furniture order ' . $order->order_number . ' is now Cancelled'));
    }

    // Same as before: cancelling does not restock or touch the payment. Only orders:release-abandoned does that.
    public function test_cancel_does_not_touch_stock_or_payment_as_before(): void
    {
        $admin = $this->admin();
        $order = $this->order('pending', 'delivery', false);
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 4, 'product_status' => 'available']);
        \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Chair', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);

        $this->actingAs($admin, 'admin')->post(route('admin.orders.stage.cancel', $order->id), ['current_status' => 'pending', 'confirmed' => '1', 'reason' => 'Customer asked us to stop'])->assertSessionHas('success');

        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
        $this->assertSame('pending', $order->payment->fresh()->payment_status);
    }

    public function test_the_abandoned_order_sweep_writes_a_history_line(): void
    {
        $user = $this->makeUser();
        $order = $this->makeOrder($user, ['total_amount' => 1000]);
        $this->makePayment($order, ['payment_method' => 'stripe', 'amount' => 1000]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subDay()]);

        $this->artisan('orders:release-abandoned', ['--minutes' => 60])->assertSuccessful();

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->count());
    }

    /* ───────────── send update only ───────────── */

    public function test_send_update_only_keeps_the_stage_notifies_and_needs_something_to_send(): void
    {
        $admin = $this->admin();
        $order = $this->order('in_production');
        $url = route('admin.orders.stage.update', $order->id);

        $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'in_production', 'message' => ''])->assertSessionHas('error');

        $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'in_production', 'message' => 'Sanding is done.', 'photo' => $this->png()])->assertSessionHas('success');
        $order->refresh();
        $this->assertSame('in_production', $order->order_status);

        $event = OrderStatusEvent::where('order_id', $order->id)->where('type', 'update')->sole();
        $this->assertTrue($event->customer_notified);
        $this->assertSame('Sanding is done.', $event->note);
        $this->assertNotNull($event->photo_path);
        Storage::disk('public')->assertExists($event->photo_path);
        $this->assertSame(1, Notification::where('user_id', $order->user_id)->count());
        Mail::assertSent(OrderStageMail::class, 1);

        // the customer sees the message and the photo
        $this->actingAs($order->user)->get(route('orders.show', $order->id))->assertOk()->assertSee('Sanding is done.')->assertSee($event->photo_path);
    }

    public function test_message_longer_than_300_characters_is_refused(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $this->advance($admin, $order, ['message' => str_repeat('a', 301)])->assertSessionHas('error');
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_message_with_markup_is_stored_clean_and_shown_escaped(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $this->advance($admin, $order, ['message' => '<script>alert(1)</script>Frame is done'])->assertSessionHas('success');

        $note = OrderStatusEvent::where('order_id', $order->id)->where('type', 'stage')->sole()->note;
        $this->assertStringNotContainsString('<', $note);
        $this->actingAs($order->user)->get(route('orders.show', $order->id))->assertDontSee('<script>alert', false);
    }

    /* ───────────── photos ───────────── */

    public function test_photo_rules_png_and_jpeg_pass_webp_gif_and_oversize_are_refused(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $url = route('admin.orders.stage.update', $order->id);
        $post = fn ($file) => $this->actingAs($admin, 'admin')->post($url, ['current_status' => 'confirmed', 'message' => 'Photo', 'photo' => $file]);

        $post(UploadedFile::fake()->createWithContent('a.webp', "RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x00" . str_repeat("\x00", 40)))->assertSessionHasErrors('photo');
        $post(UploadedFile::fake()->createWithContent('a.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')))->assertSessionHasErrors('photo');
        $post(UploadedFile::fake()->createWithContent('a.php.jpg', '<?php echo 1;'))->assertSessionHasErrors('photo');
        $post(UploadedFile::fake()->create('big.png', 6000, 'image/png'))->assertSessionHasErrors('photo');
        $this->assertSame(0, OrderStatusEvent::where('order_id', $order->id)->where('type', 'update')->count());
        $this->assertSame([], Storage::disk('public')->allFiles('order-update-photos'));

        $this->assertSame(5120, \App\Support\UploadedImage::MAX_KB);
        $post(UploadedFile::fake()->image('ok.jpg', 300, 300))->assertSessionHas('success');
        $post($this->png('ok.png'))->assertSessionHas('success');
        $this->assertCount(2, Storage::disk('public')->allFiles('order-update-photos'));
    }

    public function test_a_refused_move_does_not_leave_the_uploaded_photo_behind(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $this->advance($admin, $order, ['current_status' => 'pending', 'photo' => $this->png()])->assertSessionHas('error');
        $this->assertSame([], Storage::disk('public')->allFiles('order-update-photos'));
    }

    /* ───────────── email failure ───────────── */

    public function test_a_failing_email_never_undoes_the_status_change(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $this->advance($admin, $order)->assertSessionHas('success');

        $this->assertSame('in_production', $order->fresh()->order_status);
        $this->assertSame(1, Notification::where('user_id', $order->user_id)->where('type', 'order_update')->count());
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'stage')->count());
    }

    /* ───────────── who can see and do what ───────────── */

    public static function adminRoutes(): array
    {
        $cases = [];
        foreach (['guest', 'customer_web', 'customer_on_admin_guard'] as $who) {
            foreach (['advance', 'update', 'correct', 'cancel'] as $route) {
                $cases["$who → $route"] = [$who, $route];
            }
        }

        return $cases;
    }

    /** @dataProvider adminRoutes */
    public function test_non_admins_cannot_use_any_tracking_route(string $who, string $route): void
    {
        $order = $this->order('ready');
        $visitor = $this->makeUser();
        match ($who) {
            'customer_web'            => $this->actingAs($visitor),
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            default                   => null,
        };
        Auth::shouldUse('web');

        $this->post(route("admin.orders.stage.$route", $order->id), [
            'current_status' => 'ready', 'confirmed' => '1', 'reason' => 'A perfectly good reason', 'message' => 'hello',
        ])->assertRedirect(route('admin.login'));

        $this->assertSame('ready', $order->fresh()->order_status);
        $this->assertSame(0, OrderStatusEvent::where('order_id', $order->id)->where('type', '!=', 'placed')->count());
    }

    public function test_customers_cannot_open_another_customers_tracking_page_or_the_admin_page(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $order = $this->order('in_production', 'delivery', true, $alice);
        app(\App\Support\OrderTracking::class)->sendUpdate($order->id, $this->admin(), 'in_production', 'ALICE-ONLY-NOTE');

        $this->actingAs($bob)->get(route('orders.show', $order->id))->assertNotFound();
        $this->actingAs($bob)->get(route('admin.orders.show', $order->id))->assertRedirect(route('admin.login'));
        $this->actingAs($alice)->get(route('orders.show', $order->id))->assertOk()->assertSee('ALICE-ONLY-NOTE')->assertSee('In production');
    }

    public function test_customer_page_shows_stage_text_payment_badge_and_the_admin_page_shows_tags(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed', 'delivery');
        $this->advance($admin, $order, ['message' => 'Frame assembled'])->assertSessionHas('success');
        $this->flushSession();   // the admin's "Customer notified" toast belongs to the admin's session

        $this->actingAs($order->user)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee('Our craftsmen have started building your furniture piece.')->assertSee('Paid in full')->assertSee('Frame assembled')
            ->assertDontSee('Customer notified')->assertDontSee('Internal only');

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()
            ->assertSee('Customer notified')->assertSee('Update progress')->assertSee('Mark as ready');
    }

    public function test_customized_orders_are_not_moved_by_the_new_routes(): void
    {
        $admin = $this->admin();
        $order = $this->order('confirmed');
        $this->assertTrue(\App\Support\OrderTracking::handles($order));

        // an order that came from an agreement keeps CustomOrderStatus and its own page
        DB::table('orders')->where('id', $order->id)->update(['design_agreement_id' => null]);
        $order->design_agreement_id = 999;
        $this->assertFalse(\App\Support\OrderTracking::handles($order));
    }

    /* ───────────── migration ───────────── */

    public function test_the_migration_backfills_one_placed_line_per_order_and_is_safe_to_run_twice(): void
    {
        $a = $this->order('completed');
        $b = $this->order('pending', 'pickup', false);
        DB::table('order_status_events')->delete();

        $migration = require base_path('database/migrations/2026_10_31_090000_create_order_status_events_table.php');
        $migration->up();
        $migration->up();

        foreach ([$a, $b] as $o) {
            $rows = OrderStatusEvent::where('order_id', $o->id)->get();
            $this->assertCount(1, $rows);
            $this->assertSame('placed', $rows->first()->type);
            $this->assertTrue($rows->first()->customer_notified);
        }
    }
}
