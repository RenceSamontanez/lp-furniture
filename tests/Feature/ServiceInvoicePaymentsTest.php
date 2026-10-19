<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment as Payment;
use App\Models\ServiceInvoiceRefundNote;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\InvoicePaymentRules;
use App\Support\Luma\DefaultIntents;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21e: online and cash payments for Other Services invoices. Gateways are always replaced by a fake:
 * nothing here can reach Stripe or PayMongo, and nothing can start a refund (the fake has no refund method at all).
 */
class ServiceInvoicePaymentsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const STRIPE_SECRET   = 'whsec_unit_test_secret';
    private const PAYMONGO_SECRET = 'pm_unit_test_secret';

    private User $admin;
    private User $owner;
    private FakeInvoiceGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeUser();
        $this->gateway = new FakeInvoiceGateway();
        $this->app->instance(InvoiceGateway::class, $this->gateway);
        config(['services.stripe.webhook' => self::STRIPE_SECRET, 'services.paymongo.webhook_secret' => self::PAYMONGO_SECRET]);
        BusinessSetting::query()->first()?->forceFill(['phone' => '0917-222-3333', 'email' => 'owner@lp.test'])->save();
        Cache::forget(BusinessSetting::CACHE_KEY);
    }

    /* ───────────── helpers ───────────── */

    private function asOwner(?User $user = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user ?? $this->owner);
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'admin');
    }

    /** A sent invoice for the owner, built through the real desk so every rule applies. */
    private function invoice(string $cost = '4000', string $plan = 'full', string $type = 'repair', ?User $customer = null): ServiceInvoice
    {
        static $n = 0;
        $n++;
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (8100 + $n), 'service_type' => $type, 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => ($customer ?? $this->owner)->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => $cost, 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
        $desk = app(ServiceInvoiceDesk::class);

        return $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
    }

    private function pay(ServiceInvoice $i, string $method = 'gcash')
    {
        return $this->asOwner()->post(route('services.invoice.pay', [$i->id, $method]));
    }

    private function stripeWebhook(string $eventId, string $type, Payment $row, ?int $amountCents = null, string $payment = 'paid')
    {
        $payload = json_encode(['id' => $eventId, 'object' => 'event', 'type' => $type, 'data' => ['object' => [
            'id' => $row->provider_reference, 'object' => 'checkout.session', 'payment_status' => $payment, 'payment_intent' => 'pi_' . $row->id,
            'amount_total' => $amountCents ?? (int) round($row->amount * 100),
            'metadata' => ['kind' => 'service_invoice', 'service_invoice_payment_id' => (string) $row->id, 'service_invoice_id' => (string) $row->service_invoice_id],
        ]]]);
        $t   = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, self::STRIPE_SECRET);

        return $this->call('POST', '/webhook/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function paymongoWebhook(string $type, Payment $row, ?int $amountCents = null)
    {
        $payload = json_encode(['data' => ['attributes' => ['type' => $type, 'data' => ['attributes' => [
            'payment_intent_id' => $row->provider_reference, 'amount' => $amountCents ?? (int) round($row->amount * 100),
        ]]]]]);
        $t   = time();
        $sig = 't=' . $t . ',te=' . hash_hmac('sha256', $t . '.' . $payload, self::PAYMONGO_SECRET) . ',li=';

        return $this->call('POST', '/webhook/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function row(ServiceInvoice $i, int $installment = 1): Payment
    {
        return Payment::where('service_invoice_id', $i->id)->where('installment', $installment)->orderByDesc('id')->firstOrFail();
    }

    private function adminAlerts(): int
    {
        return Notification::where('user_id', $this->admin->id)->where('title', 'Unmatched Payment')->count();
    }

    /* ───────────── plan rules and config defaults ───────────── */

    public function test_the_config_defaults_and_installment_split(): void
    {
        $this->assertSame(3000.00, InvoicePaymentRules::HALF_HALF_MIN_TOTAL);
        $this->assertSame(40.00, InvoicePaymentRules::MIN_ONLINE_PAYMENT);
        $this->assertTrue(InvoicePaymentRules::allowsHalfHalf('repair', '3000.00'));
        $this->assertTrue(InvoicePaymentRules::allowsHalfHalf('labor_only_own_wood', '12000'));
        $this->assertFalse(InvoicePaymentRules::allowsHalfHalf('repair', '2999.99'));
        $this->assertTrue(InvoicePaymentRules::allowsHalfHalf('other', '9000'), 'Something else may split too (Stage 21h)');
        $this->assertTrue(InvoicePaymentRules::allowsHalfHalf('something_new', '3000'), 'any future type may split');
        $this->assertSame([1 => 200000], InvoicePaymentRules::installments('full', 200000));
        $this->assertSame([1 => 150000, 2 => 150000], InvoicePaymentRules::installments('half_half', 300000));
        $this->assertSame([1 => 150000, 2 => 150001], InvoicePaymentRules::installments('half_half', 300001), 'the odd centavo goes to the second half');
    }

    public function test_the_plan_is_chosen_when_sending_and_the_rules_are_enforced(): void
    {
        $i = $this->invoice('4000', 'half_half');
        $this->assertSame('half_half', $i->fresh()->payment_plan);
        $this->assertSame('full', $this->invoice('4000', 'full')->payment_plan);

        foreach ([['2999', 'repair'], ['1200', 'other']] as [$cost, $type]) {
            try {
                $this->invoice($cost, 'half_half', $type);
                $this->fail("half and half must be refused for {$type} {$cost}");
            } catch (PipelineException $e) {
                $this->assertStringContainsString('Half and half', $e->getMessage() . ' Half and half');
            }
        }

        // the sent invoice's plan is locked
        $this->expectException(\App\Exceptions\ImmutableRecordException::class);
        $i->forceFill(['payment_plan' => 'full'])->save();
    }

    /* ───────────── pay in full ───────────── */

    public function test_pay_in_full_by_gcash_creates_a_pending_payment_and_the_verified_webhook_marks_it_paid(): void
    {
        $i = $this->invoice('4000');
        $this->assertTrue($this->pay($i, 'gcash')->isRedirect('https://gcash.test/1'));

        $row = $this->row($i);
        $this->assertSame('pending', $row->status);
        $this->assertSame('4000.00', (string) $row->amount);
        $this->assertSame('paymongo', $row->method);
        $this->assertSame($this->owner->email, $this->gateway->lastBilling['email'], 'PayMongo needs an email: the account email is used when the invoice has none');
        $this->assertSame('sent', strtolower($i->fresh()->statusLabel()));
        $this->assertSame('0.00', (string) $i->fresh()->amount_paid, 'nothing is paid just because a checkout was started');

        $this->paymongoWebhook('payment.paid', $row)->assertOk();

        $i = $i->fresh();
        $this->assertSame('paid', $i->status);
        $this->assertSame('4000.00', (string) $i->amount_paid);
        $this->assertSame('paid', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->paid_at);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Invoice Fully Paid')->sole();
        $this->assertStringContainsString('0917-222-3333', $n->message);
        $this->assertStringContainsString('owner@lp.test', $n->message);
        $this->assertSame(route('services.invoice', $i->id), $n->url);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', 'Invoice Payment Received')->count());
    }

    public function test_pay_in_full_by_card_uses_server_side_amounts_and_the_event_table_makes_the_webhook_idempotent(): void
    {
        $i = $this->invoice('4000');
        // a tampered request body can not change the amount: the amount is never read from the request
        $this->asOwner()->post(route('services.invoice.pay', [$i->id, 'stripe']), ['amount' => '1', 'installment' => 2])->assertRedirect('https://checkout.stripe.test/1');

        $p = $this->gateway->stripeParams[0];
        $this->assertSame(400000, $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('service_invoice', $p['metadata']['kind']);
        $this->assertGreaterThan(time() + 31 * 60, $p['expires_at']);
        $this->assertLessThan(time() + 24 * 3600, $p['expires_at']);

        $row = $this->row($i);
        $this->stripeWebhook('evt_1', 'checkout.session.completed', $row)->assertOk()->assertJson(['status' => 'received']);
        $this->stripeWebhook('evt_1', 'checkout.session.completed', $row)->assertOk()->assertJson(['status' => 'duplicate']);
        // a different event for the same session (Stripe can send completed and async_succeeded) never double counts
        $this->stripeWebhook('evt_2', 'checkout.session.async_payment_succeeded', $row)->assertOk();

        $this->assertSame('4000.00', (string) $i->fresh()->amount_paid);
        $this->assertSame(1, Payment::where('service_invoice_id', $i->id)->where('status', 'paid')->count());
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Invoice Fully Paid')->count());
    }

    public function test_a_double_click_reuses_the_open_checkout_instead_of_creating_a_second_payment(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe')->assertRedirect('https://checkout.stripe.test/1');
        $this->pay($i, 'stripe')->assertRedirect('https://checkout.stripe.test/1');

        $this->assertSame(1, $this->gateway->stripeCreated);
        $this->assertSame(1, Payment::where('service_invoice_id', $i->id)->count());
    }

    public function test_the_return_page_never_marks_anything_paid_by_itself(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $row = $this->row($i);

        // Stripe says the session is not paid: the page asks for patience and nothing changes
        $this->gateway->stripeSession = (object) ['id' => $row->provider_reference, 'payment_status' => 'unpaid', 'amount_total' => 400000,
            'metadata' => (object) ['kind' => 'service_invoice', 'service_invoice_payment_id' => (string) $row->id]];
        $this->asOwner()->get(route('services.invoice.payment.return', [$i->id, 'success']) . '?session_id=' . $row->provider_reference)
            ->assertOk()->assertSee('We are confirming your payment');
        $this->assertSame('pending', $row->fresh()->status);
        $this->assertSame('issued', $i->fresh()->status);

        // a made-up session id matches nothing and also changes nothing
        $this->asOwner()->get(route('services.invoice.payment.return', [$i->id, 'success']) . '?session_id=cs_made_up')->assertOk();
        $this->assertSame('pending', $row->fresh()->status);

        // Stripe itself says paid: the server-side check confirms it
        $this->gateway->stripeSession->payment_status = 'paid';
        $this->asOwner()->get(route('services.invoice.payment.return', [$i->id, 'success']) . '?session_id=' . $row->provider_reference)
            ->assertOk()->assertSee('Payment received');
        $this->assertSame('paid', $i->fresh()->status);

        $this->asOwner()->get(route('services.invoice.payment.return', [$i->id, 'cancelled']))->assertOk()->assertSee('Payment cancelled')->assertSee('nothing was charged');
        $this->asOwner()->get(route('services.invoice.payment.return', [$i->id, 'failed']))->assertOk()->assertSee('Payment could not be completed');
    }

    /* ───────────── half and half ───────────── */

    public function test_half_and_half_end_to_end(): void
    {
        $i = $this->invoice('4000.01', 'half_half');
        $due = ServiceInvoicePayments::due($i);
        $this->assertSame([1, 200000, true], [$due['installment'], $due['cents'], $due['available']]);

        // first half
        $this->pay($i, 'gcash');
        $r1 = $this->row($i);
        $this->assertSame('2000.00', (string) $r1->amount);
        $this->paymongoWebhook('payment.paid', $r1)->assertOk();
        $i = $i->fresh();
        $this->assertSame('issued', $i->status);
        $this->assertSame('2000.00', (string) $i->amount_paid);
        $this->assertSame('Half paid', $i->paymentStateLabel());
        $this->assertSame('Payment Received', Notification::where('user_id', $this->owner->id)->latest('id')->first()->title);
        $this->assertStringContainsString('2,000.01', Notification::where('user_id', $this->owner->id)->latest('id')->first()->message);

        // the second half cannot be paid before Service done (online or cash), and no gateway is called
        $this->assertFalse(ServiceInvoicePayments::due($i)['available']);
        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()
            ->assertSee('Pay the rest after the service')->assertSee('opens when we mark your service as done. We will notify you.')->assertSee('Pay remaining balance')->assertSee('Half paid');
        $created = $this->gateway->paymongoCreated + $this->gateway->stripeCreated;
        $this->pay($i, 'stripe')->assertRedirect(route('services.invoice', $i->id))->assertSessionHas('error');
        $this->pay($i, 'gcash')->assertSessionHas('error');
        $this->assertSame($created, $this->gateway->paymongoCreated + $this->gateway->stripeCreated);
        $this->assertSame(1, Payment::where('service_invoice_id', $i->id)->count());
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '2000.01', 'confirm' => '1'])->assertSessionHasErrors('invoice');

        // Service done enables it and tells the customer
        $this->asAdmin()->post(route('admin.service-invoices.service-done', $i->id))->assertSessionHas('success');
        $i = $i->fresh();
        $this->assertNotNull($i->service_done_at);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Balance Due Now')->sole();
        $this->assertStringContainsString('Your service is done', $n->message);
        $this->assertStringContainsString('2,000.01', $n->message);
        $this->assertStringContainsString('0917-222-3333', $n->message);
        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->assertSee('Pay remaining balance')->assertSee('Pay with GCash')->assertDontSee('Pay the rest after the service');

        // second half
        $this->pay($i, 'stripe')->assertRedirect('https://checkout.stripe.test/1');
        $r2 = $this->row($i, 2);
        $this->assertSame('2000.01', (string) $r2->amount);
        $this->stripeWebhook('evt_h2', 'checkout.session.completed', $r2)->assertOk();

        $i = $i->fresh();
        $this->assertSame('paid', $i->status);
        $this->assertSame('4000.01', (string) $i->amount_paid, 'sum of the two halves, with the odd centavo on the second');
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Invoice Fully Paid')->count());
    }

    public function test_service_done_cannot_be_repeated_and_needs_a_live_invoice(): void
    {
        $i = $this->invoice("4000", "half_half");
        ServiceInquiry::whereKey($i->service_inquiry_id)->update(["service_started_at" => now()]);   // legacy start; the payment rule is tested in NoPayNoWorkTest
        $this->asAdmin()->post(route('admin.service-invoices.service-done', $i->id))->assertSessionHas('success');
        $this->post(route('admin.service-invoices.service-done', $i->id))->assertSessionHasErrors('invoice');
        $this->assertStringContainsString('service marked as done', $i->inquiry->fresh()->admin_notes);

        $c = $this->invoice('4000');
        app(ServiceInvoiceDesk::class)->void($c->id, $this->admin, 'Wrong amount entered.');
        $this->post(route('admin.service-invoices.service-done', $c->id))->assertSessionHasErrors('invoice');
    }

    /* ───────────── the webhook edge cases ───────────── */

    public function test_a_wrong_amount_is_not_applied_and_alerts_the_admin(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $row = $this->row($i);

        $this->stripeWebhook('evt_x', 'checkout.session.completed', $row, 100000)->assertOk();

        $this->assertSame('issued', $i->fresh()->status);
        $this->assertSame('0.00', (string) $i->fresh()->amount_paid);
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertStringContainsString('Amount mismatch', (string) $row->fresh()->note);
        $this->assertSame(1, $this->adminAlerts());
        $this->assertStringContainsString('does not match', Notification::where('title', 'Unmatched Payment')->first()->message);
    }

    public function test_a_payment_that_arrives_after_the_invoice_was_cancelled_is_recorded_and_alerts_the_admin(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $row = $this->row($i);

        // cancelled while the customer is on the checkout page (no payment yet, so cancelling is allowed)
        app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, 'Wrong amount entered.');

        $this->stripeWebhook('evt_late', 'checkout.session.completed', $row)->assertOk();

        $i = $i->fresh();
        $this->assertSame('void', $i->status, 'a late payment never changes a cancelled invoice');
        $this->assertSame('0.00', (string) $i->amount_paid);
        $r = $row->fresh();
        $this->assertSame('paid', $r->status, 'but the money is on record');
        $this->assertStringContainsString('Refund needed', (string) $r->note);
        $this->assertSame(1, $this->adminAlerts());
        $this->assertStringContainsString('cancelled before this payment arrived', Notification::where('title', 'Unmatched Payment')->first()->message);
        $this->assertSame(0, Notification::where('user_id', $this->owner->id)->where('title', 'Invoice Fully Paid')->count());

        // the same event again does not raise a second alert
        $this->stripeWebhook('evt_late', 'checkout.session.completed', $row)->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, $this->adminAlerts());

        // and a refund can still be recorded on it (the old note service stays; the admin form was replaced by cancellation requests)
        app(ServiceInvoicePayments::class)->addRefundNote($i->id, $this->admin, '4000', 'stripe', 'Refunded in the Stripe dashboard.');
        $this->assertSame(1, ServiceInvoiceRefundNote::where('service_invoice_id', $i->id)->count());
    }

    public function test_a_second_payment_for_an_installment_that_is_already_paid_is_flagged_not_counted(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $card = $this->row($i);
        $this->pay($i, 'gcash'); // the customer also opened GCash in another tab
        $gcash = Payment::where('service_invoice_id', $i->id)->where('method', 'paymongo')->sole();

        $this->stripeWebhook('evt_a', 'checkout.session.completed', $card)->assertOk();
        $this->paymongoWebhook('payment.paid', $gcash)->assertOk();

        $this->assertSame('4000.00', (string) $i->fresh()->amount_paid);
        $this->assertSame('failed', $gcash->fresh()->status);
        $this->assertStringContainsString('Duplicate', (string) $gcash->fresh()->note);
        $this->assertSame(1, $this->adminAlerts());
    }

    public function test_a_failed_payment_notifies_the_customer_and_expiry_does_not(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'gcash');
        $this->paymongoWebhook('payment.failed', $this->row($i))->assertOk();
        $this->assertSame('failed', $this->row($i)->status);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Payment Could Not Be Completed')->sole();
        $this->assertStringContainsString('0917-222-3333', $n->message);

        $this->pay($i, 'stripe');
        $this->stripeWebhook('evt_exp', 'checkout.session.expired', $this->row($i), null, 'unpaid')->assertOk();
        $this->assertSame('expired', $this->row($i)->status);
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Payment Could Not Be Completed')->count());
    }

    public function test_both_webhooks_refuse_everything_when_their_secret_is_not_set(): void
    {
        config(['services.stripe.webhook' => '', 'services.paymongo.webhook_secret' => '']);
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $row = $this->row($i);

        // signed with the empty key, exactly what a forger would do against an unset secret
        $payload = json_encode(['id' => 'evt_forged', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $row->provider_reference]]]);
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, '');
        $this->call('POST', '/webhook/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig], $payload)->assertStatus(400);
        $this->paymongoWebhook('payment.paid', $row)->assertStatus(400);
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'gcash');
        $row = $this->row($i);
        $this->call('POST', '/webhook/paymongo', [], [], [], ['HTTP_PAYMONGO_SIGNATURE' => 't=1,te=deadbeef,li='], '{"data":{}}')->assertStatus(400);
        $this->assertSame('pending', $row->fresh()->status);
    }

    /* ───────────── paying a cancelled, draft or paid invoice ───────────── */

    public function test_a_payment_can_not_start_on_a_cancelled_draft_or_paid_invoice(): void
    {
        $cancelled = $this->invoice('4000');
        app(ServiceInvoiceDesk::class)->void($cancelled->id, $this->admin, 'Wrong amount entered.');

        // through the route: the middleware sends the customer to the explanation page, no gateway call
        $this->pay($cancelled, 'stripe')->assertRedirect(route('services.invoice', $cancelled->id));
        $this->pay($cancelled, 'gcash')->assertRedirect(route('services.invoice', $cancelled->id))->assertSessionHas('error');
        $this->asOwner()->get(route('services.invoice', $cancelled->id))->assertOk()->assertSee('This invoice was cancelled')->assertDontSee('Pay with');

        // the service refuses on its own, even if a route were ever missed
        try {
            app(ServiceInvoicePayments::class)->start($cancelled->id, 'stripe');
            $this->fail('start() must refuse a cancelled invoice');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('can no longer be paid', $e->getMessage());
        }

        // a draft is a plain 404 for the customer, and refused by the service
        $ticket = ServiceInquiry::create(['reference_number' => 'SI-2026-8999', 'service_type' => 'repair', 'concern' => 'x', 'name' => 'J', 'phone' => '09171234567', 'email' => 'j@e.test', 'status' => 'new', 'user_id' => $this->owner->id]);
        $draft = app(ServiceInvoiceDesk::class)->createDraft($ticket->id, $this->admin, ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '4000', 'delivery_option' => 'none', 'customer_name' => 'J', 'customer_phone' => '09171234567']));
        $this->pay($draft, 'stripe')->assertNotFound();
        $this->expectDraftRefused($draft);

        // an already paid invoice: no second payment
        $paid = $this->invoice('4000');
        $this->pay($paid, 'gcash');
        $this->paymongoWebhook('payment.paid', $this->row($paid))->assertOk();
        $before = $this->gateway->paymongoCreated + $this->gateway->stripeCreated;
        $this->pay($paid, 'stripe')->assertRedirect(route('services.invoice', $paid->id))->assertSessionHas('error');
        $this->assertSame($before, $this->gateway->paymongoCreated + $this->gateway->stripeCreated);

        $this->assertSame(0, Payment::where('service_invoice_id', $cancelled->id)->count());
        $this->assertSame(0, Payment::where('service_invoice_id', $draft->id)->count());
    }

    private function expectDraftRefused(ServiceInvoice $draft): void
    {
        try {
            app(ServiceInvoicePayments::class)->start($draft->id, 'gcash');
            $this->fail('start() must refuse a draft');
        } catch (PipelineException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_online_payments_below_the_minimum_are_refused_but_cash_still_works(): void
    {
        $i = $this->invoice('30');
        $this->pay($i, 'stripe')->assertSessionHas('error');
        $this->assertSame(0, $this->gateway->stripeCreated);
        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->assertSee('must be at least ₱40.00');

        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '30', 'confirm' => '1'])->assertSessionHas('success');
        $this->assertSame('paid', $i->fresh()->status);
    }

    /* ───────────── ownership ───────────── */

    public function test_another_customer_and_a_guest_cannot_pay_and_learn_nothing(): void
    {
        $i = $this->invoice('4000');
        $other = $this->makeUser();

        $this->asOwner($other)->post(route('services.invoice.pay', [$i->id, 'stripe']))->assertNotFound();
        $this->post(route('services.invoice.pay', [$i->id, 'gcash']))->assertNotFound();
        $this->get(route('services.invoice.payment.return', [$i->id, 'success']))->assertNotFound();
        // identical to an invoice that does not exist
        $this->post(route('services.invoice.pay', [999999, 'stripe']))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post(route('services.invoice.pay', [$i->id, 'stripe']))->assertRedirect(route('login'));
        $this->get(route('services.invoice.print', $i->id))->assertRedirect(route('login'));

        $this->assertSame(0, $this->gateway->stripeCreated + $this->gateway->paymongoCreated);
        $this->assertSame(0, Payment::count());
    }

    /* ───────────── cash ───────────── */

    public function test_cash_needs_the_exact_amount_and_a_confirmation_and_is_logged(): void
    {
        $i = $this->invoice('4000');
        $url = route('admin.service-invoices.cash', $i->id);

        $this->asAdmin()->post($url, ['amount' => '3999.99', 'confirm' => '1'])->assertSessionHasErrors('invoice');
        $this->post($url, ['amount' => '4000.01', 'confirm' => '1'])->assertSessionHasErrors('invoice');
        $this->post($url, ['amount' => '4000'])->assertSessionHasErrors('confirm');
        $this->post($url, ['amount' => 'abc', 'confirm' => '1'])->assertSessionHasErrors('amount');
        $this->assertSame(0, Payment::count());

        $this->post($url, ['amount' => '4000', 'confirm' => '1'])->assertSessionHas('success');
        $row = $this->row($i);
        $this->assertSame(['cash', 'paid', $this->admin->id], [$row->method, $row->status, $row->recorded_by]);
        $i = $i->fresh();
        $this->assertSame(['paid', '4000.00', 'cash'], [$i->status, (string) $i->amount_paid, $i->paid_method]);
        $this->assertStringContainsString('cash payment of ₱4,000.00', $i->inquiry->fresh()->admin_notes);
        $this->assertStringContainsString('fully paid', $i->inquiry->fresh()->admin_notes);

        // recording it twice is refused (the invoice is paid) and never creates a second paid row
        $this->post($url, ['amount' => '4000', 'confirm' => '1'])->assertSessionHasErrors('invoice');
        $this->assertSame(1, Payment::where('status', 'paid')->count());
    }

    public function test_cash_closes_an_open_online_attempt_for_the_same_installment(): void
    {
        $i = $this->invoice('4000');
        $this->pay($i, 'stripe');
        $card = $this->row($i);
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '4000', 'confirm' => '1'])->assertSessionHas('success');
        $this->assertSame('expired', $card->fresh()->status);
    }

    public function test_one_installment_can_only_have_one_paid_record_in_the_database(): void
    {
        $i = $this->invoice('4000');
        Payment::create(['service_invoice_id' => $i->id, 'installment' => 1, 'amount' => '4000', 'method' => 'cash', 'status' => 'paid', 'paid_slot' => $i->id . '-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Payment::create(['service_invoice_id' => $i->id, 'installment' => 1, 'amount' => '4000', 'method' => 'stripe', 'status' => 'paid', 'paid_slot' => $i->id . '-1', 'provider_reference' => 'cs_x']);
    }

    /* ───────────── cancel and refund notes ───────────── */

    public function test_cancel_is_blocked_once_there_is_a_payment_in_the_screen_and_the_controller(): void
    {
        $i = $this->invoice('4000', 'half_half');
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '2000', 'confirm' => '1'])->assertSessionHas('success');

        $msg = 'This invoice has a payment on it. Open a cancellation request so the refund is recorded.';
        $this->get(route('admin.service-invoices.show', $i->id))->assertOk()->assertSee('This invoice has a payment on it')
            ->assertSee('Open a cancellation request so the refund is recorded.')->assertDontSee('id="inv-open-cancel"', false);

        $this->post(route('admin.service-invoices.void', $i->id), ['reason' => 'Customer changed their mind.'])->assertSessionHasErrors(['invoice' => $msg]);
        $this->assertSame('issued', $i->fresh()->status);

        // before any payment, cancel still works
        $free = $this->invoice('4000');
        $this->post(route('admin.service-invoices.void', $free->id), ['reason' => 'Wrong amount entered.'])->assertSessionHas('success');
        $this->assertSame('void', $free->fresh()->status);
    }

    public function test_old_refund_notes_stay_readable_but_the_form_and_route_are_gone_and_no_gateway_is_called(): void
    {
        $i = $this->invoice('4000');
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '4000', 'confirm' => '1']);
        $calls = $this->gateway->calls;
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.service-invoices.refund-note'), 'the admin refund note form was replaced by cancellation requests');

        $svc = app(ServiceInvoicePayments::class);
        $svc->addRefundNote($i->id, $this->admin, '1000', 'gcash', 'Refunded 1,000 through GCash.');
        $note = ServiceInvoiceRefundNote::sole();
        $this->assertSame(['1000.00', 'gcash', $this->admin->id], [(string) $note->amount, $note->method, $note->recorded_by]);

        // the table and its data are untouched, and show read-only on the invoice page
        $this->get(route('admin.service-invoices.show', $i->id))->assertOk()->assertSee('Earlier refund notes')->assertSee('Refunded 1,000 through GCash.')
            ->assertDontSee('Save refund note');

        $this->assertSame($calls, $this->gateway->calls, 'no gateway call of any kind');
        $this->assertFalse(method_exists(InvoiceGateway::class, 'refund'));
    }

    /* ───────────── printed invoice, wording, Luma ───────────── */

    public function test_the_printed_invoice_shows_unpaid_half_paid_and_paid_without_provider_references(): void
    {
        $i = $this->invoice('4000', 'half_half');
        $print = fn () => $this->asOwner()->get(route('services.invoice.print', $i->id))->assertOk()->getContent();

        $html = $print();
        $this->assertStringContainsString('Unpaid', $html);
        $this->assertStringContainsString('This is an invoice for services rendered. It is not an official receipt.', $html);

        $this->pay($i, 'gcash');
        $row = $this->row($i);
        $this->paymongoWebhook('payment.paid', $row);
        $html = $print();
        $this->assertStringContainsString('Half paid', $html);
        $this->assertStringContainsString('GCash (online) · first half: ₱2,000.00', $html);
        $this->assertStringContainsString('Balance: ₱2,000.00', $html);
        $this->assertStringNotContainsString((string) $row->provider_reference, $html);

        $this->asAdmin()->post(route('admin.service-invoices.service-done', $i->id));
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => '2000', 'confirm' => '1']);
        $html = $print();
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('remaining balance: ₱2,000.00', $html);
        $this->assertStringContainsString('Amount received: ₱4,000.00', $html);
    }

    public function test_the_sent_wording_is_now_true_and_the_pay_button_exists(): void
    {
        $this->assertStringContainsString('can pay it online', ServiceInvoice::SEND_CONFIRMATION);
        $i = $this->invoice('4000');
        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->assertSee('Pay with GCash')->assertSee('Pay with card');
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Invoice Is Ready')->sole();
        $this->assertStringContainsString('pay it online', $n->message);
    }

    public function test_luma_explains_paying_an_other_services_invoice_and_sync_is_insert_only(): void
    {
        $def = DefaultIntents::find('luma_service_invoice_payment');
        $this->assertNotNull($def);
        foreach (['GCash', 'card', 'cash', 'half', 'service is done', 'account'] as $word) {
            $this->assertStringContainsStringIgnoringCase($word, $def['en']);
        }
        $this->assertNotSame('', trim($def['tl']));
        $this->assertStringContainsString('GCash', $def['tl']);

        DefaultIntents::sync();
        $this->assertSame(1, \App\Models\ChatbotIntent::where('category', 'luma_service_invoice_payment')->count());
        \App\Models\ChatbotIntent::where('category', 'luma_service_invoice_payment')->update(['response' => 'Edited by an admin']);
        DefaultIntents::sync();
        $this->assertSame('Edited by an admin', \App\Models\ChatbotIntent::where('category', 'luma_service_invoice_payment')->value('response'));
    }
}

