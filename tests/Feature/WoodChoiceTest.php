<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDesign;
use App\Models\CustomDesignRequest;
use App\Models\Notification;
use App\Models\User;
use App\Support\WoodChoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 18 part C: a required wood choice. Three fixed woods and a special request.
 */
class WoodChoiceTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function submit(User $u, array $over = [])
    {
        Queue::fake();

        return $this->asCustomer($u)->post(route('design.submit'), array_merge([
            'prompt' => 'A sturdy wooden table with a smooth natural finish and rounded edges',
            'furniture_type' => 'Table', 'preferred_material' => 'Mahogany',
        ], $over));
    }

    private function prompt(CustomDesignRequest $r): string
    {
        $job = new GenerateAiDesign($r->id);
        $m = new \ReflectionMethod($job, 'buildPrompt');
        $m->setAccessible(true);

        return $m->invoke($job, $r->fresh());
    }

    private function special(array $over = []): array
    {
        return array_merge([
            'preferred_material' => 'Special request',
            'wood_special_details' => 'Old narra beams from our house',
            'wood_supply' => 'customer',
        ], $over);
    }

    /* ───────────── the choice ───────────── */

    public function test_the_list_is_three_woods_and_a_special_request(): void
    {
        $this->assertSame(['Gmelina', 'Mahogany', 'Acacia', 'Special request'], WoodChoices::LIST);
        $this->assertSame(['Gmelina', 'Mahogany', 'Acacia'], WoodChoices::FIXED);
        $this->assertSame('Not specified', WoodChoices::label(null));
        $this->assertSame('Mahogany', WoodChoices::label('Mahogany'));
        $this->assertSame(
            'Special request: Old narra beams (customer-supplied material, labor only)',
            WoodChoices::label('Special request', 'Old narra beams', 'customer')
        );
        $this->assertSame('Special request: teak (wood supplied by the shop)', WoodChoices::label('Special request', 'teak', 'shop'));
    }

    public function test_each_fixed_wood_is_accepted_and_stored_without_special_fields(): void
    {
        foreach (WoodChoices::FIXED as $wood) {
            $u = $this->flowCustomer();
            $this->submit($u, ['preferred_material' => $wood, 'wood_special_details' => 'ignored', 'wood_supply' => 'customer'])->assertSessionHasNoErrors();

            $r = CustomDesignRequest::latest('id')->first();
            $this->assertSame($wood, $r->preferred_material);
            $this->assertNull($r->wood_special_details, 'special text is dropped for a fixed wood');
            $this->assertNull($r->wood_supply);
            $this->assertFalse($r->is_special_wood);
        }
    }

    public function test_a_wood_is_required_and_old_values_are_not_accepted_for_new_requests(): void
    {
        $u = $this->flowCustomer();

        foreach ([null, '', '  ', 'Narra', 'Oak', 'Pine', 'No Preference', 'Gemelina', 'mahogany', 'MAHOGANY'] as $bad) {
            $this->submit($u, ['preferred_material' => $bad])->assertSessionHasErrors('preferred_material');
        }
        $this->asCustomer($u)->post(route('design.submit'), ['prompt' => 'A sturdy wooden table with a smooth natural finish', 'furniture_type' => 'Table'])
            ->assertSessionHasErrors('preferred_material');
        $this->assertSame(0, CustomDesignRequest::count());
    }

    public function test_a_special_request_needs_the_text_and_who_supplies_the_wood(): void
    {
        $u = $this->flowCustomer();

        $this->submit($u, $this->special(['wood_special_details' => '']))->assertSessionHasErrors('wood_special_details');
        $this->submit($u, $this->special(['wood_special_details' => '   ']))->assertSessionHasErrors('wood_special_details');
        $this->submit($u, $this->special(['wood_special_details' => str_repeat('x', 501)]))->assertSessionHasErrors('wood_special_details');
        $this->submit($u, $this->special(['wood_supply' => null]))->assertSessionHasErrors('wood_supply');
        $this->submit($u, $this->special(['wood_supply' => 'someone else']))->assertSessionHasErrors('wood_supply');
        $this->assertSame(0, CustomDesignRequest::count());

        $this->submit($u, $this->special())->assertSessionHasNoErrors();
        $r = CustomDesignRequest::latest('id')->first();
        $this->assertSame('Special request', $r->preferred_material);
        $this->assertSame('Old narra beams from our house', $r->wood_special_details);
        $this->assertSame('customer', $r->wood_supply);
        $this->assertTrue($r->is_special_wood);
        $this->assertTrue($r->supplies_own_wood);

        $u2 = $this->flowCustomer();
        $this->submit($u2, $this->special(['wood_supply' => 'shop']))->assertSessionHasNoErrors();
        $r2 = CustomDesignRequest::latest('id')->first();
        $this->assertSame('shop', $r2->wood_supply);
        $this->assertFalse($r2->supplies_own_wood);
    }

    public function test_the_form_has_the_four_choices_and_the_special_panel(): void
    {
        $html = $this->asCustomer($this->flowCustomer())->get(route('design.index'))->assertOk()->getContent();

        foreach (['Gmelina', 'Mahogany', 'Acacia', 'Special request'] as $wood) {
            $this->assertMatchesRegularExpression('/<input type="radio" name="preferred_material" value="' . preg_quote($wood, '/') . '" required/', $html);
        }
        foreach (['Narra', 'Oak', 'Pine', 'No Preference', 'Gemelina'] as $old) {
            $this->assertStringNotContainsString('value="' . $old . '"', $html);
        }
        $this->assertStringContainsString('name="wood_special_details"', $html);
        $this->assertStringContainsString('I will provide my own wood (labor only)', $html);
        $this->assertStringContainsString('Use a different wood supplied by the shop', $html);
        $this->assertMatchesRegularExpression('/id="cd-wood-special"\s+hidden/', $html);
    }

    /* ───────────── the AI prompt ───────────── */

    public function test_only_a_fixed_wood_goes_into_the_picture_prompt(): void
    {
        $this->submit($this->flowCustomer(), ['preferred_material' => 'Gmelina']);
        $this->assertStringContainsString('Wood: Gmelina.', $this->prompt(CustomDesignRequest::latest('id')->first()));

        $this->submit($this->flowCustomer(), $this->special());
        $prompt = $this->prompt(CustomDesignRequest::latest('id')->first());
        $this->assertStringNotContainsString('Wood:', $prompt);
        $this->assertStringNotContainsString('Old narra beams', $prompt);
    }

    /* ───────────── what the admin sees ───────────── */

    private function submitted(array $wood): array
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $r->forceFill($wood)->save();
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();

        return [$admin, $customer, $r->fresh()];
    }

    public function test_the_admin_sees_clear_flags_for_special_wood_and_customer_supplied_material(): void
    {
        [$admin, , $own] = $this->submitted([
            'preferred_material' => 'Special request', 'wood_special_details' => 'Old narra beams', 'wood_supply' => 'customer',
        ]);

        $show = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $own->id))->assertOk()
            ->assertSee('Special wood request')->assertSee('Customer-supplied material')->assertSee('Old narra beams')
            ->assertSee('No automatic price. Set the quote price by hand.');
        $this->asAdmin($admin)->get(route('admin.custom-orders.index'))->assertOk()->assertSee('Special wood')->assertSee('Own material');
        $this->asAdmin($admin)->get(route('admin.custom-orders.quotes.create', $own->id))->assertOk()
            ->assertSee('Special wood request')->assertSee('Customer-supplied material')->assertSee('There is no automatic price for this wood');

        [$admin2, , $shop] = $this->submitted([
            'preferred_material' => 'Special request', 'wood_special_details' => 'Teak', 'wood_supply' => 'shop',
        ]);
        $this->asAdmin($admin2)->get(route('admin.custom-orders.show', $shop->id))->assertOk()
            ->assertSee('Special wood request')->assertSee('Wood supplied by the shop')->assertDontSee('Customer-supplied material');

        [$admin3, , $fixed] = $this->submitted(['preferred_material' => 'Mahogany']);
        $this->asAdmin($admin3)->get(route('admin.custom-orders.show', $fixed->id))->assertOk()
            ->assertSee('Mahogany')->assertDontSee('Special wood request')->assertDontSee('Customer-supplied material');
    }

    public function test_the_quote_form_starts_from_the_wood(): void
    {
        [$admin, , $r] = $this->submitted(['preferred_material' => 'Acacia']);
        $this->asAdmin($admin)->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk()->assertSee('value="Acacia"', false);

        [$admin2, , $r2] = $this->submitted(['preferred_material' => 'Special request', 'wood_special_details' => 'Old narra beams', 'wood_supply' => 'customer']);
        $this->asAdmin($admin2)->get(route('admin.custom-orders.quotes.create', $r2->id))->assertOk()
            ->assertSee('value="Customer-supplied: Old narra beams"', false);
    }

    public function test_fixed_wood_pricing_is_unchanged_nothing_is_priced_from_the_wood(): void
    {
        foreach (['Gmelina', 'Mahogany', 'Acacia', 'Narra'] as $wood) {
            [$admin, , $r] = $this->submitted(['preferred_material' => $wood]);
            $quote = $this->quoteAndSend($admin, $r);

            $this->assertSame('20000.00', (string) $quote->price, $wood);
            $this->assertSame('20500.00', number_format($quote->totalAmount(), 2, '.', ''), $wood);
        }

        [$admin, , $special] = $this->submitted(['preferred_material' => 'Special request', 'wood_special_details' => 'x', 'wood_supply' => 'shop']);
        $quote = $this->quoteAndSend($admin, $special);
        $this->assertSame('20000.00', (string) $quote->price, 'the price is whatever the admin sets');
    }

    /* ───────────── quote, agreement and order ───────────── */

    public function test_new_records_show_the_wood_on_the_quote_agreement_and_order(): void
    {
        [$admin, $customer, $r] = $this->submitted([
            'preferred_material' => 'Special request', 'wood_special_details' => 'Old narra beams', 'wood_supply' => 'customer',
        ]);
        $label = 'Special request: Old narra beams (customer-supplied material, labor only)';

        $quote = $this->quoteAndSend($admin, $r);
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('Wood you chose')->assertSee($label);
        $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk()->assertSee($label);

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);
        $this->assertSame($label, $a->terms_snapshot['design']['wood']['label']);
        $this->assertSame('customer', $a->terms_snapshot['design']['wood']['supply']);
        $this->assertTrue($a->snapshotIsIntact());

        $this->asCustomer($customer)->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = \App\Models\Order::where('design_agreement_id', $a->id)->sole();
        $this->flushSession();
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()->assertSee('Wood: ' . $label);
        $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk()->assertSee('customer-supplied material');
    }

    public function test_old_requests_with_an_old_wood_show_it_as_it_is(): void
    {
        [$admin, $customer, $r] = $this->submitted(['preferred_material' => 'Narra']);   // from before the fixed list

        $this->assertSame('Narra', $r->wood_label);
        $this->assertFalse($r->is_special_wood);
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Narra');

        $r->forceFill(['preferred_material' => null])->save();
        $this->assertSame('Not specified', $r->fresh()->wood_label);
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk();
    }

    public function test_the_owner_doc_lists_the_customer_supplied_material_rules(): void
    {
        $doc = file_get_contents(base_path('docs/terms-draft-v2.md'));

        $this->assertStringContainsString('## Customer-supplied material', $doc);
        foreach (['Material quality', 'Waste and offcuts', 'Damage', 'Delivery of the material', 'No warranty on the material'] as $topic) {
            $this->assertStringContainsString('**' . $topic, $doc);
        }
        $section = substr($doc, strpos($doc, '## Customer-supplied material'), 1800);
        $this->assertGreaterThanOrEqual(5, substr_count($section, '[TO CONFIRM'));

        // the stored policy rows are not edited by this
        $v2 = \App\Models\AgreementPolicy::where('version', 2)->firstOrFail();
        $this->assertStringNotContainsString('Customer-supplied material', $v2->body);
    }
}
