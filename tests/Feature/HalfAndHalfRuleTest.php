<?php

namespace Tests\Feature;

use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\InvoicePaymentRules;
use App\Support\InvoiceGateway;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21h part 2: half and half is allowed for ALL service types when the invoice total is at least the threshold
 * (3,000 pesos, one constant). The admin form re-checks the total live. Live behaviour: the project has no JS test runner,
 * so the DOM wiring is covered here by markup assertions plus the documented manual check in docs/ui-components.md.
 */
class HalfAndHalfRuleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
        $this->app->instance(InvoiceGateway::class, new CancelBugGateway());
    }

    private function ticket(string $type): ServiceInquiry
    {
        static $n = 0;
        $n++;

        return ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . (9500 + $n), 'service_type' => $type, 'furniture_type' => 'Dining Table',
            'concern' => 'Needs work.', 'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com', 'status' => 'new',
        ]);
    }

    private function save(ServiceInquiry $t, string $cost, string $plan = 'half_half')
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'admin')->post(route('admin.service-invoices.store', $t->id), [
            'service_description' => 'Repair', 'service_cost' => $cost, 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => $plan,
        ]);
    }

    public function test_every_service_type_at_below_at_and_above_the_threshold(): void
    {
        foreach (['labor_only_own_wood', 'repair', 'other', 'some_future_type'] as $type) {
            $this->assertFalse(InvoicePaymentRules::allowsHalfHalf($type, '2999.99'), "$type below");
            $this->assertTrue(InvoicePaymentRules::allowsHalfHalf($type, '3000.00'), "$type at");
            $this->assertTrue(InvoicePaymentRules::allowsHalfHalf($type, '3000.01'), "$type above");
        }
        $this->assertSame(3000.00, InvoicePaymentRules::HALF_HALF_MIN_TOTAL);
    }

    public function test_the_reason_names_the_threshold_and_the_invoice_total(): void
    {
        $this->assertSame(
            'Half and half needs a total of ₱3,000.00 or more. This invoice is ₱1,200.00.',
            InvoicePaymentRules::halfHalfProblem('other', '1200')
        );
    }

    public function test_something_else_can_be_saved_as_half_and_half_when_big_enough(): void
    {
        $t = $this->ticket('other');
        $this->save($t, '3000.00')->assertSessionHasNoErrors();
        $this->assertSame('half_half', $t->invoices()->first()->payment_plan);
    }

    public function test_a_forged_half_and_half_request_below_the_threshold_is_refused_for_every_type(): void
    {
        foreach (['labor_only_own_wood', 'repair', 'other'] as $type) {
            $t = $this->ticket($type);
            $this->save($t, '2999.99')->assertSessionHasErrors();
            $this->assertSame(0, $t->invoices()->count(), "$type: nothing saved");
        }
        $t = $this->ticket('repair');
        $this->expectException(PipelineException::class);
        $draft = app(ServiceInvoiceDesk::class)->createDraft($t->id, $this->admin, ServiceInvoiceDesk::validate([
            'service_description' => 'Repair', 'service_cost' => '1200', 'delivery_option' => 'none', 'customer_name' => 'J', 'customer_phone' => '09171234567', 'payment_plan' => 'half_half',
        ]));
    }

    public function test_editing_a_draft_cannot_push_it_below_the_threshold_with_half_and_half(): void
    {
        $t = $this->ticket('other');
        $this->save($t, '4000')->assertSessionHasNoErrors();
        $inv = $t->invoices()->first();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin, 'admin')->post(route('admin.service-invoices.update', $inv->id), [
            'service_description' => 'Repair', 'service_cost' => '1200', 'delivery_option' => 'none',
            'customer_name' => 'Juan', 'customer_phone' => '09171234567', 'payment_plan' => 'half_half',
        ])->assertSessionHasErrors();
        $this->assertSame('4000.00', (string) $inv->fresh()->total);
    }

    public function test_the_form_is_live_for_every_type_on_create_and_edit(): void
    {
        foreach (['labor_only_own_wood', 'repair', 'other'] as $type) {
            $t = $this->ticket($type);
            $this->app['auth']->forgetGuards();
            $html = $this->actingAs($this->admin, 'admin')->get(route('admin.service-invoices.create', $t->id))->assertOk()->getContent();
            $this->assertStringContainsString('id="inv-half"', $html);
            $this->assertStringNotContainsString('inv-half" value="half_half" disabled', $html, 'never disabled by the server');
            $this->assertStringNotContainsString('@disabled', $html);
            $this->assertStringContainsString('function syncHalf', $html, 'the total is re-evaluated on every edit');
            $this->assertStringContainsString('data-min="3000', $html);
            $this->assertStringContainsString('Half and half needs a total of ₱3,000.00 or more.', $html);
        }

        $t = $this->ticket('other');
        $this->save($t, '4000');
        $this->get(route('admin.service-invoices.edit', $t->invoices()->first()->id))->assertOk()->assertSee('function syncHalf', false);
    }
}
