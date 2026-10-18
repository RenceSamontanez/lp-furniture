<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInvoiceActionable;
use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Support\ServiceInvoiceDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21d: a cancelled invoice shows the owner an explanation instead of a 404, and nothing can act on it.
 */
class CancelledInvoicePageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->admin->forceFill(['first_name' => 'Rhea'])->save();
        $this->owner = $this->makeUser();
    }

    private function draft(?User $customer = null, ?ServiceInquiry $ticket = null): ServiceInvoice
    {
        static $n = 0;
        $n++;
        $customer ??= $this->owner;
        $ticket ??= ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (9000 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Wobbly table leg.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $customer->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '1500', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567',
        ]);

        return app(ServiceInvoiceDesk::class)->createDraft($ticket->id, $this->admin, $clean);
    }

    private function sent(?User $customer = null, ?ServiceInquiry $ticket = null): ServiceInvoice
    {
        $d = $this->draft($customer, $ticket);

        return app(ServiceInvoiceDesk::class)->issue($d->id, $this->admin);
    }

    private function cancelled(string $reason = 'The price was wrong.', ?User $customer = null, ?ServiceInquiry $ticket = null): ServiceInvoice
    {
        $i = $this->sent($customer, $ticket);

        return app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, $reason);
    }

    private function asOwner(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->owner);
    }

    public function test_the_owner_sees_a_200_explanation_with_the_reason_number_and_date(): void
    {
        $i = $this->cancelled('The price was wrong.');

        $res = $this->asOwner()->get(route('services.invoice', $i->id));
        $res->assertOk()
            ->assertSee('This invoice was cancelled')
            ->assertSee('Cancelled')
            ->assertSee('You do not need to pay anything for it, and no payment is expected.')
            ->assertSee($i->invoice_number)
            ->assertSee('Amount that was on this invoice')
            ->assertSee('₱1,500.00')
            ->assertSee('Why it was cancelled')
            ->assertSee('The price was wrong.')
            ->assertSee($i->voided_at->timezone('Asia/Manila')->format('F j, Y'))
            ->assertSee('If a corrected invoice is needed, we will send you a new one and let you know.')
            ->assertSee('Back to my service request')
            ->assertSee(route('services.request'), false);
        $this->assertStringContainsString('<meta name="robots" content="noindex"', $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_the_page_has_no_pay_print_edit_or_cancel_controls_and_no_forms(): void
    {
        $i = $this->cancelled();
        BusinessSetting::query()->first()?->forceFill(['phone' => '0917-555-0101', 'email' => 'hello@lpfurniture.test'])->save();

        $html = $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()->getContent();
        $body = preg_replace('#<(script|style)\b.*?</\1>#is', '', $html);
        // the site layout itself has a chat widget, a nav search and a logout form; look only inside <main>
        preg_match('#<main>(.*?)</main>#is', $body, $m);
        $main = $m[1] ?? '';

        $this->assertNotSame('', $main);
        $this->assertStringNotContainsString('<form', $main);
        $this->assertStringNotContainsString('<button', $main);
        $this->assertStringNotContainsString('window.print', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(Pay now|Pay this|Print|Download|Edit|Cancel invoice)\b/i', strip_tags($main));
        $this->assertStringContainsString('tel:', $main);
        $this->assertStringContainsString('mailto:', $main);
    }

    public function test_the_fallback_text_shows_when_the_reason_is_empty(): void
    {
        $i = $this->cancelled();
        // an older cancelled invoice may have no stored reason; the model guard allows nothing else to change
        \DB::table('service_invoices')->where('id', $i->id)->update(['void_reason' => '']);

        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()
            ->assertSee('Our team cancelled this invoice. If you are not sure why, please contact us and we will explain.');
    }

    public function test_a_cancelled_invoice_that_was_paid_asks_the_customer_to_contact_us(): void
    {
        $i = $this->sent();
        app(ServiceInvoiceDesk::class)->markPaid($i->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);
        // Since Stage 21e a paid invoice can no longer be cancelled, but invoices cancelled earlier still exist: simulate one.
        \DB::table('service_invoices')->where('id', $i->id)->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'Duplicate of another invoice.']);

        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()
            ->assertSee('after a payment was recorded')
            ->assertDontSee('no payment is expected');
        $this->assertSame(0, Notification::where('user_id', $this->owner->id)->where('title', 'Your Invoice Was Cancelled')->count(), 'a paid invoice is not announced as "nothing to pay"');
    }

    public function test_acting_routes_refuse_a_cancelled_invoice_and_redirect_with_the_toast(): void
    {
        // The customer side has no pay/print/download route today; the guard is the middleware every such route must use.
        Route::middleware(['web', 'auth', 'invoice.actionable'])->group(function () {
            Route::get('/_t/invoices/{id}/print', fn () => 'PRINT')->whereNumber('id');
            Route::get('/_t/invoices/{id}/download', fn () => 'DOWNLOAD')->whereNumber('id');
            Route::post('/_t/invoices/{id}/pay', fn () => 'PAYMENT STARTED')->whereNumber('id');
        });

        $cancelled = $this->cancelled();
        $sent      = $this->sent();

        $this->asOwner();
        foreach ([['get', 'print'], ['get', 'download'], ['post', 'pay']] as [$method, $action]) {
            $res = $this->{$method}("/_t/invoices/{$cancelled->id}/{$action}");
            $res->assertRedirect(route('services.invoice', $cancelled->id));
            $res->assertSessionHas('error', EnsureInvoiceActionable::CANCELLED_MESSAGE);
            $this->assertStringNotContainsString('PAYMENT STARTED', (string) $res->getContent());
        }
        $this->assertSame('This invoice was cancelled, so it can no longer be paid or printed.', EnsureInvoiceActionable::CANCELLED_MESSAGE);

        // the redirect target shows the toast
        $this->get(route('services.invoice', $cancelled->id))->assertOk()
            ->assertSee('This invoice was cancelled, so it can no longer be paid or printed.');

        // a sent invoice passes the guard
        $this->post("/_t/invoices/{$sent->id}/pay")->assertOk()->assertSee('PAYMENT STARTED');
        $this->assertTrue($sent->fresh()->isPayable());
        $this->assertFalse($cancelled->fresh()->isPayable());
        $this->assertTrue($cancelled->fresh()->isCancelled());
    }

    public function test_the_guard_keeps_other_customers_drafts_and_missing_ids_as_404(): void
    {
        Route::middleware(['web', 'auth', 'invoice.actionable'])->post('/_t/pay/{id}', fn () => 'PAYMENT STARTED')->whereNumber('id');

        $other = $this->makeUser();
        $theirs = $this->cancelled('The price was wrong.', $other);
        $draft  = $this->draft();

        $this->asOwner();
        $this->post("/_t/pay/{$theirs->id}")->assertNotFound();
        $this->post("/_t/pay/{$draft->id}")->assertNotFound();
        $this->post('/_t/pay/999999999')->assertNotFound();
    }

    public function test_other_customers_drafts_missing_ids_are_404_and_guests_go_to_login(): void
    {
        $other = $this->makeUser();
        $theirs = $this->cancelled('The price was wrong.', $other);
        $draft  = $this->draft();

        $this->asOwner();
        $this->get(route('services.invoice', $theirs->id))->assertNotFound();
        $this->get(route('services.invoice', $draft->id))->assertNotFound();
        $this->get(route('services.invoice', 999999999))->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(route('services.invoice', $theirs->id))->assertRedirect(route('login'));
    }

    public function test_a_sent_invoice_is_unaffected(): void
    {
        $i = $this->sent();

        $this->asOwner()->get(route('services.invoice', $i->id))->assertOk()
            ->assertSee($i->invoice_number)->assertSee('Unpaid')->assertSee('Pay with GCash')->assertSee('Pay with card')
            ->assertDontSee('This invoice was cancelled');
        $this->asOwner()->get(route('services.invoice.print', $i->id))->assertOk()->assertSee('window.print', false);
    }

    public function test_cancelling_notifies_the_customer_and_old_and_new_links_resolve(): void
    {
        BusinessSetting::query()->first()?->forceFill(['phone' => '0917-555-0101', 'email' => 'hello@lpfurniture.test'])->save();
        $i = $this->sent();
        $ready = Notification::where('user_id', $this->owner->id)->where('title', 'Your Invoice Is Ready')->sole();

        app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, 'The price was wrong.');

        $n = Notification::where('user_id', $this->owner->id)->where('title', 'Your Invoice Was Cancelled')->sole();
        $this->assertStringContainsString($i->invoice_number . ' was cancelled', $n->message);
        $this->assertStringContainsString('You do not need to pay it', $n->message);
        $this->assertStringContainsString('0917-555-0101', $n->message);
        $this->assertSame(route('services.invoice', $i->id), $n->url);

        $this->asOwner();
        $this->get($n->url)->assertOk()->assertSee('This invoice was cancelled');
        $this->assertSame($ready->url, $n->url);
        $this->get($ready->url)->assertOk()->assertSee('This invoice was cancelled'); // the old "ready" link now lands on the explanation
    }

    public function test_the_service_request_page_shows_cancelled_with_a_link_and_no_amount_due(): void
    {
        $i = $this->cancelled();

        $html = $this->asOwner()->get('/services/request')->assertOk()->getContent();
        $this->assertStringContainsString('Cancelled, view why', $html);
        $this->assertStringContainsString(route('services.invoice', $i->id), $html);
        $this->assertStringNotContainsString('Unpaid', $html);
        $this->assertStringNotContainsString('₱1,500.00', $html);
    }

    public function test_a_replacement_invoice_is_current_and_the_cancelled_one_moves_to_earlier_invoices(): void
    {
        $old = $this->cancelled();
        $replacement = $this->sent($this->owner, $old->inquiry);

        $html = $this->asOwner()->get('/services/request')->assertOk()->getContent();
        $this->assertStringContainsString('Invoice ' . $replacement->invoice_number, $html);
        $this->assertStringContainsString('Unpaid', $html);
        $this->assertStringContainsString('Earlier invoices', $html);
        $this->assertStringContainsString($old->invoice_number . ' (cancelled)', $html);
        $this->assertStringNotContainsString('Cancelled, view why', $html);
    }

    public function test_the_admins_printed_copy_of_a_cancelled_invoice_still_shows_the_mark(): void
    {
        $i = $this->cancelled();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin');
        $this->get(route('admin.service-invoices.print', $i->id))->assertOk()->assertSee('iv-mark', false)->assertSee('Cancelled');
    }

    public function test_the_cancel_reason_has_a_minimum_length_and_the_dialog_says_the_customer_will_see_it(): void
    {
        $i = $this->sent();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin');

        $this->get(route('admin.service-invoices.show', $i->id))->assertOk()->assertSee('The customer will see this reason.');

        $this->post(route('admin.service-invoices.void', $i->id), ['reason' => 'oops'])->assertSessionHasErrors('reason');
        $this->assertSame('issued', $i->fresh()->status);
        $this->assertStringContainsString('at least 5 characters', session('errors')->first('reason'));

        $this->post(route('admin.service-invoices.void', $i->id), ['reason' => 'Wrong amount'])->assertSessionHasNoErrors();
        $this->assertSame('void', $i->fresh()->status);

        // the desk refuses a short reason too, whatever calls it
        $j = $this->sent();
        $this->expectException(\App\Support\PipelineException::class);
        app(ServiceInvoiceDesk::class)->void($j->id, $this->admin, 'abcd');
    }
}
