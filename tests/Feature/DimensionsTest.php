<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\User;
use App\Support\Dimensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 18 part B: Length x Width x Height with a unit, stored in centimetres.
 */
class DimensionsTest extends TestCase
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

    /* ───────────── conversion ───────────── */

    public function test_every_unit_converts_to_centimetres(): void
    {
        $this->assertSame(120.0, Dimensions::toCm(120, 'cm'));
        $this->assertSame(12.0, Dimensions::toCm(120, 'mm'));
        $this->assertSame(120.0, Dimensions::toCm(1.2, 'm'));
        $this->assertSame(304.8, Dimensions::toCm(120, 'in'));
        $this->assertSame(121.92, Dimensions::toCm(4, 'ft'));
        $this->assertSame(2.54, Dimensions::toCm(1, 'in'));
        $this->assertSame(30.48, Dimensions::toCm(1, 'ft'));
        $this->assertSame(0.1, Dimensions::toCm(1, 'mm'));
        $this->assertSame(100.0, Dimensions::toCm(1, 'm'));
        $this->assertSame(75.0, Dimensions::toCm('75', 'cm'), 'strings are fine');
    }

    public function test_the_rounding_rule_is_two_decimals_with_halves_rounded_up(): void
    {
        $this->assertSame(2.55, Dimensions::toCm(1.005, 'in'));   // 2.5527
        $this->assertSame(0.03, Dimensions::toCm(0.25, 'mm'));    // 0.025 exactly: half up
        $this->assertSame(0.03, Dimensions::toCm(0.254, 'mm'));   // 0.0254
        $this->assertSame(0.02, Dimensions::toCm(0.249, 'mm'));   // 0.0249
        $this->assertSame(10.16, Dimensions::toCm(4, 'in'));
        $this->assertSame(91.44, Dimensions::toCm(3, 'ft'));
        $this->assertSame(33.33, Dimensions::toCm(33.333333, 'cm'));
        $this->assertSame(33.34, Dimensions::toCm(33.335, 'cm'), 'x.xx5 goes up');
    }

    public function test_unknown_units_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dimensions::toCm(10, 'yard');
    }

    public function test_converting_back_for_display(): void
    {
        $this->assertSame(47.2, Dimensions::fromCm(120, 'in'));
        $this->assertSame(3.94, Dimensions::fromCm(120, 'ft'));
        $this->assertSame(1.2, Dimensions::fromCm(120, 'm'));
        $this->assertSame(1200.0, Dimensions::fromCm(120, 'mm'));
        $this->assertSame(120.0, Dimensions::fromCm(120, 'cm'));
    }

    public function test_display_formats(): void
    {
        $this->assertSame('120 cm (47.2 in)', Dimensions::display(120, 'in'));
        $this->assertSame('120 cm', Dimensions::display(120, 'cm'));
        $this->assertSame('120 cm', Dimensions::display(120, null));
        $this->assertSame('120 cm', Dimensions::display(120, 'parsec'), 'an unknown unit falls back to cm');
        $this->assertSame('75.5 cm (2.48 ft)', Dimensions::display(75.5, 'ft'));
        $this->assertSame('120 x 60 x 75 cm', Dimensions::triplet(120, 60, 75));
        $this->assertSame('47.2 x 23.6 x 29.5 in (120 x 60 x 75 cm)', Dimensions::triplet(120, 60, 75, 'in'));
        $this->assertSame('—', Dimensions::triplet(null, 60, 75));
        $this->assertSame('1.2 x 0.6 x 0.75 m (120 x 60 x 75 cm)', Dimensions::triplet('120.00', '60.00', '75.00', 'm'));
    }

    /* ───────────── limits and validation ───────────── */

    public function test_limits_apply_after_conversion_in_every_unit(): void
    {
        $other = ['cm' => 60, 'mm' => 600, 'in' => 24, 'ft' => 2, 'm' => 0.6];
        $ok = fn ($v, $u) => Dimensions::fromInput($v, $other[$u], $other[$u], $u)['errors'] === [];

        $this->assertTrue($ok('5', 'cm'));
        $this->assertFalse($ok('4.99', 'cm'));
        $this->assertTrue($ok('600', 'cm'));
        $this->assertFalse($ok('600.01', 'cm'));
        $this->assertTrue($ok('50', 'mm'));
        $this->assertFalse($ok('49', 'mm'));
        $this->assertTrue($ok('2', 'in'));            // 5.08 cm
        $this->assertFalse($ok('1.9', 'in'));         // 4.83 cm
        $this->assertTrue($ok('19.6', 'ft'));         // 597.41 cm
        $this->assertFalse($ok('20', 'ft'));          // 609.6 cm
        $this->assertTrue($ok('6', 'm'));
        $this->assertFalse($ok('6.1', 'm'));
        $this->assertFalse($ok('0', 'cm'));
    }

    public function test_the_message_names_the_field_and_the_range_in_the_customers_unit(): void
    {
        $r = Dimensions::fromInput('1', 60, 75, 'in');
        $this->assertSame('Length must be between 2 in and 236.2 in.', $r['errors']['dimension_length']);
        $this->assertSame('Length must be between 5 cm and 600 cm.', Dimensions::fromInput('2', 60, 75, 'cm')['errors']['dimension_length']);
        $this->assertSame('Height must be between 5 cm and 600 cm.', Dimensions::fromInput(100, 60, 1, 'cm')['errors']['dimension_height']);
    }

    public function test_all_three_or_none(): void
    {
        $none = Dimensions::fromInput(null, '', '  ', 'cm');
        $this->assertNull($none['values']);
        $this->assertSame([], $none['errors']);

        $some = Dimensions::fromInput(120, '', null, 'cm');
        $this->assertNull($some['values']);
        $this->assertArrayHasKey('dimension_width', $some['errors']);
        $this->assertArrayHasKey('dimension_height', $some['errors']);
        $this->assertArrayNotHasKey('dimension_length', $some['errors']);
    }

    public function test_only_plain_numbers_are_accepted(): void
    {
        foreach (['abc', '12abc', '-5', '1e3', '1,2,3', '12..5', '0x10', '1000000'] as $bad) {
            $this->assertArrayHasKey('dimension_length', Dimensions::fromInput($bad, 60, 75, 'cm')['errors'], $bad);
        }
        $this->assertSame(47.5, Dimensions::fromInput('47,5', 60, 75, 'cm')['values']['length_cm'], 'a decimal comma is understood');
        $this->assertArrayHasKey('dimension_unit', Dimensions::fromInput(120, 60, 75, 'yard')['errors']);
        $this->assertSame('cm', Dimensions::fromInput(120, 60, 75, null)['values']['unit'], 'cm is the default');
    }

    /* ───────────── the form posts and the server converts ───────────── */

    private function submit(User $u, array $over = [])
    {
        Queue::fake();

        return $this->asCustomer($u)->post(route('design.submit'), array_merge([
            'prompt' => 'A sturdy narra piece with a smooth natural finish and rounded edges',
            'furniture_type' => 'Table',
        ], $over));
    }

    public function test_the_server_converts_and_stores_centimetres_and_the_unit(): void
    {
        $u = $this->flowCustomer();

        $this->submit($u, ['dimension_length' => '47.5', 'dimension_width' => '24', 'dimension_height' => '30', 'dimension_unit' => 'in'])
            ->assertSessionHasNoErrors();

        $r = CustomDesignRequest::latest('id')->first();
        $this->assertSame('120.65', (string) $r->dim_length_cm);
        $this->assertSame('60.96', (string) $r->dim_width_cm);
        $this->assertSame('76.20', (string) $r->dim_height_cm);
        $this->assertSame('in', $r->dimension_unit);
        $this->assertSame('120.65 x 60.96 x 76.2 cm', $r->dimensions, 'the older text column stays in step');
        $this->assertSame('47.5 x 24 x 30 in (120.65 x 60.96 x 76.2 cm)', $r->dimensions_label);
    }

    public function test_a_client_side_conversion_is_never_trusted(): void
    {
        $u = $this->flowCustomer();

        $this->submit($u, [
            'dimension_length' => '10', 'dimension_width' => '10', 'dimension_height' => '10', 'dimension_unit' => 'ft',
            'dim_length_cm' => '1', 'dim_width_cm' => '1', 'dim_height_cm' => '1', 'length_cm' => '1',
        ])->assertSessionHasNoErrors();

        $r = CustomDesignRequest::latest('id')->first();
        $this->assertSame('304.80', (string) $r->dim_length_cm);
    }

    public function test_bad_sizes_are_rejected_with_a_clear_message_and_nothing_is_saved(): void
    {
        $u = $this->flowCustomer();

        $this->submit($u, ['dimension_length' => '1', 'dimension_width' => '24', 'dimension_height' => '30', 'dimension_unit' => 'in'])
            ->assertSessionHasErrors(['dimension_length' => 'Length must be between 2 in and 236.2 in.']);
        $this->submit($u, ['dimension_length' => '120', 'dimension_width' => '', 'dimension_height' => '75'])
            ->assertSessionHasErrors('dimension_width');
        $this->submit($u, ['dimension_length' => '1000', 'dimension_width' => '60', 'dimension_height' => '75', 'dimension_unit' => 'cm'])
            ->assertSessionHasErrors('dimension_length');
        $this->assertSame(0, CustomDesignRequest::count());
    }

    public function test_the_size_stays_optional(): void
    {
        $u = $this->flowCustomer();
        $this->submit($u)->assertSessionHasNoErrors();

        $r = CustomDesignRequest::latest('id')->first();
        $this->assertNull($r->dim_length_cm);
        $this->assertNull($r->dimensions);
        $this->assertNull($r->dimensions_label);
    }

    public function test_the_form_has_length_width_height_and_a_unit_and_no_depth(): void
    {
        $u = $this->flowCustomer();
        $html = $this->asCustomer($u)->get(route('design.index'))->assertOk()->getContent();

        foreach (['dimension_length', 'dimension_width', 'dimension_height', 'dimension_unit'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html);
        }
        $this->assertStringContainsString('Length</label>', $html);
        $this->assertStringContainsString('Width</label>', $html);
        $this->assertStringContainsString('Height</label>', $html);
        $this->assertStringNotContainsString('Depth', $html);
        $this->assertStringContainsString('the longest side across', $html);
        $this->assertStringContainsString('the shorter side across', $html);
        $this->assertStringContainsString('from the floor to the top', $html);
        foreach (['cm', 'in', 'ft', 'm', 'mm'] as $unit) {
            $this->assertStringContainsString('<option value="' . $unit . '"', $html);
        }
        $this->assertStringContainsString('<option value="cm" selected', $html, 'cm is the default');
    }

    /* ───────────── old rows ───────────── */

    public function test_old_rows_are_read_as_length_width_height_without_changing_them(): void
    {
        $u = $this->flowCustomer();
        // before this change: "Width x Depth x Height" in cm, no new columns
        $r = CustomDesignRequest::create([
            'user_id' => $u->id, 'title' => 'x', 'description' => 'A table', 'status' => 'approved',
            'dimensions' => '120 x 60 x 75 cm',
        ]);

        $this->assertNull($r->dim_length_cm);
        $this->assertSame(['length' => 120.0, 'width' => 60.0, 'height' => 75.0], $r->dimensions_cm);
        $this->assertSame('120 x 60 x 75 cm', $r->dimensions_label);
        $this->assertSame('120 x 60 x 75 cm', $r->fresh()->dimensions, 'the stored text is untouched');
        $this->assertNull($r->fresh()->dimension_unit);

        $r->forceFill(['dimensions' => 'big'])->save();
        $this->assertNull($r->fresh()->dimensions_cm);
        $this->assertNull($r->fresh()->dimensions_label);
    }

    public function test_new_columns_win_over_the_old_text(): void
    {
        $u = $this->flowCustomer();
        $r = CustomDesignRequest::create([
            'user_id' => $u->id, 'title' => 'x', 'description' => 'A table', 'status' => 'approved',
            'dimensions' => '1 x 2 x 3 cm', 'dim_length_cm' => 100, 'dim_width_cm' => 50, 'dim_height_cm' => 70, 'dimension_unit' => 'mm',
        ]);

        $this->assertSame(['length' => 100.0, 'width' => 50.0, 'height' => 70.0], $r->dimensions_cm);
        $this->assertSame('1000 x 500 x 700 mm (100 x 50 x 70 cm)', $r->dimensions_label);
    }

    /* ───────────── shown everywhere in Length x Width x Height ───────────── */

    public function test_the_size_is_shown_in_the_customers_unit_and_cm_on_quote_review_agreement_order_and_admin(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $r->forceFill(['furniture_type' => 'Table', 'dim_length_cm' => 180, 'dim_width_cm' => 90, 'dim_height_cm' => 75, 'dimension_unit' => 'in'])->save();
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();

        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()
            ->assertSee('Size (length x width x height)')->assertSee('70.9 x 35.4 x 29.5 in (180 x 90 x 75 cm)');

        // the quote form starts from the customer's size: Length, Width, Height
        $this->asAdmin($admin)->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk()
            ->assertSee('Length (cm)')->assertSee('Width (cm)')->assertSee('Height (cm)')->assertDontSee('Depth')
            ->assertSee('value="180"', false)->assertSee('value="90"', false);

        $quote = $this->quoteAndSend($admin, $r);   // 180 x 90 x 75 in the helper's fields
        $expected = '70.9 x 35.4 x 29.5 in (180 x 90 x 75 cm)';

        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee($expected);
        $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk()->assertSee($expected);

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);
        $this->assertSame('in', $a->terms_snapshot['quote']['dimension_unit']);
        $this->assertTrue($a->snapshotIsIntact());
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.agreement', $a->id))->assertOk()->assertSee($expected);

        $this->asCustomer($customer)->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = \App\Models\Order::where('design_agreement_id', $a->id)->sole();
        $this->flushSession();
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()->assertSee($expected);
        $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk()->assertSee($expected);
    }

    public function test_an_old_agreement_snapshot_shows_cm_only_and_is_not_changed(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);

        $this->assertArrayNotHasKey('dimension_unit', $a->terms_snapshot['quote'], 'no unit was chosen for this request');
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.agreement', $a->id))->assertOk()->assertSee('180 x 90 x 75 cm')->assertDontSee(' in (');
        $this->assertTrue($a->fresh()->snapshotIsIntact());
    }
}