/** Replaces Stripe and PayMongo: records calls, returns canned data. It has no refund method on purpose. */
class FakeInvoiceGateway extends InvoiceGateway
{
    public int $stripeCreated = 0;
    public int $paymongoCreated = 0;
    public int $calls = 0;
    public array $stripeParams = [];
    public array $lastBilling = [];
    public ?object $stripeSession = null;
    public array $paymongoIntent = ['status' => 'pending', 'amount_cents' => 0];

    public function createStripeSession(array $params): object
    {
        $this->calls++;
        $this->stripeCreated++;
        $this->stripeParams[] = $params;

        return (object) ['id' => 'cs_test_fake' . $this->stripeCreated, 'url' => 'https://checkout.stripe.test/' . $this->stripeCreated, 'payment_intent' => 'pi_fake' . $this->stripeCreated];
    }

    public function retrieveStripeSession(string $sessionId): object
    {
        $this->calls++;

        return $this->stripeSession ?? throw new \RuntimeException('no session');
    }

    public function expireStripeSession(string $sessionId): void
    {
        $this->calls++;
    }

    public function createPaymongoGcash(int $amountCents, string $description, array $metadata, array $billing, string $returnUrl): array
    {
        $this->calls++;
        $this->paymongoCreated++;
        $this->lastBilling = $billing;

        return ['intent_id' => 'pi_pm_fake' . $this->paymongoCreated, 'url' => 'https://gcash.test/' . $this->paymongoCreated];
    }

    public function retrievePaymongoIntent(string $intentId): array
    {
        $this->calls++;

        return $this->paymongoIntent;
    }
}
