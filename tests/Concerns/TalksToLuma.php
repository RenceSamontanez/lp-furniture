<?php

namespace Tests\Concerns;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use Tests\Feature\CancelBugGateway;

/**
 * Helpers for the Luma conversation tests: a chat that keeps its session between messages (like a real browser), and
 * Other Services tickets, invoices and payments built through the real services. The payment gateways are replaced by a
 * fake that has no refund method, so nothing here can reach Stripe or PayMongo.
 */
trait TalksToLuma
{
    use BuildsCustomOrderFixtures;

    protected User $admin;
    protected User $owner;
    protected User $other;
    protected int $ticketSeq = 0;

    protected function setUpLuma(): void
    {
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeUser();
        $this->other = $this->makeUser();
        $this->app->instance(InvoiceGateway::class, new CancelBugGateway());
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
    }

    /** One chat message; the session cookie is carried to the next message, so Luma remembers the conversation. */
    protected function say(string $message): array
    {
        $response = $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk();

        $name = config('session.cookie');
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                $this->withCookie($name, $cookie->getValue());
            }
        }

        return $response->json();
    }

    /** A new browser: no cookies, no memory. */
    protected function newBrowser(): void
    {
        $this->flushSession();
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
    }

    protected function loginAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);
    }

    /** @return array{0: ServiceInquiry, 1: ServiceInvoice} a sent invoice (not paid unless $payFirst) for $owner */
    protected function ticketWithInvoice(string $plan = 'full', bool $payFirst = false, string $cost = '4000', ?User $for = null): array
    {
        $this->ticketSeq++;
        $for ??= $this->owner;

        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (7000 + $this->ticketSeq + random_int(0, 80000)), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'The table leg is cracked near the joint.', 'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $for->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => $cost, 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
        $desk    = app(ServiceInvoiceDesk::class);
        $invoice = $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
        if ($payFirst) {
            app(ServiceInvoicePayments::class)->recordCash($invoice->id, $this->admin, $plan === 'full' ? $cost : (string) ((float) $cost / 2), true);
        }

        return [$ticket->refresh(), $invoice->refresh()];
    }

    protected function startWork(ServiceInquiry $ticket): void
    {
        ServiceInquiry::whereKey($ticket->id)->update(['service_started_at' => now()]);
    }

    /** The form values a customer would fill in. */
    protected function formValues(ServiceInquiry $ticket, array $over = []): array
    {
        return $over + [
            'step' => 'review', 'ticket_id' => $ticket->id, 'lang' => 'en',
            'reason_code' => 'change_of_mind', 'reason_text' => 'Plans changed.', 'contact_phone' => '09171234567', 'contact_email' => 'juan@example.com',
            'refund_method' => 'gcash', 'gcash_number' => '09181112222', 'gcash_account_name' => 'Juan Dela Cruz', 'terms' => '1',
        ];
    }
}
