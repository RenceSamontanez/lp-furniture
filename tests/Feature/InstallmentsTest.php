<?php

namespace Tests\Feature;

use App\Models\ActivityLog as ActivityRow;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\StandingEvent;
use App\Models\User;
use App\Support\InstallmentScheduler;
use App\Support\Installments\ChargeResult;
use App\Support\Installments\InstallmentCharger;
use App\Support\Installments\InstallmentEligibility;
use App\Support\Installments\InstallmentGateway;
use App\Support\Installments\InstallmentPlanStarter;
use App\Support\Installments\InstallmentStanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\Support\FakeInstallmentGateway;
use Tests\TestCase;

/**
 * Installments: eligibility, the 50% plan, due dates from the confirmed down payment, the daily job
 * (reminder, charge, retry, Pay Now link, Overdue), Installment Standing, the webhook, the ledger,
 * Pay Remaining Balance, Simulate Due Date and the terms. PayMongo is faked; nothing leaves the process.
 */
class InstallmentsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const WH = 'whsec_installments_fake';

    private FakeInstallmentGateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true, 'score' => 0.9])]);
        config(['services.paymongo.secret_key' => 'sk_test_fake', 'services.paymongo.webhook_secret' => self::WH]);
        $this->gw = new FakeInstallmentGateway();
        $this->app->instance(InstallmentGateway::class, $this->gw);
        Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    /**
     * A started 4-payment card plan on a ₱12,000 order (₱6,000 down, 4 x ₱1,500). Due dates default to
     * today + 0, 15, 30, 45 days; pass 'offsets' to change them.
     *
     * @return array{0:User,1:Order,2:Payment,3:\Illuminate\Support\Collection}
     */
    private function plan(array $o = []): array
    {
        $user = $o['user'] ?? $this->makeUser();
        $order = Order::create([
            'user_id' => $user->id, 'order_number' => 'LP-2026-' . random_int(10000, 99999), 'total_amount' => 12000,
            'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'confirmed',
        ]);
        $method = $o['method'] ?? 'paymongo_card';
        $payment = Payment::create([
            'order_id' => $order->id, 'user_id' => $user->id, 'amount' => 6000, 'payment_method' => $method, 'payment_type' => 'installment',
            'installment_duration_months' => 2, 'installment_frequency_days' => 15, 'payment_status' => 'paid', 'paid_at' => now(),
            'paymongo_customer_id' => $method === 'paymongo_card' ? 'cus_x' : null,
            'installment_card_method_id' => $method === 'paymongo_card' ? 'pm_x' : null,
            'card_brand' => 'Visa', 'card_last4' => '4345',
            'installments_started_at' => ($o['started'] ?? true) ? now() : null,
        ]);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 6000, 'method' => 'card_paymongo', 'gateway_reference' => 'pi_down_' . $order->id, 'paid_on' => now()->toDateString()]);
        $order->forceFill(['amount_paid' => 6000])->save();

        InstallmentScheduler::createSchedule($payment, $order, 6000, 2, 15);
        $rows = InstallmentSchedule::where('payment_id', $payment->id)->orderBy('installment_no')->get();
        $offsets = $o['offsets'] ?? [0, 15, 30, 45];
        foreach ($rows as $i => $row) {
            $row->forceFill(['due_date' => now('Asia/Manila')->addDays($offsets[$i])->toDateString()])->save();
        }

        return [$user, $order, $payment, $rows->fresh()];
    }

    private function daily(?string $at = null): array
    {
        return app(InstallmentCharger::class)->runDaily($at ? Carbon::parse($at, 'Asia/Manila') : null);
    }

    private function at(string $when): void
    {
        Carbon::setTestNow(Carbon::parse($when, 'Asia/Manila'));
    }

    private function score(User $u): int
    {
        return InstallmentStanding::score($u->fresh());
    }

    private function titles(User $u): array
    {
        return Notification::where('user_id', $u->id)->orderBy('id')->pluck('title')->all();
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function webhook(array $resource, string $type = 'payment.paid', ?string $sigMode = null)
    {
        $json = json_encode(['data' => ['attributes' => ['type' => $type, 'data' => ['attributes' => $resource]]]]);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$json}", self::WH);
        $header = $sigMode === 'li' ? "t={$t},te=,li={$sig}" : "t={$t},te={$sig},li=";

        return $this->call('POST', '/webhook/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => $header, 'CONTENT_TYPE' => 'application/json'], $json);
    }

    /* ───────────── eligibility ───────────── */

    /** @dataProvider eligibilityCases */
    public function test_eligibility_by_level_cap_and_id(int $score, float $total, bool $verified, bool $allowed, string $level): void
    {
        $u = $this->makeUser();
        $u->forceFill(['is_validated' => $verified])->save();
        DB::table('users')->where('id', $u->id)->update(['installment_standing_score' => $score]);

        $r = InstallmentEligibility::check($u->fresh(), $total);

        $this->assertSame($allowed, $r['allowed'], $r['reason'] ?? '');
        $this->assertSame($level, $r['level']);
        $this->assertSame($allowed, $r['reason'] === null);
    }

    public static function eligibilityCases(): array
    {
        return [
            'excellent no cap'      => [90, 900000, true, true, 'Excellent'],
            'good at the cap'       => [70, 100000, true, true, 'Good'],
            'good over the cap'     => [70, 100000.01, true, false, 'Good'],
            'fair at the cap'       => [50, 30000, true, true, 'Fair'],
            'fair over the cap'     => [59, 30001, true, false, 'Fair'],
            'limited at the cap'    => [30, 15000, true, true, 'Limited'],
            'limited over the cap'  => [39, 15001, true, false, 'Limited'],
            'suspended'             => [19, 100, true, false, 'Suspended'],
            'zero is suspended'     => [0, 100, true, false, 'Suspended'],
            'boundary 85'           => [85, 500000, true, true, 'Excellent'],
            'boundary 60'           => [60, 100000, true, true, 'Good'],
            'no verified id'        => [90, 100, false, false, 'Excellent'],
        ];
    }

    public function test_months_are_limited_by_level(): void
    {
        $u = $this->makeUser();
        DB::table('users')->where('id', $u->id)->update(['installment_standing_score' => 45]); // Fair: 2 months
        $this->assertTrue(InstallmentEligibility::allows($u->fresh(), 20000, 2, 15));
        $this->assertFalse(InstallmentEligibility::allows($u->fresh(), 20000, 3, 15));
        $this->assertFalse(InstallmentEligibility::allows($u->fresh(), 20000, 2, 7));
    }

    public function test_new_customers_start_at_70_from_config(): void
    {
        $this->assertSame(70, $this->score($this->makeUser()));
        $this->assertSame('Good', InstallmentStanding::levelFor(70)['name']);
    }

    /* ───────────── the plan ───────────── */

    public function test_down_payment_is_exactly_half_and_the_last_row_absorbs_the_remainder(): void
    {
        $total = 10001.00;
        $down = InstallmentScheduler::catalogDownpayment($total);
        $this->assertSame(5000.50, $down);

        $rows = InstallmentScheduler::splitAmounts(round($total - $down, 2), 3, 15);
        $this->assertCount(6, $rows);
        $this->assertEqualsWithDelta(1666.83 * 0 + 833.41, $rows[0]['amount'], 0.0001);
        $this->assertEqualsWithDelta(round($total - $down, 2), array_sum(array_column($rows, 'amount')), 0.0001);
        $this->assertGreaterThanOrEqual($rows[0]['amount'], end($rows)['amount']);
    }

    public function test_due_dates_count_from_the_confirmed_down_payment_not_order_creation(): void
    {
        $this->at('2026-11-01 09:00');
        $user = $this->makeUser();
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'LP-2026-70001', 'total_amount' => 12000, 'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'pending']);
        $payment = Payment::create(['order_id' => $order->id, 'user_id' => $user->id, 'amount' => 6000, 'payment_method' => 'gcash', 'payment_type' => 'installment',
            'installment_duration_months' => 2, 'installment_frequency_days' => 15, 'payment_status' => 'paid']);
        InstallmentScheduler::createSchedule($payment, $order, 6000, 2, 15);
        $this->assertSame('2026-11-16', InstallmentSchedule::where('payment_id', $payment->id)->orderBy('installment_no')->first()->due_date->toDateString(), 'rows are first dated from order creation');

        // the down payment is confirmed nine days later
        $this->at('2026-11-10 09:00');
        DB::transaction(fn () => app(InstallmentPlanStarter::class)->start($order->fresh()));

        $dates = InstallmentSchedule::where('payment_id', $payment->id)->orderBy('installment_no')->get()->map(fn ($r) => $r->due_date->toDateString())->all();
        $this->assertSame(['2026-11-25', '2026-12-10', '2026-12-25', '2027-01-09'], $dates);
        $this->assertNotNull($payment->fresh()->installments_started_at);
        $this->assertContains('Installment Plan Started', $this->titles($user));
        $this->assertSame(1, ActivityRow::where('event', 'installment_plan_created')->count());

        // starting twice changes nothing
        DB::transaction(fn () => app(InstallmentPlanStarter::class)->start($order->fresh()));
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('title', 'Installment Plan Started')->count());
    }

    public function test_a_confirmed_card_down_payment_webhook_starts_the_plan_and_redates_it(): void
    {
        $this->at('2026-11-01 09:00');
        $user = $this->makeUser();
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'LP-2026-70002', 'total_amount' => 12000, 'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'pending']);
        $payment = Payment::create(['order_id' => $order->id, 'user_id' => $user->id, 'amount' => 6000, 'payment_method' => 'paymongo_card', 'payment_type' => 'installment',
            'installment_duration_months' => 2, 'installment_frequency_days' => 15, 'payment_status' => 'pending',
            'paymongo_payment_intent_id' => 'pi_down_1', 'paymongo_payment_method_id' => 'pm_new', 'paymongo_customer_id' => 'cus_1']);
        InstallmentScheduler::createSchedule($payment, $order, 6000, 2, 15);

        $this->gw->saved = [['id' => 'pm_new', 'brand' => 'visa', 'last4' => '4345']];
        $this->at('2026-11-12 10:00');
        $this->webhook(['payment_intent_id' => 'pi_down_1', 'amount' => 600000, 'source' => ['type' => 'card']])->assertOk();

        $payment->refresh();
        $this->assertSame('paid', $payment->payment_status);
        $this->assertNotNull($payment->installments_started_at);
        $this->assertSame('pm_new', $payment->installment_card_method_id);
        $this->assertSame('4345', $payment->card_last4, 'brand and last 4 come from the vaulted card');
        $this->assertSame('card_paymongo', PaymentRecord::where('order_id', $order->id)->sole()->method);
        $this->assertSame('2026-11-27', InstallmentSchedule::where('payment_id', $payment->id)->orderBy('installment_no')->first()->due_date->toDateString());
    }

    /* ───────────── standing: every rule and the clamp ───────────── */

    public function test_auto_debit_on_the_due_date_gives_4_points(): void
    {
        [$u, , , $rows] = $this->plan();
        $this->at('2026-11-10 08:00');

        $stats = $this->daily();

        $this->assertSame(1, $stats['charged']);
        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(74, $this->score($u));
        $e = StandingEvent::where('user_id', $u->id)->sole();
        $this->assertSame([4, 74], [$e->points, $e->score_after]);
        $this->assertStringContainsString('auto-debit', $e->reason);
        $this->assertContains('Payment Received', $this->titles($u));
    }

    public function test_pay_now_before_the_due_date_gives_5_points(): void
    {
        [$u, $order, , $rows] = $this->plan(['offsets' => [5, 20, 35, 50]]);

        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'card'])->assertRedirect(route('orders.show', $order->id));

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(75, $this->score($u));
        $this->assertStringContainsString('paid early', StandingEvent::where('user_id', $u->id)->sole()->reason);
    }

    public function test_pay_now_with_a_card_on_the_due_date_gives_4_points(): void
    {
        [$u, $order, , $rows] = $this->plan();
        $this->at('2026-11-10 12:00');

        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'card'])->assertRedirect();

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(74, $this->score($u));
        $this->assertStringContainsString('due date with Pay Now', StandingEvent::where('user_id', $u->id)->sole()->reason);
    }

    public function test_pay_now_with_gcash_on_the_due_date_gives_4_points(): void
    {
        [$u, $order, , $rows] = $this->plan(['method' => 'gcash']);
        $this->at('2026-11-10 12:00');

        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash'])->assertRedirect('https://pm.test/gcash/1');
        $this->webhook(['payment_intent_id' => 'pi_gcash_1', 'amount' => 150000, 'source' => ['type' => 'gcash']])->assertOk();

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(74, $this->score($u));
    }

    public function test_paying_after_a_failed_charge_costs_5_points(): void
    {
        [$u, $order, , $rows] = $this->plan();
        $this->gw->cardResults = [ChargeResult::failed('insufficient funds', 'pi_bad')];
        $this->at('2026-11-10 08:00');
        $this->daily();
        $this->assertSame('failed', $rows[0]->fresh()->status);

        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'card'])->assertRedirect();

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(65, $this->score($u));
        $this->assertStringContainsString('failed charge', StandingEvent::where('user_id', $u->id)->sole()->reason);
    }

    public function test_becoming_overdue_costs_15_and_paying_an_overdue_installment_is_zero(): void
    {
        [$u, $order, , $rows] = $this->plan();
        $this->gw->cardResults = [ChargeResult::failed('declined', 'pi_1'), ChargeResult::failed('declined', 'pi_2')];
        $this->at('2026-11-10 08:00'); $this->daily();
        $this->at('2026-11-11 08:00'); $this->daily();
        $this->at('2026-11-13 08:00'); $this->daily();

        $this->assertSame('overdue', $rows[0]->fresh()->status);
        $this->assertSame(55, $this->score($u));
        $this->assertSame(-15, StandingEvent::where('user_id', $u->id)->latest('id')->first()->points);

        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'card']);

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(55, $this->score($u), 'paying while Overdue changes nothing');
        $zero = StandingEvent::where('user_id', $u->id)->latest('id')->first();
        $this->assertSame(0, $zero->points);
        $this->assertStringContainsString('Overdue', $zero->reason);
    }

    public function test_finishing_a_plan_with_no_overdue_gives_10_and_an_overdue_one_does_not(): void
    {
        [$u, $order, , $rows] = $this->plan(['offsets' => [5, 20, 35, 50]]);
        $this->actingAs($u);
        foreach ($rows as $r) {
            $this->post(route('installments.pay', [$order->id, $r->id]), ['method' => 'card']);
        }
        $this->assertSame(70 + 4 * 5 + 10, $this->score($u));
        $this->assertSame(1, StandingEvent::where('user_id', $u->id)->where('reason', 'like', '%finished with no Overdue%')->count());

        // a plan that had an Overdue installment: no bonus
        [$u2, $order2, , $rows2] = $this->plan();
        $rows2[0]->forceFill(['status' => 'overdue', 'overdue_at' => now(), 'charge_failed' => true])->save();
        $this->actingAs($u2);
        foreach ($rows2 as $r) {
            $this->post(route('installments.pay', [$order2->id, $r->id]), ['method' => 'card']);
        }
        $this->assertSame(0, StandingEvent::where('user_id', $u2->id)->where('reason', 'like', '%finished with no Overdue%')->count());
    }

    public function test_the_score_is_clamped_between_0_and_100(): void
    {
        $standing = app(InstallmentStanding::class);
        $u = $this->makeUser();

        DB::table('users')->where('id', $u->id)->update(['installment_standing_score' => 98]);
        $e = $standing->apply($u, 4, 'test');
        $this->assertSame([2, 100], [$e->points, $e->score_after], 'only the points that really applied are recorded');

        DB::table('users')->where('id', $u->id)->update(['installment_standing_score' => 3]);
        $e = $standing->apply($u, -15, 'test');
        $this->assertSame([-3, 0], [$e->points, $e->score_after]);
        $this->assertSame(0, $this->score($u));
    }

    public function test_an_automatic_scoring_event_can_only_happen_once(): void
    {
        [$u, , , $rows] = $this->plan();
        $standing = app(InstallmentStanding::class);

        $this->assertNotNull($standing->onOverdue($rows[0]));
        $this->assertNull($standing->onOverdue($rows[0]), 'the same key is refused the second time');
        $this->assertSame(55, $this->score($u));
    }

    public function test_admin_adjustment_needs_a_reason_and_is_recorded_and_logged(): void
    {
        [$u] = $this->plan();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.installments.adjust', $u->id), ['mode' => 'add', 'points' => 10, 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(70, $this->score($u));

        $this->actingAs($admin, 'admin')->post(route('admin.installments.adjust', $u->id), ['mode' => 'add', 'points' => 10, 'reason' => 'Bank error on our side'])
            ->assertRedirect();

        $this->assertSame(80, $this->score($u));
        $e = StandingEvent::where('user_id', $u->id)->sole();
        $this->assertSame([10, 80, $admin->id], [$e->points, $e->score_after, $e->admin_id]);
        $this->assertStringContainsString('Bank error on our side', $e->reason);
        $this->assertSame(1, ActivityRow::where('event', 'installment_standing_adjusted')->count());

        $this->post(route('admin.installments.adjust', $u->id), ['mode' => 'set', 'points' => 30, 'reason' => 'Reset after review']);
        $this->assertSame(30, $this->score($u));
        $this->assertSame(-50, StandingEvent::where('user_id', $u->id)->latest('id')->first()->points);
    }

    /* ───────────── the daily job ───────────── */

    public function test_retry_then_pay_link_then_overdue(): void
    {
        [$u, , , $rows] = $this->plan();
        $this->gw->cardResults = [ChargeResult::failed('insufficient funds', 'pi_a'), ChargeResult::failed('insufficient funds', 'pi_b')];
        $admin = $this->admin();

        // due day: charged, declined
        $this->at('2026-11-10 08:00');
        $this->daily();
        $r = $rows[0]->fresh();
        $this->assertSame(['failed', 1], [$r->status, $r->attempts]);
        $this->assertSame('2026-11-11 08:00', $r->next_attempt_at->timezone('Asia/Manila')->format('Y-m-d H:i'));
        $this->assertContains('Payment Failed, We Will Try Again', $this->titles($u));
        $this->daily(); // the same day again: nothing new is tried
        $this->assertCount(1, $this->gw->charges);

        // next day: retried, declined again -> Pay Now link + admin notice
        $this->at('2026-11-11 08:00');
        $this->daily();
        $r = $rows[0]->fresh();
        $this->assertSame(['link_sent', 2], [$r->status, $r->attempts]);
        $this->assertContains('Please Pay Your Installment', $this->titles($u));
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Installment Needs Attention')->count());
        $this->assertCount(2, $this->gw->charges);

        // two days later: still inside the grace period
        $this->at('2026-11-12 08:00');
        $this->daily();
        $this->assertSame('link_sent', $rows[0]->fresh()->status);

        // three days after the due date: Overdue, set by the job
        $this->at('2026-11-13 08:00');
        $stats = $this->daily();
        $this->assertSame(1, $stats['overdue']);
        $this->assertSame('overdue', $rows[0]->fresh()->status);
        $this->assertContains('Installment Overdue', $this->titles($u));
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Installment Overdue')->count());
        $this->assertCount(2, $this->gw->charges, 'no further charge attempts');

        // running it again is harmless
        $this->daily();
        $this->assertSame(1, StandingEvent::where('user_id', $u->id)->where('points', -15)->count());
    }

    public function test_the_reminder_is_sent_once_three_days_before(): void
    {
        [$u] = $this->plan(['offsets' => [3, 18, 33, 48]]);
        $this->at('2026-11-10 08:00');

        $this->assertSame(1, $this->daily()['reminders']);
        $this->assertSame(0, $this->daily()['reminders']);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('title', 'Payment Reminder')->count());

        [$u2] = $this->plan(['offsets' => [5, 20, 35, 50]]);
        $this->daily();
        $this->assertSame(0, Notification::where('user_id', $u2->id)->where('title', 'Payment Reminder')->count(), 'five days out is too early');
    }

    public function test_a_gcash_plan_gets_a_pay_now_notification_not_a_charge(): void
    {
        [$u, , , $rows] = $this->plan(['method' => 'gcash']);
        $this->at('2026-11-10 08:00');

        $stats = $this->daily();

        $this->assertSame(1, $stats['links']);
        $this->assertSame([], $this->gw->charges);
        $this->assertSame('link_sent', $rows[0]->fresh()->status);
        $this->assertContains('Pay Now With GCash', $this->titles($u));
        // it becomes Overdue after the grace period like any other
        $this->at('2026-11-13 08:00');
        $this->daily();
        $this->assertSame('overdue', $rows[0]->fresh()->status);
    }

    public function test_overdue_is_not_set_by_viewing_the_admin_page(): void
    {
        [, , , $rows] = $this->plan(['offsets' => [-5, 10, 25, 40]]);
        $rows[0]->forceFill(['status' => 'pending'])->save();

        $this->actingAs($this->admin(), 'admin')->get(route('admin.installments.index'))->assertOk();
        $this->actingAs($this->admin(), 'admin')->get(route('admin.installments.index', ['status' => 'overdue']))->assertOk();

        $this->assertSame('pending', $rows[0]->fresh()->status);
    }

    public function test_orders_that_are_cancelled_or_not_started_are_left_alone(): void
    {
        [, $order, , $rows] = $this->plan();
        $order->forceFill(['order_status' => 'cancelled'])->save();
        [, , , $notStarted] = $this->plan(['started' => false]);
        $this->at('2026-11-10 08:00');

        $this->daily();

        $this->assertSame('pending', $rows[0]->fresh()->status);
        $this->assertSame('pending', $notStarted[0]->fresh()->status);
        $this->assertSame([], $this->gw->charges);
    }

    /* ───────────── ledger, Pay Now, Pay Remaining Balance ───────────── */

    public function test_each_installment_payment_is_one_ledger_row_with_its_own_reference(): void
    {
        [$u, $order, , $rows] = $this->plan();
        $this->at('2026-11-10 08:00');
        $this->daily();

        $rec = PaymentRecord::where('installment_schedule_id', $rows[0]->id)->sole();
        $this->assertSame(['card_paymongo', '1500.00'], [$rec->method, (string) $rec->amount]);
        $this->assertStringStartsWith('pi_saved_', $rec->gateway_reference);
        $this->assertSame('7500.00', (string) $order->fresh()->amount_paid);

        // the same capture can never be written twice
        $this->expectException(\Illuminate\Database\QueryException::class);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $rec->payment_id, 'amount' => 1500, 'method' => 'card_paymongo', 'gateway_reference' => $rec->gateway_reference, 'paid_on' => now()->toDateString()]);
    }

    public function test_pay_remaining_balance_in_full_marks_all_unpaid_installments_paid(): void
    {
        [$u, $order, $payment, $rows] = $this->plan(['offsets' => [5, 20, 35, 50]]);
        $rows[0]->forceFill(['status' => 'overdue', 'overdue_at' => now()])->save();

        $this->actingAs($u)->post(route('installments.payFull', $order->id), ['method' => 'card'])->assertRedirect(route('orders.show', $order->id));

        $this->assertSame(0, InstallmentSchedule::where('payment_id', $payment->id)->where('status', '!=', 'paid')->count());
        $recs = PaymentRecord::where('order_id', $order->id)->whereNotNull('installment_schedule_id')->get();
        $this->assertCount(4, $recs);
        $this->assertSame(6000.0, round((float) $recs->sum('amount'), 2));
        $this->assertCount(4, $recs->pluck('gateway_reference')->unique());
        $this->assertSame('12000.00', (string) $order->fresh()->amount_paid);
        $this->assertSame(70, $this->score($u), 'no per-installment points for paying the balance');
        $this->assertSame(1, $this->gw->n, 'one charge for the whole balance');
        $this->assertSame(600000, $this->gw->charges[0][1]);
    }

    public function test_pay_now_with_gcash_goes_to_gcash_and_the_webhook_completes_it_once(): void
    {
        [$u, $order, , $rows] = $this->plan(['method' => 'gcash', 'offsets' => [5, 20, 35, 50]]);

        $resp = $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash']);
        $resp->assertRedirect('https://pm.test/gcash/1');
        $this->assertSame('pending', $rows[0]->fresh()->status);

        $res = ['payment_intent_id' => 'pi_gcash_1', 'amount' => 150000, 'source' => ['type' => 'gcash']];
        $this->webhook($res)->assertOk();
        $this->webhook($res)->assertOk(); // PayMongo retries

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame('pending', $rows[1]->fresh()->status, 'the other installments of the order are untouched');
        $rec = PaymentRecord::where('installment_schedule_id', $rows[0]->id)->sole();
        $this->assertSame(['gcash_paymongo', 'pi_gcash_1'], [$rec->method, $rec->gateway_reference]);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('title', 'Payment Received')->count());
        $this->assertSame('7500.00', (string) $order->fresh()->amount_paid);
        $this->assertSame(75, $this->score($u), 'paid before the due date');
    }

    public function test_the_webhook_ignores_an_amount_that_does_not_match(): void
    {
        [$u, $order, , $rows] = $this->plan(['method' => 'gcash', 'offsets' => [5, 20, 35, 50]]);
        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash']);

        $this->webhook(['payment_intent_id' => 'pi_gcash_1', 'amount' => 100, 'source' => ['type' => 'gcash']])->assertOk();

        $this->assertSame('pending', $rows[0]->fresh()->status);
        $this->assertSame(0, PaymentRecord::where('installment_schedule_id', $rows[0]->id)->count());
    }

    public function test_a_second_payment_for_an_already_paid_installment_is_flagged_not_lost(): void
    {
        [$u, $order, , $rows] = $this->plan(['method' => 'gcash', 'offsets' => [5, 20, 35, 50]]);
        $this->actingAs($u);
        $this->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash']);
        $this->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash']); // a second link opened
        $this->webhook(['payment_intent_id' => 'pi_gcash_1', 'amount' => 150000, 'source' => ['type' => 'gcash']])->assertOk();
        $this->webhook(['payment_intent_id' => 'pi_gcash_2', 'amount' => 150000, 'source' => ['type' => 'gcash']])->assertOk();

        $this->assertSame(1, PaymentRecord::where('installment_schedule_id', $rows[0]->id)->count());
        $this->assertTrue((bool) $order->fresh()->needs_review);
    }

    public function test_the_webhook_signature_follows_the_key_mode(): void
    {
        [$u, $order, , $rows] = $this->plan(['method' => 'gcash', 'offsets' => [5, 20, 35, 50]]);
        $this->actingAs($u)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'gcash']);
        $res = ['payment_intent_id' => 'pi_gcash_1', 'amount' => 150000, 'source' => ['type' => 'gcash']];

        // live key: the li= signature counts, a te= one is refused
        config(['services.paymongo.secret_key' => 'sk_live_fake']);
        $this->webhook($res)->assertStatus(400);
        $this->assertSame('pending', $rows[0]->fresh()->status);
        $this->webhook($res, 'payment.paid', 'li')->assertOk();
        $this->assertSame('paid', $rows[0]->fresh()->status);
    }

    /* ───────────── Simulate Due Date ───────────── */

    public function test_simulate_is_blocked_with_live_keys(): void
    {
        [, , , $rows] = $this->plan(['offsets' => [10, 25, 40, 55]]);
        config(['services.paymongo.secret_key' => 'sk_live_fake']);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.installments.simulate', $rows[0]->id))->assertSessionHasErrors('simulate');

        $this->assertSame('pending', $rows[0]->fresh()->status);
        $this->assertSame([], $this->gw->charges);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.installments.index'))->assertOk()->assertDontSee('data-ia-sim', false)->assertDontSee('id="ia-sim"', false);
    }

    public function test_simulate_runs_the_real_charge_job_for_that_installment_only(): void
    {
        [$u, , , $rows] = $this->plan(['offsets' => [10, 25, 40, 55]]);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.installments.index'))->assertOk()->assertSee('Simulate Due Date');

        $this->post(route('admin.installments.simulate', $rows[0]->id))->assertRedirect();

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertCount(1, $this->gw->charges);
        $this->assertSame('pending', $rows[1]->fresh()->status);
        $this->assertSame(74, $this->score($u), 'same scoring as a real due date');
        $this->assertTrue((bool) \App\Models\InstallmentAttempt::where('installment_schedule_id', $rows[0]->id)->where('simulated', true)->exists());

        // declined -> next day -> link -> overdue, each step through the same job
        $this->gw->cardResults = [ChargeResult::failed('no', 'x1'), ChargeResult::failed('no', 'x2')];
        $this->post(route('admin.installments.simulate', $rows[1]->id));
        $this->assertSame('failed', $rows[1]->fresh()->status);
        $this->post(route('admin.installments.simulate', $rows[1]->id));
        $this->assertSame('link_sent', $rows[1]->fresh()->status);
        $this->post(route('admin.installments.simulate', $rows[1]->id));
        $this->assertSame('overdue', $rows[1]->fresh()->status);
    }

    public function test_mark_paid_goes_through_the_ledger_and_is_logged(): void
    {
        [$u, $order, , $rows] = $this->plan();
        $rows[0]->forceFill(['status' => 'overdue', 'overdue_at' => now()])->save();

        $this->actingAs($this->admin(), 'admin')->post(route('admin.installments.markPaid', $rows[0]->id), ['method' => 'bank_transfer'])->assertRedirect();

        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame('admin', $rows[0]->fresh()->paid_via);
        $this->assertSame('bank_transfer', PaymentRecord::where('installment_schedule_id', $rows[0]->id)->sole()->method);
        $this->assertSame(1, ActivityRow::where('event', 'payment_recorded')->count());
    }

    /* ───────────── checkout: terms, consent, methods ───────────── */

    private function checkoutUser(): array
    {
        $u = $this->makeUser();
        $p = Product::create(['product_name' => 'Chair', 'price' => 20000, 'stock_quantity' => 5, 'product_status' => 'available']);
        DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);

        return [$u, $p];
    }

    private function place(User $u, array $over = [])
    {
        return $this->actingAs($u)->post(route('order.place'), $over + [
            'delivery_option' => 'pickup', 'payment_type' => 'installment', 'installment_duration' => 3, 'installment_frequency' => 15,
            'payment_method' => 'paymongo_card', 'accept_installment_terms' => 1, 'accept_auto_charge' => 1,
        ]);
    }

    public function test_installment_checkout_requires_the_terms_and_for_cards_the_auto_charge_consent(): void
    {
        [$u] = $this->checkoutUser();

        $this->place($u, ['accept_installment_terms' => 0])->assertSessionHasErrors('payment_type');
        $this->place($u, ['accept_auto_charge' => 0])->assertSessionHasErrors('payment_type');
        $this->assertSame(0, Order::where('user_id', $u->id)->count());

        $this->place($u)->assertRedirect(route('payment.card.paymongo', Order::first()?->id ?? 1));
        $order = Order::where('user_id', $u->id)->sole();
        $p = $order->payment;
        $this->assertSame(['paymongo_card', 'installment', '10000.00'], [$p->payment_method, $p->payment_type, (string) $p->amount]);
        $this->assertSame(config('installments.terms_version'), $p->terms_version);
        $this->assertNotNull($p->terms_accepted_at);
        $this->assertNotNull($p->auto_charge_consent_at);
        $this->assertSame(6, InstallmentSchedule::where('payment_id', $p->id)->count());
        $this->assertSame(1, ActivityRow::where('event', 'installment_terms_accepted')->count());
        $this->assertNull($p->installments_started_at, 'the plan starts when the down payment is confirmed');
    }

    public function test_gcash_installments_need_only_the_terms(): void
    {
        [$u] = $this->checkoutUser();
        $this->place($u, ['payment_method' => 'gcash', 'accept_auto_charge' => 0])->assertRedirect();
        $this->assertNull(Order::where('user_id', $u->id)->sole()->payment->auto_charge_consent_at);
    }

    public function test_pay_on_site_and_stripe_are_refused_for_installments_and_card_for_full_payments(): void
    {
        [$u] = $this->checkoutUser();

        foreach (['onsite', 'stripe'] as $m) {
            $this->place($u, ['payment_method' => $m])->assertSessionHasErrors('payment_type');
        }
        $this->place($u, ['payment_type' => 'full', 'payment_method' => 'paymongo_card'])->assertSessionHasErrors('payment_type');
        $this->assertSame(0, Order::where('user_id', $u->id)->count());
        $this->place($u, ['payment_type' => 'full', 'payment_method' => 'onsite'])->assertRedirect();
    }

    public function test_checkout_refuses_installments_without_a_verified_id_or_over_the_cap_or_too_many_months(): void
    {
        [$u] = $this->checkoutUser();
        $u->forceFill(['is_validated' => false])->save();
        // an unvalidated account is stopped by the validated gate before the form
        $this->place($u)->assertRedirect();
        $this->assertSame(0, Order::where('user_id', $u->id)->count());

        [$u2] = $this->checkoutUser();
        DB::table('users')->where('id', $u2->id)->update(['installment_standing_score' => 30]); // Limited: 1 month, up to 15,000
        $this->place($u2)->assertSessionHasErrors('payment_type');
        $this->assertSame(0, Order::where('user_id', $u2->id)->count());

        [$u3] = $this->checkoutUser();
        DB::table('users')->where('id', $u3->id)->update(['installment_standing_score' => 50]); // Fair, 2 months, cap 30,000
        $this->place($u3, ['installment_duration' => 3])->assertSessionHasErrors('payment_type');
        $this->place($u3, ['installment_duration' => 2])->assertRedirect();
        $this->assertSame(1, Order::where('user_id', $u3->id)->count());
    }

    public function test_the_checkout_page_shows_the_standing_and_the_terms_link(): void
    {
        [$u] = $this->checkoutUser();

        $this->actingAs($u)->get(route('checkout'))->assertOk()
            ->assertSee('Your Installment Standing', false)
            ->assertSee('Installment Terms', false)
            ->assertSee('accept_installment_terms', false);
    }

    public function test_the_terms_page_is_public_and_shows_the_version_and_the_configured_values(): void
    {
        config(['installments.terms.stop_auto_debit_days' => 21, 'installments.terms_version' => 'vTEST']);

        $this->get(route('installments.terms'))->assertOk()
            ->assertSee('Version vTEST', false)->assertSee('at least 21 days', false)->assertSee('Installment Standing', false);
        $this->assertSame('/installment-terms', parse_url(route('installments.terms'), PHP_URL_PATH));
    }

    /* ───────────── a customer cannot touch another customer's plan ───────────── */

    public function test_another_customers_plan_is_not_reachable(): void
    {
        [, $order, , $rows] = $this->plan();
        $other = $this->makeUser();

        $this->actingAs($other)->post(route('installments.pay', [$order->id, $rows[0]->id]), ['method' => 'card'])->assertNotFound();
        $this->post(route('installments.payFull', $order->id), ['method' => 'card'])->assertNotFound();
        $this->assertSame([], $this->gw->charges);
    }

    public function test_the_order_page_shows_the_plan_schedule_and_standing(): void
    {
        [$u, $order] = $this->plan();
        \App\Models\OrderItem::create(['order_id' => $order->id, 'product_name' => 'Chair', 'quantity' => 1, 'unit_price' => 12000, 'subtotal' => 12000]);

        $this->actingAs($u)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee('Payment Plan', false)->assertSee('Pay Remaining Balance In Full', false)
            ->assertSee('Update Card', false)->assertSee('Your Installment Standing', false)->assertSee('Visa ending 4345', false);
    }

    public function test_the_paymongo_driver_sends_an_e164_phone_and_treats_an_unattended_3ds_redirect_as_a_failed_charge(): void
    {
        config(['services.paymongo.secret_key' => 'sk_test_unit']);
        Http::swap(new \Illuminate\Http\Client\Factory()); // drop setUp's catch-all fake, it would win
        Http::fake([
            'api.paymongo.com/v1/customers' => Http::response(['data' => ['id' => 'cus_1']], 200),
            'api.paymongo.com/v1/payment_intents' => Http::response(['data' => ['id' => 'pi_1', 'attributes' => ['client_key' => 'ck']]], 200),
            'api.paymongo.com/v1/payment_intents/pi_1/attach' => Http::response(['data' => ['attributes' => ['status' => 'awaiting_next_action', 'next_action' => ['redirect' => ['url' => 'https://pm.test/3ds']]]]], 200),
        ]);
        $u = new User(['first_name' => 'Maria', 'last_name' => 'Santos', 'email' => 'maria@example.test']);
        $u->phone_number = '09171234567';
        $u->phone_e164 = '+639171234567';
        $gw = new \App\Support\Installments\PayMongoInstallmentGateway();

        $this->assertSame('cus_1', $gw->ensureCustomer($u));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/customers') && $r['data']['attributes']['phone'] === '+639171234567');

        $r = $gw->chargeSavedCard('cus_1', 'pm_1', 100000, 'x', []);
        $this->assertTrue($r->failedNow(), 'nobody can finish 3-D Secure on an unattended charge');
        $this->assertNull($r->redirectUrl);
    }
}
