<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 10: customer and admin timelines, and the catalog / customized
 * split in reports and the orders list.
 */
class CustomOrderTimelineTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const SECRET_NOTE = 'INTERNAL-CALL-NOTE-do-not-show';
    private const SECRET_UA = 'SecretBrowser/9.9 (internal)';

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

    /** A request with a full set of events, built through the real pipeline where possible. */
    private function story(): array
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $r = CustomDesignRequest::create([
            'user_id' => $customer->id, 'title' => 'Dining Table - Ana', 'furniture_type' => 'Dining Table',
            'description' => 'A table', 'status' => 'approved', 'pipeline_status' => 'submitted',
            'contact_phone' => '09171234567', 'contact_email' => 'a@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact', 'submitted_at' => now(),
        ]);
        CustomOrderEvent::create(['request_id' => $r->id, 'type' => 'submitted', 'actor_type' => 'customer',
            'actor_id' => $customer->id, 'occurred_at' => now()->subHours(6)]);

        $pipe = app(\App\Support\CustomOrderPipeline::class);
        $pipe->logCall($r->id, $admin, false, self::SECRET_NOTE . ' no answer', 0);
        $pipe->markUnreachable($r->id, $admin, 1);
        $pipe->logCall($r->id, $admin, true, self::SECRET_NOTE . ' answered, wants narra', 2);

        $draft = $pipe->createDraft($r->id, $admin, [
            'final_description' => 'Dining table', 'width_cm' => 180, 'depth_cm' => 90, 'height_cm' => 75,
            'material' => 'Narra', 'finish' => '', 'modifications' => '', 'price' => 20000,
            'delivery_option' => 'pickup', 'delivery_fee' => '', 'delivery_address' => '', 'production_days' => 20,
            'payment_terms' => 'full', 'deposit_amount' => '', 'installment_duration_months' => '',
            'installment_frequency_days' => '', 'call_notes' => self::SECRET_NOTE . ' quote notes',
        ], 3);
        $pipe->sendQuote($r->id, $draft->id, $admin, 4);
        $quote = $draft->fresh();

        $this->actingAs($customer)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.77', 'HTTP_USER_AGENT' => self::SECRET_UA])
            ->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();

        $this->actingAs($customer)->post(route('custom.checkout.place', \App\Models\DesignAgreement::first()->id), ['payment_method' => 'gcash'])->assertRedirect();
        $order = Order::firstOrFail();

        PaymentRecord::create([
            'order_id' => $order->id, 'payment_id' => $order->payment->id, 'amount' => 20000,
            'method' => 'cash', 'paid_on' => now()->toDateString(), 'recorded_by' => $admin->id,
            'note' => 'INTERNAL-LEDGER-NOTE',
        ]);
        $order->forceFill(['amount_paid' => 20000])->save();

        app(\App\Support\CustomOrderStatus::class)->change($order->id, $admin, 'confirmed');

        return [$customer, $admin, $r->fresh(), $order->fresh()];
    }

    /* ───────────── customer timeline ───────────── */

    public function test_customer_timeline_shows_the_right_events_and_hides_internal_ones(): void
    {
        [$customer, , $r, $order] = $this->story();

        $html = html_entity_decode($this->actingAs($customer)->get(route('design.show', $r->id))->assertOk()->getContent(), ENT_QUOTES);

        foreach ([
            'What has happened so far',
            'You sent your request to our team',
            "We tried to call you but couldn't get through",
            "We couldn't reach you by phone",
            'Our team called you',
            'Your quote is ready',
            'You accepted agreement LP-A-',
            'Agreed on ',
            'Order ' . $order->order_number . ' was created',
            'Payment of ₱20,000.00 received',
            'Your order was confirmed',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "customer timeline should contain: $expected");
        }

        foreach ([
            self::SECRET_NOTE, 'INTERNAL-LEDGER-NOTE', self::SECRET_UA, '203.0.113.77',
            'Rhea', 'Santos', 'Quote draft', 'draft', 'hash', 'integrity', 'call_notes',
        ] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, "customer must not see: $hidden");
        }

        // order page carries it too
        $o = $this->actingAs($customer)->get(route('orders.show', $order->id))->assertOk()->getContent();
        $this->assertStringContainsString('What has happened so far', $o);
        $this->assertStringContainsString('Payment of ₱20,000.00 received', $o);
        $this->assertStringNotContainsString(self::SECRET_NOTE, $o);
        $this->assertStringNotContainsString('INTERNAL-LEDGER-NOTE', $o);
    }

    public function test_customer_timeline_is_in_chronological_order(): void
    {
        [$customer, , $r] = $this->story();

        $html = $this->actingAs($customer)->get(route('design.show', $r->id))->getContent();

        $pos = fn ($s) => strpos($html, $s);
        $this->assertLessThan($pos('Your quote is ready'), $pos('You sent your request to our team'));
        $this->assertLessThan($pos('You accepted agreement'), $pos('Your quote is ready'));
        $this->assertLessThan($pos('Payment of'), $pos('You accepted agreement'));
    }

    public function test_other_customer_cannot_see_the_timeline(): void
    {
        [, , $r, $order] = $this->story();
        $bob = $this->customer('Bob');

        $this->actingAs($bob)->get(route('design.show', $r->id))->assertNotFound();
        $this->actingAs($bob)->get(route('orders.show', $order->id))->assertNotFound();
    }

    public function test_cancel_reason_is_not_shown_to_the_customer(): void
    {
        [$customer, $admin, $r, $order] = $this->story();
        app(\App\Support\CustomOrderStatus::class)->change($order->id, $admin, 'cancelled', 'REFUND-DETAILS-INTERNAL bank ref 123');

        $html = $this->actingAs($customer)->get(route('design.show', $r->id))->getContent();

        $this->assertStringContainsString('Your order was cancelled', $html);
        $this->assertStringNotContainsString('REFUND-DETAILS-INTERNAL', $html);
    }

    /* ───────────── admin timeline ───────────── */

    public function test_admin_timeline_shows_everything_with_who_and_when(): void
    {
        [, $admin, $r, $order] = $this->story();

        $html = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();

        foreach ([
            'Customer sent the request to the team',
            'Call logged (attempt 1, no answer)',
            'Marked unreachable, customer notified',
            'Call logged (attempt 2, answered)',
            'Quote draft created (version 1)',
            'Quote version 1 sent to the customer',
            'Customer accepted agreement LP-A-',
            'Order ' . $order->order_number . ' placed by the customer',
            'Payment of ₱20,000.00 recorded (Cash)',
            'Note: INTERNAL-LEDGER-NOTE',
            'Order status: pending to confirmed',
            self::SECRET_NOTE,
            'Rhea',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html, "admin timeline should contain: $expected");
        }

        // when, in Asia/Manila
        $this->assertMatchesRegularExpression('/\w{3} \d{2}, \d{4} \d{2}:\d{2} (AM|PM) · /', $html);
    }

    public function test_admin_timeline_shows_flags_and_failed_hash_checks(): void
    {
        [, $admin, $r, $order] = $this->story();
        $order->forceFill(['needs_review' => true, 'review_reason' => 'duplicate payment, check for refund'])->save();
        CustomOrderEvent::create(['request_id' => $r->id, 'type' => 'agreement_integrity_failed', 'actor_type' => 'system',
            'data' => ['agreement_number' => 'LP-A-2026-0001'], 'occurred_at' => now()]);

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk()
            ->assertSee('Order flagged for review')
            ->assertSee('duplicate payment, check for refund')
            ->assertSee('FAILED its hash check');
    }

    /* ───────────── reports and the orders list ───────────── */

    public function test_reports_split_customized_without_changing_existing_totals(): void
    {
        $admin = $this->admin();
        $c = $this->customer();
        $this->makeOrder($c, ['total_amount' => 1000, 'order_status' => 'confirmed']);
        $this->makeOrder($c, ['total_amount' => 500, 'order_status' => 'pending']);
        $this->makeOrder($c, ['total_amount' => 999, 'order_status' => 'cancelled']);

        $page = $this->actingAs($admin, 'admin')->get(route('admin.reports'))->assertOk();
        $before = $page->viewData('totalRevenue');
        $this->assertEquals(1500, $before);
        $this->assertEquals(2, array_sum($page->viewData('ordersByStatus')->only(['confirmed', 'pending'])->all()));
        $this->assertEquals(1500.0, $page->viewData('orderSplit')['catalog']['revenue']);
        $this->assertSame(0, $page->viewData('orderSplit')['customized']['orders']);

        // add a customized order: the old totals count it exactly as before, the split separates it
        $custom = $this->makeOrder($c, ['total_amount' => 20000, 'order_status' => 'confirmed']);
        $a = \App\Models\DesignAgreement::query()->exists() ? null : null;
        $r = $this->makeRequest($c);
        $q = $this->makeQuote($r, ['status' => 'accepted', 'sent_by' => $admin->id]);
        $ag = $this->makeAgreement($q, $c, $admin);
        $custom->forceFill(['design_agreement_id' => $ag->id, 'amount_paid' => 5000])->save();

        $page = $this->actingAs($admin, 'admin')->get(route('admin.reports'))->assertOk();
        $this->assertEquals(21500, $page->viewData('totalRevenue'), 'the existing total still includes every non-cancelled order');
        $split = $page->viewData('orderSplit');
        $this->assertEquals(1500.0, $split['catalog']['revenue']);
        $this->assertSame(1, $split['customized']['orders']);
        $this->assertEquals(20000.0, $split['customized']['revenue']);
        $this->assertEquals(5000.0, $split['customized']['paid']);
        $this->assertEquals(
            $split['catalog']['revenue'] + $split['customized']['revenue'],
            $page->viewData('totalRevenue')
        );
        $page->assertSee('Catalog vs Customized orders');
    }

    public function test_orders_list_can_filter_by_type(): void
    {
        $admin = $this->admin();
        $c = $this->customer();
        $cat = $this->makeOrder($c, ['order_number' => 'LP-CAT-1']);
        $cus = $this->makeOrder($c, ['order_number' => 'LP-CUS-1']);
        $r = $this->makeRequest($c);
        $ag = $this->makeAgreement($this->makeQuote($r, ['status' => 'accepted', 'sent_by' => $admin->id]), $c, $admin);
        $cus->forceFill(['design_agreement_id' => $ag->id])->save();

        $this->actingAs($admin, 'admin')->get(route('admin.orders.index'))->assertSee('LP-CAT-1')->assertSee('LP-CUS-1');
        $this->actingAs($admin, 'admin')->get(route('admin.orders.index', ['type' => 'customized']))
            ->assertSee('LP-CUS-1')->assertDontSee('LP-CAT-1');
        $this->actingAs($admin, 'admin')->get(route('admin.orders.index', ['type' => 'catalog']))
            ->assertSee('LP-CAT-1')->assertDontSee('LP-CUS-1');
    }
}
