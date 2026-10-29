<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 11: the whole custom-order flow, start to finish, through the
 * real routes with faked gateways. After every step it asserts the
 * pipeline status, order status, payment status, amount_paid, ledger,
 * notifications and events together.
 */
class EndToEndCustomOrderTest extends TestCase
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

    private function events(CustomDesignRequest $r, string $type): int
    {
        return CustomOrderEvent::where('request_id', $r->id)->where('type', $type)->count();
    }

    private function notes(User $u, string $title): int
    {
        return Notification::where('user_id', $u->id)->where('title', $title)->count();
    }

    private function ledger(Order $o): string
    {
        return number_format((float) PaymentRecord::where('order_id', $o->id)->sum('amount'), 2, '.', '');
    }

    /* ═════════════ flow 1: deposit by Stripe, balance in cash, to completed ═════════════ */

    public function test_flow_deposit_by_stripe_then_cash_then_completed(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $suggestion] = $this->generatedRequest($customer);

        // ── 1. customer sends the request with phone and email
        $this->submitRequest($customer, $r, $suggestion)->assertSessionHasNoErrors()->assertRedirect();
        $r = $r->fresh();
        $this->assertSame('submitted', $r->pipeline_status);
        $this->assertSame('approved', $r->status);
        $this->assertSame('09171234567', $r->contact_phone);
        $this->assertSame('ana@example.com', $r->contact_email);
        $this->assertSame(1, $this->events($r, 'submitted'));
        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('type', 'custom_order')->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'custom_order')->count());
        $this->assertSame(0, Order::count());

        // ── 2. admin logs an unanswered call
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '0', 'note' => 'Rang, no answer'])->assertSessionHasNoErrors();
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(1, CustomOrderCallLog::where('request_id', $r->id)->where('answered', false)->count());

        // ── 3. marks unreachable
        $this->adminPost($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasNoErrors();
        $this->assertSame('unreachable', $r->fresh()->pipeline_status);
        $this->assertSame(1, $this->notes($customer, 'We Could Not Reach You'));
        $this->assertSame(1, $this->events($r, 'marked_unreachable'));

        // ── 4. logs an answered call: back to contacting
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '1', 'note' => 'Reached, wants narra'])->assertSessionHasNoErrors();
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(2, CustomOrderCallLog::where('request_id', $r->id)->count());

        // ── 5. creates and sends the quote
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $r, $this->quoteFields())->assertSessionHasNoErrors();
        $draft = CustomQuote::where('request_id', $r->id)->sole();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(0, $this->notes($customer, 'Your Custom Quote Is Ready'), 'a draft is never shown to the customer');

        $this->asAdmin($admin)->post(route('admin.custom-orders.quotes.send', [$r->id, $draft->id]), ['lock_version' => $r->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $quote = $draft->fresh();
        $r = $r->fresh();
        $this->assertSame('sent', $quote->status);
        $this->assertSame('quoted', $r->pipeline_status);
        $this->assertSame('20500.00', number_format($quote->totalAmount(), 2, '.', ''));
        $this->assertSame(1, $this->notes($customer, 'Your Custom Quote Is Ready'));
        $this->assertSame(1, $this->events($r, 'quote_sent'));

        // ── 6. customer reviews and accepts
        $review = $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk();
        $review->assertSee('Accept agreement')->assertDontSee('INTERNAL: customer wants it by December');

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $r = $r->fresh();
        $this->assertSame('accepted', $r->pipeline_status);
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame('20500.00', (string) $agreement->total_amount);
        $this->assertSame('10250.00', (string) $agreement->upfront_amount);
        $this->assertTrue($agreement->snapshotIsIntact());
        $this->assertNotNull($r->payment_due_at);
        $this->assertSame(1, $this->events($r, 'quote_accepted'));
        $this->assertSame(1, $this->notes($customer, 'Agreement Accepted'));
        $this->assertSame(1, $this->notes($admin, 'Agreement Accepted'));
        $this->assertSame(0, Order::count());

        // ── 7. customer proceeds to checkout and chooses Stripe
        $this->asCustomer($customer)->get(route('custom.checkout', $agreement->id))->assertOk()->assertSee('Amount due now: ₱10,250.00', false);
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();

        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $r = $r->fresh();
        $this->assertSame('ordered', $r->pipeline_status);
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('pending', $order->payment->payment_status);
        $this->assertSame('installment', $order->payment->payment_type);
        $this->assertSame('10250.00', (string) $order->payment->amount);
        $this->assertSame('20500.00', (string) $order->total_amount);
        $this->assertSame('0.00', (string) $order->amount_paid);
        $this->assertSame(2, InstallmentSchedule::where('order_id', $order->id)->count());
        $this->assertSame(0, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame(1, $this->events($r, 'order_placed'));
        $this->assertSame(1, $this->notes($customer, 'Order Created'));

        // ── 8. pays the deposit through (faked) Stripe
        $this->asCustomer($customer)->post(route('payment.stripe.checkout', $order->id))
            ->assertRedirect('https://checkout.stripe.test/cs_e2e_1');
        $this->assertSame(1025000, $this->stripe->posts[0]['line_items'][0]['price_data']['unit_amount'], 'Stripe is asked for exactly the deposit, in centavos');
        $this->stripePaid($order, 'cs_e2e_1', 'evt_e2e_1')->assertOk();

        $order = $order->fresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame('10250.00', (string) $order->amount_paid);
        $this->assertSame('ordered', $r->fresh()->pipeline_status);
        $row = PaymentRecord::where('order_id', $order->id)->sole();
        $this->assertSame('stripe', $row->method);
        $this->assertSame('pi_e2e_1', $row->gateway_reference);
        $this->assertNull($row->recorded_by);
        $this->assertNotNull($order->expected_ready_date);
        $paid = Notification::where('user_id', $customer->id)->where('title', 'Payment Successful')->sole();
        $this->assertSame('custom_order', $paid->type);
        $this->assertStringContainsString('Expected ready date: about', $paid->message);

        // a repeat of the same webhook changes nothing
        $this->stripePaid($order, 'cs_e2e_1', 'evt_e2e_2')->assertOk();
        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame(1, $this->notes($customer, 'Payment Successful'));

        // ── 9. admin records cash for the first installment (part of the balance)
        [$i1, $i2] = $order->installments()->orderBy('installment_no')->get()->all();
        $this->recordCash($admin, $order, '5125.00', $i1->id)->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame('15375.00', (string) $order->amount_paid);
        $this->assertSame('15375.00', $this->ledger($order));
        $this->assertSame('partial', $order->payment->payment_status);
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('paid', $i1->fresh()->status);
        $this->assertSame('pending', $i2->fresh()->status);
        $cash = PaymentRecord::where('order_id', $order->id)->where('method', 'cash')->sole();
        $this->assertSame($admin->id, (int) $cash->recorded_by);
        $this->assertSame($i1->id, (int) $cash->installment_schedule_id);

        // ── 10. admin records the rest: fully paid
        $this->recordCash($admin, $order, '5125.00', $i2->id)->assertSessionHasNoErrors();
        $order = $order->fresh();
        $this->assertSame('20500.00', (string) $order->amount_paid);
        $this->assertSame('20500.00', $this->ledger($order));
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame('paid', $i2->fresh()->status);
        $this->assertSame(0, InstallmentSchedule::where('order_id', $order->id)->where('status', '!=', 'paid')->count());
        $this->recordCash($admin, $order, '1.00')->assertSessionHasErrors('payment');   // nothing left to pay
        $this->assertSame(3, PaymentRecord::where('order_id', $order->id)->count());

        // ── 11. admin moves the order forward to completed
        foreach (['in_production', 'ready', 'completed'] as $step) {
            $this->adminSetStatus($admin, $order, $step)->assertSessionHasNoErrors();
            $this->assertSame($step, $order->fresh()->order_status);
            $this->assertSame('ordered', $r->fresh()->pipeline_status, 'the request stays ordered');
        }
        $this->assertSame(3, Notification::where('user_id', $customer->id)->where('type', 'order_update')->count());
        $this->assertSame(3, $this->events($r, 'order_status_changed'));
        $this->adminSetStatus($admin, $order, 'in_production')->assertSessionHasErrors('status');   // never backwards
        $this->assertSame('completed', $order->fresh()->order_status);

        // ── final consistency
        $order = $order->fresh();
        $this->assertSame((string) $order->amount_paid, $this->ledger($order));
        $this->assertSame((string) $order->total_amount, (string) $order->amount_paid);

        $timeline = $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->getContent();
        $this->assertStringContainsString('Your order was completed', $timeline);
        $this->assertStringNotContainsString('INTERNAL: customer wants it by December', $timeline);
        $this->assertStringNotContainsString('Reached, wants narra', $timeline);

        $adminView = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk();
        $adminView->assertSee('Hash Verified')->assertSee('completed');
    }

    /* ═════════════ flow 2: full payment by GCash ═════════════ */

    public function test_flow_full_payment_by_gcash(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $suggestion] = $this->generatedRequest($customer);

        $this->submitRequest($customer, $r, $suggestion)->assertRedirect();
        $quote = $this->quoteAndSend($admin, $r->fresh(), [
            'delivery_option' => 'pickup', 'delivery_fee' => '', 'delivery_address' => '',
            'payment_terms' => 'full', 'deposit_amount' => '',
            'installment_duration_months' => '', 'installment_frequency_days' => '',
        ]);
        $this->assertSame('20000.00', number_format($quote->totalAmount(), 2, '.', ''));

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $this->assertSame('accepted', $r->fresh()->pipeline_status);
        $this->assertSame('20000.00', (string) $agreement->upfront_amount);

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'gcash'])
            ->assertRedirect();
        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $this->assertSame('ordered', $r->fresh()->pipeline_status);
        $this->assertSame('full', $order->payment->payment_type);
        $this->assertSame('gcash', $order->payment->payment_method);
        $this->assertSame('20000.00', (string) $order->payment->amount);
        $this->assertSame(0, InstallmentSchedule::where('order_id', $order->id)->count());
        $this->assertSame('pending', $order->order_status);

        // GCash via (faked) PayMongo: the intent exists, the redirect back verifies it
        $order->payment->update(['paymongo_payment_intent_id' => 'pi_e2e_gc']);
        Http::fake(['api.paymongo.com/v1/payment_intents/pi_e2e_gc' => Http::response(
            ['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 2000000]]], 200)]);

        $this->asCustomer($customer)->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));

        $order = $order->fresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertSame('20000.00', (string) $order->amount_paid);
        $row = PaymentRecord::where('order_id', $order->id)->sole();
        $this->assertSame('gcash_paymongo', $row->method);
        $this->assertSame('pi_e2e_gc', $row->gateway_reference);
        $this->assertSame(1, $this->notes($customer, 'Payment Successful'));

        // the same payment arriving again by redirect does not double anything
        $this->asCustomer($customer)->get(route('payment.gcash.paymongo.success', $order->id))
            ->assertRedirect(route('payment.confirmed', $order->id));
        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('20000.00', (string) $order->fresh()->amount_paid);

        // fully paid: admin cannot record more, and the pickup order goes to completed
        $this->recordCash($admin, $order, '100')->assertSessionHasErrors('payment');
        foreach (['in_production', 'out_for_delivery', 'completed'] as $step) {
            $this->adminSetStatus($admin, $order, $step)->assertSessionHasNoErrors();
        }
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame('ordered', $r->fresh()->pipeline_status);
    }

    /* ═════════════ flow 3: the quote expires ═════════════ */

    public function test_flow_quote_expires_then_a_new_version_is_accepted(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $suggestion] = $this->generatedRequest($customer);

        $this->submitRequest($customer, $r, $suggestion)->assertRedirect();
        $v1 = $this->quoteAndSend($admin, $r->fresh());
        $this->assertSame('quoted', $r->fresh()->pipeline_status);
        Notification::query()->delete();

        // the 7 days pass; the customer opens their page
        Carbon::setTestNow($v1->expires_at->copy()->addMinute());
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('This quote expired on');

        $r = $r->fresh();
        $this->assertSame('expired', $r->pipeline_status);
        $this->assertSame('expired', $v1->fresh()->status);
        $this->assertSame(1, $this->events($r, 'quote_expired'));
        $this->assertSame(1, $this->notes($customer, 'Your Quote Has Expired'));
        $this->assertSame(1, $this->notes($admin, 'Quote Expired'));

        // nothing can be accepted or paid any more
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $v1->id]), ['agree' => '1'])->assertSessionHasErrors('quote');
        $this->assertSame(0, DesignAgreement::count());
        $this->assertSame(0, Order::count());

        // running the sweep again is a no-op
        $this->artisan('custom-orders:expire')->assertSuccessful();
        $this->assertSame(1, $this->events($r, 'quote_expired'));

        // the admin sends a new version; the customer can accept that one
        Carbon::setTestNow();
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $r, $this->quoteFields(['price' => '21000', 'deposit_amount' => '10750']))->assertSessionHasNoErrors();
        $v2 = CustomQuote::where('request_id', $r->id)->where('status', 'draft')->sole();
        $this->assertSame(2, (int) $v2->version);
        $this->asAdmin($admin)->post(route('admin.custom-orders.quotes.send', [$r->id, $v2->id]), ['lock_version' => $r->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame('quoted', $r->fresh()->pipeline_status);

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $v2->id]), ['agree' => '1'])->assertRedirect();
        $this->assertSame('accepted', $r->fresh()->pipeline_status);
        $this->assertSame('21500.00', (string) $this->agreementOf($r)->total_amount);
        $this->assertSame(1, DesignAgreement::count());
    }
}
