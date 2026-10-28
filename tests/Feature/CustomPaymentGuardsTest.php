<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use App\Support\WorkingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 9 part B: payment-handler guards for customized orders, Stripe
 * session reuse, abandonment exclusion and unpaid-agreement expiry.
 * Both gateways are faked; no real request is made.
 */
class CustomPaymentGuardsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const STRIPE_SECRET   = 'whsec_test_fake_secret';
    private const PAYMONGO_SECRET = 'whsec_paymongo_fake_secret';

    private object $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        config([
            'services.stripe.secret'           => 'sk_test_fake',
            'services.stripe.webhook'          => self::STRIPE_SECRET,
            'services.paymongo.secret_key'     => 'sk_test_fake',
            'services.paymongo.webhook_secret' => self::PAYMONGO_SECRET,
        ]);

        $this->stripe = $this->fakeStripe();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fakes ───────────── */

    /** Fake Stripe: creates sessions with predictable ids, retrieves stored ones. */
    private function fakeStripe(): object
    {
        $client = new class implements ClientInterface {
            public array $posts = [];      // params of every create
            public array $gets = [];
            public array $sessions = [];   // id => body
            public int $counter = 0;

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                if (strtoupper($method) === 'POST' && str_ends_with($absUrl, '/v1/checkout/sessions')) {
                    $this->counter++;
                    $id = 'cs_new_' . $this->counter;
                    $this->posts[] = $params;
                    $body = [
                        'object' => 'checkout.session', 'id' => $id, 'status' => 'open',
                        'url' => 'https://checkout.stripe.test/' . $id,
                        'payment_intent' => 'pi_new_' . $this->counter,
                        'amount_total' => $params['line_items'][0]['price_data']['unit_amount'] ?? 0,
                        'payment_status' => 'unpaid', 'metadata' => $params['metadata'] ?? [],
                    ];
                    $this->sessions[$id] = $body;

                    return [json_encode($body), 200, []];
                }

                foreach ($this->sessions as $id => $body) {
                    if (str_contains($absUrl, $id)) {
                        $this->gets[] = $id;

                        return [json_encode($body), 200, []];
                    }
                }

                return [json_encode(['error' => ['message' => 'No such session']]), 404, []];
            }
        };

        ApiRequestor::setHttpClient($client);

        return $client;
    }

    private function stripeWebhook(string $eventId, array $session)
    {
        $json = json_encode(['id' => $eventId, 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::STRIPE_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$sig}", 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    private function paidSession(Order $order, string $id = 'cs_paid_1'): array
    {
        return [
            'object' => 'checkout.session', 'id' => $id, 'payment_status' => 'paid',
            'amount_total' => (int) round($order->payment->amount * 100),
            'payment_intent' => 'pi_' . $id, 'customer' => null,
            'metadata' => ['order_id' => (string) $order->id],
        ];
    }

    /* ───────────── fixtures ───────────── */

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function customer(string $first = 'Ana'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    /** Accepted agreement through the real endpoint, then (optionally) its order. */
    private function agreement(?User $customer = null, array $quote = []): array
    {
        $customer ??= $this->customer();
        $admin = User::where('role', 'admin')->first() ?? $this->admin();

        $r = CustomDesignRequest::create([
            'user_id' => $customer->id, 'title' => 'Dining Table - ' . $customer->first_name,
            'furniture_type' => 'Dining Table', 'description' => 'A table', 'status' => 'approved',
            'pipeline_status' => 'quoted', 'contact_phone' => '09171234567', 'contact_email' => 'a@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact', 'submitted_at' => now(),
        ]);

        $q = CustomQuote::create(array_merge([
            'request_id' => $r->id, 'version' => 1, 'status' => 'sent',
            'final_description' => 'Dining table', 'width_cm' => 180, 'depth_cm' => 90, 'height_cm' => 75,
            'material' => 'Narra', 'price' => 20000, 'delivery_option' => 'pickup', 'delivery_fee' => 0,
            'production_days' => 20, 'payment_terms' => 'full',
            'created_by' => $admin->id, 'sent_by' => $admin->id,
            'sent_at' => now()->subHour(), 'expires_at' => now()->addDays(5),
        ], $quote));

        $this->actingAs($customer)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertRedirect();

        return [$customer, $r->fresh(), DesignAgreement::where('quote_id', $q->id)->firstOrFail()];
    }

    private function order(string $method = 'stripe', array $quote = [], ?User $customer = null): array
    {
        [$c, $r, $a] = $this->agreement($customer, $quote);
        $this->actingAs($c)->post(route('custom.checkout.place', $a->id), ['payment_method' => $method])->assertRedirect();

        return [$c, $r->fresh(), $a, Order::where('design_agreement_id', $a->id)->firstOrFail()];
    }

    /* ───────────── B1: starting a payment ───────────── */

    public function test_stripe_session_refused_after_the_deadline(): void
    {
        [$c, $r, , $order] = $this->order();

        Carbon::setTestNow($r->payment_due_at->copy()->addMinute());
        $res = $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id));

        $res->assertRedirect(route('orders.show', $order->id))->assertSessionHasErrors('payment');
        $this->assertSame([], $this->stripe->posts, 'no Stripe session may be created');
    }

    public function test_paymongo_intent_refused_after_the_deadline(): void
    {
        Http::fake();
        [$c, $r, , $order] = $this->order('gcash');

        Carbon::setTestNow($r->payment_due_at->copy()->addMinute());
        $this->actingAs($c)->post(route('payment.gcash.paymongo.checkout', $order->id))
            ->assertRedirect(route('orders.show', $order->id))->assertSessionHasErrors('payment');

        Http::assertNothingSent();
    }

    public function test_payment_cannot_start_for_a_non_current_agreement(): void
    {
        Http::fake();
        [$c, $r, , $order] = $this->order();
        $admin = User::where('role', 'admin')->first();
        $q2 = $this->makeQuote($r, ['version' => 2, 'status' => 'accepted', 'sent_by' => $admin->id]);
        $this->makeAgreement($q2, $c, $admin);

        $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id))->assertSessionHasErrors('payment');
        $this->assertSame([], $this->stripe->posts);

        $order->payment->update(['payment_method' => 'gcash']);
        $this->actingAs($c)->post(route('payment.gcash.paymongo.checkout', $order->id))->assertSessionHasErrors('payment');
        Http::assertNothingSent();
    }

    /* ───────────── completing a payment is never refused for being late ───────────── */

    public function test_stripe_completion_after_the_deadline_is_honored_and_sets_ready_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));
        [$c, $r, , $order] = $this->order();

        // the customer started paying in time, the money lands after the window
        Carbon::setTestNow($r->payment_due_at->copy()->addHours(3));
        $paidDay = now('Asia/Manila');

        $this->stripeWebhook('evt_late_1', $this->paidSession($order))->assertOk();

        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame('20000.00', (string) $order->amount_paid);

        $expected = WorkingDays::add($paidDay, 20)->toDateString();
        $this->assertSame($expected, Carbon::parse($order->expected_ready_date)->toDateString());

        $n = Notification::where('user_id', $c->id)->where('title', 'Payment Successful')->sole();
        $this->assertSame('custom_order', $n->type);
        $this->assertStringContainsString('Expected ready date: about ' . Carbon::parse($expected)->format('M d, Y'), $n->message);
        $this->assertFalse((bool) $order->needs_review);
    }

    public function test_paymongo_completion_after_the_deadline_is_honored(): void
    {
        [$c, $r, , $order] = $this->order('gcash');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_late_gc']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_late_gc' => Http::response(
            ['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 2000000]]], 200)]);

        Carbon::setTestNow($r->payment_due_at->copy()->addDay());
        $this->actingAs($c)->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));

        $order->refresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertNotNull($order->expected_ready_date);
        $this->assertSame('20000.00', (string) $order->amount_paid);
    }

    public function test_payment_for_an_outdated_agreement_is_recorded_flagged_and_not_confirmed(): void
    {
        [$c, $r, , $order] = $this->order();
        $admin = User::where('role', 'admin')->first();
        $q2 = $this->makeQuote($r, ['version' => 2, 'status' => 'accepted', 'sent_by' => $admin->id]);
        $this->makeAgreement($q2, $c, $admin);
        Notification::query()->delete();

        $this->stripeWebhook('evt_old_1', $this->paidSession($order))->assertOk();

        $order->refresh();
        $this->assertSame('pending', $order->order_status, 'not confirmed');
        $this->assertSame('paid', $order->payment->payment_status, 'the money did arrive');
        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('20000.00', (string) $order->amount_paid);
        $this->assertTrue((bool) $order->needs_review);
        $this->assertStringContainsString('outdated agreement', $order->review_reason);
        $this->assertNull($order->expected_ready_date);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Payment Needs Review')->count());
        $this->assertSame(0, Notification::where('user_id', $c->id)->count(), 'no "confirmed" message');
    }

    public function test_catalog_payment_notification_is_unchanged(): void
    {
        $user = $this->customer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe',
        ])->assertRedirect();
        $order = Order::where('user_id', $user->id)->firstOrFail();

        $this->stripeWebhook('evt_cat_1', $this->paidSession($order, 'cs_cat'))->assertOk();

        // catalog: one combined notice (payment received + order confirmed)
        $n = Notification::where('user_id', $user->id)->where('title', 'Payment received and your order is confirmed.')->sole();
        $this->assertSame('payment_confirmed', $n->type);
        $this->assertStringNotContainsString('Expected ready date', $n->message);
        $this->assertNull($order->fresh()->expected_ready_date);
    }

    /* ───────────── B2: Stripe session reuse and expiry ───────────── */

    public function test_open_stripe_session_is_reused(): void
    {
        [$c, , , $order] = $this->order();

        $first = $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id));
        $first->assertRedirect('https://checkout.stripe.test/cs_new_1');
        $this->assertCount(1, $this->stripe->posts);

        $second = $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id));
        $second->assertRedirect('https://checkout.stripe.test/cs_new_1');

        $this->assertCount(1, $this->stripe->posts, 'no second session was created');
        $this->assertContains('cs_new_1', $this->stripe->gets);
        $this->assertSame('cs_new_1', $order->payment->fresh()->stripe_client_secret);
    }

    public function test_expired_stripe_session_is_replaced(): void
    {
        [$c, , , $order] = $this->order();
        $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id));

        $this->stripe->sessions['cs_new_1']['status'] = 'expired';
        $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id))
            ->assertRedirect('https://checkout.stripe.test/cs_new_2');

        $this->assertCount(2, $this->stripe->posts);
    }

    public function test_session_expiry_respects_stripes_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));

        // due date days away: the 23 hour cap applies (Stripe's limit is 24, with room for clock skew)
        [$c, , , $order] = $this->order();
        $this->actingAs($c)->post(route('payment.stripe.checkout', $order->id));
        $exp = (int) $this->stripe->posts[0]['expires_at'];
        $this->assertSame(now()->addHours(23)->timestamp, $exp);
        $this->assertLessThan(now()->addHours(24)->timestamp, $exp);

        // due date inside 31 minutes: no expires_at is sent at all (see StripeParamsTest for the exact bounds)
        $c2 = $this->customer('Soon');
        [, $r2, , $order2] = $this->order('stripe', ['expires_at' => now()->addMinutes(40)], $c2);
        $r2->forceFill(['payment_due_at' => now()->addMinutes(20)])->save();
        $this->actingAs($c2)->post(route('payment.stripe.checkout', $order2->id));
        $this->assertArrayNotHasKey('expires_at', $this->stripe->posts[1]);
    }

    public function test_catalog_session_has_no_expiry_parameter(): void
    {
        $user = $this->customer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe',
        ])->assertRedirect();
        $order = Order::where('user_id', $user->id)->firstOrFail();

        $this->actingAs($user)->post(route('payment.stripe.checkout', $order->id));

        $this->assertArrayNotHasKey('expires_at', $this->stripe->posts[0]);
    }

    /* ───────────── B3: payment method ───────────── */

    public function test_onsite_is_blocked_for_customized_orders(): void
    {
        [$c, , , $order] = $this->order();

        $this->actingAs($c)->post(route('orders.paymentMethod.update', $order->id), ['payment_method' => 'onsite'])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame('stripe', $order->payment->fresh()->payment_method);

        // switching between the online methods still works
        $this->actingAs($c)->post(route('orders.paymentMethod.update', $order->id), ['payment_method' => 'gcash'])
            ->assertRedirect(route('payment.gcash.paymongo', $order->id));
        $this->assertSame('gcash', $order->payment->fresh()->payment_method);
    }

    /* ───────────── B5: abandonment and expiry ───────────── */

    public function test_abandoned_sweep_skips_customized_orders_but_still_cancels_catalog_ones(): void
    {
        [, , , $custom] = $this->order();
        $custom->forceFill(['created_at' => now()->subHours(5)])->save();

        $user = $this->customer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 2, 'added_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe',
        ])->assertRedirect();
        $catalog = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
        $catalog->forceFill(['created_at' => now()->subHours(5)])->save();

        Artisan::call('orders:release-abandoned', ['--minutes' => 60]);

        $this->assertSame('pending', $custom->fresh()->order_status);
        $this->assertSame('pending', $custom->fresh()->payment->payment_status);
        $this->assertSame('cancelled', $catalog->fresh()->order_status);
        $this->assertSame('failed', $catalog->fresh()->payment->payment_status);
        $this->assertSame(5, (int) $product->fresh()->stock_quantity, 'catalog stock is restored as before');
    }

    public function test_expire_command_expires_unpaid_accepted_agreements_only(): void
    {
        $admin = $this->admin();
        [$cLate, $rLate] = $this->agreement($this->customer('Late'));
        [$cOk, $rOk] = $this->agreement($this->customer('Fine'));
        [$cOrd, $rOrd, , $ordered] = $this->order('stripe', [], $this->customer('Ordered'));
        Notification::query()->delete();

        Carbon::setTestNow(now()->addDays(6));        // past the 5-day quote expiry window? due = expiry (5d)
        $rOk->forceFill(['payment_due_at' => now()->addDay()])->save();   // this one is still inside its window

        $this->artisan('custom-orders:expire')->assertSuccessful();

        $this->assertSame('expired', $rLate->fresh()->pipeline_status);
        $this->assertSame('accepted', $rOk->fresh()->pipeline_status);
        $this->assertSame('ordered', $rOrd->fresh()->pipeline_status, 'a request with an order is never expired here');

        $e = CustomOrderEvent::where('request_id', $rLate->id)->where('type', 'agreement_expired')->sole();
        $this->assertSame('system', $e->actor_type);

        $this->assertSame(1, Notification::where('user_id', $cLate->id)->where('title', 'Agreement Expired')->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Agreement Expired Unpaid')->count());
        $this->assertSame(0, Notification::where('user_id', $cOk->id)->count());

        // the unpaid order past the window is not cancelled
        $this->assertSame('pending', $ordered->fresh()->order_status);

        // running again changes nothing
        $this->artisan('custom-orders:expire')->assertSuccessful();
        $this->assertSame(1, CustomOrderEvent::where('type', 'agreement_expired')->count());
    }

    public function test_lazy_check_expires_on_page_load_and_blocks_checkout(): void
    {
        [$c, $r, $a] = $this->agreement();

        Carbon::setTestNow($r->payment_due_at->copy()->addMinute());
        $this->actingAs($c)->get(route('design.show', $r->id))->assertOk();

        $this->assertSame('expired', $r->fresh()->pipeline_status);
        $this->actingAs($c)->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])
            ->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::count());
    }
}
