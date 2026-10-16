<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A verified gateway payment that matches no order (or an order that has
 * since switched to another method) alerts admins and changes nothing
 * else. Webhooks are faked with locally signed payloads.
 */
class UnmatchedPaymentAlertTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET   = 'whsec_test_fake_secret';
    private const PAYMONGO_SECRET = 'whsec_paymongo_fake_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret'           => 'sk_test_fake',
            'services.stripe.webhook'          => self::STRIPE_SECRET,
            'services.paymongo.webhook_secret' => self::PAYMONGO_SECRET,
        ]);
    }

    private function user(string $role = 'customer'): User
    {
        $u = new User();
        $u->forceFill([
            'first_name' => 'Pay', 'last_name' => 'Tester',
            'email' => 'pay' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'not-a-real-password', 'role' => $role,
            'is_validated' => true, 'account_status' => 'active',
        ])->save();

        return $u;
    }

    private function placeOrder(User $user, string $method): Order
    {
        $product = Product::create([
            'product_name' => 'Test Chair', 'price' => 1000, 'stock_quantity' => 10, 'product_status' => 'available',
        ]);
        DB::table('cart_items')->insert([
            'user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => $method,
        ])->assertRedirect();

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
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

    private function stripeSession(?int $orderId, string $id, string $intent, int $amount = 100000): array
    {
        return [
            'object' => 'checkout.session', 'id' => $id, 'payment_status' => 'paid',
            'amount_total' => $amount, 'payment_intent' => $intent, 'customer' => null,
            'metadata' => $orderId ? ['order_id' => (string) $orderId] : [],
        ];
    }

    private function paymongoWebhook(string $intentId, int $amount = 100000)
    {
        $json = json_encode(['data' => ['attributes' => ['type' => 'payment.paid',
            'data' => ['attributes' => ['payment_intent_id' => $intentId, 'amount' => $amount]]]]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::PAYMONGO_SECRET);

        return $this->call('POST', '/webhook/paymongo', [], [], [], [
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=", 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    private function alerts(User $admin)
    {
        return Notification::where('user_id', $admin->id)->where('title', 'Unmatched Payment')->get();
    }

    public function test_paymongo_intent_matching_no_order_alerts_admin_only(): void
    {
        $admin = $this->user('admin');

        $this->paymongoWebhook('pi_unknown_12345678')->assertOk();

        $n = $this->alerts($admin)->sole();
        $this->assertSame('general', $n->type);
        $this->assertStringContainsString('GCash (PayMongo)', $n->message);
        $this->assertStringContainsString('₱1,000.00', $n->message);
        $this->assertStringContainsString('…12345678', $n->message);
        $this->assertStringNotContainsString('pi_unknown', $n->message);
        $this->assertStringContainsString('Check the gateway dashboard and refund or match this payment.', $n->message);
        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_paymongo_old_intent_after_switch_alerts_and_leaves_order_alone(): void
    {
        $admin = $this->user('admin');
        $order = $this->placeOrder($this->user(), 'gcash');
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_old_abcdefgh']);
        // The customer switched to Stripe but the intent id is still on the row.
        $order->payment->update(['payment_method' => 'stripe']);

        $this->paymongoWebhook('pi_old_abcdefgh')->assertOk();

        $this->assertSame(1, $this->alerts($admin)->count());
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame('pending', $order->payment->fresh()->payment_status);
        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_stripe_old_session_after_switch_alerts_and_leaves_order_alone(): void
    {
        $admin = $this->user('admin');
        $order = $this->placeOrder($this->user(), 'stripe');
        $order->payment->update(['payment_method' => 'gcash']);

        $this->stripeWebhook('evt_sw_1', $this->stripeSession($order->id, 'cs_old', 'pi_stripe_zyxwvuts'))->assertOk();

        $n = $this->alerts($admin)->sole();
        $this->assertStringContainsString('Stripe', $n->message);
        $this->assertStringContainsString('…zyxwvuts', $n->message);
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame('pending', $order->payment->fresh()->payment_status);
        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_stripe_session_for_unknown_order_alerts(): void
    {
        $admin = $this->user('admin');

        $this->stripeWebhook('evt_un_1', $this->stripeSession(999999, 'cs_x', 'pi_nomatch_00001111'))->assertOk();
        $this->stripeWebhook('evt_un_2', $this->stripeSession(null, 'cs_y', 'pi_nometa_22223333'))->assertOk();

        $this->assertSame(2, $this->alerts($admin)->count());
        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_duplicate_events_alert_once(): void
    {
        $admin = $this->user('admin');
        $order = $this->placeOrder($this->user(), 'stripe');
        $order->payment->update(['payment_method' => 'gcash']);
        $session = $this->stripeSession($order->id, 'cs_dup', 'pi_dup_55556666');

        $this->stripeWebhook('evt_dup', $session)->assertOk();
        $this->stripeWebhook('evt_dup', $session)->assertOk();                 // same event id
        $this->stripeWebhook('evt_dup_other', $session)->assertOk();           // redelivered under a new event id

        $this->paymongoWebhook('pi_pm_dup_77778888')->assertOk();
        $this->paymongoWebhook('pi_pm_dup_77778888')->assertOk();

        $this->assertSame(2, $this->alerts($admin)->count());
    }

    public function test_normal_flows_are_unchanged(): void
    {
        $admin = $this->user('admin');
        $customer = $this->user();
        $stripeOrder = $this->placeOrder($customer, 'stripe');
        $gcashOrder = $this->placeOrder($customer, 'gcash');
        $gcashOrder->payment->update(['paymongo_payment_intent_id' => 'pi_ok_gcash']);

        $this->stripeWebhook('evt_ok', $this->stripeSession($stripeOrder->id, 'cs_ok', 'pi_ok_stripe'))->assertOk();
        $this->paymongoWebhook('pi_ok_gcash')->assertOk();

        $this->assertSame('confirmed', $stripeOrder->fresh()->order_status);
        $this->assertSame('confirmed', $gcashOrder->fresh()->order_status);
        $this->assertSame(2, PaymentRecord::count());
        $this->assertSame(0, $this->alerts($admin)->count());
    }
}
