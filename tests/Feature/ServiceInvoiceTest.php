<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use App\Support\ServiceInvoiceDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21 follow-up part B: simple invoices for Other Services tickets.
 */
class ServiceInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->admin->forceFill(['first_name' => 'Rhea'])->save();
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'admin');
    }

    private function ticket(array $over = []): ServiceInquiry
    {
        static $n = 0;
        $n++;

        return ServiceInquiry::create(array_merge([
            'reference_number' => 'SI-2026-' . str_pad((string) (7000 + $n), 4, '0', STR_PAD_LEFT),
            'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Wobbly table leg, needs repair.',
            'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com', 'status' => 'new',
        ], $over));
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'service_description' => 'Repair service: dining table',
            'service_cost'        => '1500',
            'delivery_option'     => 'none',
            'customer_name'       => 'juan dela cruz',
            'customer_phone'      => '0917 123 4567',
            'customer_email'      => 'juan@example.com',
            'customer_address'    => 'San Juan, Batangas',
        ], $over);
    }

    private function draft(ServiceInquiry $t, array $over = []): ServiceInvoice
    {
        return app(ServiceInvoiceDesk::class)->createDraft($t->id, $this->admin, ServiceInvoiceDesk::validate($this->payload($over)));
    }

    private function issued(?ServiceInquiry $t = null, array $over = []): ServiceInvoice
    {
        $t ??= $this->ticket();
        $d = $this->draft($t, $over);

        return app(ServiceInvoiceDesk::class)->issue($d->id, $this->admin);
    }

    /* ───────────── delivery logic and totals ───────────── */

    public function test_no_delivery_forces_the_amount_to_zero_and_makes_no_line(): void
    {
        $clean = ServiceInvoiceDesk::validate($this->payload(['delivery_option' => 'none', 'delivery_amount' => '999']));

        $this->assertCount(1, $clean['lines']);
        $this->assertSame('service_cost', $clean['lines'][0]['kind']);
        $this->assertSame('1500.00', $clean['total']);
    }

    public function test_every_other_option_needs_an_amount_over_zero_and_makes_the_right_line(): void
    {
        $expect = [
            'home_service'        => ['home_service_fee', 'Home service fee'],
            'delivery_one_way'    => ['delivery_fee', 'Delivery fee (one way)'],
            'delivery_round_trip' => ['delivery_fee', 'Delivery fee (round trip)'],
        ];

        foreach ($expect as $option => [$kind, $text]) {
            foreach ([null, '', '0', '0.00', '-5', 'abc'] as $bad) {
                try {
                    ServiceInvoiceDesk::validate($this->payload(['delivery_option' => $option, 'delivery_amount' => $bad]));
                    $this->fail("{$option} accepted amount " . var_export($bad, true));
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('delivery_amount', $e->errors(), "{$option} / " . var_export($bad, true));
                }
            }

            $clean = ServiceInvoiceDesk::validate($this->payload(['delivery_option' => $option, 'delivery_amount' => '350.50']));
            $this->assertCount(2, $clean['lines'], $option);
            $this->assertSame(['kind' => $kind, 'description' => $text, 'amount' => '350.50'], $clean['lines'][1], $option);
            $this->assertSame('1850.50', $clean['total'], $option);
        }
    }

    public function test_the_delivery_choice_is_required_and_must_be_listed(): void
    {
        foreach ([null, 'teleport'] as $bad) {
            try {
                ServiceInvoiceDesk::validate($this->payload(['delivery_option' => $bad]));
                $this->fail('accepted ' . var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('delivery_option', $e->errors());
            }
        }
    }

    public function test_totals_add_the_cost_every_fee_and_delivery_without_float_noise(): void
    {
        $clean = ServiceInvoiceDesk::validate($this->payload([
            'service_cost' => '0.10',
            'lines' => [
                ['kind' => 'material_fee', 'description' => 'Varnish', 'amount' => '0.20'],
                ['kind' => 'other_fee', 'description' => 'Rush', 'amount' => '1000.05'],
                ['kind' => '', 'description' => '', 'amount' => ''],            // blank row: skipped
            ],
            'delivery_option' => 'delivery_round_trip', 'delivery_amount' => '0.30',
        ]));

        $this->assertSame(['service_cost', 'material_fee', 'other_fee', 'delivery_fee'], array_column($clean['lines'], 'kind'));
        $this->assertSame('1000.65', $clean['total']);
        $this->assertSame($clean['total'], $clean['subtotal']);
    }

    public function test_the_service_cost_must_be_more_than_zero_and_a_half_filled_fee_row_is_refused(): void
    {
        foreach (['0', '', '-1', '1000000'] as $bad) {
            try {
                ServiceInvoiceDesk::validate($this->payload(['service_cost' => $bad]));
                $this->fail('accepted cost ' . var_export($bad, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('service_cost', $e->errors());
            }
        }

        try {
            ServiceInvoiceDesk::validate($this->payload(['lines' => [['kind' => 'other_fee', 'description' => 'Glue', 'amount' => '']]]));
            $this->fail('accepted a fee with no amount');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.amount', $e->errors());
        }
    }

    public function test_the_service_form_is_prefilled_from_the_ticket_and_shows_every_delivery_explanation(): void
    {
        $t = $this->ticket(['service_type' => 'labor_only_own_wood', 'furniture_type' => 'Dining Table']);

        $this->asAdmin()->get(route('admin.service-invoices.create', $t->id))->assertOk()
            ->assertSee('value="Labor service: dining table"', false)
            ->assertSee('value="Juan Dela Cruz"', false)
            ->assertSee('No delivery or service fee (customer brings the furniture and picks it up)')
            ->assertSee('Home service fee (we do the work at the customer&#039;s location)', false)
            ->assertSee('Delivery fee, one way (furniture delivered to the shop, or returned to the customer)')
            ->assertSee('Delivery fee, round trip (picked up and returned)')
            ->assertSee('Running total');
    }

    /* ───────────── create, edit, issue, lock ───────────── */

    public function test_creating_a_draft_copies_the_customer_and_logs_to_the_ticket(): void
    {
        $t = $this->ticket();

        $res = $this->asAdmin()->post(route('admin.service-invoices.store', $t->id), $this->payload());

        $i = ServiceInvoice::sole();
        $res->assertRedirect(route('admin.service-invoices.show', $i->id));
        $this->assertSame('draft', $i->status);
        $this->assertNull($i->invoice_number);
        $this->assertSame('Juan Dela Cruz', $i->customer_name);
        $this->assertSame('09171234567', $i->customer_phone);
        $this->assertSame('San Juan, Batangas', $i->customer_address);
        $this->assertSame('none', $i->delivery_option);
        $this->assertSame('1500.00', (string) $i->total);
        $this->assertSame($this->admin->id, $i->created_by_admin_id);
        $this->assertCount(1, $i->lines);
        $this->assertStringContainsString('Rhea · Invoice draft #' . $i->id . ' created, total ₱1,500.00', $t->fresh()->admin_notes);
        $this->assertSame(0, Notification::where('type', 'general')->where('title', 'Your Invoice Is Ready')->count(), 'a draft tells the customer nothing');
    }

    public function test_a_draft_can_be_edited_then_issued_and_is_then_locked(): void
    {
        $t = $this->ticket();
        $i = $this->draft($t);

        $this->asAdmin()->post(route('admin.service-invoices.update', $i->id), $this->payload([
            'service_cost' => '2000', 'delivery_option' => 'home_service', 'delivery_amount' => '300',
        ]))->assertRedirect(route('admin.service-invoices.show', $i->id));

        $i->refresh();
        $this->assertSame('2300.00', (string) $i->total);
        $this->assertSame(['service_cost', 'home_service_fee'], $i->lines->pluck('kind')->all());

        $this->post(route('admin.service-invoices.issue', $i->id))->assertSessionHas('success');
        $i->refresh();
        $this->assertSame('issued', $i->status);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-0001$/', $i->invoice_number);
        $this->assertNotNull($i->issued_at);

        // the edit page and the update route now refuse
        $this->get(route('admin.service-invoices.edit', $i->id))->assertRedirect(route('admin.service-invoices.show', $i->id));
        $this->post(route('admin.service-invoices.update', $i->id), $this->payload(['service_cost' => '1']))
            ->assertSessionHasErrors('invoice');
        $this->assertSame('2300.00', (string) $i->fresh()->total);

        $notes = $t->fresh()->admin_notes;
        $this->assertStringContainsString('Invoice draft #' . $i->id . ' updated', $notes);
        $this->assertStringContainsString("Invoice {$i->invoice_number} sent to the customer, total ₱2,300.00", $notes);
    }

    public function test_an_issued_invoice_cannot_be_changed_or_deleted_at_the_model_layer(): void
    {
        $i = $this->issued();
        $line = $i->lines()->first();

        $attempts = [
            'total'          => fn () => $i->forceFill(['total' => '1.00'])->save(),
            'customer name'  => fn () => $i->forceFill(['customer_name' => 'Someone Else'])->save(),
            'description'    => fn () => $i->forceFill(['service_description' => 'Other'])->save(),
            'number'         => fn () => $i->forceFill(['invoice_number' => 'INV-2026-9999'])->save(),
            'back to draft'  => fn () => $i->forceFill(['status' => 'draft'])->save(),
            'line amount'    => fn () => $line->forceFill(['amount' => '1.00'])->save(),
            'line delete'    => fn () => $line->delete(),
            'line add'       => fn () => ServiceInvoiceLine::create(['invoice_id' => $i->id, 'kind' => 'other_fee', 'description' => 'x', 'amount' => '5']),
            'invoice delete' => fn () => $i->delete(),
        ];
        foreach ($attempts as $label => $try) {
            try {
                $try();
                $this->fail("{$label} was allowed on an issued invoice");
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
        }

        $fresh = $i->fresh();
        $this->assertSame('issued', $fresh->status);
        $this->assertSame('1500.00', (string) $fresh->total);
        $this->assertSame('Juan Dela Cruz', $fresh->customer_name);
    }

    public function test_a_paid_or_void_invoice_is_locked_too(): void
    {
        $paid = $this->issued();
        app(ServiceInvoiceDesk::class)->markPaid($paid->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);

        $paid = $paid->fresh();
        $this->expectException(ImmutableRecordException::class);
        $paid->forceFill(['amount_received' => '1.00'])->save();
    }

    public function test_only_a_draft_can_be_deleted(): void
    {
        $t = $this->ticket();
        $d = $this->draft($t);

        $this->asAdmin()->post(route('admin.service-invoices.delete', $d->id))->assertRedirect(route('admin.service-inquiries.show', $t->id));
        $this->assertSame(0, ServiceInvoice::count());
        $this->assertSame(0, ServiceInvoiceLine::count());
        $this->assertStringContainsString("Invoice draft #{$d->id} deleted", $t->fresh()->admin_notes);

        $i = $this->issued();
        $this->post(route('admin.service-invoices.delete', $i->id))->assertSessionHasErrors('invoice');
        $this->assertSame(1, ServiceInvoice::count());
    }

    public function test_a_ticket_has_one_live_invoice_at_a_time(): void
    {
        $t = $this->ticket();
        $this->draft($t);

        $this->asAdmin()->post(route('admin.service-invoices.store', $t->id), $this->payload())->assertSessionHasErrors('invoice');
        $this->get(route('admin.service-invoices.create', $t->id))->assertRedirect(route('admin.service-invoices.show', ServiceInvoice::sole()->id));
        $this->assertSame(1, ServiceInvoice::count());
    }

    /* ───────────── numbers ───────────── */

    public function test_invoice_numbers_are_sequential_per_year_and_never_reused(): void
    {
        $year = now('Asia/Manila')->format('Y');
        $a = $this->issued();
        $b = $this->issued();

        $this->assertSame(["INV-{$year}-0001", "INV-{$year}-0002"], [$a->invoice_number, $b->invoice_number]);

        // voiding keeps the number used
        app(ServiceInvoiceDesk::class)->void($b->id, $this->admin, 'Wrong amount');
        $this->assertSame("INV-{$year}-0003", $this->issued()->invoice_number);
        $this->assertCount(3, array_unique(ServiceInvoice::pluck('invoice_number')->all()));
    }

    public function test_a_taken_number_is_skipped_and_the_database_refuses_a_duplicate(): void
    {
        $year = now('Asia/Manila')->format('Y');
        $t = $this->ticket();
        ServiceInvoice::create([
            'service_inquiry_id' => $t->id, 'invoice_number' => "INV-{$year}-0007", 'status' => 'draft',
            'customer_name' => 'Old', 'customer_phone' => '09170000000', 'service_description' => 'Legacy',
        ]);

        $this->assertSame("INV-{$year}-0008", $this->issued()->invoice_number);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ServiceInvoice::create([
            'service_inquiry_id' => $t->id, 'invoice_number' => "INV-{$year}-0007", 'status' => 'draft',
            'customer_name' => 'Dup', 'customer_phone' => '09170000000', 'service_description' => 'Dup',
        ]);
    }

    /* ───────────── void and paid ───────────── */

    public function test_voiding_needs_a_reason_then_a_new_invoice_can_be_issued(): void
    {
        $t = $this->ticket();
        $i = $this->issued($t);

        $this->asAdmin()->post(route('admin.service-invoices.void', $i->id), ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame('issued', $i->fresh()->status);

        $this->post(route('admin.service-invoices.void', $i->id), ['reason' => 'Wrong amount quoted.'])->assertSessionHas('success');
        $v = $i->fresh();
        $this->assertSame('void', $v->status);
        $this->assertSame('Wrong amount quoted.', $v->void_reason);
        $this->assertNotNull($v->voided_at);
        $this->assertSame($i->invoice_number, $v->invoice_number);
        $this->assertStringContainsString("Invoice {$i->invoice_number} cancelled. Wrong amount quoted.", $t->fresh()->admin_notes);

        // a void invoice cannot be voided again or paid
        $this->post(route('admin.service-invoices.void', $i->id), ['reason' => 'again'])->assertSessionHasErrors('invoice');

        // and the ticket can take a replacement
        $this->post(route('admin.service-invoices.store', $t->id), $this->payload(['service_cost' => '1600']))->assertSessionHasNoErrors();
        $this->assertSame(2, ServiceInvoice::count());
    }

    public function test_recording_payment_stores_method_reference_amount_and_date(): void
    {
        $t = $this->ticket();
        $i = $this->issued($t);
        $today = now('Asia/Manila')->toDateString();
        $url = route('admin.service-invoices.paid', $i->id);

        $this->asAdmin();
        $this->post($url, ['paid_method' => '', 'amount_received' => '1500', 'paid_date' => $today])->assertSessionHasErrors('paid_method');
        $this->post($url, ['paid_method' => 'gcash_direct', 'paid_reference' => '', 'amount_received' => '1500', 'paid_date' => $today])->assertSessionHasErrors('invoice');
        $this->post($url, ['paid_method' => 'cash', 'amount_received' => '1000', 'paid_date' => $today])->assertSessionHasErrors('invoice');
        $this->post($url, ['paid_method' => 'cash', 'amount_received' => '1500', 'paid_date' => now('Asia/Manila')->addDays(3)->toDateString()])->assertSessionHasErrors('invoice');
        $this->assertSame('issued', $i->fresh()->status);

        $this->post($url, ['paid_method' => 'gcash_direct', 'paid_reference' => 'GC-12345', 'amount_received' => '1500', 'paid_date' => $today])
            ->assertSessionHas('success');

        $p = $i->fresh();
        $this->assertSame('paid', $p->status);
        $this->assertSame('gcash_direct', $p->paid_method);
        $this->assertSame('GC-12345', $p->paid_reference);
        $this->assertSame('1500.00', (string) $p->amount_received);
        $this->assertNotNull($p->paid_at);
        $this->assertStringContainsString("Invoice {$p->invoice_number} marked paid: GCash (direct to the shop), reference GC-12345, ₱1,500.00 received", $t->fresh()->admin_notes);

        // paid once; a second payment is refused and nothing is overwritten
        $this->post($url, ['paid_method' => 'cash', 'amount_received' => '9999', 'paid_date' => $today])->assertSessionHasErrors('invoice');
        $this->assertSame('GC-12345', $i->fresh()->paid_reference);
    }

    public function test_cash_needs_no_reference_and_a_paid_invoice_can_no_longer_be_cancelled(): void
    {
        $i = $this->issued();
        app(ServiceInvoiceDesk::class)->markPaid($i->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1600', 'date' => now('Asia/Manila')->subDay()->toDateString()]);
        $this->assertSame('1600.00', (string) $i->fresh()->amount_received);

        // Stage 21e rule 5: an invoice with a payment on it is never cancelled; a refund note is recorded instead.
        try {
            app(ServiceInvoiceDesk::class)->void($i->id, $this->admin, 'Customer cancelled; refunded in person.');
            $this->fail('A paid invoice must not be cancellable.');
        } catch (\App\Support\PipelineException $e) {
            $this->assertSame('This invoice has a payment on it. Open a cancellation request so the refund is recorded.', $e->getMessage());
        }
        $v = $i->fresh();
        $this->assertSame('paid', $v->status);
        $this->assertSame('cash', $v->paid_method);
        $this->assertSame('1600.00', (string) $v->amount_received);
    }

    /* ───────────── notifications ───────────── */

    public function test_the_customer_is_notified_when_issued_and_when_paid_with_professional_wording(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);
        Cache::forget(BusinessSetting::CACHE_KEY);
        $customer = $this->makeUser();
        $t = $this->ticket(['user_id' => $customer->id]);

        $i = $this->issued($t);
        $n = Notification::where('user_id', $customer->id)->sole();
        $this->assertSame('Your Invoice Is Ready', $n->title);
        $this->assertStringContainsString($i->invoice_number, $n->message);
        $this->assertStringContainsString('₱1,500.00', $n->message);
        $this->assertStringContainsString('0917-222-3333', $n->message);
        $this->assertSame(route('services.invoice', $i->id), $n->url);

        app(ServiceInvoiceDesk::class)->markPaid($i->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);
        $paid = Notification::where('user_id', $customer->id)->where('title', 'Payment Recorded')->sole();
        $this->assertStringContainsString($i->invoice_number, $paid->message);
        $this->assertStringContainsString('₱1,500.00', $paid->message);

        foreach (Notification::where('user_id', $customer->id)->get() as $note) {
            $text = $note->title . $note->message;
            $this->assertStringNotContainsString('!', $text);
            $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text);
            $this->assertStringNotContainsString('{', $text);
        }
    }

    public function test_a_ticket_without_an_account_notifies_nobody_and_the_admin_page_says_so(): void
    {
        $i = $this->issued($this->ticket(['user_id' => null]));
        app(ServiceInvoiceDesk::class)->markPaid($i->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);

        $this->assertSame(0, Notification::where('title', 'Your Invoice Is Ready')->orWhere('title', 'Payment Recorded')->count());
        $this->asAdmin()->get(route('admin.service-invoices.show', $i->id))->assertOk()
            ->assertSee('No account, so no in-app notification is sent');
    }

    /* ───────────── customer view and ownership ───────────── */

    public function test_a_customer_sees_and_opens_only_their_own_issued_and_paid_invoices(): void
    {
        $mine  = $this->makeUser();
        $other = $this->makeUser();
        $t     = $this->ticket(['user_id' => $mine->id]);
        $issued = $this->issued($t);
        $draftT = $this->ticket(['user_id' => $mine->id]);
        $draft  = $this->draft($draftT);
        $voidT  = $this->ticket(['user_id' => $mine->id]);
        $void   = $this->issued($voidT);
        app(ServiceInvoiceDesk::class)->void($void->id, $this->admin, 'Issued by mistake');

        $this->app['auth']->forgetGuards();
        $this->actingAs($mine);

        $this->get('/services/request')->assertOk()
            ->assertSee('Invoice ' . $issued->invoice_number)->assertSee('Unpaid')
            ->assertSee('Cancelled, view why'); // Stage 21d: a cancelled invoice is listed, as cancelled, with no amount due
        $this->get(route('services.invoice', $issued->id))->assertOk()->assertSee($issued->invoice_number);
        $this->get(route('services.invoice', $draft->id))->assertNotFound();
        $this->get(route('services.invoice', $void->id))->assertOk()->assertSee('This invoice was cancelled'); // Stage 21d: explanation page, not a 404

        app(ServiceInvoiceDesk::class)->markPaid($issued->id, $this->admin, ['method' => 'cash', 'reference' => null, 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);
        $this->get('/services/request')->assertSee('Paid');
        $this->get(route('services.invoice', $issued->id))->assertOk()->assertSee('Amount received');

        // another customer gets a 404, not the invoice
        $this->app['auth']->forgetGuards();
        $this->actingAs($other);
        $this->get(route('services.invoice', $issued->id))->assertNotFound();
        $this->get('/services/request')->assertDontSee($issued->invoice_number);

        // a guest is sent to login
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get(route('services.invoice', $issued->id))->assertRedirect(route('login'));
    }

    public function test_an_invoice_on_an_account_less_ticket_is_not_open_to_any_customer(): void
    {
        $i = $this->issued($this->ticket(['user_id' => null]));

        $this->actingAs($this->makeUser());
        $this->get(route('services.invoice', $i->id))->assertNotFound();
    }

    /* ───────────── the printable page ───────────── */

    public function test_the_print_page_has_everything_the_owner_asked_for(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);
        Cache::forget(BusinessSetting::CACHE_KEY);
        $i = $this->issued(null, [
            'delivery_option' => 'delivery_round_trip', 'delivery_amount' => '600',
            'lines' => [['kind' => 'material_fee', 'description' => 'Narra varnish', 'amount' => '250.50']],
            'notes' => 'Pick-up on Friday.',
        ]);

        $res = $this->asAdmin()->get(route('admin.service-invoices.print', $i->id))->assertOk();
        $res->assertSee('LP Furniture')->assertSee('0917-222-3333')->assertSee('owner@lp.test')
            ->assertSee($i->invoice_number)->assertSee('Juan Dela Cruz')->assertSee('09171234567')->assertSee('juan@example.com')
            ->assertSee('San Juan, Batangas')->assertSee('Repair service: dining table')
            ->assertSee('Narra varnish')->assertSee('₱250.50')->assertSee('Delivery fee (round trip)')->assertSee('₱600.00')
            ->assertSee('₱2,350.50')->assertSee('Unpaid')->assertSee('Pick-up on Friday.')
            ->assertSee('Delivery fee, round trip (picked up and returned)')
            ->assertSee('This is an invoice for services rendered. It is not an official receipt.')
            ->assertSee('service-invoice-print.css', false);

        $css = file_get_contents(public_path('assets/css/service-invoice-print.css'));
        $this->assertStringContainsString('@page { size: A4;', $css);
    }

    public function test_the_footer_comes_from_one_config_value(): void
    {
        $i = $this->issued();
        config(['invoice.footer' => 'Official wording set by the accountant.']);

        $this->asAdmin()->get(route('admin.service-invoices.print', $i->id))
            ->assertSee('Official wording set by the accountant.')
            ->assertDontSee('It is not an official receipt.');
    }

    public function test_without_contact_settings_the_page_says_through_our_website(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '', 'email' => '']);
        Cache::forget(BusinessSetting::CACHE_KEY);

        $this->asAdmin()->get(route('admin.service-invoices.print', $this->issued()->id))->assertSee('through our website');
    }

    public function test_a_paid_invoice_shows_the_payment_and_a_void_one_is_marked(): void
    {
        $i = $this->issued();
        app(ServiceInvoiceDesk::class)->markPaid($i->id, $this->admin, ['method' => 'bank_transfer', 'reference' => 'BDO-998', 'amount' => '1500', 'date' => now('Asia/Manila')->toDateString()]);

        $this->asAdmin()->get(route('admin.service-invoices.print', $i->id))
            ->assertSee('Bank transfer')->assertSee('BDO-998')->assertSee('Amount received: ₱1,500.00');

        $other = $this->issued();
        app(ServiceInvoiceDesk::class)->void($other->id, $this->admin, 'Duplicate');
        $this->get(route('admin.service-invoices.print', $other->id))->assertSee('iv-mark', false)->assertSee('no longer valid');
    }


    /* ───────────── Stage 21c: drafts stay editable ───────────── */

    public function test_a_draft_can_be_edited_again_and_again_and_each_save_keeps_it_a_draft(): void
    {
        $t = $this->ticket();
        $i = $this->draft($t, ['lines' => [['kind' => 'material_fee', 'description' => 'Glue', 'amount' => '100']]]);

        foreach ([['2000', '300'], ['1800', '0'], ['2500', '450']] as $n => [$cost, $fee]) {
            $this->asAdmin()->get(route('admin.service-invoices.edit', $i->id))->assertOk()
                ->assertSee('Save draft')->assertSee('Send to customer');

            $res = $this->post(route('admin.service-invoices.update', $i->id), $this->payload([
                'service_description' => 'Repair v' . $n, 'service_cost' => $cost,
                'delivery_option' => $fee === '0' ? 'none' : 'delivery_one_way', 'delivery_amount' => $fee === '0' ? null : $fee,
                'customer_name' => 'Maria Santos', 'notes' => 'Edit ' . $n,
                'lines' => $fee === '0' ? [] : [['kind' => 'other_fee', 'description' => 'Screws', 'amount' => '50']],
            ]));
            $res->assertRedirect(route('admin.service-invoices.show', $i->id))->assertSessionHas('success');

            $i->refresh();
            $this->assertSame('draft', $i->status);
            $this->assertNull($i->invoice_number);
            $this->assertSame('Repair v' . $n, $i->service_description);
            $this->assertSame('Maria Santos', $i->customer_name);
            $this->assertSame('Edit ' . $n, $i->notes);
        }

        $this->assertSame('3000.00', (string) $i->total); // 2500 + 450 delivery + 50 fee
        $this->assertSame(3, substr_count($t->fresh()->admin_notes, "Invoice draft #{$i->id} updated"));
        $this->assertSame(0, Notification::where('type', 'general')->count(), 'saving a draft never notifies');
    }

    public function test_the_edit_page_shows_the_saved_values_and_the_send_confirmation_text(): void
    {
        $t = $this->ticket();
        $i = $this->draft($t, ['delivery_option' => 'home_service', 'delivery_amount' => '300', 'notes' => 'Bring tools']);

        $html = $this->asAdmin()->get(route('admin.service-invoices.edit', $i->id))->assertOk()->getContent();

        $this->assertStringContainsString('value="1500.00"', $html);
        $this->assertStringContainsString('value="300.00"', $html);
        $this->assertStringContainsString('Bring tools', $html);
        $this->assertStringContainsString(e(ServiceInvoice::SEND_CONFIRMATION), $html);
        $this->assertSame(
            'Send this invoice to the customer? They will be notified and can pay it online. Once sent, it can no longer be edited. If something is wrong you can cancel it and send a new one.',
            ServiceInvoice::SEND_CONFIRMATION
        );
    }

    public function test_deleting_a_draft_leaves_no_gap_in_the_numbering_and_writes_a_ticket_note(): void
    {
        $t = $this->ticket();
        $d = $this->draft($t);
        $this->assertNull($d->invoice_number);

        $this->asAdmin()->post(route('admin.service-invoices.delete', $d->id))->assertRedirect(route('admin.service-inquiries.show', $t->id));
        $this->assertStringContainsString('no gap in the numbering', $t->fresh()->admin_notes);

        $first = $this->issued($this->ticket());
        $this->assertMatchesRegularExpression('/^INV-\d{4}-0001$/', $first->invoice_number);
    }

    public function test_save_and_send_in_one_step_sends_what_is_on_the_page_then_locks_it(): void
    {
        $customer = $this->makeUser();
        $t = $this->ticket(['user_id' => $customer->id]);
        $i = $this->draft($t);

        $this->asAdmin()->post(route('admin.service-invoices.update', $i->id), $this->payload(['service_cost' => '2750', 'then' => 'send']))
            ->assertRedirect(route('admin.service-invoices.show', $i->id))->assertSessionHas('success');

        $i->refresh();
        $this->assertSame('issued', $i->status);
        $this->assertSame('2750.00', (string) $i->total, 'the edit on the page was saved before sending');
        $this->assertNotNull($i->invoice_number);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->where('url', route('services.invoice', $i->id))->count());

        // locked: no edit controls on the page, the edit page and the server both refuse
        $this->get(route('admin.service-invoices.show', $i->id))->assertOk()
            ->assertDontSee('Edit draft')->assertDontSee('Delete draft')->assertDontSee('Save draft');
        $this->get(route('admin.service-invoices.edit', $i->id))->assertRedirect(route('admin.service-invoices.show', $i->id));
        $this->post(route('admin.service-invoices.update', $i->id), $this->payload(['service_cost' => '1', 'then' => 'send']))->assertSessionHasErrors('invoice');
        $this->post(route('admin.service-invoices.update', $i->id), $this->payload(['service_cost' => '1']))->assertSessionHasErrors('invoice');
        $this->post(route('admin.service-invoices.delete', $i->id))->assertSessionHasErrors('invoice');
        $this->assertSame('2750.00', (string) $i->fresh()->total);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->count(), 'a refused second send notifies nobody again');
    }

    public function test_the_draft_page_offers_edit_send_and_delete_and_the_customer_never_sees_a_draft(): void
    {
        $customer = $this->makeUser();
        $t = $this->ticket(['user_id' => $customer->id]);
        $i = $this->draft($t);

        $this->asAdmin()->get(route('admin.service-invoices.show', $i->id))->assertOk()
            ->assertSee('Edit draft')->assertSee('Send to customer')->assertSee('Delete draft');

        $this->app['auth']->forgetGuards();
        $this->actingAs($customer);
        $this->get(route('services.invoice', $i->id))->assertNotFound();
        $this->get('/services/request')->assertOk()->assertDontSee('Draft')->assertDontSee('Invoice #' . $i->id);
        $this->assertSame(0, Notification::where('user_id', $customer->id)->count());
    }

    public function test_save_and_send_is_admin_only(): void
    {
        $customer = $this->makeUser();
        $t = $this->ticket(['user_id' => $customer->id]);
        $i = $this->draft($t);
        $uri = route('admin.service-invoices.update', $i->id, false);

        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->post($uri, $this->payload(['then' => 'send']))->isRedirect(route('admin.login')), 'guest');
        $this->app['auth']->forgetGuards();
        $this->actingAs($customer, 'web');
        $this->assertTrue($this->post($uri, $this->payload(['then' => 'send']))->isRedirect(route('admin.login')), 'customer');
        $this->assertSame('draft', $i->fresh()->status);
    }

    /* ───────────── RBAC ───────────── */

    public function test_every_invoice_route_is_admin_only_and_the_customer_route_is_account_only(): void
    {
        $customer = $this->makeUser();
        $t = $this->ticket(['user_id' => $customer->id]);
        $i = $this->issued($t);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->getName() ?? '', 'admin.service-invoices.'))
            ->values();
        $this->assertCount(12, $routes);

        foreach ($routes as $route) {
            $method = $route->methods()[0];
            $uri = '/' . ltrim(str_replace(['{ticketId}', '{id}'], [(string) $t->id, (string) $i->id], $route->uri()), '/');
            $label = "{$method} {$uri}";

            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $this->assertTrue($this->call($method, $uri)->isRedirect(route('admin.login')), "guest {$label}");

            $this->app['auth']->forgetGuards();
            $this->actingAs($customer, 'web');
            $this->assertTrue($this->call($method, $uri)->isRedirect(route('admin.login')), "customer {$label}");

            $this->app['auth']->forgetGuards();
            $this->actingAs($customer, 'admin');
            $this->assertTrue($this->call($method, $uri)->isRedirect(route('admin.login')), "customer on admin guard {$label}");

            $this->app['auth']->forgetGuards();
            $this->actingAs($this->admin, 'admin');
            $res = $this->call($method, $uri);
            $this->assertNotContains($res->getStatusCode(), [401, 403, 419], "admin {$label}");
            $this->assertLessThan(500, $res->getStatusCode(), "admin {$label}");
            $this->assertFalse($res->isRedirect(route('admin.login')), "admin {$label}");
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame('1500.00', (string) $i->fresh()->total, 'nobody but an admin changed the invoice');
        $this->assertSame('issued', $i->fresh()->status);
    }
}
