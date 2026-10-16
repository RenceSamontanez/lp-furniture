<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 9 part C: admin side of customized orders. The forward-only
 * status map, cancel rules, badges and links, and access control.
 */
class CustomOrderStatusTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const STATUSES = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'completed', 'cancelled'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    /**
     * A customized order built straight in the database (no endpoint),
     * with its request in pipeline 'ordered'. Deposit 10,000 of 20,000.
     */
    private function customized(string $status = 'pending', bool $upfrontPaid = false, ?User $customer = null): Order
    {
        $customer ??= $this->customer();
        $admin = User::where('role', 'admin')->first() ?? $this->admin();

        $r = CustomDesignRequest::create([
            'user_id' => $customer->id, 'title' => 'Dining Table - ' . $customer->first_name,
            'furniture_type' => 'Dining Table', 'description' => 'A table', 'status' => 'approved',
            'pipeline_status' => 'ordered', 'payment_due_at' => now()->addDay(),
        ]);
        $q = $this->makeQuote($r, ['status' => 'accepted', 'sent_by' => $admin->id]);
        $snapshot = ['design' => ['title' => $r->title], 'production' => ['days' => 20], 'money' => ['total' => '20000.00']];
        $a = $this->makeAgreement($q, $customer, $admin, [
            'agreement_number' => 'LP-A-2026-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'terms_snapshot'   => $snapshot,
            'snapshot_sha256'  => DesignAgreement::hashSnapshot($snapshot),
        ]);

        $order = $this->makeOrder($customer, ['total_amount' => 20000, 'order_status' => $status]);
        $order->forceFill(['design_agreement_id' => $a->id, 'design_request_id' => $r->id])->save();
        $payment = $this->makePayment($order, ['amount' => 10000, 'payment_method' => 'stripe', 'payment_type' => 'installment']);

        if ($upfrontPaid) {
            PaymentRecord::create([
                'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 10000,
                'method' => 'cash', 'paid_on' => now()->toDateString(), 'recorded_by' => $admin->id,
            ]);
            $order->forceFill(['amount_paid' => 10000])->save();
        }

        return $order->fresh();
    }

    /** Everything received: the ledger and amount_paid equal the total. */
    private function payInFull(Order $order): Order
    {
        $already = (float) PaymentRecord::where('order_id', $order->id)->sum('amount');
        if ($already < (float) $order->total_amount) {
            PaymentRecord::create([
                'order_id' => $order->id, 'payment_id' => $order->payment->id,
                'amount' => number_format((float) $order->total_amount - $already, 2, '.', ''),
                'method' => 'cash', 'paid_on' => now()->toDateString(), 'recorded_by' => User::where('role', 'admin')->value('id'),
            ]);
        }
        $order->forceFill(['amount_paid' => $order->total_amount])->save();

        return $order->fresh();
    }

    private function catalog(string $status = 'pending'): Order
    {
        $order = $this->makeOrder($this->customer('Cat'), ['order_status' => $status]);
        $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'onsite']);

        return $order->fresh();
    }

    private function setStatus(User $admin, Order $order, string $to, ?string $reason = null)
    {
        return $this->actingAs($admin, 'admin')
            ->from(route('admin.orders.show', $order->id))
            ->post(route('admin.orders.updateStatus', $order->id), array_filter([
                'order_status' => $to, 'cancel_reason' => $reason,
            ], fn ($v) => $v !== null));
    }

    /* ───────────── forward-only map ───────────── */

    public static function everyMove(): array
    {
        $allowed = [
            'pending'          => ['confirmed'],
            'confirmed'        => ['in_production'],
            'in_production'    => ['ready', 'out_for_delivery'],
            'ready'            => ['out_for_delivery', 'completed'],
            'out_for_delivery' => ['completed'],
            'completed'        => [],
            'cancelled'        => [],
        ];

        $cases = [];
        foreach (self::STATUSES as $from) {
            foreach (self::STATUSES as $to) {
                $ok = in_array($to, $allowed[$from], true);
                // an unpaid order may be cancelled from any open status
                if ($to === 'cancelled' && !in_array($from, ['completed', 'cancelled'], true)) {
                    $ok = true;
                }
                $cases["$from → $to"] = [$from, $to, $ok];
            }
        }

        return $cases;
    }

    /** @dataProvider everyMove */
    public function test_every_status_move(string $from, string $to, bool $allowed): void
    {
        $admin = $this->admin();
        $order = $this->customized($from, true);   // upfront in the ledger so the confirm gate is not the reason
        if ($to === 'completed') {
            $order = $this->payInFull($order);       // completion needs the full amount; refusal for that has its own tests
        }
        if ($to === 'cancelled') {
            // "nothing paid" is covered by its own tests: here no reason is given, so use an unpaid order
            $order = $this->customized($from, false);
        }

        $res = $this->setStatus($admin, $order, $to);

        if ($allowed) {
            $res->assertSessionHasNoErrors();
            $this->assertSame($to, $order->fresh()->order_status);
            $this->assertSame($admin->id, (int) $order->fresh()->status_updated_by);
        } else {
            $res->assertSessionHasErrors('status');
            $this->assertSame($from, $order->fresh()->order_status);
        }
    }

    public function test_manual_confirm_needs_the_upfront_amount_in_the_ledger(): void
    {
        $admin = $this->admin();
        $order = $this->customized('pending', false);

        $this->setStatus($admin, $order, 'confirmed')->assertSessionHasErrors('status');
        $this->assertSame('pending', $order->fresh()->order_status);

        $paid = $this->customized('pending', true);
        $this->setStatus($admin, $paid, 'confirmed')->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $paid->fresh()->order_status);
    }

    public function test_status_change_notifies_the_customer_and_writes_an_event(): void
    {
        $admin = $this->admin();
        $order = $this->customized('confirmed', true);

        $this->setStatus($admin, $order, 'in_production')->assertSessionHasNoErrors();

        $n = Notification::where('user_id', $order->user_id)->sole();
        $this->assertSame('order_update', $n->type);
        $this->assertSame('Your order is now in production.', $n->message);

        $e = CustomOrderEvent::where('type', 'order_status_changed')->sole();
        $this->assertSame($order->id, (int) $e->order_id);
        $this->assertSame('in_production', $e->data['to']);
    }

    public function test_completing_keeps_the_request_ordered_and_shows_completed(): void
    {
        $admin = $this->admin();
        $order = $this->payInFull($this->customized('ready', true));

        $this->setStatus($admin, $order, 'completed')->assertSessionHasNoErrors();

        $this->assertSame('ordered', CustomDesignRequest::find($order->design_request_id)->pipeline_status);

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))
            ->assertOk()->assertSee('completed');
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $order->design_request_id))
            ->assertOk()->assertSee('completed')->assertSee($order->order_number);
    }

    /* ───────────── cancel rules ───────────── */

    public function test_cancel_with_nothing_paid_needs_no_reason(): void
    {
        $admin = $this->admin();
        $order = $this->customized('pending', false);

        $this->setStatus($admin, $order, 'cancelled')->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('cancelled', CustomDesignRequest::find($order->design_request_id)->pipeline_status);
        $this->assertSame('Your order has been cancelled.', Notification::where('user_id', $order->user_id)->sole()->message);
    }

    public function test_cancel_after_payment_needs_a_reason_which_is_kept(): void
    {
        $admin = $this->admin();
        $order = $this->customized('confirmed', true);

        $this->setStatus($admin, $order, 'cancelled')->assertSessionHasErrors('status');
        $this->assertSame('confirmed', $order->fresh()->order_status);

        $this->setStatus($admin, $order, 'cancelled', 'Customer asked to cancel; refund done by bank transfer')
            ->assertSessionHasNoErrors();

        $o = $order->fresh();
        $this->assertSame('cancelled', $o->order_status);
        $this->assertStringContainsString('refund done by bank transfer', $o->notes);
        $this->assertSame('cancelled', CustomDesignRequest::find($o->design_request_id)->pipeline_status);
        $e = CustomOrderEvent::where('type', 'order_status_changed')->sole();
        $this->assertSame('Customer asked to cancel; refund done by bank transfer', $e->data['reason']);
    }

    public function test_completed_and_cancelled_orders_cannot_be_cancelled_or_reopened(): void
    {
        $admin = $this->admin();

        foreach (['completed', 'cancelled'] as $status) {
            $order = $this->customized($status, true);
            $this->setStatus($admin, $order, 'cancelled', 'reason')->assertSessionHasErrors('status');
            $this->setStatus($admin, $order, 'pending')->assertSessionHasErrors('status');
            $this->assertSame($status, $order->fresh()->order_status);
        }
    }

    /* ───────────── catalog orders are unchanged ───────────── */

    public function test_catalog_orders_keep_the_free_form_dropdown_and_behaviour(): void
    {
        $admin = $this->admin();
        $order = $this->catalog('pending');

        $page = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk();
        foreach (self::STATUSES as $s) {
            $page->assertSee('value="' . $s . '"', false);
        }
        $page->assertDontSee('Customized Order')->assertDontSee('only move forward');

        // any jump is still accepted, including backwards
        $this->setStatus($admin, $order, 'completed')->assertSessionHasNoErrors();
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->setStatus($admin, $order, 'pending')->assertSessionHasNoErrors();
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->setStatus($admin, $order, 'cancelled')->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame(0, CustomOrderEvent::count());
    }

    public function test_customized_dropdown_offers_only_forward_targets_and_cancel(): void
    {
        $admin = $this->admin();
        $order = $this->customized('in_production', true);

        $page = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk();
        $page->assertSee('value="ready"', false)->assertSee('value="out_for_delivery"', false)->assertSee('value="cancelled"', false);
        $page->assertDontSee('value="pending"', false)->assertDontSee('value="confirmed"', false)->assertDontSee('value="completed"', false);
    }

    /* ───────────── badges, links, flags ───────────── */

    public function test_list_and_detail_show_badge_agreement_and_links(): void
    {
        $admin = $this->admin();
        $order = $this->customized('confirmed', true);
        $order->forceFill(['expected_ready_date' => '2026-11-20'])->save();
        $order->refresh();
        $cat = $this->catalog();

        $list = $this->actingAs($admin, 'admin')->get(route('admin.orders.index'))->assertOk();
        $list->assertSee('Customized');
        $this->assertSame(1, substr_count($list->getContent(), 'admin-pill confirmed" style="font-size:10px;margin-left:6px;">Customized'));

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))
            ->assertOk()
            ->assertSee('Customized Order')
            ->assertSee($order->agreement->agreement_number)
            ->assertSee(route('admin.agreements.show', $order->design_agreement_id), false)
            ->assertSee(route('admin.custom-orders.show', $order->design_request_id), false)
            ->assertSee('about Nov 20, 2026')
            ->assertSee('Record payment');   // the ledger panel works for these orders

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $order->design_request_id))
            ->assertOk()->assertSee($order->order_number)->assertSee(route('admin.orders.show', $order->id), false);
    }

    public function test_overdue_unpaid_flag_shows_only_when_it_applies(): void
    {
        $admin = $this->admin();
        $order = $this->customized('pending', false);

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()->assertDontSee('Overdue, unpaid');

        CustomDesignRequest::find($order->design_request_id)->forceFill(['payment_due_at' => now()->subHour()])->save();

        $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()->assertSee('Overdue, unpaid');
        $this->actingAs($admin, 'admin')->get(route('admin.orders.index'))->assertOk()->assertSee('Overdue, unpaid');
        $this->assertSame('pending', $order->fresh()->order_status, 'never cancelled automatically');

        // once something is paid it is no longer overdue-unpaid
        $paid = $this->customized('pending', true);
        CustomDesignRequest::find($paid->design_request_id)->forceFill(['payment_due_at' => now()->subHour()])->save();
        $this->assertFalse($paid->fresh()->isOverdueUnpaid());
    }

    public function test_record_payment_panel_works_on_a_customized_order(): void
    {
        $admin = $this->admin();
        $order = $this->customized('pending', false);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->getContent();
        preg_match('/name="form_token" value="([a-f0-9]+)"/', $html, $m);

        $this->actingAs($admin, 'admin')->post(route('admin.orders.payments.store', $order->id), [
            'form_token' => $m[1], 'amount' => '10000', 'method' => 'cash', 'paid_on' => now('Asia/Manila')->toDateString(),
        ])->assertSessionHasNoErrors();

        $o = $order->fresh();
        $this->assertSame('confirmed', $o->order_status);
        $this->assertNotNull($o->expected_ready_date, 'ready date is set when an admin-recorded payment confirms the order');
        $this->assertSame('10000.00', (string) $o->amount_paid);
    }

    /* ───────────── access ───────────── */

    public static function accessMatrix(): array
    {
        $cases = [];
        foreach (['guest', 'customer_web', 'customer_on_admin_guard'] as $who) {
            foreach (['status', 'show'] as $route) {
                $cases["$who → $route"] = [$who, $route];
            }
        }

        return $cases;
    }

    /** @dataProvider accessMatrix */
    public function test_non_admins_cannot_touch_customized_orders(string $who, string $route): void
    {
        $order = $this->customized('confirmed', true);
        $visitor = $this->customer('Visitor');

        match ($who) {
            'customer_web'            => $this->actingAs($visitor),
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            default                   => null,
        };
        Auth::shouldUse('web');

        $resp = $route === 'status'
            ? $this->post(route('admin.orders.updateStatus', $order->id), ['order_status' => 'in_production'])
            : $this->get(route('admin.orders.show', $order->id));

        $resp->assertRedirect(route('admin.login'));
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(0, CustomOrderEvent::count());
    }

    public function test_customers_cannot_see_each_others_customized_orders(): void
    {
        $alice = $this->customer('Alice');
        $bob = $this->customer('Bob');
        $order = $this->customized('confirmed', true, $alice);

        $this->actingAs($bob)->get(route('orders.show', $order->id))->assertNotFound();
        $this->actingAs($bob)->get(route('design.show', $order->design_request_id))->assertNotFound();
        $this->actingAs($bob)->get(route('custom.checkout', $order->design_agreement_id))->assertNotFound();
        $this->actingAs($bob)->get(route('design.agreement', $order->design_agreement_id))->assertNotFound();
        $this->actingAs($bob)->get(route('orders.index'))->assertOk()->assertDontSee($order->order_number);

        $this->actingAs($alice)->get(route('orders.show', $order->id))
            ->assertOk()->assertSee($order->order_number)->assertSee('Custom order agreement');
        $this->actingAs($alice)->get(route('orders.index'))->assertOk()->assertSee($order->order_number);
    }
}
