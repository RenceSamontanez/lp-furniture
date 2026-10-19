<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceWorkState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21h part 1: "no pay, no work". Work can start only after the customer paid (pay in full: the whole
 * invoice; half and half: the first half). Cash recorded by an admin counts the same. Enforced on the server,
 * including through "Service done".
 */
class NoPayNoWorkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeUser();
        $this->app->instance(InvoiceGateway::class, new CancelBugGateway());
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'admin');
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'reason_code' => 'change_of_mind', 'reason_text' => 'Plans changed.', 'contact_phone' => '09171234567', 'contact_email' => 'juan@example.com',
            'refund_method' => 'gcash', 'gcash_number' => '09181112222', 'gcash_account_name' => 'Juan Dela Cruz', 'terms' => '1',
        ];
    }

    private function ticket(): ServiceInquiry
    {
        static $n = 0;
        $n++;

        return ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (9100 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
    }

    private function draft(ServiceInquiry $t, string $cost = '4000', string $plan = 'full'): ServiceInvoice
    {
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => $cost, 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);

        return app(ServiceInvoiceDesk::class)->createDraft($t->id, $this->admin, $clean);
    }

    private function sent(ServiceInquiry $t, string $cost = '4000', string $plan = 'full'): ServiceInvoice
    {
        $d = $this->draft($t, $cost, $plan);

        return app(ServiceInvoiceDesk::class)->issue($d->id, $this->admin);
    }

    private function cash(ServiceInvoice $i, string $amount): void
    {
        $this->asAdmin()->post(route('admin.service-invoices.cash', $i->id), ['amount' => $amount, 'confirm' => '1'])->assertSessionHas('success');
    }

    public function test_every_reason_code(): void
    {
        $t = $this->ticket();
        $this->assertSame('no_invoice', ServiceWorkState::canStart($t));

        $d = $this->draft($t);
        $this->assertSame('invoice_draft', ServiceWorkState::canStart($t->fresh()));

        $i = app(ServiceInvoiceDesk::class)->issue($d->id, $this->admin);
        $this->assertSame('awaiting_payment', ServiceWorkState::canStart($t->fresh()));

        $this->cash($i, '4000.00');
        $this->assertSame(ServiceWorkState::ALLOWED, ServiceWorkState::canStart($t->fresh()));

        $t2 = $this->ticket();
        $i2 = $this->sent($t2);
        $i2->forceFill(['status' => ServiceInvoice::VOID])->saveQuietly();
        $this->assertSame('invoice_cancelled', ServiceWorkState::canStart($t2->fresh()));

        $t3 = $this->ticket();
        $i3 = $this->sent($t3);
        $this->cash($i3, '4000.00');
        app(\App\Support\ServiceRefundRequestService::class)->create($t3->id, $this->payload(), $this->owner, 'form');
        $this->assertSame('on_hold', ServiceWorkState::canStart($t3->fresh()));

        $t4 = $this->ticket();
        $i4 = $this->sent($t4);
        $this->cash($i4, '4000.00');
        $this->asAdmin()->post(route('admin.service-inquiries.work-started', $t4->id))->assertSessionHas('success');
        $this->assertSame('already_started', ServiceWorkState::canStart($t4->fresh()));
    }

    public function test_pay_in_full_needs_the_whole_invoice(): void
    {
        $t = $this->ticket();
        $i = $this->sent($t, '4000', 'full');
        $this->assertFalse(ServiceWorkState::paidEnough($i->fresh()));
        $this->cash($i, '4000.00');
        $this->assertTrue(ServiceWorkState::paidEnough($i->fresh()));
    }

    public function test_half_and_half_needs_only_the_first_half(): void
    {
        $t = $this->ticket();
        $i = $this->sent($t, '4000', 'half_half');
        $this->assertSame('awaiting_payment', ServiceWorkState::canStart($t->fresh()));
        $this->cash($i, '2000.00');
        $this->assertSame(ServiceWorkState::ALLOWED, ServiceWorkState::canStart($t->fresh()));
        $this->asAdmin()->post(route('admin.service-inquiries.work-started', $t->id))->assertSessionHas('success');
        $this->assertNotNull($t->fresh()->service_started_at);
    }

    public function test_a_forged_start_post_is_refused_for_every_unpaid_state(): void
    {
        $none = $this->ticket();
        $draft = $this->ticket();
        $this->draft($draft);
        $unpaid = $this->ticket();
        $this->sent($unpaid, '4000', 'half_half');

        foreach ([$none, $draft, $unpaid] as $t) {
            $this->asAdmin()->post(route('admin.service-inquiries.work-started', $t->id))->assertSessionHasErrors('ticket');
            $this->assertNull($t->fresh()->service_started_at);
        }
    }

    public function test_a_customer_cannot_start_work(): void
    {
        $t = $this->ticket();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->post(route('admin.service-inquiries.work-started', $t->id))->assertRedirect();
        $this->assertNull($t->fresh()->service_started_at);
    }

    public function test_service_done_cannot_start_work_that_was_not_allowed_to_start(): void
    {
        $t = $this->ticket();
        $i = $this->sent($t, '4000', 'half_half');

        $this->asAdmin()->post(route('admin.service-invoices.service-done', $i->id))->assertSessionHasErrors('invoice');
        $this->assertNull($t->fresh()->service_started_at);
        $this->assertNull($i->fresh()->service_done_at);

        $this->cash($i, '2000.00');
        $this->post(route('admin.service-invoices.service-done', $i->id))->assertSessionHas('success');
        $this->assertNotNull($t->fresh()->service_started_at);
    }

    public function test_service_done_service_call_throws_when_unpaid(): void
    {
        $t = $this->ticket();
        $i = $this->sent($t);
        $this->expectException(PipelineException::class);
        app(\App\Support\ServiceInvoicePayments::class)->serviceDone($i->id, $this->admin);
    }

    public function test_tickets_started_before_the_rule_are_left_alone_and_flagged(): void
    {
        $t = $this->ticket();
        $this->sent($t);
        $t->forceFill(['service_started_at' => now()])->save();

        $this->assertTrue(ServiceWorkState::startedWithoutPayment($t->fresh()));
        $this->asAdmin()->get(route('admin.service-inquiries.show', $t->id))
            ->assertOk()->assertSee('Started before payments were required')->assertDontSee('data-lp-modal-open="si-start-modal"', false);
        $this->assertNotNull($t->fresh()->service_started_at);
    }

    public function test_the_work_panel_in_each_state(): void
    {
        $t = $this->ticket();
        $this->asAdmin()->get(route('admin.service-inquiries.show', $t->id))
            ->assertSee('Create an invoice first')->assertDontSee('Start work')->assertDontSee('Not started');

        $i = $this->sent($t, '4000', 'half_half');
        $this->get(route('admin.service-inquiries.show', $t->id))
            ->assertSee('Waiting for payment of ' . $i->invoice_number . ', ₱2,000.00')->assertDontSee('data-lp-modal-open="si-start-modal"', false);

        $this->cash($i, '2000.00');
        $this->get(route('admin.service-inquiries.show', $t->id))
            ->assertSee('data-lp-modal-open="si-start-modal"', false)->assertSee('Half paid: ₱2,000.00 of ₱4,000.00. The balance of ₱2,000.00 is due after the service.')
            ->assertSee('After this, the customer can no longer get an automatic refund.');

        $this->post(route('admin.service-inquiries.work-started', $t->id));
        $this->get(route('admin.service-inquiries.show', $t->id))
            ->assertSee('Work started')->assertSee('Mark service done')->assertDontSee('Start work');
    }

    public function test_ready_to_start_counter_and_filter(): void
    {
        $paid = $this->ticket();
        $this->cash($this->sent($paid), '4000.00');
        $unpaid = $this->ticket();
        $this->sent($unpaid);
        $started = $this->ticket();
        $this->cash($this->sent($started), '4000.00');
        $this->post(route('admin.service-inquiries.work-started', $started->id));

        $this->asAdmin()->get(route('admin.service-inquiries.index', ['ready' => 1]))
            ->assertOk()->assertSee('Ready to start (1)')->assertSee($paid->reference_number)
            ->assertDontSee($unpaid->reference_number)->assertDontSee($started->reference_number);
    }

    public function test_nothing_paid_customer_cancellation_stays_instant(): void
    {
        $t = $this->ticket();
        $i = $this->sent($t);
        $svc = app(\App\Support\ServiceRefundRequestService::class);
        $r = $svc->create($t->id, $this->payload(['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null]), $this->owner, 'form');

        $this->assertNull($t->fresh()->service_started_at);
        $this->assertSame('cancelled', $t->fresh()->status);
        $this->assertSame('invoice_cancelled', $i->fresh()->paymentBlockReason());
        $this->assertSame('invoice_cancelled', ServiceWorkState::canStart($t->fresh()));
        $this->assertNotNull($r);
    }
}
