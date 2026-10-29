<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 11 edge checks: ownership everywhere, one result per double
 * submit, prices from stored records only, tamper blocking.
 */
class CustomOrderEdgeChecksTest extends TestCase
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

    /** Request taken all the way to an order (Stripe, deposit). */
    private function orderFlow(?User $customer = null, ?User $admin = null): array
    {
        $admin ??= User::where('role', 'admin')->first() ?? $this->flowAdmin();
        $customer ??= $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);

        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $quote = $this->quoteAndSend($admin, $r->fresh());
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();

        return [$customer, $admin, $r->fresh(), $quote->fresh(), $agreement, Order::where('design_agreement_id', $agreement->id)->sole()];
    }

    /* ═════════════ ownership: customer B on customer A's records, with real ids ═════════════ */

    public function test_another_customer_cannot_see_or_act_on_anything_of_mine(): void
    {
        [$alice, , $r, $quote, $agreement, $order] = $this->orderFlow();
        $bob = $this->flowCustomer('Bob');
        $suggestion = AiDesignSuggestion::where('design_id', $r->id)->first();

        $before = [
            'events'  => CustomOrderEvent::count(),
            'orders'  => Order::count(),
            'agree'   => DesignAgreement::count(),
            'ledger'  => PaymentRecord::count(),
            'notes'   => Notification::count(),
            'status'  => [$r->fresh()->pipeline_status, $quote->fresh()->status, $order->fresh()->order_status],
        ];

        $get = [
            route('design.show', $r->id),
            route('design.sendForm', $r->id),
            route('design.quote.review', [$r->id, $quote->id]),
            route('design.agreement', $agreement->id),
            route('design.agreement.print', $agreement->id),
            route('design.agreement.image', $agreement->id),
            route('custom.checkout', $agreement->id),
            route('orders.show', $order->id),
            route('payment.stripe', $order->id),
            route('payment.stripe.success', $order->id) . '?session_id=cs_whatever',
            route('payment.gcash.paymongo', $order->id),
            route('payment.gcash.paymongo.success', $order->id),
            route('payment.confirmed', $order->id),
        ];
        $post = [
            [route('design.select', $r->id), ['suggestion_id' => $suggestion->id]],
            [route('design.regenerate', $r->id), []],
            [route('design.sendToTeam', $r->id), ['contact_phone' => '09170000009', 'contact_email' => 'b@example.com',
                'preferred_call_window' => 'anytime', 'request_mode' => 'exact']],
            [route('design.favorite', $r->id), ['suggestion_id' => $suggestion->id]],
            [route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1']],
            [route('design.quote.decline', [$r->id, $quote->id]), ['reason' => 'x']],
            [route('custom.checkout.place', $agreement->id), ['payment_method' => 'gcash']],
            [route('orders.paymentMethod.update', $order->id), ['payment_method' => 'gcash']],
            [route('payment.stripe.checkout', $order->id), []],
            [route('payment.gcash.paymongo.checkout', $order->id), []],
        ];

        $this->asCustomer($bob);
        foreach ($get as $url) {
            $this->get($url)->assertNotFound();
        }
        foreach ($post as [$url, $data]) {
            $this->post($url, $data)->assertNotFound();
        }

        $this->asCustomer($bob)->get(route('orders.index'))->assertOk()->assertDontSee($order->order_number);

        $this->assertSame($before['events'], CustomOrderEvent::count());
        $this->assertSame($before['orders'], Order::count());
        $this->assertSame($before['agree'], DesignAgreement::count());
        $this->assertSame($before['ledger'], PaymentRecord::count());
        $this->assertSame($before['notes'], Notification::count());
        $this->assertSame($before['status'], [$r->fresh()->pipeline_status, $quote->fresh()->status, $order->fresh()->order_status]);
        $this->assertSame('stripe', $order->payment->fresh()->payment_method);
    }

    /* ═════════════ two submits of every state-changing form -> one result ═════════════ */

    public function test_customer_forms_submitted_twice_give_one_result(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);

        // send to team
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $this->submitRequest($customer, $r, $s);
        $this->assertSame(1, CustomOrderEvent::where('type', 'submitted')->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'custom_order')->count(), 'one admin alert, not two');

        // accept
        $quote = $this->quoteAndSend($admin, $r->fresh());
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $this->assertSame(1, DesignAgreement::count());
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_accepted')->count());
        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('title', 'Agreement Accepted')->count());

        // checkout (two tabs, different methods)
        $agreement = $this->agreementOf($r);
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'gcash'])->assertRedirect();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CustomOrderEvent::where('type', 'order_placed')->count());
        $this->assertSame('stripe', Payment::first()->payment_method);
    }

    public function test_decline_submitted_twice_gives_one_result(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $quote = $this->quoteAndSend($admin, $r->fresh());

        $this->asCustomer($customer)->post(route('design.quote.decline', [$r->id, $quote->id]), ['reason' => 'Too high'])->assertRedirect();
        $this->asCustomer($customer)->post(route('design.quote.decline', [$r->id, $quote->id]), ['reason' => 'Too high'])
            ->assertSessionHasErrors('quote');

        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_declined_by_customer')->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Quote Declined')->count());
    }

    public function test_admin_forms_submitted_twice_give_one_result(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $r = $r->fresh();
        $v = $r->lock_version;

        // call log: the second post carries the same (now stale) lock_version
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '0'], $v)->assertSessionHasNoErrors();
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '0'], $v)->assertSessionHasErrors('pipeline');
        $this->assertSame(1, CustomOrderCallLog::where('request_id', $r->id)->count());

        // mark unreachable
        $v = $r->fresh()->lock_version;
        $this->adminPost($admin, 'admin.custom-orders.unreachable', $r, [], $v)->assertSessionHasNoErrors();
        $this->adminPost($admin, 'admin.custom-orders.unreachable', $r, [], $v)->assertSessionHasErrors('pipeline');
        $this->assertSame(1, CustomOrderEvent::where('type', 'marked_unreachable')->count());

        // answered call, then quote draft twice, then send twice
        $this->adminPost($admin, 'admin.custom-orders.calls', $r, ['answered' => '1'])->assertSessionHasNoErrors();
        $v = $r->fresh()->lock_version;
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $r, $this->quoteFields(), $v)->assertSessionHasNoErrors();
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $r, $this->quoteFields(), $v)->assertSessionHasErrors();
        $this->assertSame(1, CustomQuote::where('request_id', $r->id)->count());

        $draft = CustomQuote::where('request_id', $r->id)->sole();
        $v = $r->fresh()->lock_version;
        $send = fn () => $this->asAdmin($admin)->post(route('admin.custom-orders.quotes.send', [$r->id, $draft->id]), ['lock_version' => $v]);
        $send()->assertSessionHasNoErrors();
        $send()->assertSessionHasErrors('pipeline');
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_sent')->count());
        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('title', 'Your Custom Quote Is Ready')->count());
    }

    public function test_record_payment_and_status_forms_submitted_twice_give_one_result(): void
    {
        [, $admin, , , , $order] = $this->orderFlow();
        $this->stripePaid($order, $this->openSession($order), 'evt_edge_1')->assertOk();
        $order = $order->fresh();
        $this->assertSame('confirmed', $order->order_status);

        // Record payment: same rendered form (same token) twice
        $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk();
        $token = collect(session(\App\Http\Controllers\Admin\PaymentRecordController::TOKEN_SESSION_KEY))->last();
        $payload = ['form_token' => $token, 'amount' => '500.00', 'method' => 'cash', 'paid_on' => now('Asia/Manila')->toDateString()];
        $this->asAdmin($admin)->post(route('admin.orders.payments.store', $order->id), $payload)->assertSessionHasNoErrors();
        $this->asAdmin($admin)->post(route('admin.orders.payments.store', $order->id), $payload)->assertSessionHasErrors('payment');
        $this->assertSame(2, PaymentRecord::where('order_id', $order->id)->count());   // stripe + one cash row
        $this->assertSame('10750.00', (string) $order->fresh()->amount_paid);

        // status: the second identical change is refused
        $this->adminSetStatus($admin, $order, 'in_production')->assertSessionHasNoErrors();
        $this->adminSetStatus($admin, $order, 'in_production')->assertSessionHasErrors('status');
        $this->assertSame(1, CustomOrderEvent::where('type', 'order_status_changed')->count());
    }

    private function openSession(Order $order): string
    {
        $this->asCustomer($order->user)->post(route('payment.stripe.checkout', $order->id))->assertRedirect();

        return 'cs_e2e_' . $this->stripe->counter;
    }

    /* ═════════════ every price comes from the stored records ═════════════ */

    public function test_forms_cannot_change_prices_totals_or_balances(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);

        // the request form: a posted price/budget is not a thing
        $this->submitRequest($customer, $r, $s, ['price' => '1', 'total' => '1', 'budget_min' => '1'])->assertRedirect();
        $this->assertNull($r->fresh()->budget_min);

        $quote = $this->quoteAndSend($admin, $r->fresh());
        $this->assertSame('20000.00', (string) $quote->price);

        // accept: junk money fields are ignored
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), [
            'agree' => '1', 'price' => '1', 'total' => '1', 'total_amount' => '1', 'upfront_amount' => '1',
        ])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $this->assertSame('20500.00', (string) $agreement->total_amount);
        $this->assertSame('10250.00', (string) $agreement->upfront_amount);

        // checkout: only payment_method counts
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), [
            'payment_method' => 'stripe', 'amount' => '1', 'total' => '1', 'price' => '1', 'delivery_fee' => '0',
            'payment_type' => 'full', 'delivery_option' => 'pickup', 'installment_duration' => '1',
        ])->assertRedirect();
        $order = Order::sole();
        $this->assertSame('20500.00', (string) $order->total_amount);
        $this->assertSame('10250.00', (string) $order->payment->amount);
        $this->assertSame('installment', $order->payment->payment_type);
        $this->assertSame('delivery', $order->delivery_option);

        // Stripe is asked for the stored amount, whatever the form says
        $this->asCustomer($customer)->post(route('payment.stripe.checkout', $order->id), ['amount' => '1', 'unit_amount' => '100']);
        $this->assertSame(1025000, $this->stripe->posts[0]['line_items'][0]['price_data']['unit_amount']);

        // Record payment: a posted balance never raises the limit
        $this->stripePaid($order, 'cs_e2e_1', 'evt_edge_2')->assertOk();
        $this->recordCash($admin, $order->fresh(), '10251.00', null, ['balance' => '999999', 'remaining' => '999999'])
            ->assertSessionHasErrors('payment');
        $this->assertSame('10250.00', (string) $order->fresh()->amount_paid);

        // admin status form cannot jump the map or smuggle values
        $this->adminSetStatus($admin, $order->fresh(), 'completed')->assertSessionHasErrors('status');
    }

    /* ═════════════ a tampered snapshot blocks the agreement and checkout ═════════════ */

    public function test_tampered_snapshot_blocks_agreement_and_checkout(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $quote = $this->quoteAndSend($admin, $r->fresh());
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);

        $snapshot = $agreement->terms_snapshot;
        $snapshot['money']['upfront'] = '1.00';
        $snapshot['money']['total'] = '1.00';
        DB::table('design_agreements')->where('id', $agreement->id)->update(['terms_snapshot' => json_encode($snapshot)]);

        $this->asCustomer($customer)->get(route('design.agreement', $agreement->id))->assertStatus(409);
        $this->asCustomer($customer)->get(route('design.agreement.print', $agreement->id))->assertStatus(409);
        $this->asCustomer($customer)->get(route('custom.checkout', $agreement->id))->assertRedirect(route('design.show', $r->id));
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])
            ->assertSessionHasErrors('quote');
        $this->asAdmin($admin)->get(route('admin.agreements.show', $agreement->id))->assertStatus(409);

        $this->assertSame(0, Order::count());
        $this->assertSame('accepted', $r->fresh()->pipeline_status);
        $this->assertGreaterThanOrEqual(1, Notification::where('user_id', $admin->id)->where('title', 'Agreement Integrity Check Failed')->count());
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Hash Mismatch');
    }
}
