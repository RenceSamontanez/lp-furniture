<?php

namespace Tests\Feature;

use App\Models\InstallmentAttempt;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;
use App\Support\InstallmentScheduler;
use App\Support\Installments\InstallmentCharger;
use App\Support\Installments\InstallmentGateway;
use App\Support\Installments\InstallmentPlanStarter;
use App\Support\Installments\InstallmentStanding;
use App\Support\Installments\PayMongoInstallmentGateway;
use App\Support\Installments\PlanMode;
use App\Support\Installments\SandboxInstallmentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * The sandbox driver (test keys only): a simulated saved card, simulated due-date charges through the SAME engine
 * (attempts, retry, Pay Now link, Overdue, standing, ledger with SIM- references), the card down payment without any
 * vaulting request, PayMongo customer reuse, and the "Sandbox: simulated charge" label following the gateway.
 */
class InstallmentsSandboxTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.paymongo.secret_key' => 'sk_test_fake', 'services.paymongo.webhook_secret' => 'whsec_fake', 'installments.gateway' => 'sandbox', 'installments.vault_cards' => false]);
        Http::fake(['*' => Http::response(['success' => true, 'score' => 0.9])]);
        $this->app->instance(InstallmentGateway::class, new SandboxInstallmentGateway());
        Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:User,1:Order,2:Payment,3:\Illuminate\Support\Collection} a started card plan holding a simulated saved card */
    private function plan(array $o = []): array
    {
        $user = $this->makeUser();
        $order = Order::create(['user_id' => $user->id, 'order_number' => 'LP-2026-' . random_int(10000, 99999), 'total_amount' => 12000,
            'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'confirmed']);
        $payment = Payment::create(['order_id' => $order->id, 'user_id' => $user->id, 'amount' => 6000, 'payment_method' => 'paymongo_card', 'payment_type' => 'installment',
            'installment_duration_months' => 2, 'installment_frequency_days' => 15, 'payment_status' => 'paid', 'paid_at' => now(),
            'paymongo_customer_id' => null, 'installment_card_method_id' => ($o['started'] ?? true) ? 'sim_card_1' : null, 'card_simulated' => $o['started'] ?? true,
            'card_brand' => 'Test Card', 'installments_started_at' => ($o['started'] ?? true) ? now() : null,
            'paymongo_payment_intent_id' => $o['intent'] ?? null]);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 6000, 'method' => 'card_paymongo', 'gateway_reference' => 'pi_down_' . $order->id, 'paid_on' => now()->toDateString()]);
        $order->forceFill(['amount_paid' => 6000])->save();
        InstallmentScheduler::createSchedule($payment, $order, 6000, 2, 15);
        $rows = InstallmentSchedule::where('payment_id', $payment->id)->orderBy('installment_no')->get();
        foreach ($rows as $i => $row) {
            $row->forceFill(['due_date' => now('Asia/Manila')->addDays([0, 15, 30, 45][$i])->toDateString()])->save();
        }

        return [$user, $order, $payment, $rows->fresh()];
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function score(User $u): int
    {
        return InstallmentStanding::score($u->fresh());
    }

    private function noPayMongoCall(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'paymongo.com'));
    }

    /* ───────────── the simulated charge goes through the real engine ───────────── */

    public function test_a_due_date_charge_succeeds_in_the_sandbox_without_calling_paymongo(): void
    {
        [$u, $order, , $rows] = $this->plan();

        $stats = app(InstallmentCharger::class)->runDaily();

        $this->assertSame(1, $stats['charged']);
        $this->assertSame('paid', $rows[0]->fresh()->status);
        $rec = PaymentRecord::where('installment_schedule_id', $rows[0]->id)->sole();
        $this->assertSame('card_sandbox', $rec->method, 'a simulated charge is never recorded as real card money');
        $this->assertStringStartsWith('SIM-', $rec->gateway_reference);
        $this->assertSame('7500.00', (string) $order->fresh()->amount_paid);
        $this->assertSame(74, $this->score($u), 'same +4 as an on-time auto-debit');
        $this->assertTrue((bool) InstallmentAttempt::where('installment_schedule_id', $rows[0]->id)->where('kind', 'charge')->where('result', 'ok')->value('simulated'));
        $this->assertSame(1, \App\Models\Notification::where('user_id', $u->id)->where('title', 'Payment Received')->count());
        $this->noPayMongoCall();
    }

    public function test_a_declined_charge_retries_then_sends_the_pay_link_then_goes_overdue(): void
    {
        [$u, , , $rows] = $this->plan();
        $admin = $this->admin();
        $sim = fn (string $outcome) => $this->actingAs($admin, 'admin')->post(route('admin.installments.simulate', $rows[0]->id), ['outcome' => $outcome])->assertRedirect();

        $sim('declined');
        $this->assertSame('failed', $rows[0]->fresh()->status);
        $this->assertSame(0, PaymentRecord::where('installment_schedule_id', $rows[0]->id)->count());

        $sim('insufficient');
        $r = $rows[0]->fresh();
        $this->assertSame('link_sent', $r->status, 'second failure: Pay Now link');
        $this->assertTrue((bool) InstallmentAttempt::where('installment_schedule_id', $r->id)->where('message', 'like', '%insufficient funds%')->exists());
        $this->assertContains('Please Pay Your Installment',\App\Models\Notification::where('user_id', $u->id)->pluck('title')->all());

        $sim('success'); // nothing left to charge: link_sent only moves toward Overdue
        $this->assertSame('overdue', $rows[0]->fresh()->status);
        $this->assertSame(55, $this->score($u), '70 - 15 for becoming Overdue');
        $this->assertSame(0, PaymentRecord::where('installment_schedule_id', $rows[0]->id)->count());
        $this->noPayMongoCall();
    }

    public function test_a_declined_charge_then_a_successful_retry_costs_5_points(): void
    {
        [$u, , , $rows] = $this->plan();
        SandboxInstallmentGateway::setOutcome($rows[0]->id, 'insufficient');

        app(InstallmentCharger::class)->runDaily();
        $this->assertSame('failed', $rows[0]->fresh()->status);

        app(InstallmentCharger::class)->runDaily(Carbon::parse('2026-11-11 08:30', 'Asia/Manila')); // next day: nothing picked, so it succeeds
        $this->assertSame('paid', $rows[0]->fresh()->status);
        $this->assertSame(65, $this->score($u));
        $this->assertStringStartsWith('SIM-', PaymentRecord::where('installment_schedule_id', $rows[0]->id)->sole()->gateway_reference);
    }

    public function test_the_outcome_choice_is_used_once_and_only_for_that_installment(): void
    {
        [, , , $rows] = $this->plan(['offsets' => []]);
        SandboxInstallmentGateway::setOutcome($rows[1]->id, 'declined');
        $gw = new SandboxInstallmentGateway();

        $this->assertTrue($gw->chargeSavedCard('', 'sim_card_1', 150000, 'x', ['installment_schedule_id' => $rows[0]->id])->ok());
        $this->assertTrue($gw->chargeSavedCard('', 'sim_card_1', 150000, 'x', ['installment_schedule_id' => $rows[1]->id])->failedNow());
        $this->assertTrue($gw->chargeSavedCard('', 'sim_card_1', 150000, 'x', ['installment_schedule_id' => $rows[1]->id])->ok(), 'the pick is used up');
    }

    /* ───────────── hard block ───────────── */

    public function test_the_sandbox_refuses_to_run_with_a_live_key_and_is_not_even_bound(): void
    {
        config(['services.paymongo.secret_key' => 'sk_live_fake']);

        $r = (new SandboxInstallmentGateway())->chargeSavedCard('', 'sim_card_1', 100000, 'x', ['installment_schedule_id' => 5]);
        $this->assertTrue($r->failedNow());
        $this->assertNull($r->reference, 'no SIM- reference is ever created under a live key');

        $this->app->forgetInstance(InstallmentGateway::class);
        $this->app->offsetUnset(InstallmentGateway::class);
        (new \App\Providers\AppServiceProvider($this->app))->register();
        $driver = $this->app->make(InstallmentGateway::class);
        $this->assertNotInstanceOf(SandboxInstallmentGateway::class, $driver);
        $this->assertInstanceOf(PayMongoInstallmentGateway::class, $driver);
        $this->assertFalse($driver->isSimulated());
    }

    public function test_the_default_gateway_follows_the_key(): void
    {
        // config/installments.php: unset = sandbox for an sk_test_ key, paymongo otherwise.
        $src = file_get_contents(base_path('config/installments.php'));
        $this->assertStringContainsString("'sk_test_') ? 'sandbox' : 'paymongo'", $src);
    }

    /* ───────────── the simulated saved card ───────────── */

    public function test_the_plan_start_records_a_simulated_card_with_the_brand_and_last4_paymongo_returned(): void
    {
        [, $order, $payment] = $this->plan(['started' => false, 'intent' => 'pi_down']);
        Http::swap(new \Illuminate\Http\Client\Factory()); // drop setUp's catch-all fake, it would win
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_down' => Http::response(['data' => ['attributes' => ['status' => 'succeeded', 'amount' => 600000, 'metadata' => [],
                'payments' => [['attributes' => ['source' => ['type' => 'card', 'brand' => 'visa', 'last4' => '0007']]]]]]], 200),
        ]);

        $this->assertTrue(app(InstallmentPlanStarter::class)->start($order));

        $p = $payment->fresh();
        $this->assertTrue($p->card_simulated);
        $this->assertSame(['Visa', '0007'], [$p->card_brand, $p->card_last4]);
        $this->assertSame('sim_card_' . $p->id, $p->installment_card_method_id);
        $this->assertTrue(PlanMode::autoCharges($p), 'a card plan with a simulated card is charged on its due dates');
    }

    public function test_the_plan_start_labels_a_plain_test_card_when_paymongo_returns_no_card_details(): void
    {
        [, $order, $payment] = $this->plan(['started' => false, 'intent' => 'pi_down']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.paymongo.com/*' => Http::response(['errors' => []], 404)]);

        $this->assertTrue(app(InstallmentPlanStarter::class)->start($order));

        $p = $payment->fresh();
        $this->assertSame(['Test Card', null, true], [$p->card_brand, $p->card_last4, $p->card_simulated]);
    }

    /* ───────────── the label follows the gateway ───────────── */

    public function test_the_sandbox_label_shows_for_a_simulated_card_and_disappears_with_the_real_gateway(): void
    {
        [$u, $order, , ] = $this->plan();
        $admin = $this->admin();

        $page = fn () => $this->actingAs($u)->get(route('orders.show', $order->id))->assertOk();
        $adminPage = fn () => $this->actingAs($admin, 'admin')->get(route('admin.installments.index', ['tab' => 'all']))->assertOk();

        $page()->assertSee('Sandbox: simulated charge', false);
        $adminPage()->assertSee('Sandbox: simulated charge', false)->assertSee('How Should The Charge End?', false);
        $this->actingAs($admin, 'admin')->get(route('admin.installments.customer', $u->id))->assertSee('Sandbox: simulated charge', false);

        // go live: the real driver is the gateway
        $this->app->instance(InstallmentGateway::class, new PayMongoInstallmentGateway());
        config(['installments.gateway' => 'paymongo']);

        $page()->assertDontSee('Sandbox: simulated charge', false);
        $adminPage()->assertDontSee('Sandbox: simulated charge', false)->assertDontSee('How Should The Charge End?', false);
        $this->actingAs($admin, 'admin')->get(route('admin.installments.customer', $u->id))->assertDontSee('Sandbox: simulated charge', false);
        $this->assertFalse(PlanMode::autoCharges(Payment::where('order_id', $order->id)->first()), 'a simulated card is never charged for real: the plan falls back to Pay Now');
    }

    /* ───────────── card checkout: no vaulting request ───────────── */

    private function pendingCardOrder(): array
    {
        $u = $this->makeUser();
        $o = Order::create(['user_id' => $u->id, 'order_number' => 'LP-2026-' . random_int(10000, 99999), 'total_amount' => 12000,
            'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'pending']);
        Payment::create(['order_id' => $o->id, 'user_id' => $u->id, 'amount' => 6000, 'payment_method' => 'paymongo_card', 'payment_type' => 'installment',
            'installment_duration_months' => 2, 'installment_frequency_days' => 15, 'payment_status' => 'pending']);

        return [$u, $o];
    }

    private function fakePayMongo(array $extra = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($extra + [
            'api.paymongo.com/v1/customers*' => fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['data' => ['id' => 'cus_new']], 200)
                : Http::response(['data' => []], 200),
            'api.paymongo.com/v1/payment_intents' => Http::response(['data' => ['id' => 'pi_1', 'attributes' => ['client_key' => 'ck']]], 200),
            'api.paymongo.com/v1/payment_intents/pi_1/attach' => Http::response(['data' => ['attributes' => ['status' => 'succeeded']]], 200),
        ]);
    }

    private function intentBody(): ?array
    {
        $body = null;
        Http::assertSent(function (Request $r) use (&$body) {
            if (str_ends_with($r->url(), '/v1/payment_intents')) {
                $body = $r->data();
            }

            return true;
        });

        return $body;
    }

    public function test_the_card_down_payment_is_an_ordinary_payment_with_no_vaulting_request(): void
    {
        [$u, $o] = $this->pendingCardOrder();
        $this->app->instance(InstallmentGateway::class, new SandboxInstallmentGateway());
        $this->fakePayMongo();

        $this->actingAs($u)->postJson(route('payment.card.paymongo.checkout', $o->id), ['payment_method_id' => 'pm_abc123'])
            ->assertOk()->assertJsonStructure(['redirect']);

        $attrs = $this->intentBody()['data']['attributes'];
        $this->assertArrayNotHasKey('setup_future_usage', $attrs);
        $this->assertSame(600000, $attrs['amount']);
        $this->assertSame('pi_1', Payment::where('order_id', $o->id)->value('paymongo_payment_intent_id'));
    }

    public function test_the_card_down_payment_still_goes_through_when_paymongo_cannot_create_a_customer(): void
    {
        [$u, $o] = $this->pendingCardOrder();
        $this->fakePayMongo(['api.paymongo.com/v1/customers*' => Http::response(['errors' => [['detail' => 'down']]], 500)]);

        $this->actingAs($u)->postJson(route('payment.card.paymongo.checkout', $o->id), ['payment_method_id' => 'pm_abc123'])
            ->assertOk()->assertJsonStructure(['redirect']);
        $this->assertNull(Payment::where('order_id', $o->id)->value('paymongo_customer_id'));
    }

    public function test_vaulting_is_only_requested_when_enabled_and_dropped_when_paymongo_says_not_yet_supported(): void
    {
        $this->fakePayMongo();
        config(['installments.vault_cards' => true]);
        $this->assertTrue((new PayMongoInstallmentGateway())->startCardPayment('cus_1', 'pm_1', 100000, 'x', [], 'http://x.test/')->ok());
        $this->assertSame('cus_1', $this->intentBody()['data']['attributes']['setup_future_usage']['customer_id']);

        // PayMongo refuses the vaulting parameter: the very same payment is taken without it.
        $this->fakePayMongo([
            'api.paymongo.com/v1/payment_intents' => Http::sequence()
                ->push(['errors' => [['code' => 'parameter_invalid', 'detail' => 'On session payments are not yet supported.']]], 400)
                ->push(['data' => ['id' => 'pi_1', 'attributes' => ['client_key' => 'ck']]], 200),
        ]);
        $this->assertTrue((new PayMongoInstallmentGateway())->startCardPayment('cus_1', 'pm_1', 100000, 'x', [], 'http://x.test/')->ok());
        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/payment_intents'));
        $this->assertCount(2, $sent);
        $this->assertArrayNotHasKey('setup_future_usage', $sent->last()[0]->data()['data']['attributes']);
    }

    /* ───────────── PayMongo customer reuse ───────────── */

    public function test_the_paymongo_customer_id_is_stored_on_the_user_and_reused(): void
    {
        $this->fakePayMongo();
        $u = $this->makeUser();
        $gw = new PayMongoInstallmentGateway();

        $this->assertSame('cus_new', $gw->ensureCustomer($u));
        $this->assertSame('cus_new', $u->fresh()->paymongo_customer_id);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(); // anything sent from here would be recorded
        $this->assertSame('cus_new', $gw->ensureCustomer($u->fresh()));
        Http::assertNothingSent();
    }

    public function test_a_duplicate_email_422_looks_the_customer_up_instead_of_failing(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'api.paymongo.com/v1/customers*' => fn (Request $r) => $r->method() === 'POST'
                ? Http::response(['errors' => [['code' => 'invalid_request_body', 'detail' => 'A customer with this email already exists']]], 422)
                : Http::response(['data' => [['id' => 'cus_existing']]], 200),
        ]);
        $u = $this->makeUser();

        $this->assertSame('cus_existing', (new PayMongoInstallmentGateway())->ensureCustomer($u));

        $this->assertSame('cus_existing', $u->fresh()->paymongo_customer_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'email='));
    }

    public function test_a_customer_that_cannot_be_created_or_found_still_throws_for_the_caller_to_handle(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.paymongo.com/v1/customers*' => Http::response(['errors' => [['detail' => 'boom']]], 500)]);

        $this->expectException(\RuntimeException::class);
        (new PayMongoInstallmentGateway())->ensureCustomer($this->makeUser());
    }
}
