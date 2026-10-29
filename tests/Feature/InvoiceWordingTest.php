<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\NotificationTemplates;
use App\Support\ServiceInvoiceDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21c: people read Draft, Sent, Paid, Cancelled. The stored values (draft, issued, paid, void) and the
 * route names stay as they are; only the words on screen, in notifications and in ticket notes changed.
 */
class InvoiceWordingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const OLD_WORDS = '/\b(Void|Voided|Voiding|voided|Issued|Issuing|Issue invoice)\b/';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->admin->forceFill(['first_name' => 'Rhea'])->save();
    }

    private function draft(User $customer): ServiceInvoice
    {
        static $n = 0;
        $n++;
        $t = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (8000 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Wobbly table leg.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $customer->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '1500', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567',
        ]);

        return app(ServiceInvoiceDesk::class)->createDraft($t->id, $this->admin, $clean);
    }

    private function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);

        return html_entity_decode(strip_tags($html));
    }

    public function test_no_old_wording_in_the_invoice_views_or_the_notification_templates(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [$root . '/app/Support/NotificationTemplates.php'];
        foreach (['resources/views/admin/service-invoices', 'resources/views/service-invoices', 'resources/views/admin/service-inquiries', 'resources/views/services'] as $dir) {
            foreach (glob($root . '/' . $dir . '/*.blade.php') as $f) {
                $files[] = $f;
            }
        }
        $this->assertGreaterThan(8, count($files));

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(self::OLD_WORDS, file_get_contents($file), basename($file));
        }
    }

    public function test_status_labels_come_from_one_helper(): void
    {
        $this->assertSame('Draft', ServiceInvoice::labelFor('draft'));
        $this->assertSame('Sent', ServiceInvoice::labelFor('issued'));
        $this->assertSame('Paid', ServiceInvoice::labelFor('paid'));
        $this->assertSame('Cancelled', ServiceInvoice::labelFor('void'));
        $this->assertSame(['draft', 'issued', 'paid', 'void'], array_keys(ServiceInvoice::STATUSES), 'stored values are unchanged');
    }

    public function test_every_screen_in_every_status_reads_in_plain_words(): void
    {
        $customer = $this->makeUser();
        $desk = app(ServiceInvoiceDesk::class);

        $draft = $this->draft($customer);
        $sent  = $this->draft($customer);
        $desk->issue($sent->id, $this->admin);
        $paid  = $this->draft($customer);
        $desk->issue($paid->id, $this->admin);
        $desk->markPaid($paid->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);
        $cancelled = $this->draft($customer);
        $desk->issue($cancelled->id, $this->admin);
        $desk->void($cancelled->id, $this->admin, 'Wrong amount');

        $expected = [$draft->id => 'Draft', $sent->id => 'Sent', $paid->id => 'Paid', $cancelled->id => 'Cancelled'];
        $pages = [];

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin');
        foreach ($expected as $id => $label) {
            $html = $this->get(route('admin.service-invoices.show', $id))->assertOk()->getContent();
            $this->assertStringContainsString('>' . $label . '<', $html, "admin page shows {$label}");
            $pages["admin show {$label}"] = $html;
            $pages["admin print {$label}"] = $this->get(route('admin.service-invoices.print', $id))->assertOk()->getContent();
            $pages["ticket {$label}"] = $this->get(route('admin.service-inquiries.show', ServiceInvoice::find($id)->service_inquiry_id))->assertOk()->getContent();
        }
        $pages['admin edit draft'] = $this->get(route('admin.service-invoices.edit', $draft->id))->assertOk()->getContent();
        $pages['admin ticket list'] = $this->get(route('admin.service-inquiries.index'))->assertOk()->getContent();

        $this->app['auth']->forgetGuards();
        $this->actingAs($customer);
        foreach ([$sent->id, $paid->id] as $id) {
            $pages["customer print {$id}"] = $this->get(route('services.invoice', $id))->assertOk()->getContent();
        }
        $pages['customer requests'] = $this->get('/services/request')->assertOk()->getContent();

        foreach ($pages as $name => $html) {
            $this->assertDoesNotMatchRegularExpression('/\b(void(ed)?|voiding|issued|issuing|issue invoice)\b/i', $this->visibleText($html), $name);
        }

        $this->assertStringContainsString('CANCELLED', strtoupper($this->visibleText($pages['admin print Cancelled'])));
        $this->assertStringContainsString(ServiceInvoice::CANCEL_CONFIRMATION, html_entity_decode($pages['admin show Sent']));
        $this->assertStringContainsString('Cancel Invoice', $this->visibleText($pages['admin show Paid']));
    }

    public function test_notifications_and_ticket_notes_use_the_new_words(): void
    {
        $customer = $this->makeUser();
        $d = $this->draft($customer);
        $desk = app(ServiceInvoiceDesk::class);
        $desk->issue($d->id, $this->admin);
        $desk->void($d->id, $this->admin, 'Wrong amount');

        $notes = $d->inquiry->fresh()->admin_notes;
        $this->assertStringContainsString('sent to the customer', $notes);
        $this->assertStringContainsString('cancelled. Wrong amount', $notes);
        $this->assertDoesNotMatchRegularExpression('/\b(voided|issued)\b/i', $notes);

        foreach (['service_invoice_issued', 'service_invoice_paid'] as $template) {
            $t = NotificationTemplates::render($template, ['invoice' => 'INV-2026-0001', 'service' => 'Repair', 'total' => '₱1,500.00', 'amount' => '₱1,500.00', 'when' => 'Oct 17, 2026', 'contact' => '']);
            $this->assertDoesNotMatchRegularExpression('/\b(void(ed)?|issued|issuing)\b/i', $t['title'] . ' ' . $t['message']);
        }
    }

    public function test_the_server_errors_use_the_new_words_too(): void
    {
        $customer = $this->makeUser();
        $d = $this->draft($customer);
        app(ServiceInvoiceDesk::class)->issue($d->id, $this->admin);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin');
        $res = $this->get(route('admin.service-invoices.edit', $d->id));
        $res->assertRedirect(route('admin.service-invoices.show', $d->id));
        $msg = session('errors')->first('invoice');
        $this->assertStringContainsString('sent and is locked', $msg);
        $this->assertDoesNotMatchRegularExpression('/\b(void|issued)\b/i', $msg);

        $this->post(route('admin.service-invoices.void', $d->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertStringContainsString('cancel an invoice', session('errors')->first('reason'));
    }
}
