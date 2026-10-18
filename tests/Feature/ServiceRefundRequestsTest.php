<?php

namespace Tests\Feature;

use App\Models\ChatbotLog;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment as Payment;
use App\Models\ServiceRefundAttempt;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use App\Support\ServiceRefundRequestService;
use App\Support\ServiceWorkState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21f: cancellations and refunds for Other Services, the "Work started" step, the hold on payments, and the
 * privacy of the GCash number. Gateways are replaced by a fake that has no refund method: nothing here can reach
 * Stripe or PayMongo.
 */
class ServiceRefundRequestsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const GCASH = '09181112222';

    private User $admin;
    private User $owner;
    private User $other;
    private CancelBugGateway $gateway;
    private ServiceRefundRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = $this->makeUser('admin');
        $this->owner   = $this->makeUser();
        $this->other   = $this->makeUser();
        $this->gateway = new CancelBugGateway();
        $this->app->instance(InvoiceGateway::class, $this->gateway);
        $this->svc = app(ServiceRefundRequestService::class);
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

    /** A sent invoice for the owner. $firstHalf records the first half (or the full amount) in cash. */
    private function invoice(string $plan = 'half_half', bool $firstHalf = false, string $cost = '4000'): ServiceInvoice
    {
        static $n = 0;
        $n++;
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (6000 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => $cost, 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
        $desk    = app(ServiceInvoiceDesk::class);
        $invoice = $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
        if ($firstHalf) {
            app(ServiceInvoicePayments::class)->recordCash($invoice->id, $this->admin, $plan === 'full' ? $cost : (string) ((float) $cost / 2), true);
        }

        return $invoice->refresh();
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'reason_code' => 'change_of_mind', 'reason_text' => 'Plans changed.', 'contact_phone' => '09171234567', 'contact_email' => 'juan@example.com',
            'refund_method' => 'gcash', 'gcash_number' => self::GCASH, 'gcash_account_name' => 'Juan Dela Cruz', 'terms' => '1',
        ];
    }

    private function request(ServiceInvoice $i, array $over = [], string $source = 'form'): Req
    {
        $actor = $source === 'admin' ? $this->admin : $this->owner;

        return $this->svc->create($i->service_inquiry_id, $this->payload($over), $actor, $source);
    }

    private function unpaidPayload(array $over = []): array
    {
        return $this->payload(['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null] + $over);
    }

    private function startWork(ServiceInvoice $i): void
    {
        $this->svc->startWork($i->service_inquiry_id, $this->admin);
    }

    private function msgs(Req $r): string
    {
        return $r->messages()->pluck('body')->implode("\n");
    }

    /* ───────────── Part 1: the central block reason ───────────── */

    public function test_payment_block_reason_has_one_code_per_case(): void
    {
        $paid = $this->invoice('full', true);
        $this->assertSame('already_paid', $paid->fresh()->paymentBlockReason());

        $half = $this->invoice('half_half', true);
        $this->assertSame('installment_not_open', $half->fresh()->paymentBlockReason(), 'the second half waits for Service done');
        $this->assertNull($half->fresh()->hardBlockReason());
        app(ServiceInvoicePayments::class)->serviceDone($half->id, $this->admin);
        $this->assertNull($half->fresh()->paymentBlockReason());
        $this->assertTrue($half->fresh()->isPayable());

        $this->request($half);
        $this->assertSame('on_hold_cancellation_request', $half->fresh()->paymentBlockReason());
        $this->assertFalse($half->fresh()->isPayable());

        $ticketGone = $this->invoice('full');
        $ticketGone->inquiry->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame('ticket_cancelled', $ticketGone->fresh()->paymentBlockReason());

        $cancelled = $this->invoice('full');
        app(ServiceInvoiceDesk::class)->void($cancelled->id, $this->admin, 'Wrong amount entered.');
        $this->assertSame('invoice_cancelled', $cancelled->fresh()->paymentBlockReason());

        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-5999', 'service_type' => 'repair', 'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
        $draft = app(ServiceInvoiceDesk::class)->createDraft($ticket->id, $this->admin, ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '100', 'delivery_option' => 'none', 'customer_name' => 'Juan', 'customer_phone' => '09171234567',
        ]));
        $this->assertSame('draft', $draft->fresh()->paymentBlockReason());
    }

    public function test_a_hold_stops_payment_and_the_customer_sees_a_notice_and_no_pay_buttons_or_amount_due(): void
    {
        $i = $this->invoice('half_half', true);
        app(ServiceInvoicePayments::class)->serviceDone($i->id, $this->admin);
        $r = $this->request($i);

        $this->asOwner()->post(route('services.invoice.pay', [$i->id, 'gcash']))->assertRedirect()->assertSessionHas('error', 'Cancellation requested. Payments are paused until we reply.');
        $this->post(route('services.invoice.pay', [$i->id, 'stripe']))->assertRedirect();
        $this->assertSame(0, $this->gateway->calls);

        $page = $this->get(route('services.invoice', $i->id))->assertOk()
            ->assertSee('Cancellation requested')->assertSee('Payments are paused until we reply.')->assertSee($r->reference)
            ->assertDontSee('Pay with GCash')->assertDontSee('Pay with card')->assertDontSee('Pay remaining balance');
        $this->assertStringNotContainsString('<dt>Balance</dt>', $page->getContent());
        $this->assertStringNotContainsString('Request cancellation or refund', $page->getContent(), 'one open request: link to it, not a second button');

        // the service request page shows the hold too
        $this->get(route('services.request'))->assertOk()->assertSee('Cancellation requested')->assertSee('See request ' . $r->reference);
    }

    public function test_blocking_closes_open_checkouts_and_expires_the_stripe_session(): void
    {
        $i = $this->invoice('full');
        $this->asOwner()->post(route('services.invoice.pay', [$i->id, 'stripe']))->assertRedirect();
        $row = Payment::where('service_invoice_id', $i->id)->sole();
        $this->assertSame('pending', $row->status);

        app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, 'Wrong amount entered.');
        $this->assertSame('expired', $row->fresh()->status);
        $this->assertContains('cs_fake1', $this->gateway->expired);

        // and a hold does the same
        $j = $this->invoice('half_half', true);
        app(ServiceInvoicePayments::class)->serviceDone($j->id, $this->admin);
        $this->asOwner()->post(route('services.invoice.pay', [$j->id, 'stripe']))->assertRedirect();
        $pending = Payment::where('service_invoice_id', $j->id)->where('status', 'pending')->sole();
        $this->request($j);
        $this->assertSame('expired', $pending->fresh()->status);
        $this->assertContains($pending->provider_reference, $this->gateway->expired);
    }

    public function test_money_that_arrives_during_a_hold_is_recorded_and_raises_the_admin_alert(): void
    {
        $i = $this->invoice('half_half', true);
        app(ServiceInvoicePayments::class)->serviceDone($i->id, $this->admin);
        $this->asOwner()->post(route('services.invoice.pay', [$i->id, 'stripe']))->assertRedirect();
        $row = Payment::where('service_invoice_id', $i->id)->where('status', 'pending')->sole();
        $this->request($i);

        $session = (object) ['id' => $row->provider_reference, 'payment_status' => 'paid', 'payment_intent' => 'pi_late', 'amount_total' => 200000,
            'metadata' => (object) ['service_invoice_payment_id' => (string) $row->id]];
        $this->assertSame('paid', app(ServiceInvoicePayments::class)->handleStripeSession($session, 'checkout.session.completed'));

        $this->assertSame('paid', $row->fresh()->status);
        $alert = Notification::where('user_id', $this->admin->id)->where('title', 'Unmatched Payment')->first();
        $this->assertNotNull($alert);
        $this->assertStringContainsString('on hold for a cancellation request', $alert->message);
    }

    public function test_admin_ticket_cancel_without_payment_cancels_the_invoice_and_tells_the_customer(): void
    {
        $i = $this->invoice('full');
        $this->asAdmin()->post(route('admin.service-inquiries.close', $i->service_inquiry_id), ['reason' => 'Customer cannot be reached.'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $i->inquiry->fresh()->status);
        $this->assertSame('void', $i->fresh()->status);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Service Request Was Cancelled')->sole();
        $this->assertSame(route('services.invoice', $i->id), $n->url);
        $this->assertStringContainsString('SI-2026-', $n->message);

        $this->asOwner()->get($n->url)->assertOk()->assertSee('This invoice was cancelled')->assertDontSee('Pay with');
    }

    public function test_admin_cancel_of_a_ticket_with_a_payment_goes_to_the_request_flow_and_puts_the_invoice_on_hold(): void
    {
        $i = $this->invoice('half_half', true);
        $tid = $i->service_inquiry_id;

        $this->asAdmin()->post(route('admin.service-inquiries.close', $tid), ['reason' => 'Customer asked by phone.'])
            ->assertSessionHasErrors('ticket')->assertSessionHas('offer_cancellation_request', true);
        $this->get(route('admin.service-inquiries.show', $tid))->assertOk()
            ->assertSee('This service has a payment on it, so it must go through a cancellation request so the refund is recorded.')
            ->assertSee('Open a cancellation request');

        $this->post(route('admin.service-inquiries.refund-request', $tid), $this->payload())->assertSessionHasNoErrors();
        $r = Req::sole();
        $this->assertSame(['admin', 'new', 't' . $tid], [$r->source, $r->status, $r->open_slot]);
        $this->assertSame('new', $i->inquiry->fresh()->status, 'the ticket is not cancelled until the request is settled');
        $this->assertSame('on_hold_cancellation_request', $i->fresh()->paymentBlockReason());

        // a finished and fully paid service is a normal completion, not a cancellation
        $done = $this->invoice('full', true);
        app(ServiceInvoicePayments::class)->serviceDone($done->id, $this->admin);
        $this->post(route('admin.service-inquiries.close', $done->service_inquiry_id), ['reason' => 'All done and paid.'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $done->inquiry->fresh()->status);
        $this->assertSame('paid', $done->fresh()->status);
    }

    /* ───────────── Part 2: Work started ───────────── */

    public function test_work_started_button_undo_rules_and_service_done(): void
    {
        $i = $this->invoice('half_half', true);
        $t = $i->inquiry;
        $this->assertSame(ServiceWorkState::NOT_STARTED, ServiceWorkState::for($t));

        $this->asAdmin()->post(route('admin.service-inquiries.work-started', $t->id))->assertSessionHas('success');
        $t = $t->fresh();
        $this->assertNotNull($t->service_started_at);
        $this->assertStringContainsString('Work started', $t->admin_notes);
        $this->assertSame(ServiceWorkState::STARTED, ServiceWorkState::for($t));

        $this->post(route('admin.service-inquiries.work-started', $t->id))->assertSessionHasErrors('ticket');

        // undo is logged, and refused while a request is open
        $this->post(route('admin.service-inquiries.work-started-undo', $t->id))->assertSessionHas('success');
        $this->assertNull($t->fresh()->service_started_at);
        $this->assertStringContainsString('Work started was undone', $t->fresh()->admin_notes);
        $this->startWork($i);
        $this->request($i);
        $this->post(route('admin.service-inquiries.work-started-undo', $t->id))->assertSessionHasErrors('ticket');
        $this->assertNotNull($t->fresh()->service_started_at);

        // "Service done" starts the work when it was not started, and makes the state done
        $j = $this->invoice('half_half', true);
        $this->assertNull($j->inquiry->service_started_at);
        $this->post(route('admin.service-invoices.service-done', $j->id))->assertSessionHas('success');
        $this->assertNotNull($j->inquiry->fresh()->service_started_at);
        $this->assertSame(ServiceWorkState::DONE, ServiceWorkState::for($j->inquiry->fresh()));
        $this->post(route('admin.service-inquiries.work-started-undo', $j->inquiry->id))->assertSessionHasErrors('ticket');
    }

    /* ───────────── Part 3: creating a request ───────────── */

    public function test_nothing_paid_and_work_not_started_cancels_at_once_and_tells_both_sides(): void
    {
        $i = $this->invoice('half_half');
        $r = $this->svc->create($i->service_inquiry_id, $this->unpaidPayload(), $this->owner, 'form');

        $this->assertSame('closed', $r->status);
        $this->assertNull($r->open_slot);
        $this->assertNull($r->refund_method);
        $this->assertSame('cancelled', $i->inquiry->fresh()->status);
        $inv = $i->fresh();
        $this->assertSame('void', $inv->status);
        $this->assertSame("Cancelled at the customer's request, {$r->reference}", $inv->void_reason);
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Service Request Cancelled')->count());
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', 'New Cancellation Request')->count());
        $this->assertMatchesRegularExpression('/^REF-\d{4}-0001$/', $r->reference);
    }

    public function test_nothing_paid_but_work_started_still_goes_to_review_and_holds_the_invoice(): void
    {
        $i = $this->invoice('half_half');
        $this->startWork($i);
        $r = $this->svc->create($i->service_inquiry_id, $this->unpaidPayload(), $this->owner, 'form');

        $this->assertSame('new', $r->status);
        $this->assertSame('started', $r->work_state_snapshot);
        $this->assertSame('on_hold_cancellation_request', $i->fresh()->paymentBlockReason());
        $this->assertSame('new', $i->inquiry->fresh()->status);
    }

    public function test_only_one_open_request_per_ticket_even_on_a_double_submit(): void
    {
        $i = $this->invoice('half_half', true);
        $first = $this->request($i);

        try {
            $this->request($i);
            $this->fail('a second open request must be refused');
        } catch (PipelineException $e) {
            $this->assertStringContainsString($first->reference, $e->getMessage());
        }

        // the database refuses it too, whatever the code does
        $this->expectException(QueryException::class);
        Req::create(['reference' => 'REF-2026-9999', 'service_inquiry_id' => $i->service_inquiry_id, 'source' => 'form', 'reason_code' => 'other',
            'contact_phone' => '09170000000', 'contact_email' => 'a@b.test', 'work_state_snapshot' => 'not_started', 'status' => 'new', 'open_slot' => $first->open_slot]);
    }

    public function test_validation_phone_email_terms_gcash_formats_and_method(): void
    {
        $i = $this->invoice('half_half', true);
        $bad = function (array $over, string $key) use ($i) {
            try {
                $this->svc->create($i->service_inquiry_id, $this->payload($over), $this->owner, 'form');
                $this->fail("expected a validation error on {$key}");
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        };

        $bad(['contact_phone' => ''], 'contact_phone');
        $bad(['contact_phone' => '12345'], 'contact_phone');
        $bad(['contact_email' => ''], 'contact_email');
        $bad(['contact_email' => 'not-an-email'], 'contact_email');
        $bad(['terms' => null], 'terms');
        $bad(['reason_code' => 'nope'], 'reason_code');
        $bad(['refund_method' => null, 'gcash_number' => null], 'refund_method');
        $bad(['refund_method' => 'bitcoin'], 'refund_method');
        $bad(['gcash_number' => ''], 'gcash_number');
        $bad(['gcash_number' => '0918111222'], 'gcash_number');        // 10 digits
        $bad(['gcash_number' => '639181112222'], 'gcash_number');      // 12 characters: neither 11 nor 13
        $bad(['gcash_number' => '08181112222'], 'gcash_number');
        $bad(['gcash_account_name' => ''], 'gcash_account_name');
        $this->assertSame(0, Req::count());

        $this->assertSame('09181112222', ServiceRefundRequestService::normalizeGcash('+639181112222'));
        $this->assertSame('09181112222', ServiceRefundRequestService::normalizeGcash('0918-111-2222'));
        $r = $this->request($i, ['gcash_number' => '+639181112222']);
        $this->assertSame(self::GCASH, $r->gcash_number, 'stored in the 09 form');

        // cash needs no GCash fields
        $j = $this->invoice('half_half', true);
        $c = $this->request($j, ['refund_method' => 'cash', 'gcash_number' => null, 'gcash_account_name' => null]);
        $this->assertSame(['cash', null], [$c->refund_method, $c->gcash_number]);
    }

    public function test_decline_lifts_the_hold_and_the_invoice_is_payable_again(): void
    {
        $i = $this->invoice('half_half', true);
        app(ServiceInvoicePayments::class)->serviceDone($i->id, $this->admin);
        $r = $this->request($i);
        $this->assertFalse($i->fresh()->isPayable());

        $this->svc->decline($r->id, $this->admin, 'Work has already started, so we cannot refund it.');
        $this->assertNull($r->fresh()->open_slot);
        $this->assertTrue($i->fresh()->isPayable(), 'payable again');

        // and the customer can ask again later
        $again = $this->request($i);
        $this->assertSame('new', $again->status);
    }

    /* ───────────── Part 5: admin decisions ───────────── */

    public function test_approve_is_only_for_not_started_and_exceptions_need_a_reason(): void
    {
        $i = $this->invoice('half_half', true);
        $this->startWork($i);
        $r = $this->request($i);

        try {
            $this->svc->approve($r->id, $this->admin);
            $this->fail('normal approve must be refused once work started');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('already started', $e->getMessage());
        }
        $this->assertSame('new', $r->fresh()->status);

        foreach (['', 'short'] as $reason) {
            try {
                $this->svc->approveAsException($r->id, $this->admin, $reason);
                $this->fail('an exception needs a written reason');
            } catch (PipelineException $e) {
                $this->assertStringContainsString('exception', $e->getMessage());
            }
        }
        $this->assertSame('new', $r->fresh()->status);

        $r = $this->svc->approveAsException($r->id, $this->admin, 'The laborer was delayed by the shop for a week.');
        $this->assertSame('approved', $r->status);
        $this->assertTrue($r->approved_as_exception);
        $this->assertSame('2000.00', (string) $r->approved_amount, 'the full amount paid, read from the invoice');
        $this->assertStringContainsString('EXCEPTION', $r->inquiry->admin_notes);
        $this->assertEqualsWithDelta(Req::REFUND_DAYS * 86400, $r->refund_due_at->timestamp - now()->timestamp, 5);

        // a normal approval works while work has not started, and the exception path is refused then
        $j = $this->invoice('half_half', true);
        $r2 = $this->request($j);
        try {
            $this->svc->approveAsException($r2->id, $this->admin, 'Work has not started so this is not one.');
            $this->fail('exception only after work started');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('normal Approve', $e->getMessage());
        }
        $r2 = $this->svc->approve($r2->id, $this->admin);
        $this->assertSame(['approved', false, '2000.00'], [$r2->status, $r2->approved_as_exception, (string) $r2->approved_amount]);
    }

    public function test_the_live_work_state_decides_not_the_snapshot(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->request($i);            // snapshot: not started
        $this->startWork($i);               // then the work starts
        $this->expectException(PipelineException::class);
        $this->svc->approve($r->id, $this->admin);
    }

    public function test_decline_needs_twenty_characters_and_ask_for_info_round_trip(): void
    {
        $i = $this->invoice('half_half', true);
        $this->startWork($i);
        $r = $this->request($i);

        $this->asAdmin()->post(route('admin.refunds.decline', $r->id), ['explanation' => 'Too short.'])->assertSessionHasErrors('request');
        $this->assertSame('new', $r->fresh()->status);

        $this->post(route('admin.refunds.ask-info', $r->id), ['message' => 'Why did you want to cancel?'])->assertSessionHas('success');
        $this->assertSame('needs_info', $r->fresh()->status);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'We Need a Little More Information')->sole();
        $this->assertSame(route('services.refund.show', $r->reference), $n->url);

        $this->asOwner()->post(route('services.refund.reply', $r->reference), ['body' => 'The price changed.'])->assertSessionHas('success');
        $this->assertSame('new', $r->fresh()->status);
        $this->assertSame(1, Notification::where('user_id', $this->admin->id)->where('title', 'Customer Replied on a Cancellation Request')->count());

        $this->asAdmin()->post(route('admin.refunds.decline', $r->id), ['explanation' => 'Work has already started on this service.'])->assertSessionHas('success');
        $r = $r->fresh();
        $this->assertSame('declined', $r->status);
        $this->assertSame('Work has already started on this service.', $r->decision_note);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Request Was Not Approved')->sole();
        $this->assertStringContainsString('Work has already started on this service.', $n->message);
    }

    public function test_mark_refunded_needs_a_transaction_number_and_a_note_when_the_amount_differs(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->svc->approve($this->request($i)->id, $this->admin);
        $ok = ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => 'GC-778899', 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null];

        foreach (['', 'abc'] as $tx) {
            try {
                $this->svc->markRefunded($r->id, $this->admin, ['transaction_number' => $tx] + $ok);
                $this->fail('transaction number is required');
            } catch (PipelineException $e) {
                $this->assertStringContainsString('transaction number', $e->getMessage());
            }
        }
        try {
            $this->svc->markRefunded($r->id, $this->admin, ['amount' => '1500'] + $ok);
            $this->fail('a different amount needs a note');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('note explaining the difference', $e->getMessage());
        }
        try {
            $this->svc->markRefunded($r->id, $this->admin, ['sent_on' => now('Asia/Manila')->addDay()->toDateString()] + $ok);
            $this->fail('not in the future');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('future', $e->getMessage());
        }
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, ServiceRefundAttempt::count());

        $r = $this->svc->markRefunded($r->id, $this->admin, ['amount' => '1500', 'note' => 'Customer kept 500 for the delivery already done.'] + $ok);
        $this->assertSame('refunded', $r->status);
        $this->assertSame('1500.00', (string) ServiceRefundAttempt::sole()->amount);
    }

    public function test_refunded_cancels_the_invoice_and_ticket_and_is_the_only_way_a_paid_invoice_is_cancelled(): void
    {
        $i = $this->invoice('half_half', true);
        // the old rule stands for every other path
        try {
            app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, 'Customer cancelled; refunded in person.');
            $this->fail('a paid invoice must not be cancellable here');
        } catch (PipelineException $e) {
            $this->assertSame(ServiceInvoicePayments::CANCEL_BLOCKED, $e->getMessage());
        }
        $this->assertSame('issued', $i->fresh()->status);

        $r = $this->svc->approve($this->request($i)->id, $this->admin);
        $this->assertSame('issued', $i->fresh()->status, 'approval alone does not cancel');
        $r = $this->svc->markRefunded($r->id, $this->admin, ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => 'GC-778899', 'sent_on' => '2026-10-19', 'note' => null]);

        $this->assertSame('refunded', $r->status);
        $this->assertNull($r->open_slot);
        $inv = $i->fresh();
        $this->assertSame('void', $inv->status);
        $this->assertSame("Cancelled at the customer's request, {$r->reference}", $inv->void_reason);
        $this->assertSame('cancelled', $i->inquiry->fresh()->status);
        $this->assertSame('invoice_cancelled', $inv->paymentBlockReason());
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Refund Was Sent')->sole();
        $this->assertStringContainsString('GC-778899', $n->message);
        $this->assertSame(route('services.refund.show', $r->reference), $n->url);

        // the explanation page now shows the refund
        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->assertSee('Your refund was sent')
            ->assertSee('₱2,000.00')->assertSee('GCash')->assertSee('GC-778899')->assertSee('October 19, 2026')->assertSee($r->reference);
        // the request page shows it too
        $this->get(route('services.refund.show', $r->reference))->assertOk()->assertSee('GC-778899')->assertSee('Refunded');
    }

    public function test_the_wrong_gcash_number_path_asks_for_the_right_one_and_keeps_the_history(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->svc->approve($this->request($i)->id, $this->admin);

        $this->asAdmin()->post(route('admin.refunds.refund-failed', $r->id), ['problem' => 'wrong_gcash_number', 'note' => 'The number belongs to someone else.'])->assertSessionHas('success');
        $r = $r->fresh();
        $this->assertSame('refund_problem', $r->status);
        $this->assertSame('failed_wrong_details', ServiceRefundAttempt::sole()->outcome);
        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Refund Needs Your Attention')->sole();
        $this->assertSame(route('services.refund.show', $r->reference), $n->url);
        $this->assertStringNotContainsString(self::GCASH, $n->message);

        $this->asOwner()->get(route('services.refund.show', $r->reference))->assertOk()->assertSee('Send corrected details')->assertSee('We could not send your refund');

        $this->post(route('services.refund.reply', $r->reference), ['gcash_number' => '12345', 'gcash_account_name' => 'Juan'])->assertSessionHasErrors('gcash_number');
        $this->assertSame('refund_problem', $r->fresh()->status);

        $this->post(route('services.refund.reply', $r->reference), ['gcash_number' => '09175550000', 'gcash_account_name' => 'juan dela cruz'])->assertSessionHas('success');
        $r = $r->fresh();
        $this->assertSame(['approved', '09175550000'], [$r->status, $r->gcash_number]);
        $this->assertSame(1, ServiceRefundAttempt::count(), 'the old attempt stays in the history');

        $this->asAdmin()->post(route('admin.refunds.mark-refunded', $r->id), ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => 'GC-100200', 'sent_on' => now('Asia/Manila')->toDateString()])
            ->assertSessionHas('success');
        $this->assertSame(2, ServiceRefundAttempt::count());
        $this->assertSame('refunded', $r->fresh()->status);
    }

    public function test_overdue_refunds_are_highlighted_and_the_badge_counts_what_needs_attention(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->svc->approve($this->request($i)->id, $this->admin);
        $this->assertSame(0, Req::attentionCount());

        $this->asAdmin()->get(route('admin.refunds.index'))->assertOk()->assertDontSee('rf-overdue-tag');
        $r->forceFill(['refund_due_at' => now()->subDay()])->save();
        $this->assertTrue($r->fresh()->isOverdue());
        $this->get(route('admin.refunds.index'))->assertOk()->assertSee('Overdue')->assertSee('class="rf-overdue"', false);
        $this->assertSame(1, Req::attentionCount());

        $new = $this->invoice('half_half', true);
        $this->request($new);
        $this->assertSame(2, Req::attentionCount(), 'new requests count too');
    }

    public function test_every_transition_notifies_with_the_contact_details_and_a_link_to_the_request_page(): void
    {
        \App\Models\BusinessSetting::query()->first()?->forceFill(['phone' => '0917-222-3333', 'email' => 'owner@lp.test'])->save();
        \Illuminate\Support\Facades\Cache::forget(\App\Models\BusinessSetting::CACHE_KEY);

        $i = $this->invoice('half_half', true);
        $r = $this->request($i);
        $r = $this->svc->approve($r->id, $this->admin);
        $r = $this->svc->markRefundFailed($r->id, $this->admin, 'gcash_not_found', null);
        $r = $this->svc->customerReply($r->id, $this->owner, ['gcash_number' => '09175550000', 'gcash_account_name' => 'Juan']);
        $r = $this->svc->markRefunded($r->id, $this->admin, ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => 'GC-555666', 'sent_on' => now('Asia/Manila')->toDateString()]);

        $titles = ['Cancellation Request Received', 'Your Request Was Approved', 'Your Refund Needs Your Attention', 'Your Refund Was Sent'];
        foreach ($titles as $title) {
            $n = Notification::where('user_id', $this->owner->id)->where('title', $title)->sole();
            $this->assertSame(route('services.refund.show', $r->reference), $n->url, $title);
            $this->assertStringContainsString('0917-222-3333', $n->message, $title);
            $this->assertStringContainsString('owner@lp.test', $n->message, $title);
        }
        $approved = Notification::where('title', 'Your Request Was Approved')->first();
        $this->assertStringContainsString('up to 7 days after approval', $approved->message);

        // declined, and the no-refund cancellation
        $j = $this->invoice('half_half', true);
        $this->startWork($j);
        $d = $this->svc->decline($this->request($j)->id, $this->admin, 'Work has already started on this service.');
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Your Request Was Not Approved')->count());
        $k = $this->invoice('half_half');
        $this->svc->create($k->service_inquiry_id, $this->unpaidPayload(), $this->owner, 'form');
        $this->assertSame(1, Notification::where('user_id', $this->owner->id)->where('title', 'Service Request Cancelled')->count());
    }

    /* ───────────── privacy of the GCash number ───────────── */

    public function test_the_gcash_number_is_encrypted_at_rest_masked_in_lists_and_never_logged(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->request($i);
        $r = $this->svc->approve($r->id, $this->admin);
        $r = $this->svc->markRefundFailed($r->id, $this->admin, 'wrong_gcash_number', 'Please check it.');

        $raw = (string) DB::table('service_refund_requests')->where('id', $r->id)->value('gcash_number');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::GCASH, $raw);
        $this->assertSame(self::GCASH, $r->fresh()->gcash_number, 'decrypts through the model');
        $this->assertSame('0918 *** 2222', $r->maskedGcash());
        $this->assertArrayNotHasKey('gcash_number', $r->toArray(), 'hidden from serialisation');

        // lists show it masked, only the detail page shows it in full
        $this->asAdmin()->get(route('admin.refunds.index'))->assertOk()->assertSee('0918 *** 2222')->assertDontSee(self::GCASH);
        $this->get(route('admin.refunds.show', $r->id))->assertOk()->assertSee(self::GCASH)->assertSee('Copy number');

        // never in the activity log, notifications, the thread, chatbot logs or the application log
        $this->assertStringNotContainsString(self::GCASH, (string) $i->inquiry->fresh()->admin_notes);
        $this->assertStringNotContainsString('1112222', (string) $i->inquiry->fresh()->admin_notes);
        foreach (Notification::all() as $n) {
            $this->assertStringNotContainsString(self::GCASH, $n->title . ' ' . $n->message . ' ' . $n->url);
        }
        $this->assertStringNotContainsString(self::GCASH, $this->msgs($r));
        $this->assertStringNotContainsString(self::GCASH, ServiceRefundAttempt::all()->toJson());
        $this->assertStringNotContainsString(self::GCASH, ChatbotLog::all()->toJson());
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $this->assertStringNotContainsString(self::GCASH, (string) file_get_contents($log));
        }
        // the customer's own pages show nothing but what they typed; the request page does not echo the number
        $this->asOwner()->get(route('services.refund.show', $r->reference))->assertOk()->assertDontSee(self::GCASH);
    }

    public function test_no_code_path_calls_a_stripe_or_paymongo_refund_api(): void
    {
        $this->assertFalse(method_exists(InvoiceGateway::class, 'refund'));
        $this->assertFalse(method_exists(InvoiceGateway::class, 'refundStripe'));

        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            $this->assertDoesNotMatchRegularExpression('#paymongo\.com/v1/refunds|->refunds->create|refunds\(\)->create|/v1/refunds#i', $src, $file->getFilename());
        }

        // a full request lifecycle makes no gateway call at all
        $i = $this->invoice('half_half', true);
        $calls = $this->gateway->calls;
        $r = $this->svc->approve($this->request($i)->id, $this->admin);
        $this->svc->markRefunded($r->id, $this->admin, ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => 'GC-1234', 'sent_on' => now('Asia/Manila')->toDateString()]);
        $this->assertSame($calls, $this->gateway->calls);
    }

    /* ───────────── customer pages and routes ───────────── */

    public function test_the_request_form_flow_from_the_invoice_page_to_the_confirmation_page(): void
    {
        $i = $this->invoice('half_half', true);
        $tid = $i->service_inquiry_id;

        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->assertSee('Request cancellation or refund');

        $form = $this->get(route('services.refund.create', $tid))->assertOk()
            ->assertSee('You can get back the full amount you paid')->assertSee('Other Services cancellation and refund terms');
        $this->assertStringNotContainsString('Work has already started', $form->getContent());

        $this->post(route('services.refund.review', $tid), $this->payload(['terms' => null]))->assertSessionHasErrors('terms');
        $this->post(route('services.refund.review', $tid), $this->payload())->assertOk()
            ->assertSee('Check your request')->assertSee('Send my request')->assertSee('2,000.00');
        $this->assertSame(0, Req::count(), 'the summary saves nothing');

        $res = $this->post(route('services.refund.store', $tid), $this->payload());
        $r = Req::sole();
        $res->assertRedirect(route('services.refund.sent', $r->reference));
        $this->get(route('services.refund.sent', $r->reference))->assertOk()->assertSee($r->reference)
            ->assertSee('A refund, if approved, takes up to 7 days after approval.');
        $this->get(route('services.refund.show', $r->reference))->assertOk()->assertSee('We are reviewing your request')->assertSee('Under review');

        // with the request open, the form sends the customer to it
        $this->get(route('services.refund.create', $tid))->assertRedirect(route('services.refund.show', $r->reference));
    }

    public function test_the_form_wording_when_work_has_started_and_when_nothing_was_paid(): void
    {
        $i = $this->invoice('half_half', true);
        $this->startWork($i);
        $this->asOwner()->get(route('services.refund.create', $i->service_inquiry_id))->assertOk()
            ->assertSee('Work has already started on this service. Under our terms, refunds are usually not possible once work starts. You can still send your request and our team will review it and reply to you.');

        $free = $this->invoice('half_half');
        $this->get(route('services.refund.create', $free->service_inquiry_id))->assertOk()->assertSee('Cancel this service request')
            ->assertDontSee('Where should we send your refund?');
    }

    public function test_another_customer_and_guests_cannot_reach_any_customer_route(): void
    {
        $i = $this->invoice('half_half', true);
        $tid = $i->service_inquiry_id;
        $r = $this->request($i);

        $routes = [
            ['GET', route('services.refund.create', $tid)],
            ['POST', route('services.refund.review', $tid)],
            ['POST', route('services.refund.store', $tid)],
            ['GET', route('services.refund.show', $r->reference)],
            ['GET', route('services.refund.sent', $r->reference)],
            ['POST', route('services.refund.reply', $r->reference)],
        ];
        foreach ($routes as [$method, $url]) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->call($method, $url, $this->payload())->assertRedirect(route('login'));

            $this->asOwner($this->other)->call($method, $url, $this->payload())->assertNotFound();
        }
        $this->assertSame('new', $r->fresh()->status);
        $this->assertSame(1, Req::count());
    }

    public function test_every_new_admin_route_is_admin_only(): void
    {
        $i = $this->invoice('half_half', true);
        $r = $this->request($i);
        $tid = $i->service_inquiry_id;

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($rt) => str_starts_with($rt->getName() ?? '', 'admin.refunds.')
            || in_array($rt->getName(), ['admin.service-inquiries.refund-request', 'admin.service-inquiries.work-started', 'admin.service-inquiries.work-started-undo'], true));
        $this->assertCount(11, $routes);

        foreach ($routes as $route) {
            $method = $route->methods()[0];
            $uri = '/' . ltrim(str_replace('{id}', str_starts_with($route->getName(), 'admin.refunds.') ? (string) $r->id : (string) $tid, $route->uri()), '/');

            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $this->call($method, $uri)->assertRedirect();   // guest: to the admin login

            $this->actingAs($this->owner, 'web');
            $res = $this->call($method, $uri);
            $this->assertTrue($res->isRedirect() || $res->getStatusCode() === 403 || $res->getStatusCode() === 404, "customer {$method} {$uri}");
            $this->assertNotSame(200, $res->getStatusCode(), "customer {$method} {$uri}");
            $this->app['auth']->forgetGuards();
        }
        $this->assertSame('new', $r->fresh()->status);
        $this->assertNull($i->inquiry->fresh()->service_started_at);
    }

    /* ───────────── Part 6: the terms ───────────── */

    public function test_the_other_services_terms_page_and_the_form_checkbox(): void
    {
        $this->get('/services/terms')->assertOk()
            ->assertSee('Other Services terms')->assertSee('Cancellation and refunds')
            ->assertSee('A refund is possible only if the service has not started', false)
            ->assertSee('up to 7 days after we approve it', false)
            ->assertSee('GCash or cash', false);

        $payload = [
            'service_type' => 'repair', 'furniture_type' => 'Table', 'concern' => 'The leg of my table is broken, please fix it.',
            'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
        ];
        $this->asOwner()->get(route('services.request'))->assertOk()->assertSee('Other Services terms')->assertSee('I have read and agree to the');
        $this->post(route('services.request.submit'), $payload)->assertSessionHasErrors('terms');
        $this->assertSame(0, ServiceInquiry::count());
        $this->post(route('services.request.submit'), $payload + ['terms' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull(ServiceInquiry::sole()->terms_accepted_at);
    }
}
