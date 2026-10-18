<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21f, Part 1: the owner cancelled a ticket that had a first-half payment on it; the admin side showed
 * "cancelled" but the customer could still pay the second half and saw no notice. These tests were written
 * first and failed against the old code (see the report).
 */
class CancelledTicketPaymentBugTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;
    private User $owner;
    private CancelBugGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin   = $this->makeUser('admin');
        $this->owner   = $this->makeUser();
        $this->gateway = new CancelBugGateway();
        $this->app->instance(InvoiceGateway::class, $this->gateway);
    }

    private function asOwner(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->owner);
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'admin');
    }

    /** A sent half-and-half invoice; $firstHalfPaid records the first half in cash, $done taps "Service done". */
    private function invoice(bool $firstHalfPaid, bool $done = false, string $plan = 'half_half'): ServiceInvoice
    {
        static $n = 0;
        $n++;
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (7000 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '4000', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
        $desk    = app(ServiceInvoiceDesk::class);
        $invoice = $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
        $payments = app(ServiceInvoicePayments::class);
        if ($firstHalfPaid) {
            $payments->recordCash($invoice->id, $this->admin, '2000', true);
        }
        if ($done) {
            $payments->serviceDone($invoice->id, $this->admin);
        }

        return $invoice->refresh();
    }

    public function test_a_payment_cannot_start_once_the_ticket_is_cancelled_by_any_path(): void
    {
        $invoice = $this->invoice(true, true);
        // However the ticket got cancelled, the stored result is the same status.
        $invoice->inquiry->forceFill(['status' => 'cancelled'])->save();

        foreach (['gcash', 'stripe'] as $method) {
            $this->asOwner()->post(route('services.invoice.pay', [$invoice->id, $method]))->assertRedirect();
        }
        $this->assertSame(0, $this->gateway->calls, 'no checkout may be created for a cancelled ticket');
        $this->assertSame(0, $invoice->payments()->where('status', 'pending')->count());
    }

    public function test_closing_a_ticket_with_a_payment_on_it_is_refused_and_points_to_a_cancellation_request(): void
    {
        $invoice = $this->invoice(true);
        $ticket  = $invoice->inquiry;

        $this->asAdmin()->post(route('admin.service-inquiries.close', $ticket->id), ['reason' => 'Customer changed their mind'])
            ->assertRedirect(route('admin.service-inquiries.show', $ticket->id))
            ->assertSessionHasErrors('ticket');

        $this->assertSame('new', $ticket->fresh()->status, 'the ticket must not be quietly cancelled while money is on it');
    }

    public function test_closing_a_ticket_with_an_unpaid_sent_invoice_cancels_the_invoice_too(): void
    {
        $invoice = $this->invoice(false);

        $this->asAdmin()->post(route('admin.service-inquiries.close', $invoice->service_inquiry_id), ['reason' => 'Customer changed their mind'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $invoice->inquiry->fresh()->status);
        $this->assertSame(ServiceInvoice::VOID, $invoice->fresh()->status);

        $this->asOwner()->post(route('services.invoice.pay', [$invoice->id, 'gcash']))->assertRedirect();
        $this->assertSame(0, $this->gateway->calls);
    }
}
