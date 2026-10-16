<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 12: correctness fixes found in the Stage 11 end-to-end run.
 */
class Stage12FixesTest extends TestCase
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

    /** Request taken through the real routes to an unpaid order. */
    private function flowOrder(array $quote = [], ?User $customer = null, string $method = 'stripe'): array
    {
        $admin = User::where('role', 'admin')->first() ?? $this->flowAdmin();
        $customer ??= $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);

        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $q = $this->quoteAndSend($admin, $r->fresh(), $quote);
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => $method])->assertRedirect();

        return [$customer, $admin, $r->fresh(), $agreement, Order::where('design_agreement_id', $agreement->id)->sole()];
    }

    private function catalogOrder(string $type = 'full', string $method = 'stripe'): Order
    {
        $user = $this->flowCustomer('Cat');
        $product = Product::create(['product_name' => 'Chair', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
        $this->asCustomer($user)->post(route('order.place'), array_filter([
            'delivery_option' => 'pickup', 'payment_type' => $type, 'payment_method' => $method,
            'installment_duration' => $type === 'installment' ? 2 : null,
            'installment_frequency' => $type === 'installment' ? 15 : null,
        ], fn ($v) => $v !== null))->assertRedirect();

        return Order::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function ledger(Order $o): float
    {
        return (float) PaymentRecord::where('order_id', $o->id)->sum('amount');
    }

    /* ═════════ 1. completion guard ═════════ */

    public function test_customized_order_cannot_complete_unless_fully_paid(): void
    {
        [, $admin, $r, , $order] = $this->flowOrder();
        $this->stripePaid($order, $this->openSession($order), 'evt_s12_1')->assertOk();      // deposit 10,250 of 20,500
        foreach (['in_production', 'ready'] as $s) {
            $this->adminSetStatus($admin, $order->fresh(), $s)->assertSessionHasNoErrors();
        }
        $this->assertLessThan(20500, $this->ledger($order));

        $this->adminSetStatus($admin, $order->fresh(), 'completed')->assertSessionHasErrors('status');
        $this->assertSame('ready', $order->fresh()->order_status);
        $this->assertSame(0, CustomOrderEvent::where('type', 'order_status_changed')->where('data->to', 'completed')->count());
    }

    public function test_completion_override_needs_a_reason_and_is_recorded(): void
    {
        [, $admin, $r, , $order] = $this->flowOrder();
        $this->stripePaid($order, $this->openSession($order), 'evt_s12_2')->assertOk();
        foreach (['in_production', 'ready'] as $s) {
            $this->adminSetStatus($admin, $order->fresh(), $s)->assertSessionHasNoErrors();
        }

        $this->asAdmin($admin)->post(route('admin.orders.updateStatus', $order->id), [
            'order_status' => 'completed', 'override_reason' => 'Balance waived by the owner for a repeat customer',
        ])->assertSessionHasNoErrors();

        $o = $order->fresh();
        $this->assertSame('completed', $o->order_status);
        $this->assertStringContainsString('Balance waived by the owner', $o->notes);
        $e = CustomOrderEvent::where('type', 'order_status_changed')->where('data->to', 'completed')->sole();
        $this->assertSame('Balance waived by the owner for a repeat customer', $e->data['completion_override_reason']);
        $this->assertSame('ordered', $r->fresh()->pipeline_status);
    }

    public function test_fully_paid_customized_order_completes_without_a_reason(): void
    {
        [, $admin, , , $order] = $this->flowOrder();
        $this->stripePaid($order, $this->openSession($order), 'evt_s12_3')->assertOk();
        [$i1, $i2] = $order->installments()->orderBy('installment_no')->get()->all();
        $this->recordCash($admin, $order->fresh(), '5125.00', $i1->id)->assertSessionHasNoErrors();
        $this->recordCash($admin, $order->fresh(), '5125.00', $i2->id)->assertSessionHasNoErrors();

        foreach (['in_production', 'ready', 'completed'] as $s) {
            $this->adminSetStatus($admin, $order->fresh(), $s)->assertSessionHasNoErrors();
        }
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    public function test_catalog_orders_can_still_complete_without_payment(): void
    {
        $admin = $this->flowAdmin();
        $order = $this->catalogOrder();

        $this->adminSetStatus($admin, $order, 'completed')->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->order_status);
    }

    private function openSession(Order $order): string
    {
        $this->asCustomer($order->user)->post(route('payment.stripe.checkout', $order->id))->assertRedirect();

        return 'cs_e2e_' . $this->stripe->counter;
    }

    /* ═════════ 2. payment page wording ═════════ */

    public function test_payment_page_states_the_real_deposit_percentage(): void
    {
        [$c, , , , $order] = $this->flowOrder(['deposit_amount' => '12300']);   // 60% of 20,500

        foreach (['payment.stripe', 'payment.gcash.paymongo'] as $route) {
            $this->asCustomer($c)->get(route($route, $order->id))->assertOk()
                ->assertSee("You're paying the downpayment of ₱12,300.00 (60% of the total)", false)
                ->assertDontSee('50% downpayment');
        }
    }

    public function test_payment_page_for_a_full_payment_says_full_payment(): void
    {
        [$c, , , , $order] = $this->flowOrder([
            'payment_terms' => 'full', 'deposit_amount' => '', 'installment_duration_months' => '', 'installment_frequency_days' => '',
        ]);

        $html = $this->asCustomer($c)->get(route('payment.stripe', $order->id))->assertOk()->getContent();

        $this->assertStringContainsString('full payment of ₱20,500.00', $html);
        $this->assertStringNotContainsString('50%', $html);
    }

    public function test_catalog_payment_wording_is_unchanged(): void
    {
        $order = $this->catalogOrder('installment');

        $this->asCustomer($order->user)->get(route('payment.stripe', $order->id))->assertOk()
            ->assertSee("You're paying the 50% downpayment now", false)
            ->assertSee('Downpayment Due (50%)');
    }

    /* ═════════ 3. customer order page ═════════ */

    public function test_customized_order_page_wording_and_no_change_method(): void
    {
        [$c, , $r, , $order] = $this->flowOrder();
        $due = $r->payment_due_at->timezone('Asia/Manila')->format('M d, Y');

        $this->asCustomer($c)->get(route('orders.show', $order->id))->assertOk()
            ->assertDontSee('Your items are being held')
            ->assertDontSee('Change Payment Method')
            ->assertSee('Your agreement is reserved until ' . $due);
    }

    public function test_catalog_order_page_keeps_its_wording_and_change_method(): void
    {
        $order = $this->catalogOrder();

        $this->asCustomer($order->user)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee('Your items are being held')
            ->assertSee('Change Payment Method');
    }

    /* ═════════ 4. admin order page ═════════ */

    public function test_admin_order_page_shows_real_item_method_status_and_production(): void
    {
        [, $admin, , , $order] = $this->flowOrder();

        // unpaid
        $page = $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk();
        $page->assertSee('Dining Table - Ana')->assertDontSee('N/A')->assertSee('Unpaid');

        // half paid in cash
        $this->recordCash($admin, $order->fresh(), '10250.00')->assertSessionHasNoErrors();
        $page = $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('Partially paid', $html);
        $this->assertMatchesRegularExpression('/Method<\/span>\s*<span[^>]*>\s*Cash\s*</', $html);
        $this->assertDoesNotMatchRegularExpression('/Method<\/span>\s*<span[^>]*>\s*stripe\s*</i', $html);

        // production status mirrors the order status, including after Completed
        foreach (['in_production', 'ready'] as $s) {
            $this->adminSetStatus($admin, $order->fresh(), $s)->assertSessionHasNoErrors();
        }
        $this->recordCash($admin, $order->fresh(), '10250.00', null, ['method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->adminSetStatus($admin, $order->fresh(), 'completed')->assertSessionHasNoErrors();

        $html = $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk()->getContent();
        $this->assertStringContainsString('Paid in full', $html);
        $this->assertMatchesRegularExpression('/Production Status<\/span>\s*<span[^>]*>\s*completed\s*</i', $html);
    }

    public function test_admin_catalog_order_page_is_unchanged(): void
    {
        $admin = $this->flowAdmin();
        $order = $this->catalogOrder();

        $html = $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk()->getContent();
        $this->assertStringContainsString('Chair', $html);
        $this->assertStringNotContainsString('Partially paid', $html);
        $this->assertMatchesRegularExpression('/Method<\/span>\s*<span[^>]*>\s*stripe\s*</i', $html);
    }

    /* ═════════ 5. "Order confirmed" event ═════════ */

    public function test_payment_driven_confirmation_writes_one_event_shown_on_both_timelines(): void
    {
        [$c, $admin, $r, , $order] = $this->flowOrder();
        $this->assertSame(0, CustomOrderEvent::where('type', 'order_confirmed')->count());

        $sid = $this->openSession($order);
        $this->stripePaid($order, $sid, 'evt_s12_5')->assertOk();
        $this->stripePaid($order, $sid, 'evt_s12_5b')->assertOk();          // repeat: no second event

        $e = CustomOrderEvent::where('type', 'order_confirmed')->sole();
        $this->assertSame($r->id, (int) $e->request_id);
        $this->assertSame($order->id, (int) $e->order_id);
        $this->assertSame('system', $e->actor_type);
        $this->assertSame('confirmed', $order->fresh()->order_status);

        $this->asCustomer($c)->get(route('design.show', $r->id))->assertOk()->assertSee('Your order was confirmed');
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Order confirmed by payment');
    }

    public function test_admin_recorded_payment_that_confirms_also_writes_the_event(): void
    {
        [, $admin, $r, , $order] = $this->flowOrder();

        $this->recordCash($admin, $order, '10250.00')->assertSessionHasNoErrors();

        $e = CustomOrderEvent::where('type', 'order_confirmed')->sole();
        $this->assertSame('admin', $e->actor_type);
        $this->assertSame($admin->id, (int) $e->actor_id);
    }

    public function test_catalog_confirmation_writes_no_custom_order_event(): void
    {
        $order = $this->catalogOrder();
        $session = [
            'object' => 'checkout.session', 'id' => 'cs_cat_s12', 'payment_status' => 'paid',
            'amount_total' => (int) round($order->payment->amount * 100), 'payment_intent' => 'pi_cat_s12',
            'customer' => null, 'metadata' => ['order_id' => (string) $order->id],
        ];
        $this->stripe->sessions['cs_cat_s12'] = $session;
        $this->stripePaid($order, 'cs_cat_s12', 'evt_cat_s12')->assertOk();

        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(0, CustomOrderEvent::count());
    }

    /* ═════════ 6. admin custom order page copy ═════════ */

    public function test_ordered_request_shows_neutral_quote_line_and_one_completed_label(): void
    {
        [, $admin, $r, , $order] = $this->flowOrder();
        $this->recordCash($admin, $order, '10250.00')->assertSessionHasNoErrors();
        $this->recordCash($admin, $order->fresh(), '10250.00', null, ['method' => 'bank_transfer'])->assertSessionHasNoErrors();
        foreach (['in_production', 'ready', 'completed'] as $s) {
            $this->adminSetStatus($admin, $order->fresh(), $s)->assertSessionHasNoErrors();
        }

        $html = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('A quote can only be started once the request is in contact', $html);
        $this->assertStringContainsString('Quote accepted, order created', $html);
        $this->assertSame(1, preg_match_all('/>\s*completed\s*</i', $html), 'the Completed marker appears once');
    }

    public function test_timeline_actor_names_are_consistent(): void
    {
        [, $admin, $r, , $order] = $this->flowOrder();
        $this->recordCash($admin, $order, '10250.00')->assertSessionHasNoErrors();
        $this->adminSetStatus($admin, $order->fresh(), 'in_production')->assertSessionHasNoErrors();

        $html = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();
        $timeline = substr($html, strpos($html, 'class="co-tl"'));
        $timeline = substr($timeline, 0, strpos($timeline, '</ul>'));

        $this->assertStringContainsString('Rhea Santos', $timeline);
        $this->assertSame(0, preg_match_all('/· Rhea\s*</', $timeline), 'no entry may use only the first name');
        $this->assertGreaterThanOrEqual(5, substr_count($timeline, '· Rhea Santos'));
    }

    /* ═════════ 7. call window values ═════════ */

    public static function callWindows(): array
    {
        return [
            'morning'   => ['morning_8_12', 'Morning, 8 AM to 12 PM'],
            'afternoon' => ['afternoon_12_5', 'Afternoon, 12 PM to 5 PM'],
            'anytime'   => ['anytime', 'Anytime, 8 AM to 5 PM'],
        ];
    }

    /** @dataProvider callWindows */
    public function test_each_call_window_is_saved_and_shown_as_chosen(string $value, string $label): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);

        // the form itself offers exactly this value on exactly this option
        $form = $this->asCustomer($customer)->get(route('design.sendForm', [$r->id, 'suggestion' => $s->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="preferred_call_window" value="' . $value . '"/', $form);

        $this->submitRequest($customer, $r, $s, ['preferred_call_window' => $value])->assertSessionHasNoErrors();

        $this->assertSame($value, $r->fresh()->preferred_call_window);
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('during ' . $label);
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee($label);
    }
}
