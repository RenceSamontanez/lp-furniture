<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceRefundAttempt;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** Stage 21h part 7: transaction number rules on "Mark refund as sent", and the admin detail layout. */
class RefundTransactionNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;
    private User $owner;
    private ServiceRefundRequestService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->owner = $this->makeUser();
        $this->app->instance(InvoiceGateway::class, new CancelBugGateway());
        $this->svc = app(ServiceRefundRequestService::class);
    }

    /** An approved GCash (or cash) refund request on a half-paid invoice. */
    private function approved(string $method = 'gcash'): Req
    {
        static $n = 0;
        $n++;
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (9700 + $n), 'service_type' => 'repair', 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'status' => 'new', 'user_id' => $this->owner->id,
        ]);
        $clean = ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '4000', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => 'half_half',
        ]);
        $desk = app(ServiceInvoiceDesk::class);
        $i = $desk->issue($desk->createDraft($ticket->id, $this->admin, $clean)->id, $this->admin);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin')->post(route('admin.service-invoices.cash', $i->id), ['amount' => '2000.00', 'confirm' => '1'])->assertSessionHas('success');

        $r = $this->svc->create($ticket->id, [
            'reason_code' => 'change_of_mind', 'reason_text' => 'Plans changed.', 'contact_phone' => '09171234567', 'contact_email' => 'juan@example.com',
            'refund_method' => $method, 'gcash_number' => $method === 'gcash' ? '09181112222' : null, 'gcash_account_name' => $method === 'gcash' ? 'Juan D.' : null, 'terms' => '1',
        ], $this->owner, 'form');

        return $this->svc->approve($r->id, $this->admin);
    }

    private function send(Req $r, string $tx, array $extra = [], ?string $method = null): Req
    {
        return $this->svc->markRefunded($r->id, $this->admin, [
            'amount' => '2000', 'method' => $method ?? $r->refund_method, 'transaction_number' => $tx, 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null,
        ] + $extra);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function gcashCases(): array
    {
        return [
            'ten digits'     => ['6028871884', true],
            'thirteen'       => ['6028871884223', true],
            'spaced'         => ['6028 871 884223', true],
            'dashed'         => ['6028-871-884223', true],
            'spaces+dashes'  => [' 6028 - 871 884-223 ', true],
            'nine digits'    => ['602887188', false],
            'fourteen'       => ['60288718842234', false],
            'letters'        => ['GC6028871884', false],
            'mixed'          => ['6028871884x', false],
        ];
    }

    /** @dataProvider gcashCases */
    public function test_gcash_numbers(string $tx, bool $valid): void
    {
        $r = $this->approved();
        if ($valid) {
            $this->send($r, $tx);
            $this->assertSame(preg_replace('/[\s\-]/', '', $tx), ServiceRefundAttempt::sole()->transaction_number, 'the cleaned digits are stored');

            return;
        }
        try {
            $this->send($r, $tx);
            $this->fail('should be refused');
        } catch (PipelineException $e) {
            $this->assertStringContainsString('10 to 13 digits', $e->getMessage());
        }
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, ServiceRefundAttempt::count());
    }

    public function test_override_needs_the_tick_and_a_reason_of_ten_characters(): void
    {
        $r = $this->approved();
        foreach ([[[], 'usually 10 to 13'], [['reference_override' => '1'], 'at least 10'], [['reference_override' => '1', 'reference_override_reason' => 'too short'], 'at least 10'],
                  [['reference_override_reason' => 'The receipt shows this number clearly'], 'usually 10 to 13']] as [$extra, $needle]) {
            try {
                $this->send($r, 'GCASH-ABC', $extra);
                $this->fail('refused');
            } catch (PipelineException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
        $this->assertSame(0, ServiceRefundAttempt::count());

        $this->send($r, 'GCASH-ABC', ['reference_override' => '1', 'reference_override_reason' => 'The receipt shows this number clearly']);
        $a = ServiceRefundAttempt::sole();
        $this->assertSame('GCASH-ABC', $a->transaction_number);
        $this->assertStringContainsString('Reference override: The receipt shows this number clearly', $a->note);
        $this->assertSame('refunded', $r->fresh()->status);
        $this->assertStringContainsString('did not look like a normal GCash reference', $r->inquiry->fresh()->admin_notes);
    }

    public function test_the_override_is_never_written_to_the_customers_thread(): void
    {
        $r = $this->approved();
        $this->send($r, 'GCASH-ABC', ['reference_override' => '1', 'reference_override_reason' => 'The receipt shows this number clearly']);
        $thread = $r->messages()->pluck('body')->implode("\n");
        $this->assertStringNotContainsString('override', strtolower($thread));
        $this->assertStringNotContainsString('does not look like', $thread);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->get(route('services.refund.show', $r->reference))->assertOk()->assertDontSee('The receipt shows this number clearly');
    }

    public function test_cash_receipt_rules(): void
    {
        foreach (['abc', str_repeat('A', 21), 'AB 1234', 'AB#1234', ''] as $bad) {
            $r = $this->approved('cash');
            try {
                $this->send($r, $bad);
                $this->fail("$bad should be refused");
            } catch (PipelineException $e) {
                $this->assertMatchesRegularExpression('/receipt|transaction number/', $e->getMessage());
            }
            // cash has no override
            try {
                $this->send($r, $bad === '' ? 'ab' : $bad, ['reference_override' => '1', 'reference_override_reason' => 'It is what it is, really']);
                $this->fail('no override for cash');
            } catch (PipelineException $e) {
                $this->assertStringContainsString('4 to 20', $e->getMessage());
            }
        }
        foreach (['R-2026-0042', 'abcd', str_repeat('9', 20)] as $good) {
            $r = $this->approved('cash');
            $this->send($r, $good);
            $this->assertSame($good, ServiceRefundAttempt::orderByDesc('id')->first()->transaction_number);
        }
    }

    public function test_the_http_route_accepts_a_spaced_number_and_the_detail_page_has_the_two_rows(): void
    {
        $r = $this->approved();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin')->get(route('admin.refunds.show', $r->id))->assertOk()
            ->assertSee('GCash Number')->assertSee('09181112222')->assertSee('GCash Initials')->assertSee('Juan D.')
            ->assertSee('GCash reference numbers are usually 10 to 13 digits, for example 6028 871 884223')
            ->assertSee('I sent this refund and the details match')
            ->assertDontSee('onsubmit="return confirm', false);

        $this->post(route('admin.refunds.mark-refunded', $r->id), [
            'amount' => '2000', 'method' => 'gcash', 'transaction_number' => '6028 871 884223', 'sent_on' => now('Asia/Manila')->toDateString(),
        ])->assertSessionHas('success');
        $this->assertSame('6028871884223', ServiceRefundAttempt::sole()->transaction_number);
    }
}
