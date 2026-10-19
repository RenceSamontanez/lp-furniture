<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\NotificationTemplates;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceNotificationDetails;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** Stage 21h part 3: read-only details pages for Other Services notifications. */
class ServiceNotificationDetailsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;
    private User $owner;
    private User $other;
    private ServiceRefundRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeUser();
        $this->other = $this->makeUser();
        $this->app->instance(InvoiceGateway::class, new CancelBugGateway());
        $this->svc = app(ServiceRefundRequestService::class);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u);
    }

    private function ticketWithInvoice(string $plan = 'half_half', bool $paid = true): array
    {
        static $n = 0;
        $n++;
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (9800 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '4000', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
        $desk = app(ServiceInvoiceDesk::class);
        $inv = $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
        if ($paid) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->admin, 'admin')->post(route('admin.service-invoices.cash', $inv->id), ['amount' => $plan === 'half_half' ? '2000.00' : '4000.00', 'confirm' => '1'])->assertSessionHas('success');
        }

        return [$ticket->fresh(), $inv->fresh()];
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'reason_code' => 'change_of_mind', 'reason_text' => 'Plans changed.', 'contact_phone' => '09171234567', 'contact_email' => 'juan@example.com',
            'refund_method' => 'gcash', 'gcash_number' => '09181112222', 'gcash_account_name' => 'Juan D.', 'terms' => '1',
        ];
    }

    private function notify(string $template, string $url, array $values = []): Notification
    {
        $t = NotificationTemplates::render($template, $values + ['contact' => '']);

        return Notification::create(['user_id' => $this->owner->id, 'title' => $t['title'], 'message' => $t['message'], 'type' => 'general', 'url' => $url, 'is_read' => false]);
    }

    /** One notification of every Other Services kind, built the way production code builds it. @return array<string, Notification> */
    private function allKinds(): array
    {
        [$ticket, $inv] = $this->ticketWithInvoice();
        $invUrl = route('services.invoice', $inv->id);
        $n = [];
        $n['request_received']  = $this->notify('inquiry_received', route('services.request'), ['reference' => $ticket->reference_number, 'phone' => '09171234567', 'window' => null]);
        $n['call_update']       = $this->notify('inquiry_call_ringing', route('services.request'), ['reference' => $ticket->reference_number, 'times' => 'once']);
        $n['invoice_ready']     = $this->notify('service_invoice_issued', $invUrl, ['invoice' => $inv->invoice_number, 'service' => 'Repair', 'total' => 4000]);
        $n['payment_received']  = $this->notify('service_invoice_payment_received', $invUrl, ['invoice' => $inv->invoice_number, 'amount' => '2000.00', 'balance_note' => '']);
        $n['balance_due']       = $this->notify('service_invoice_balance_due', $invUrl, ['invoice' => $inv->invoice_number, 'amount' => 2000]);
        $n['fully_paid']        = $this->notify('service_invoice_fully_paid', $invUrl, ['invoice' => $inv->invoice_number, 'amount' => 4000]);
        $n['payment_failed']    = $this->notify('service_invoice_payment_failed', $invUrl, ['invoice' => $inv->invoice_number, 'amount' => 2000]);

        $r = $this->svc->create($ticket->id, $this->payload(), $this->owner, 'form');
        $reqUrl = route('services.refund.show', $r->reference);
        $n['cancellation_received'] = $this->notify('refund_received', $reqUrl, ['reference' => $ticket->reference_number, 'request' => $r->reference]);
        $n['needs_info']     = $this->notify('refund_needs_info', $reqUrl, ['request' => $r->reference, 'message' => 'Why did you cancel?']);
        $r = $this->svc->approve($r->id, $this->admin);
        $n['approved']       = $this->notify('refund_approved', $reqUrl, ['request' => $r->reference, 'refund_line' => 'You get back ₱2,000.00.']);
        $n['refund_problem'] = $this->notify('refund_problem', $reqUrl, ['request' => $r->reference, 'problem' => 'The GCash number was not found.']);
        $this->svc->markRefunded($r->id, $this->admin, ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => '6028871884223', 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null]);
        $n['refunded']       = $this->notify('refund_refunded', $reqUrl, ['request' => $r->reference, 'amount' => 2000, 'method' => 'GCash', 'transaction' => '6028871884223']);
        $n['invoice_cancelled'] = $this->notify('service_invoice_cancelled', $invUrl, ['invoice' => $inv->invoice_number]);

        // a declined request, a request cancelled by the shop and a cancelled-without-refund one
        [$t2, $i2] = $this->ticketWithInvoice('full', true);
        $r2 = $this->svc->create($t2->id, $this->payload(), $this->owner, 'form');
        $this->svc->decline($r2->id, $this->admin, 'Work has already started so we cannot refund the cost of the work done.');
        $n['declined'] = $this->notify('refund_declined', route('services.refund.show', $r2->reference), ['request' => $r2->reference, 'explanation' => 'Work has already started.']);

        [$t3, $i3] = $this->ticketWithInvoice('full', false);
        $r3 = $this->svc->create($t3->id, $this->payload(['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null]), $this->owner, 'form');
        $n['cancelled_no_refund'] = $this->notify('refund_cancelled_no_refund', route('services.refund.show', $r3->reference), ['reference' => $t3->reference_number, 'request' => $r3->reference]);

        [$t4, $i4] = $this->ticketWithInvoice('full', false);
        $this->svc->adminClose($t4->id, $this->admin, 'Wrong customer details on the invoice.');
        $n['request_cancelled'] = $this->notify('service_request_cancelled', route('services.invoice', $i4->id), ['reference' => $t4->reference_number]);

        return $n;
    }

    public function test_every_kind_renders_and_marks_read(): void
    {
        $all = $this->allKinds();
        $this->assertEqualsCanonicalizing(ServiceNotificationDetails::KINDS, array_keys($all), 'a fixture for every kind');

        foreach ($all as $kind => $notif) {
            $this->assertSame($kind, ServiceNotificationDetails::kind($notif), $kind);
            $this->assertFalse((bool) $notif->fresh()->is_read);
            $html = $this->as($this->owner)->get(route('notifications.show', $notif->id))->assertOk()
                ->assertSee('What happened')->assertSee('What happens next')->assertSee('How to track this')->assertSee('Back to notifications')->getContent();
            $this->assertTrue((bool) $notif->fresh()->is_read, "$kind marked read");
            $this->assertStringContainsString('My service requests', $html, $kind);

            // read-only: nothing inside the details card can start or submit anything
            $card = substr($html, strpos($html, 'id="notif-detail"'));
            $card = substr($card, 0, strpos($card, '</article>'));
            $this->assertStringNotContainsString('<form', $card, "$kind has no form");
            $this->assertDoesNotMatchRegularExpression('/type="submit"/', $card, "$kind has no submit button");
        }
    }

    public function test_content_for_cancellation_refund_and_decline(): void
    {
        $all = $this->allKinds();

        $this->as($this->owner)->get(route('notifications.show', $all['approved']->id))
            ->assertSee('Refund amount')->assertSee('₱2,000.00')->assertSee('GCash')->assertSee('Refund due by')->assertSee('REF-')->assertSee('SI-2026-')->assertSee('Current status: Refunded');

        $this->get(route('notifications.show', $all['refunded']->id))
            ->assertSee('Transaction number')->assertSee('6028871884223')->assertSee('Date sent')->assertDontSee('09181112222');

        $this->get(route('notifications.show', $all['declined']->id))->assertSee('Our explanation')->assertSee('Work has already started so we cannot refund');

        $this->get(route('notifications.show', $all['request_cancelled']->id))->assertSee('Why it was cancelled')->assertSee('Wrong customer details on the invoice.');

        $this->get(route('notifications.show', $all['cancellation_received']->id))->assertSee('Your reason')->assertSee('Plans changed.');

        $page = $this->get(route('notifications.show', $all['invoice_ready']->id))->assertSee('Service type')->assertSee('Repair or restoration')->assertSee('Furniture type')->assertSee('Dining Table')
            ->assertSee('Invoice total')->assertSee('INV-')->assertSee('data-copy-text', false);
        $this->assertMatchesRegularExpression('/aria-label="Copy invoice number INV-/', $page->getContent());
    }

    public function test_only_the_owner_can_open_a_notification_and_its_records_are_scoped(): void
    {
        $all = $this->allKinds();
        $n = $all['approved'];

        $this->as($this->other)->get(route('notifications.show', $n->id))->assertNotFound();
        $this->assertFalse((bool) $n->fresh()->is_read, 'a stranger does not mark it read');

        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get(route('notifications.show', $n->id))->assertRedirect();

        // a notification of this owner that points at someone else's invoice shows nothing of it
        [$t, $inv] = (function () {
            $theirs = ServiceInquiry::create([
                'reference_number' => 'SI-2026-9990', 'service_type' => 'repair', 'concern' => 'x', 'name' => 'Z', 'phone' => '09171234567', 'status' => 'new', 'user_id' => $this->other->id,
            ]);
            $clean = ServiceInvoiceDesk::validate(['service_description' => 'R', 'service_cost' => '1000', 'delivery_option' => 'none', 'customer_name' => 'Z', 'customer_phone' => '09171234567', 'payment_plan' => 'full']);
            $desk = app(ServiceInvoiceDesk::class);

            return [$theirs, $desk->issue($desk->createDraft($theirs->id, $this->admin, $clean)->id, $this->admin)];
        })();
        $forged = $this->notify('service_invoice_issued', route('services.invoice', $inv->id), ['invoice' => 'x', 'service' => 'x', 'total' => 1]);
        $this->as($this->owner)->get(route('notifications.show', $forged->id))->assertOk()->assertDontSee($inv->invoice_number)->assertDontSee('SI-2026-9990');
    }

    public function test_other_notification_types_are_not_changed(): void
    {
        $order = Notification::create(['user_id' => $this->owner->id, 'title' => 'Payment Received', 'message' => 'We received your payment for order LP-2026-00001.',
            'type' => 'order_update', 'url' => '/orders/1', 'is_read' => false]);
        $this->assertNull(ServiceNotificationDetails::kind($order));
        $this->as($this->owner)->get(route('notifications.show', $order->id))->assertRedirect('/orders/1');

        $this->get(route('notifications.index'))->assertOk()->assertSee('/orders/1');
    }

    public function test_the_list_sends_service_notifications_to_the_details_page(): void
    {
        $all = $this->allKinds();
        $this->as($this->owner)->get(route('notifications.index'))->assertOk()
            ->assertSee(route('notifications.show', $all['request_received']->id), false)
            ->assertSee('Related service request');
    }

    public function test_the_ask_luma_button_is_on_by_default_and_the_flag_can_hide_it(): void
    {
        $n = $this->allKinds()['approved'];
        $this->assertTrue(config('luma.notification_ask_button'), 'on since Luma can look up a reference number (Stage 21i)');
        $this->as($this->owner)->get(route('notifications.show', $n->id))->assertOk()->assertSee('Ask Luma about this')->assertSee('data-ref="REF-', false);

        config(['luma.notification_ask_button' => false]);
        $this->get(route('notifications.show', $n->id))->assertOk()->assertDontSee('Ask Luma about this')->assertDontSee('id="nd-ask-luma"', false);
    }

    public function test_old_notifications_without_any_stored_snapshot_work(): void
    {
        // The notifications table has no data column: every notification is "old shape". Build one by hand, the way the 8c-8e code did.
        [$ticket, $inv] = $this->ticketWithInvoice('full', true);
        $old = Notification::create(['user_id' => $this->owner->id, 'title' => 'Payment Received', 'message' => 'We received your payment of ₱4,000.00 for invoice ' . $inv->invoice_number . '.',
            'type' => 'general', 'url' => route('services.invoice', $inv->id), 'is_read' => false]);

        $this->as($this->owner)->get(route('notifications.show', $old->id))->assertOk()->assertSee('We received your payment')->assertSee($inv->invoice_number)->assertSee($ticket->reference_number);
    }

    public function test_the_live_status_line_appears_when_the_request_moved_on(): void
    {
        [$ticket] = $this->ticketWithInvoice();
        $r = $this->svc->create($ticket->id, $this->payload(), $this->owner, 'form');
        $n = $this->notify('refund_received', route('services.refund.show', $r->reference), ['reference' => $ticket->reference_number, 'request' => $r->reference]);

        $this->as($this->owner)->get(route('notifications.show', $n->id))->assertOk()->assertDontSee('Current status:');
        $this->svc->approve($r->id, $this->admin);
        $this->get(route('notifications.show', $n->id))->assertSee('Current status: Approved');
    }
}
