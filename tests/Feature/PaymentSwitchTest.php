<?php

namespace Tests\Feature;

use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 14 part B: friendly gateway failures and switching between
 * Stripe and GCash on a customized order.
 */
class PaymentSwitchTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function customOrder(string $method = 'stripe'): array
    {
        $admin = User::where('role', 'admin')->first() ?? $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $q = $this->quoteAndSend($admin, $r->fresh());
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertRedirect();
        $this->asCustomer($customer)->post(route('custom.checkout.place', $this->agreementOf($r)->id), ['payment_method' => $method])->assertRedirect();

        return [$customer, $admin, $r->fresh(), Order::where('user_id', $customer->id)->latest('id')->firstOrFail()];
    }

    private function switchTo(User $who, Order $order, string $method)
    {
        return $this->asCustomer($who)->post(route('orders.paymentMethod.update', $order->id), ['payment_method' => $method]);
    }

    private function startStripe(User $customer, Order $order): string
    {
        $this->asCustomer($customer)->post(route('payment.stripe.checkout', $order->id))->assertRedirect();

        return 'cs_e2e_' . $this->stripe->counter;
    }

    /* ═════════ switching ═════════ */

    public function test_stripe_to_gcash_expires_the_old_session_and_keeps_one_payment_row(): void
    {
        [$c, , $r, $order] = $this->customOrder('stripe');
        $sid = $this->startStripe($c, $order);
        $this->assertSame($sid, $order->payment->fresh()->stripe_client_secret);

        $this->switchTo($c, $order, 'gcash')->assertRedirect(route('payment.gcash.paymongo', $order->id));

        $p = $order->payment->fresh();
        $this->assertSame('gcash', $p->payment_method);
        $this->assertNull($p->stripe_client_secret);
        $this->assertNull($p->stripe_payment_intent_id);
        $this->assertSame('pending', $p->payment_status);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame([$sid], $this->stripe->expired, 'the old Checkout Session was expired at Stripe');

        $e = CustomOrderEvent::where('type', 'payment_method_changed')->sole();
        $this->assertSame($r->id, (int) $e->request_id);
        $this->assertSame('customer', $e->actor_type);
        $this->assertSame('stripe', $e->data['from']);
        $this->assertSame('gcash', $e->data['to']);
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_gcash_to_stripe_ignores_the_old_intent(): void
    {
        [$c, , , $order] = $this->customOrder('gcash');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_old_gc', 'paymongo_payment_method_id' => 'pm_old']);

        $this->switchTo($c, $order, 'stripe')->assertRedirect(route('payment.stripe', $order->id));

        $p = $order->payment->fresh();
        $this->assertSame('stripe', $p->payment_method);
        $this->assertNull($p->paymongo_payment_intent_id);
        $this->assertNull($p->paymongo_payment_method_id);
        $this->assertSame([], $this->stripe->expired);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());

        // the old PayMongo intent's webhook now finds no payment: nothing is confirmed
        $json = json_encode(['data' => ['attributes' => ['type' => 'payment.paid', 'data' => ['attributes' => ['payment_intent_id' => 'pi_old_gc']]]]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", 'whsec_paymongo_fake_secret');
        $this->call('POST', '/webhook/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=", 'CONTENT_TYPE' => 'application/json'], $json)->assertOk();
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_switching_to_the_same_method_changes_nothing(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');
        $this->switchTo($c, $order, 'stripe')->assertRedirect(route('payment.stripe', $order->id));

        $this->assertSame(0, CustomOrderEvent::where('type', 'payment_method_changed')->count());
    }

    public function test_switch_is_refused_after_any_payment_is_recorded(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');
        PaymentRecord::create([
            'order_id' => $order->id, 'payment_id' => $order->payment->id, 'amount' => 100,
            'method' => 'cash', 'paid_on' => now()->toDateString(),
        ]);
        $order->forceFill(['amount_paid' => 100])->save();

        $this->switchTo($c, $order, 'gcash')->assertSessionHasErrors('payment_method');

        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
        $this->assertSame(0, CustomOrderEvent::where('type', 'payment_method_changed')->count());
    }

    public function test_switch_is_refused_when_the_payment_is_already_paid_or_the_order_is_not_pending(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');
        $order->payment->update(['payment_status' => 'paid']);
        $this->switchTo($c, $order, 'gcash')->assertForbidden();   // existing rule: a paid payment is closed

        $order->payment->update(['payment_status' => 'pending']);
        foreach (['confirmed', 'in_production', 'cancelled', 'completed'] as $status) {
            $order->forceFill(['order_status' => $status])->save();
            $this->switchTo($c, $order, 'gcash')->assertForbidden();
        }
        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
    }

    public function test_another_customer_gets_404(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');
        $bob = $this->flowCustomer('Bob');

        $this->switchTo($bob, $order, 'gcash')->assertNotFound();

        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
    }

    public function test_onsite_is_refused_on_the_server(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');

        $this->switchTo($c, $order, 'onsite')->assertSessionHasErrors('payment_method');
        $this->switchTo($c, $order, 'bank')->assertSessionHasErrors('payment_method');

        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
    }

    public function test_guest_is_sent_to_login(): void
    {
        [, , , $order] = $this->customOrder('stripe');
        auth()->logout();

        $this->post(route('orders.paymentMethod.update', $order->id), ['payment_method' => 'gcash'])->assertRedirect(route('login'));
        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
    }

    public function test_old_stripe_session_paid_after_gcash_already_paid_is_flagged_not_double_confirmed(): void
    {
        [$c, $admin, , $order] = $this->customOrder('stripe');
        $sid = $this->startStripe($c, $order);
        $this->switchTo($c, $order, 'gcash')->assertRedirect();

        // GCash pays first
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_new_gc']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_new_gc' => Http::response(
            ['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 1025000]]], 200)]);
        $this->asCustomer($c)->get(route('payment.gcash.paymongo.success', $order->id))->assertRedirect(route('payment.confirmed', $order->id));
        $this->assertSame('confirmed', $order->fresh()->order_status);
        Notification::where('user_id', $admin->id)->delete();

        // the customer then also paid the old card session
        $this->stripePaid($order, $sid, 'evt_old_session')->assertOk();

        $o = $order->fresh();
        $this->assertSame('confirmed', $o->order_status);
        $this->assertSame(2, PaymentRecord::where('order_id', $o->id)->count());
        $this->assertSame('20500.00', (string) $o->amount_paid);
        $this->assertTrue((bool) $o->needs_review);
        $this->assertStringContainsString('duplicate payment', $o->review_reason);
        $this->assertSame(1, Notification::where('user_id', $c->id)->where('title', 'Payment Successful')->count(), 'confirmed once');
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Payment Needs Review')->count());
    }

    public function test_catalog_switching_rules_are_unchanged(): void
    {
        $user = $this->flowCustomer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->asCustomer($user)->post(route('order.place'), ['delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe'])->assertRedirect();
        $order = Order::where('user_id', $user->id)->firstOrFail();

        $this->switchTo($user, $order, 'onsite')->assertRedirect(route('orders.show', $order->id));
        $this->assertSame('onsite', $order->payment->fresh()->payment_method);
        $this->switchTo($user, $order, 'gcash')->assertRedirect(route('payment.gcash.paymongo', $order->id));
        $this->assertSame(0, CustomOrderEvent::count());
    }

    /* ═════════ the pages ═════════ */

    public function test_pages_offer_the_switch_for_customized_orders_without_cash(): void
    {
        [$c, , , $order] = $this->customOrder('stripe');

        $page = $this->asCustomer($c)->get(route('orders.show', $order->id))->assertOk();
        $page->assertSee('Change Payment Method')->assertSee('value="stripe"', false)->assertSee('value="gcash"', false)
            ->assertDontSee('value="onsite"', false)->assertDontSee('Cash on Pickup');

        $this->asCustomer($c)->get(route('payment.stripe', $order->id))->assertOk()->assertSee('Switch to GCash');

        $this->switchTo($c, $order, 'gcash');
        $this->asCustomer($c)->get(route('payment.gcash.paymongo', $order->id))->assertOk()->assertSee('Switch to card (Stripe)');

        // hidden once a payment is on record
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $order->payment->id, 'amount' => 50, 'method' => 'cash', 'paid_on' => now()->toDateString()]);
        $order->forceFill(['amount_paid' => 50])->save();
        $this->asCustomer($c)->get(route('payment.gcash.paymongo', $order->id))->assertOk()->assertDontSee('Switch to card');
        $this->asCustomer($c)->get(route('orders.show', $order->id))->assertOk()->assertDontSee('Change Payment Method');
    }

    /* ═════════ friendly failures ═════════ */

    public static function stripeFailures(): array
    {
        return ['stripe rejects the request' => ['invalid'], 'stripe cannot be reached' => ['network']];
    }

    /** @dataProvider stripeFailures */
    public function test_stripe_failure_is_a_friendly_message_not_a_500(string $mode): void
    {
        Log::spy();
        [$c, , , $order] = $this->customOrder('stripe');
        $this->stripe->failCreate = $mode;

        $res = $this->asCustomer($c)->post(route('payment.stripe.checkout', $order->id));

        $res->assertRedirect(route('payment.stripe', $order->id));
        $res->assertSessionHasErrors(['payment' => "We couldn't start the card payment. Please try again, or switch to GCash."]);
        $this->assertNull($order->payment->fresh()->stripe_client_secret);

        $page = $this->asCustomer($c)->get(route('payment.stripe', $order->id))->assertOk();
        $page->assertSee('Switch to GCash');

        // logged, but never with a key
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, "order {$order->id}") && !str_contains($m, 'SECRETVALUE'))->once();
    }

    public function test_paymongo_exception_and_error_response_are_friendly(): void
    {
        Log::spy();
        [$c, , , $order] = $this->customOrder('gcash');

        Http::fake(['api.paymongo.com/*' => fn () => throw new ConnectionException('timeout talking to PayMongo')]);
        $this->asCustomer($c)->post(route('payment.gcash.paymongo.checkout', $order->id))
            ->assertRedirect(route('payment.gcash.paymongo', $order->id))
            ->assertSessionHasErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);

        Http::fake(['api.paymongo.com/*' => Http::response(['errors' => [['detail' => 'boom']]], 500)]);
        $this->asCustomer($c)->post(route('payment.gcash.paymongo.checkout', $order->id))
            ->assertRedirect(route('payment.gcash.paymongo', $order->id))
            ->assertSessionHasErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);

        $this->asCustomer($c)->get(route('payment.gcash.paymongo', $order->id))->assertOk()->assertSee('Switch to card (Stripe)');
    }

    public function test_catalog_stripe_failure_is_friendly_too(): void
    {
        $user = $this->flowCustomer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->asCustomer($user)->post(route('order.place'), ['delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'stripe'])->assertRedirect();
        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->stripe->failCreate = 'invalid';

        $this->asCustomer($user)->post(route('payment.stripe.checkout', $order->id))
            ->assertRedirect(route('payment.stripe', $order->id))
            ->assertSessionHasErrors('payment');
    }
}
