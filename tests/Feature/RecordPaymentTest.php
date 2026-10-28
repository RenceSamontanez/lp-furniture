<?php

namespace Tests\Feature;

use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;
use App\Support\RecordPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 8 part B: Record Payment, the ledger on the admin order page,
 * the installment button, needs_review and access control.
 */
class RecordPaymentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(string $first = 'Rhea'): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => $first])->save();

        return $a;
    }

    /**
     * A catalog order. $total 1000; for 'installment' the downpayment is
     * 500 and the other 500 is split into a schedule (rows of 250).
     */
    private function order(?User $customer = null, string $type = 'full', string $method = 'onsite', array $over = []): Order
    {
        $customer ??= $this->makeUser();
        $order = $this->makeOrder($customer, array_merge(['total_amount' => 1000], $over));
        $payment = $this->makePayment($order, [
            'amount'         => $type === 'installment' ? 500 : 1000,
            'payment_method' => $method,
            'payment_type'   => $type,
        ]);

        if ($type === 'installment') {
            foreach ([1, 2] as $i) {
                InstallmentSchedule::create([
                    'payment_id' => $payment->id, 'order_id' => $order->id, 'user_id' => $customer->id,
                    'installment_no' => $i, 'amount_due' => 250, 'due_date' => now()->addDays(15 * $i), 'status' => 'pending',
                ]);
            }
        }

        return $order->fresh();
    }

    private function formToken(User $admin, Order $order): string
    {
        // The page issues a fresh token on every render (also when the form
        // itself is hidden, e.g. a paid-in-full order), kept in the session.
        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk();
        $tokens = session(\App\Http\Controllers\Admin\PaymentRecordController::TOKEN_SESSION_KEY);
        $this->assertNotEmpty($tokens, 'the page issued a one-time token');

        return end($tokens);
    }

    private function pay(User $admin, Order $order, array $data = [], bool $withToken = true)
    {
        $token = $withToken ? $this->formToken($admin, $order) : 'not-a-real-token';

        return $this->actingAs($admin, 'admin')
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.payments.store', $order->id), array_merge([
                'form_token' => $token,
                'amount'     => '100.00',
                'method'     => 'cash',
                'paid_on'    => now('Asia/Manila')->toDateString(),
            ], $data));
    }

    private function ledgerSum(Order $order): string
    {
        return number_format((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2, '.', '');
    }

    /* ───────────── validation ───────────── */

    public static function badAmounts(): array
    {
        return [
            'zero'        => ['0'],
            'zero cents'  => ['0.00'],
            'negative'    => ['-5'],
            'over balance' => ['1000.01'],
            'text'        => ['abc'],
            'three decimals' => ['10.123'],
            'empty'       => [''],
        ];
    }

    /** @dataProvider badAmounts */
    public function test_bad_amounts_are_refused(string $amount): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, ['amount' => $amount])->assertRedirect(route('admin.orders.show', $order->id));

        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame('0.00', (string) $order->fresh()->amount_paid);
    }

    public function test_future_date_is_refused_and_today_is_fine(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, ['paid_on' => now('Asia/Manila')->addDay()->toDateString()])
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, PaymentRecord::count());

        $this->pay($admin, $order, ['paid_on' => now('Asia/Manila')->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(1, PaymentRecord::count());
    }

    public function test_method_must_be_an_admin_method(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, ['method' => 'stripe'])->assertSessionHasErrors('payment');
        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_balance_is_computed_server_side(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        // a posted balance field is ignored; the 1000 total is what limits it
        $this->pay($admin, $order, ['amount' => '1000.00', 'remaining' => '5000', 'balance' => '5000'])
            ->assertSessionHasNoErrors();

        $this->assertSame('1000.00', $this->ledgerSum($order));
    }

    /* ───────────── status changes ───────────── */

    public function test_partial_then_full_payment_changes_payment_status(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, ['amount' => '400'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('400.00', (string) $order->amount_paid);
        $this->assertSame('partial', $order->payment->payment_status);
        $this->assertNull($order->payment->paid_at);

        // a different amount, so it is not read as a double submit
        $this->pay($admin, $order, ['amount' => '600'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('1000.00', (string) $order->amount_paid);
        $this->assertSame('paid', $order->payment->payment_status);
        $this->assertNotNull($order->payment->paid_at);

        // fully paid: nothing more can be recorded
        $this->pay($admin, $order, ['amount' => '1'])->assertSessionHasErrors('payment');
        $this->assertSame(2, PaymentRecord::count());
    }

    public function test_ledger_total_equals_amount_paid(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        foreach (['100', '250.50', '99.50'] as $a) {
            $this->pay($admin, $order, ['amount' => $a])->assertSessionHasNoErrors();
        }

        $this->assertSame($this->ledgerSum($order), (string) $order->fresh()->amount_paid);
        $this->assertSame('450.00', $this->ledgerSum($order));
    }

    public function test_pending_becomes_confirmed_only_when_first_required_amount_is_reached(): void
    {
        $admin = $this->admin();
        $order = $this->order(null, 'installment');   // downpayment 500 of 1000

        $this->pay($admin, $order, ['amount' => '499'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->order_status);

        $this->pay($admin, $order, ['amount' => '1'])->assertSessionHasNoErrors();
        $o = $order->fresh();
        $this->assertSame('confirmed', $o->order_status);
        $this->assertSame($admin->id, (int) $o->status_updated_by);
    }

    public function test_orders_past_pending_keep_their_status(): void
    {
        $admin = $this->admin();

        foreach (['in_production', 'ready', 'completed'] as $status) {
            $order = $this->order(null, 'full', 'onsite', ['order_status' => $status]);
            $this->pay($admin, $order, ['amount' => '1000'])->assertSessionHasNoErrors();
            $this->assertSame($status, $order->fresh()->order_status);
            $this->assertSame('paid', $order->fresh()->payment->payment_status);
        }
    }

    public function test_cancelled_orders_refuse_payments(): void
    {
        $admin = $this->admin();
        $order = $this->order(null, 'full', 'onsite', ['order_status' => 'cancelled']);

        $this->pay($admin, $order)->assertSessionHasErrors('payment');

        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_customized_order_uses_its_upfront_amount_and_notification_type(): void
    {
        $admin = $this->admin();
        $customer = $this->makeUser();
        $request = $this->makeRequest($customer);
        $quote = $this->makeQuote($request, ['sent_by' => $admin->id, 'status' => 'accepted']);
        $agreement = $this->makeAgreement($quote, $customer, $admin, ['agreement_number' => 'LP-A-2026-0042']);

        $order = $this->makeOrder($customer, ['total_amount' => 20000]);
        $order->forceFill(['design_agreement_id' => $agreement->id])->save();
        $this->makePayment($order, ['amount' => 10000, 'payment_method' => 'onsite', 'payment_type' => 'installment']);
        $order = $order->fresh();

        $this->pay($admin, $order, ['amount' => '9999.99'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->order_status);

        $n = Notification::where('user_id', $customer->id)->latest('id')->first();
        $this->assertSame('custom_order', $n->type);
        $this->assertStringContainsString('We received your payment of ₱9,999.99 for order', $n->message);
        $this->assertStringContainsString('The remaining balance is', $n->message);

        $this->pay($admin, $order, ['amount' => '0.01'])->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->order_status);
    }

    public function test_catalog_notification_type_and_wording(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, ['amount' => '1000'])->assertSessionHasNoErrors();

        // a payment that confirms the order is ONE combined notice (order tracking), not a payment notice plus a stage notice
        $n = Notification::where('user_id', $order->user_id)->sole();
        $this->assertSame('payment_confirmed', $n->type);
        $this->assertSame('Payment received and your order is confirmed.', $n->title);
        $this->assertSame(0, Notification::where('user_id', $order->user_id)->where('type', 'order_update')->count());
        $this->assertStringContainsString($order->order_number, $n->message);
        $this->assertSame(route('orders.show', $order->id), $n->url);
    }

    /* ───────────── installments ───────────── */

    public function test_installment_link_closes_the_row_only_when_covered(): void
    {
        $admin = $this->admin();
        $order = $this->order(null, 'installment');
        $row = $order->installments()->orderBy('installment_no')->first();

        $this->pay($admin, $order, ['amount' => '100', 'installment_id' => $row->id])->assertSessionHasNoErrors();
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame($row->id, (int) PaymentRecord::first()->installment_schedule_id);

        $this->pay($admin, $order, ['amount' => '150', 'installment_id' => $row->id])->assertSessionHasNoErrors();
        $this->assertSame('paid', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->paid_at);

        // an already-paid row can't be linked again
        $this->pay($admin, $order, ['amount' => '10', 'installment_id' => $row->id])->assertSessionHasErrors('payment');
    }

    public function test_installment_from_another_order_is_refused(): void
    {
        $admin = $this->admin();
        $order = $this->order(null, 'installment');
        $other = $this->order(null, 'installment');
        $foreign = $other->installments()->first();

        $this->pay($admin, $order, ['amount' => '250', 'installment_id' => $foreign->id])->assertSessionHasErrors('payment');

        $this->assertSame(0, PaymentRecord::count());
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_installment_mark_paid_button_goes_through_the_ledger(): void
    {
        $admin = $this->admin('Rhea');
        $order = $this->order(null, 'installment');
        $row = $order->installments()->orderBy('installment_no')->first();

        $this->actingAs($admin, 'admin')->post(route('admin.installments.markPaid', $row->id))->assertSessionHasNoErrors();

        $rec = PaymentRecord::sole();
        $this->assertSame('250.00', (string) $rec->amount);
        $this->assertSame('cash', $rec->method);
        $this->assertSame($admin->id, (int) $rec->recorded_by);
        $this->assertSame($row->id, (int) $rec->installment_schedule_id);
        $this->assertStringContainsString('Installment #1', $rec->note);
        $this->assertSame('paid', $row->fresh()->status);
        $this->assertSame('250.00', (string) $order->fresh()->amount_paid);
        $this->assertSame('partial', $order->fresh()->payment->payment_status);

        // clicking it again is refused, not recorded twice
        $this->actingAs($admin, 'admin')->post(route('admin.installments.markPaid', $row->id))->assertSessionHasErrors('payment');
        $this->assertSame(1, PaymentRecord::count());
    }

    public function test_second_installment_of_equal_amount_is_not_a_double_submit(): void
    {
        $admin = $this->admin();
        $order = $this->order(null, 'installment');
        [$a, $b] = $order->installments()->orderBy('installment_no')->get()->all();

        $this->actingAs($admin, 'admin')->post(route('admin.installments.markPaid', $a->id))->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.installments.markPaid', $b->id))->assertSessionHasNoErrors();

        $this->assertSame(2, PaymentRecord::count());
        $this->assertSame('500.00', (string) $order->fresh()->amount_paid);
    }

    /* ───────────── double submit ───────────── */

    public function test_one_time_token_blocks_a_resubmit(): void
    {
        $admin = $this->admin();
        $order = $this->order();
        $token = $this->formToken($admin, $order);
        $payload = ['form_token' => $token, 'amount' => '100', 'method' => 'cash', 'paid_on' => now('Asia/Manila')->toDateString()];

        $this->actingAs($admin, 'admin')->post(route('admin.orders.payments.store', $order->id), $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.orders.payments.store', $order->id), $payload)->assertSessionHasErrors('payment');

        $this->assertSame(1, PaymentRecord::count());
    }

    public function test_made_up_token_is_refused(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order, [], false)->assertSessionHasErrors('payment');

        $this->assertSame(0, PaymentRecord::count());
    }

    public function test_same_payment_from_a_second_tab_inside_the_window_is_refused(): void
    {
        $admin = $this->admin();
        $order = $this->order();

        $this->pay($admin, $order)->assertSessionHasNoErrors();        // tab 1, its own token
        $this->pay($admin, $order)->assertSessionHasErrors('payment'); // tab 2, fresh token, same payment

        $this->assertSame(1, PaymentRecord::count());
    }

    /* ───────────── ledger uniqueness / review flow ───────────── */

    public function test_unique_gateway_reference_blocks_a_second_row(): void
    {
        $order = $this->order(null, 'full', 'stripe');
        $attrs = ['order_id' => $order->id, 'payment_id' => $order->payment->id, 'amount' => 10,
            'method' => 'stripe', 'gateway_reference' => 'pi_same', 'paid_on' => now()->toDateString()];

        $this->assertNotNull(\App\Support\PaymentLedger::insert($order, $order->payment, $attrs));
        $this->assertNull(\App\Support\PaymentLedger::insert($order, $order->payment, $attrs));
        $this->assertSame(1, PaymentRecord::count());
    }

    public function test_needs_review_flag_and_mark_reviewed(): void
    {
        $admin = $this->admin('Rhea');
        $order = $this->order();
        $order->forceFill(['needs_review' => true, 'review_reason' => 'duplicate payment, check for refund'])->save();

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Needs review')
            ->assertSee('duplicate payment, check for refund')
            ->assertSee('Mark reviewed')
            ->assertSee('Refunds are handled outside the system');

        $this->actingAs($admin, 'admin')->post(route('admin.orders.review', $order->id))->assertSessionHasNoErrors();

        $o = $order->fresh();
        $this->assertFalse((bool) $o->needs_review);
        $this->assertSame($admin->id, (int) $o->reviewed_by);
        $this->assertNotNull($o->reviewed_at);
        $this->assertSame('pending', $o->order_status, 'reviewing never changes the status');

        $this->actingAs($admin, 'admin')->post(route('admin.orders.review', $order->id))->assertSessionHasErrors('payment');
    }

    public function test_order_page_shows_ledger_totals_and_unrecorded_label(): void
    {
        $admin = $this->admin('Rhea');
        $order = $this->order();   // onsite (cash), nothing recorded

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))
            ->assertOk()->assertSee('Unrecorded')->assertSee('Record payment');

        $this->pay($admin, $order, ['amount' => '300', 'note' => 'Paid at the shop'])->assertSessionHasNoErrors();

        $page = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id));
        $page->assertOk()
            ->assertDontSee('Unrecorded')
            ->assertSee('Paid at the shop')
            ->assertSee('Cash')
            ->assertSee('Rhea')
            ->assertSee('₱300.00')
            ->assertSee('₱700.00');
    }

    /* ───────────── access control ───────────── */

    public static function accessMatrix(): array
    {
        $cases = [];
        foreach (['guest', 'customer_web', 'customer_on_admin_guard'] as $who) {
            foreach (['store', 'review', 'markPaid'] as $route) {
                $cases["$who → $route"] = [$who, $route];
            }
        }

        return $cases;
    }

    /** @dataProvider accessMatrix */
    public function test_non_admins_cannot_record_or_review(string $who, string $route): void
    {
        $order = $this->order(null, 'installment');
        $order->forceFill(['needs_review' => true, 'review_reason' => 'x'])->save();
        $row = $order->installments()->first();

        $visitor = $this->makeUser();
        match ($who) {
            'customer_web'            => $this->actingAs($visitor),
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            default                   => null,
        };
        Auth::shouldUse('web');

        $resp = match ($route) {
            'store'    => $this->post(route('admin.orders.payments.store', $order->id), ['form_token' => 'x', 'amount' => '10', 'method' => 'cash', 'paid_on' => now()->toDateString()]),
            'review'   => $this->post(route('admin.orders.review', $order->id)),
            'markPaid' => $this->post(route('admin.installments.markPaid', $row->id)),
        };

        $resp->assertRedirect(route('admin.login'));
        $this->assertSame(0, PaymentRecord::count());
        $this->assertTrue((bool) $order->fresh()->needs_review);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_customer_cannot_see_another_customers_payments(): void
    {
        $admin = $this->admin();
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $order = $this->order($alice);

        $this->pay($admin, $order, ['amount' => '321', 'note' => 'ALICE-ONLY-NOTE'])->assertSessionHasNoErrors();
        Auth::shouldUse('web');

        // Bob gets nothing about Alice's order or its notification
        $this->actingAs($bob)->get(route('orders.show', $order->id))->assertNotFound();
        $this->actingAs($bob)->get(route('notifications.index'))->assertOk()->assertDontSee('₱321.00');

        // Alice sees her own payment notification
        $this->actingAs($alice)->get(route('notifications.index'))->assertOk()->assertSee('₱321.00');

        // and the customer-facing pages never expose ledger notes or the admin ledger
        $this->actingAs($alice)->get(route('orders.show', $order->id))->assertOk()->assertDontSee('ALICE-ONLY-NOTE');
    }
}
