<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\DesignAgreement;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\MaterialEstimate;
use App\Models\MaterialUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\ProductMaterialEstimate;
use App\Models\User;
use App\Support\AdminReports;
use App\Support\CustomOrderStatus;
use App\Support\MaterialDeduction;
use App\Support\MaterialEstimator;
use App\Support\MaterialStock;
use App\Support\OrderTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Materials: the estimate deducted when an order is CONFIRMED, put back when it is cancelled, never blocking an order,
 * the inventory movements, the furniture-type library, the per-product estimate and Reports > Materials.
 */
class MaterialsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');
        $this->stock();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function stock(array $override = []): void
    {
        $rows = $override + [
            'Wood (Narra)' => [1000, 'sqft'], 'Wood (Gemelina)' => [1000, 'sqft'], 'Barnis / Varnish' => [100, 'liters'],
            'White Lacquer' => [100, 'liters'], 'Nails and Screws' => [5000, 'pieces'], 'Sandpaper' => [500, 'pieces'], 'Wood Glue' => [50, 'liters'],
        ];
        foreach ($rows as $name => [$qty, $unit]) {
            MaterialStock::create(['material_name' => $name, 'material_quantity' => $qty, 'unit' => $unit, 'low_stock_threshold' => 10]);
        }
    }

    private function qty(string $name): float
    {
        return (float) Inventory::where('material_name', $name)->value('material_quantity');
    }

    private function product(string $name = 'King Bed Frame', string $wood = 'Narra', string $category = 'Bed'): Product
    {
        $p = Product::create(['product_name' => $name, 'category' => $category, 'material' => $wood, 'finish' => 'Natural', 'price' => 1000, 'stock_quantity' => 5, 'product_status' => 'available']);
        MaterialEstimator::createProductEstimate($p);

        return $p;
    }

    /** A pending catalog order, paid in full, with $qty of the product. */
    private function catalogOrder(Product $product, int $qty = 2, string $status = 'pending'): Order
    {
        $order = $this->makeOrder($this->makeUser(), ['total_amount' => 1000, 'delivery_option' => 'pickup', 'order_status' => $status]);
        $payment = $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'onsite']);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->product_name, 'quantity' => $qty, 'unit_price' => 500, 'subtotal' => 500 * $qty]);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 1000, 'method' => 'cash', 'paid_on' => now()->toDateString()]);
        $order->forceFill(['amount_paid' => 1000])->save();

        return $order->fresh();
    }

    private function confirm(Order $order): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.orders.stage.advance', $order->id), ['current_status' => 'pending', 'confirmed' => '1', 'message' => ''])
            ->assertSessionHas('success');
    }

    private function cancel(Order $order, string $from): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.orders.stage.cancel', $order->id), ['current_status' => $from, 'confirmed' => '1', 'reason' => 'Customer asked us to stop'])
            ->assertSessionHas('success');
    }

    /** A customized order at $status with a request carrying the given attributes; upfront (10,000) already paid. */
    private function customOrder(array $request = [], string $status = 'pending'): Order
    {
        $customer = $this->makeUser();
        $admin = User::where('role', 'admin')->first() ?? $this->admin();
        $r = CustomDesignRequest::create($request + [
            'user_id' => $customer->id, 'title' => 'Dining table', 'furniture_type' => 'Table', 'description' => 'A table', 'status' => 'approved',
            'pipeline_status' => 'ordered', 'payment_due_at' => now()->addDay(), 'preferred_material' => 'Narra',
        ]);
        $q = $this->makeQuote($r, ['status' => 'accepted', 'sent_by' => $admin->id]);
        $snapshot = ['design' => ['title' => $r->title], 'money' => ['total' => '20000.00']];
        $a = $this->makeAgreement($q, $customer, $admin, [
            'agreement_number' => 'LP-A-2026-' . random_int(1000, 9999), 'terms_snapshot' => $snapshot, 'snapshot_sha256' => DesignAgreement::hashSnapshot($snapshot),
        ]);
        $order = $this->makeOrder($customer, ['total_amount' => 20000, 'order_status' => $status]);
        $order->forceFill(['design_agreement_id' => $a->id, 'design_request_id' => $r->id])->save();
        $payment = $this->makePayment($order, ['amount' => 10000, 'payment_type' => 'installment']);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 10000, 'method' => 'cash', 'paid_on' => now()->toDateString(), 'recorded_by' => $admin->id]);
        $order->forceFill(['amount_paid' => 10000])->save();

        return $order->fresh();
    }

    private function confirmCustom(Order $order): void
    {
        app(CustomOrderStatus::class)->change($order->id, $this->admin(), 'confirmed');
    }

    /* ───────────── A) deduct when CONFIRMED ───────────── */

    public function test_confirming_a_catalog_order_deducts_quantity_times_the_product_estimate(): void
    {
        $order = $this->catalogOrder($this->product(), 2);

        $this->assertSame(1000.0, $this->qty('Wood (Narra)'), 'placing/holding a pending order takes nothing');
        $this->assertSame(0, MaterialUsage::count());

        $this->confirm($order);

        $this->assertSame('confirmed', $order->fresh()->order_status);
        // King bed: 100 sqft, 2.4 L varnish, 1.6 L lacquer, 600 nails, 16 sandpaper, 0.5 L glue; two pieces
        $this->assertEqualsWithDelta(800, $this->qty('Wood (Narra)'), 0.001);
        $this->assertEqualsWithDelta(95.2, $this->qty('Barnis / Varnish'), 0.001);
        $this->assertEqualsWithDelta(96.8, $this->qty('White Lacquer'), 0.001);
        $this->assertEqualsWithDelta(3800, $this->qty('Nails and Screws'), 0.001);
        $this->assertEqualsWithDelta(468, $this->qty('Sandpaper'), 0.001);
        $this->assertEqualsWithDelta(49, $this->qty('Wood Glue'), 0.001);

        $this->assertSame(6, MaterialUsage::where('order_id', $order->id)->count());
        $this->assertSame(6, InventoryMovement::where('order_id', $order->id)->where('type', 'consumed')->count());
        $this->assertNotNull($order->fresh()->materials_deducted_at);
    }

    public function test_a_payment_that_confirms_the_order_deducts_too(): void
    {
        $order = $this->catalogOrder($this->product(), 1);
        $this->assertTrue(app(OrderTracking::class)->autoConfirm($order));

        $this->assertEqualsWithDelta(900, $this->qty('Wood (Narra)'), 0.001);
    }

    public function test_confirming_twice_or_retrying_deducts_only_once(): void
    {
        $order = $this->catalogOrder($this->product(), 1);
        $this->confirm($order);
        $after = $this->qty('Wood (Narra)');

        MaterialDeduction::deduct($order->fresh());
        MaterialDeduction::deduct($order->fresh());
        $this->assertFalse(app(OrderTracking::class)->autoConfirm($order->fresh()), 'already confirmed: no second confirmation');
        $this->actingAs($this->admin(), 'admin')->post(route('admin.orders.stage.advance', $order->id), ['current_status' => 'pending', 'confirmed' => '1'])->assertSessionHas('error');

        $this->assertSame($after, $this->qty('Wood (Narra)'));
        $this->assertSame(6, MaterialUsage::where('order_id', $order->id)->count());
    }

    public function test_confirming_a_custom_order_deducts_the_type_estimate_for_the_requests_wood(): void
    {
        // Table -> "Dining Table" (53 sqft, 1.2, 0.8, 200, 12, 0.3); no size given, so the factor is 1
        $order = $this->customOrder();
        $this->confirmCustom($order);

        $this->assertEqualsWithDelta(947, $this->qty('Wood (Narra)'), 0.001);
        $this->assertEqualsWithDelta(98.8, $this->qty('Barnis / Varnish'), 0.001);
        $this->assertEqualsWithDelta(4800, $this->qty('Nails and Screws'), 0.001);
        $this->assertSame(6, MaterialUsage::where('order_id', $order->id)->count());
    }

    public function test_an_unknown_custom_type_uses_other_unknown(): void
    {
        $this->confirmCustom($this->customOrder(['furniture_type' => 'Other', 'furniture_type_other' => 'Spaceship']));

        $this->assertEqualsWithDelta(959, $this->qty('Wood (Narra)'), 0.001);   // Other / Unknown = 41 sqft
    }

    public function test_a_wood_that_is_not_stocked_deducts_no_wood_and_is_recorded_as_unmapped(): void
    {
        $order = $this->catalogOrder($this->product('Pine Bench', 'Pine', 'Chair'), 1);
        $this->confirm($order);

        $this->assertSame(1000.0, $this->qty('Wood (Narra)'));
        $this->assertEqualsWithDelta(99.5, $this->qty('Barnis / Varnish'), 0.001, 'finish is still taken (Bench 0.5 L)');
        $this->assertSame('unmapped_wood:Pine', $order->fresh()->materials_note);
        $this->assertSame(5, MaterialUsage::where('order_id', $order->id)->count());
    }

    public function test_gmelina_and_gemelina_are_the_same_wood(): void
    {
        $order = $this->catalogOrder($this->product('Gmelina Table', 'Gmelina', 'Table'), 1);
        $this->confirm($order);
        $this->assertEqualsWithDelta(947, $this->qty('Wood (Gemelina)'), 0.001, 'Gmelina product -> Wood (Gemelina) row');

        $this->assertSame('Wood (Gemelina)', MaterialEstimator::woodRow('Gmelina')->material_name);
        $this->assertSame('Wood (Gemelina)', MaterialEstimator::woodRow('gemelina')->material_name);
        $this->assertSame('Wood (Narra)', MaterialEstimator::woodRow('NARRA')->material_name, 'case-insensitive');
        $this->assertNull(MaterialEstimator::woodRow('Bamboo-wood'));
    }

    public function test_customer_supplied_wood_deducts_no_wood_but_still_the_consumables(): void
    {
        $order = $this->customOrder(['preferred_material' => 'Special request', 'wood_special_details' => 'Narra', 'wood_supply' => 'customer']);
        $this->confirmCustom($order);

        $this->assertSame(1000.0, $this->qty('Wood (Narra)'));
        $this->assertEqualsWithDelta(98.8, $this->qty('Barnis / Varnish'), 0.001);
        $this->assertSame('customer_wood', $order->fresh()->materials_note);
    }

    public function test_a_special_wood_the_shop_supplies_is_unmapped_when_it_is_not_stocked(): void
    {
        $order = $this->customOrder(['preferred_material' => 'Special request', 'wood_special_details' => 'Molave', 'wood_supply' => 'shop']);
        $this->confirmCustom($order);

        $this->assertSame('unmapped_wood:Molave', $order->fresh()->materials_note);
        $this->assertSame(1000.0, $this->qty('Wood (Narra)'));
    }

    public function test_the_custom_size_factor_is_volume_over_reference_volume_clamped(): void
    {
        $ref = MaterialEstimate::where('name', 'Dining Table')->firstOrFail();
        $this->assertEquals([180, 90, 75], [$ref->ref_length_cm, $ref->ref_width_cm, $ref->ref_height_cm]);
        $this->assertNull(MaterialEstimate::where('name', 'Bunk Bed')->value('ref_length_cm'), 'only the listed types have a reference size');

        $req = fn (array $d) => new CustomDesignRequest($d);
        $f = fn (array $d, string $type = 'Dining Table') => MaterialEstimator::sizeFactor($req($d), $type);

        $this->assertEqualsWithDelta(1.0, $f(['dim_length_cm' => 180, 'dim_width_cm' => 90, 'dim_height_cm' => 75]), 0.0001);
        $this->assertEqualsWithDelta(1.2, $f(['dim_length_cm' => 216, 'dim_width_cm' => 90, 'dim_height_cm' => 75]), 0.0001);
        $this->assertEqualsWithDelta(1.8, $f(['dim_length_cm' => 600, 'dim_width_cm' => 300, 'dim_height_cm' => 200]), 0.0001, 'clamped at 1.8');
        $this->assertEqualsWithDelta(0.6, $f(['dim_length_cm' => 20, 'dim_width_cm' => 20, 'dim_height_cm' => 20]), 0.0001, 'clamped at 0.6');
        $this->assertSame(1.0, $f([]), 'no size given');
        $this->assertSame(1.0, $f(['dim_length_cm' => 200, 'dim_width_cm' => 100, 'dim_height_cm' => 80], 'Bunk Bed'), 'no reference size for this type');
    }

    public function test_the_size_factor_scales_wood_and_consumables_of_a_custom_order_but_never_a_catalog_order(): void
    {
        $this->confirmCustom($this->customOrder(['dim_length_cm' => 216, 'dim_width_cm' => 90, 'dim_height_cm' => 75, 'dimension_unit' => 'cm']));

        $this->assertEqualsWithDelta(1000 - 53 * 1.2, $this->qty('Wood (Narra)'), 0.01);
        $this->assertEqualsWithDelta(100 - 1.2 * 1.2, $this->qty('Barnis / Varnish'), 0.01);

        // a catalog product with a stored size is not scaled
        $p = $this->product('Narra Dining Table', 'Narra', 'Table');
        $p->forceFill(['dimensions' => '360 x 180 x 150 cm'])->save();
        $before = $this->qty('Wood (Narra)');
        $this->confirm($this->catalogOrder($p, 1));
        $this->assertEqualsWithDelta($before - 53, $this->qty('Wood (Narra)'), 0.001);
    }

    public function test_a_failure_while_deducting_never_blocks_the_confirmation(): void
    {
        Log::spy();
        Inventory::saving(function () { throw new \RuntimeException('boom'); });

        $order = $this->catalogOrder($this->product(), 1);
        $this->confirm($order);

        $this->assertSame('confirmed', $order->fresh()->order_status, 'the order is confirmed anyway');
        $this->assertNull($order->fresh()->materials_deducted_at, 'the failed attempt left nothing behind');
        $this->assertSame(0, MaterialUsage::count());
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Materials: could not deduct'))->atLeast()->once();
    }

    /* ───────────── B) restore on cancel ───────────── */

    public function test_cancelling_a_confirmed_order_puts_back_exactly_what_was_taken(): void
    {
        $order = $this->catalogOrder($this->product(), 2);
        $this->confirm($order);
        $this->assertEqualsWithDelta(800, $this->qty('Wood (Narra)'), 0.001);

        $this->cancel($order, 'confirmed');

        $this->assertEqualsWithDelta(1000, $this->qty('Wood (Narra)'), 0.001);
        $this->assertEqualsWithDelta(100, $this->qty('Barnis / Varnish'), 0.001);
        $this->assertSame(0, MaterialUsage::where('order_id', $order->id)->whereNull('reversed_at')->count());
        $this->assertSame(6, InventoryMovement::where('order_id', $order->id)->where('type', 'restored')->count());

        MaterialDeduction::restore($order->fresh());
        $this->assertEqualsWithDelta(1000, $this->qty('Wood (Narra)'), 0.001, 'a second restore adds nothing');
    }

    public function test_cancelling_from_pending_restores_nothing(): void
    {
        $order = $this->catalogOrder($this->product(), 2);
        $this->cancel($order, 'pending');

        $this->assertSame(1000.0, $this->qty('Wood (Narra)'));
        $this->assertSame(0, InventoryMovement::where('type', 'restored')->count());
    }

    public function test_the_customer_cancel_and_the_system_cancel_paths_restore_what_was_taken(): void
    {
        // customer cancel only works on a pending, unpaid order: it never has rows, so nothing is added or lost
        $customer = $this->makeUser();
        $pending = $this->makeOrder($customer, ['order_status' => 'pending']);
        $this->makePayment($pending, ['amount' => 1000, 'payment_method' => 'onsite']);
        app(OrderTracking::class)->customerCancel($pending->id, $customer, 'Changed My Mind', null);
        $this->assertSame(1000.0, $this->qty('Wood (Narra)'));

        // the sweep / system path restores rows if there are any
        $order = $this->catalogOrder($this->product(), 1);
        $this->confirm($order);
        $this->assertEqualsWithDelta(900, $this->qty('Wood (Narra)'), 0.001);
        app(OrderTracking::class)->systemCancel($order->fresh(), 'Payment was not completed in time.');
        $this->assertEqualsWithDelta(1000, $this->qty('Wood (Narra)'), 0.001);
    }

    public function test_the_abandoned_order_command_restores_materials_too(): void
    {
        $order = $this->catalogOrder($this->product(), 1);
        $this->confirm($order);
        $this->assertEqualsWithDelta(900, $this->qty('Wood (Narra)'), 0.001);

        // an order that was (wrongly) pending again with an unpaid stripe payment and still holding rows
        $order = $order->fresh();
        $order->forceFill(['order_status' => 'pending', 'created_at' => now()->subHours(3)])->save();
        $order->payment->forceFill(['payment_method' => 'stripe', 'payment_status' => 'pending'])->save();

        $this->artisan('orders:release-abandoned', ['--minutes' => 60])->assertExitCode(0);

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertEqualsWithDelta(1000, $this->qty('Wood (Narra)'), 0.001);
    }

    public function test_cancelling_a_confirmed_custom_order_restores_its_materials(): void
    {
        $order = $this->customOrder();
        $this->confirmCustom($order);
        $this->assertEqualsWithDelta(947, $this->qty('Wood (Narra)'), 0.001);

        app(CustomOrderStatus::class)->change($order->id, $this->admin(), 'cancelled', 'Customer withdrew');

        $this->assertEqualsWithDelta(1000, $this->qty('Wood (Narra)'), 0.001);
        $this->assertSame(0, MaterialUsage::where('order_id', $order->id)->whereNull('reversed_at')->count());
    }

    /* ───────────── C) never block ordering; Short display ───────────── */

    public function test_stock_may_go_negative_and_is_shown_as_short(): void
    {
        $this->stock(['Wood (Lawaan)' => [10, 'sqft'], 'Wood (Mahogany)' => [0, 'sqft']]);
        $p = $this->product('King Bed Frame', 'Lawaan');
        $this->confirm($this->catalogOrder($p, 1));     // needs 100 sqft, has 10

        $lawaan = Inventory::where('material_name', 'Wood (Lawaan)')->first();
        $this->assertEqualsWithDelta(-90, (float) $lawaan->material_quantity, 0.001);
        $this->assertSame('out_of_stock', $lawaan->inventory_status, 'the enum stays: below zero is out of stock');
        $this->assertSame('Short', MaterialStock::chipLabel($lawaan));
        $this->assertSame('Short by 90 sqft', MaterialStock::remainingText($lawaan));

        $mahogany = Inventory::where('material_name', 'Wood (Mahogany)')->first();
        $this->assertSame('Out Of Stock', MaterialStock::chipLabel($mahogany));
        $this->assertSame('0 sqft', MaterialStock::remainingText($mahogany));

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.inventory.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Short by 90 sqft', $html);
        $this->assertStringContainsString('mt-chip short', $html);
        $this->assertStringContainsString('mt-chip out', $html);
    }

    public function test_status_is_computed_in_one_place(): void
    {
        $this->assertSame('out_of_stock', MaterialStock::statusFor(-5, 10));
        $this->assertSame('out_of_stock', MaterialStock::statusFor(0, 10));
        $this->assertSame('low_stock', MaterialStock::statusFor(10, 10));
        $this->assertSame('in_stock', MaterialStock::statusFor(10.01, 10));
    }

    /* ───────────── restock, correct, create, remove ───────────── */

    public function test_restock_adds_a_quantity_and_writes_a_movement(): void
    {
        $row = Inventory::where('material_name', 'Wood Glue')->first();
        $this->actingAs($this->admin(), 'admin')->post(route('admin.inventory.restock', $row->id), ['_modal' => 'material-restock-' . $row->id, 'add_quantity' => '12.5', 'note' => 'Delivered Oct 31'])
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertEqualsWithDelta(62.5, $this->qty('Wood Glue'), 0.001);
        $m = InventoryMovement::where('inventory_id', $row->id)->where('type', 'restocked')->sole();
        $this->assertEqualsWithDelta(12.5, $m->quantity_change, 0.001);
        $this->assertEqualsWithDelta(62.5, $m->quantity_after, 0.001);
        $this->assertSame('Delivered Oct 31', $m->note);
    }

    public function test_a_rejected_restock_reopens_its_modal(): void
    {
        $row = Inventory::where('material_name', 'Wood Glue')->first();
        $this->actingAs($this->admin(), 'admin')->from(route('admin.inventory.index'))
            ->post(route('admin.inventory.restock', $row->id), ['_modal' => 'material-restock-' . $row->id, 'add_quantity' => '0'])
            ->assertSessionHasErrors('add_quantity');

        $html = $this->get(route('admin.inventory.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="material-restock-' . $row->id . '"[^>]*data-open-on-load/', $html);
        $this->assertSame(50.0, $this->qty('Wood Glue'));
    }

    public function test_correcting_the_count_is_a_correction_with_the_difference_not_a_restock(): void
    {
        $row = Inventory::where('material_name', 'Sandpaper')->first();
        $this->actingAs($this->admin(), 'admin')->put(route('admin.inventory.update', $row->id), [
            '_modal' => 'material-edit-' . $row->id, 'material_name' => 'Sandpaper', 'unit' => 'pieces', 'low_stock_threshold' => 600, 'material_quantity' => 480,
        ])->assertRedirect(route('admin.inventory.index'));

        $row->refresh();
        $this->assertEqualsWithDelta(480, (float) $row->material_quantity, 0.001);
        $this->assertSame('low_stock', $row->inventory_status, 'the new Low Stock At applies at once');
        $m = InventoryMovement::where('inventory_id', $row->id)->where('type', 'corrected')->sole();
        $this->assertEqualsWithDelta(-20, $m->quantity_change, 0.001);
        $this->assertSame(0, InventoryMovement::where('inventory_id', $row->id)->where('type', 'restocked')->count());

        // saving the form again without a different count writes nothing more
        $this->put(route('admin.inventory.update', $row->id), ['material_name' => 'Sandpaper', 'unit' => 'pieces', 'low_stock_threshold' => 600, 'material_quantity' => 480]);
        $this->assertSame(1, InventoryMovement::where('inventory_id', $row->id)->where('type', 'corrected')->count());
    }

    public function test_a_short_material_can_be_edited_without_touching_its_negative_count(): void
    {
        $row = Inventory::where('material_name', 'Sandpaper')->first();
        MaterialStock::change($row->id, -520, 'consumed');     // -20
        $this->actingAs($this->admin(), 'admin')->put(route('admin.inventory.update', $row->id), [
            'material_name' => 'Sandpaper (Fine)', 'unit' => 'pieces', 'low_stock_threshold' => 50, 'material_quantity' => -20,
        ])->assertSessionHasNoErrors();
        $this->assertSame('Sandpaper (Fine)', $row->fresh()->material_name);

        $this->put(route('admin.inventory.update', $row->id), ['material_name' => 'Sandpaper (Fine)', 'unit' => 'pieces', 'low_stock_threshold' => 50, 'material_quantity' => -30])
            ->assertSessionHasErrors('material_quantity');
    }

    public function test_adding_a_material_writes_a_created_movement_and_removing_keeps_the_history(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.inventory.store'), [
            '_modal' => 'material-add', 'material_name' => 'Wood (Lawaan)', 'unit' => 'sqft', 'low_stock_threshold' => 20, 'material_quantity' => 300,
        ])->assertRedirect(route('admin.inventory.index'));

        $row = Inventory::where('material_name', 'Wood (Lawaan)')->firstOrFail();
        $this->assertSame('in_stock', $row->inventory_status);
        $created = InventoryMovement::where('inventory_id', $row->id)->where('type', 'created')->sole();
        $this->assertEqualsWithDelta(300, $created->quantity_change, 0.001);

        // used by an order, then removed: the usage rows go, the movements keep the name
        $order = $this->catalogOrder($this->product('Lawaan Chair', 'Lawaan', 'Chair'), 1);
        $this->confirm($order);
        $this->delete(route('admin.inventory.destroy', $row->id))->assertRedirect();

        $this->assertNull(Inventory::find($row->id));
        $this->assertGreaterThan(0, InventoryMovement::where('material_name', 'Wood (Lawaan)')->whereNull('inventory_id')->count());
    }

    public function test_the_old_adjust_route_and_the_inline_quantity_boxes_are_gone(): void
    {
        $row = Inventory::first();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.inventory.adjust'));
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.inventory.index'))->getContent();
        $this->assertStringNotContainsString('admin-quick-stock-input', $html);
        foreach (['Restock', 'Edit', 'Remove', 'Lasts About', 'Material Estimates', 'After restocking:', 'Add To Stock', 'Keep It', 'Remove Material'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertNotNull($row);
    }

    public function test_lasts_about_uses_the_last_30_days_of_use(): void
    {
        $row = Inventory::where('material_name', 'Wood Glue')->first();     // 50 L
        $this->assertSame('No recent use', MaterialStock::lastsAbout($row, 0));
        $this->assertSame('About 50 days', MaterialStock::lastsAbout($row, 30));      // 1 L a day
        $this->assertSame('About 1 day', MaterialStock::lastsAbout($row, 1000));      // 33 L a day -> 1.5 days
        $this->assertSame('Less than a day', MaterialStock::lastsAbout($row, 3000));
        $row->forceFill(['material_quantity' => 0])->save();
        $this->assertSame('Out', MaterialStock::lastsAbout($row->fresh(), 30));
    }

    /* ───────────── F) library vs product vs reset ───────────── */

    public function test_editing_the_library_changes_new_products_but_not_products_that_already_have_an_estimate(): void
    {
        $existing = $this->product('King Bed Frame');
        $king = MaterialEstimate::where('name', 'Bed, King')->firstOrFail();

        $this->actingAs($this->admin(), 'admin')->put(route('admin.material-estimates.update', $king->id), [
            '_modal' => 'estimate-edit-' . $king->id, 'name' => 'Bed, King', 'wood' => 120, 'varnish' => 2.4, 'lacquer' => 1.6, 'nails' => 600, 'sand' => 16, 'glue' => 0.5,
        ])->assertRedirect(route('admin.material-estimates.index'));

        $this->assertTrue($king->fresh()->is_edited);
        $this->assertEquals(100, $existing->fresh()->materialEstimate->wood, 'the product keeps its saved estimate');
        $this->assertEquals(100, $king->fresh()->starter_wood, 'the starter is remembered');

        $new = Product::create(['product_name' => 'Royal King Bed', 'category' => 'Bed', 'material' => 'Narra', 'finish' => 'Natural', 'price' => 1, 'stock_quantity' => 1, 'product_status' => 'available']);
        $this->assertEquals(120, MaterialEstimator::createProductEstimate($new)->wood, 'a new product starts from the edited library row');

        // reset to starter
        $this->post(route('admin.material-estimates.reset', $king->id))->assertRedirect(route('admin.material-estimates.index'));
        $this->assertEquals(100, $king->fresh()->wood);
        $this->assertFalse($king->fresh()->is_edited);
    }

    public function test_a_type_added_by_hand_has_no_starter_to_reset_to(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post(route('admin.material-estimates.store'), [
            '_modal' => 'estimate-add', 'name' => 'Pergola Post', 'wood' => 10, 'varnish' => 1, 'lacquer' => 1, 'nails' => 20, 'sand' => 2, 'glue' => 0.1,
        ])->assertRedirect(route('admin.material-estimates.index'));

        $type = MaterialEstimate::where('name', 'Pergola Post')->firstOrFail();
        $this->assertFalse($type->isSeeded());
        $this->post(route('admin.material-estimates.reset', $type->id))->assertSessionHasErrors('reset');

        $this->post(route('admin.material-estimates.store'), ['name' => 'pergola post', 'wood' => 1, 'varnish' => 1, 'lacquer' => 1, 'nails' => 1, 'sand' => 1, 'glue' => 1])
            ->assertSessionHasErrors('name');
    }

    public function test_a_rejected_furniture_type_reopens_its_modal_with_the_typed_values(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.material-estimates.index'))
            ->post(route('admin.material-estimates.store'), ['_modal' => 'estimate-add', 'name' => 'Pergola Post', 'wood' => '', 'varnish' => 1, 'lacquer' => 1, 'nails' => 20, 'sand' => 2, 'glue' => 0.1])
            ->assertSessionHasErrors('wood');

        $html = $this->get(route('admin.material-estimates.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="estimate-add"[^>]*data-open-on-load[^>]*data-force-dirty/', $html);
        $this->assertStringContainsString('value="Pergola Post"', $html);
        $this->assertSame(0, MaterialEstimate::where('name', 'Pergola Post')->count());
    }

    public function test_the_estimates_page_lists_every_type_with_its_product_count(): void
    {
        $this->product('King Bed Frame');
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.material-estimates.index'))->assertOk()->getContent();

        $this->assertSame(37, MaterialEstimate::count());
        $this->assertStringContainsString('1 product', $html);
        $this->assertStringContainsString('None yet', $html);
        $this->assertStringContainsString('Add Furniture Type', $html);
        $this->assertStringContainsString('Reset To Starter', $html);
        $this->assertStringContainsString('Back To Inventory', $html);
        $this->assertSame(38, preg_match_all('/id="estimate-(add|edit-\d+)"/', $html));
    }

    public function test_the_old_estimate_urls_redirect_and_open_the_modal(): void
    {
        $t = MaterialEstimate::first();
        $this->actingAs($this->admin(), 'admin');
        $this->get(route('admin.material-estimates.create'))->assertRedirect(route('admin.material-estimates.index', ['add' => 1]));
        $this->get(route('admin.material-estimates.edit', $t->id))->assertRedirect(route('admin.material-estimates.index', ['edit' => $t->id]));
        $this->assertMatchesRegularExpression('/id="estimate-edit-' . $t->id . '"[^>]*data-open-on-load/', $this->get(route('admin.material-estimates.index', ['edit' => $t->id]))->getContent());
        $this->get(route('admin.products.create'))->assertRedirect(route('admin.products.index', ['add' => 1]));
        $p = $this->product();
        $this->get(route('admin.products.edit', $p->id))->assertRedirect(route('admin.products.index', ['edit' => $p->id]));
        $this->assertMatchesRegularExpression('/id="product-edit-' . $p->id . '"[^>]*data-open-on-load/', $this->get(route('admin.products.index', ['edit' => $p->id]))->getContent());
        $this->assertMatchesRegularExpression('/id="product-add"[^>]*data-open-on-load/', $this->get(route('admin.products.index', ['add' => 1]))->getContent());
    }

    /* ───────────── G) the starter matcher ───────────── */

    /** @dataProvider matcherCases */
    public function test_the_matcher_picks_the_starter_from_the_name_first_then_the_category(string $name, ?string $category, string $expected): void
    {
        $this->assertSame($expected, MaterialEstimator::matchType($name, $category));
    }

    public static function matcherCases(): array
    {
        return [
            'king' => ['King Bed Frame', 'Bed', 'Bed, King'],
            'queen' => ['Queen Platform Bed', 'Bed', 'Bed, Queen'],
            'bunk' => ['Wooden Bunk Bed', 'Bed', 'Bunk Bed'],
            'crib' => ['Baby CRIB', null, 'Crib / Baby Bed'],
            'headboard' => ['Carved Headboard', null, 'Headboard'],
            'bedside' => ['Bedside Cabinet', 'Cabinet', 'Bedside Table'],
            'wardrobe' => ['4-Door Wardrobe Cabinet', 'Cabinet', 'Wardrobe (4-Door)'],
            'dresser' => ['Dresser', null, 'Dresser / Drawer Chest'],
            'vanity' => ['Vanity Set', null, 'Vanity Table'],
            'tv with space' => ['TV Console Cabinet', 'Cabinet', 'Cabinet / TV Stand'],
            'console' => ['Entry Console', null, 'Console Table'],
            'china' => ['China Cabinet', 'Cabinet', 'Display / China Cabinet'],
            'kitchen' => ['Kitchen Cabinet Set', null, 'Kitchen Cabinet (Set)'],
            'shoe' => ['Shoe Cabinet', null, 'Shoe Rack'],
            'bookcase' => ['Tall Bookcase', null, 'Shelf / Bookcase'],
            'dining set beats table' => ['Dining Set 6 Seater', 'Table', 'Dining Set (Table + 6 Chairs)'],
            'coffee' => ['Coffee Table', 'Table', 'Coffee Table'],
            'study beats table' => ['Study Table with Shelves', 'Table', 'Desk / Study Table'],
            'desk' => ['Office Desk', null, 'Desk / Study Table'],
            'reception' => ['Reception Counter', null, 'Reception / Counter Desk'],
            'bar stool beats stool' => ['Bar Stool', 'Chair', 'Bar Stool'],
            'bar counter' => ['Home Bar Counter', null, 'Bar Counter'],
            'rocking' => ['Rocking Chair', 'Chair', 'Rocking Chair'],
            'office chair' => ['Executive Office Chair', 'Chair', 'Office Chair (Wood Frame)'],
            'stool' => ['Wooden Stool', null, 'Stool'],
            'bench' => ['Garden Bench', null, 'Bench'],
            'ottoman' => ['Ottoman', null, 'Ottoman / Footstool'],
            'sala' => ['Sala Set 5-piece', 'Sofa', 'Sala Set (5-Piece)'],
            'loveseat' => ['Loveseat', 'Sofa', 'Sofa, 2-Seater'],
            'altar' => ['Altar Table', 'Table', 'Altar'],
            'window' => ['Window Frame', null, 'Window Frame'],
            'door' => ['Panel Door', null, 'Door'],
            'cat Bed' => ['Plain Frame', 'Bed', 'Bed, Queen'],
            'cat Cabinet' => ['Plain Thing', 'Cabinet', 'Cabinet / TV Stand'],
            'cat Chair' => ['Plain Thing', 'Chair', 'Chair'],
            'cat Sofa' => ['3-Seater Sofa Frame', 'Sofa', 'Sofa, 3-Seater (Frame)'],
            'cat Table' => ['Narra Dining Table', 'Table', 'Dining Table'],
            'cat Altar' => ['Plain Thing', 'Altar', 'Altar'],
            'cat Door' => ['Plain Thing', 'Door', 'Door'],
            'cat Shelf' => ['Plain Thing', 'Shelf', 'Shelf / Bookcase'],
            'cat Desk' => ['Plain Thing', 'Desk', 'Desk / Study Table'],
            'unknown category' => ['Plain Thing', 'Gazebo', 'Other / Unknown'],
            'no category' => ['Plain Thing', null, 'Other / Unknown'],
        ];
    }

    public function test_the_nine_existing_products_get_their_starter_estimate(): void
    {
        $names = [
            'King Bed Frame' => 'Bed, King', '4-Door Wardrobe Cabinet' => 'Wardrobe (4-Door)', 'TV Console Cabinet' => 'Cabinet / TV Stand',
            'Executive Office Chair' => 'Office Chair (Wood Frame)', 'Rocking Chair' => 'Rocking Chair', '3-Seater Sofa Frame' => 'Sofa, 3-Seater (Frame)',
            'Sala Set 5-piece' => 'Sala Set (5-Piece)', 'Narra Dining Table' => 'Dining Table', 'Study Table with Shelves' => 'Desk / Study Table',
        ];
        foreach (array_keys($names) as $n) {
            Product::create(['product_name' => $n, 'category' => 'Misc', 'material' => 'Narra', 'finish' => 'x', 'price' => 1, 'stock_quantity' => 1, 'product_status' => 'available']);
        }

        $this->artisan('materials:seed-estimates')->assertExitCode(0);
        $this->artisan('materials:seed-estimates')->assertExitCode(0);      // safe to run again

        $this->assertSame(9, ProductMaterialEstimate::count());
        foreach ($names as $n => $type) {
            $this->assertSame($type, Product::where('product_name', $n)->first()->materialEstimate->type_name, $n);
        }
        $this->assertSame(37, MaterialEstimate::count());
    }

    public function test_the_library_numbers_are_the_approved_starter_values(): void
    {
        $bedKing = MaterialEstimate::where('name', 'Bed, King')->first();
        $this->assertEquals([100, 2.4, 1.6, 600, 16, 0.5], array_values($bedKing->values()));
        $this->assertEquals([41, 1, 0.8, 200, 10, 0.2], array_values(MaterialEstimate::where('name', 'Other / Unknown')->first()->values()));
        $this->assertEquals([77, 2, 1.3, 540, 14, 0.4], array_values(MaterialEstimate::where('name', 'Bed, Queen')->first()->values()), 'a name with a comma is one name');
        $this->assertEquals([160, 203, 110], [MaterialEstimate::where('name', 'Bed, Queen')->value('ref_length_cm'), MaterialEstimate::where('name', 'Bed, Queen')->value('ref_width_cm'), MaterialEstimate::where('name', 'Bed, Queen')->value('ref_height_cm')]);
    }

    /* ───────────── product Add / Edit with the Materials Needed block ───────────── */

    private function productPayload(array $over = []): array
    {
        return $over + [
            '_modal' => 'product-add', 'product_name' => 'Queen Bed Frame', 'category' => 'Bed', 'material' => 'Narra', 'finish' => 'Natural',
            'price' => '12000', 'stock_quantity' => '3', 'product_status' => 'available',
        ];
    }

    public function test_a_new_product_always_gets_an_estimate_even_if_the_section_was_never_touched(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.products.store'), $this->productPayload())->assertSessionHasNoErrors();

        $est = Product::where('product_name', 'Queen Bed Frame')->first()->materialEstimate;
        $this->assertSame('Bed, Queen', $est->type_name);
        $this->assertEquals(77, $est->wood);
        $this->assertFalse($est->is_edited);
    }

    public function test_the_numbers_posted_with_a_product_are_saved_and_marked_edited(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.products.store'), $this->productPayload([
            'est_type' => 'Bed, Queen', 'est_wood' => 90, 'est_varnish' => 2, 'est_lacquer' => 1.3, 'est_nails' => 540, 'est_sand' => 14, 'est_glue' => 0.4,
        ]))->assertSessionHasNoErrors();

        $est = Product::where('product_name', 'Queen Bed Frame')->first()->materialEstimate;
        $this->assertEquals(90, $est->wood);
        $this->assertTrue($est->is_edited);

        // editing the product keeps those numbers; resetting puts the starter back
        $p = $est->product;
        $this->put(route('admin.products.update', $p->id), $this->productPayload(['_modal' => 'product-edit-' . $p->id, 'est_type' => 'Bed, Queen', 'est_wood' => 90, 'est_varnish' => 2, 'est_lacquer' => 1.3, 'est_nails' => 540, 'est_sand' => 14, 'est_glue' => 0.4, 'price' => '13000']))
            ->assertSessionHasNoErrors();
        $this->assertEquals(90, $p->fresh()->materialEstimate->wood);
        $this->assertEquals(13000, $p->fresh()->price);

        $this->put(route('admin.products.update', $p->id), $this->productPayload(['_modal' => 'product-edit-' . $p->id, 'est_type' => 'Bed, Queen', 'est_wood' => 77, 'est_varnish' => 2, 'est_lacquer' => 1.3, 'est_nails' => 540, 'est_sand' => 14, 'est_glue' => 0.4]));
        $this->assertEquals(77, $p->fresh()->materialEstimate->wood);
        $this->assertFalse($p->fresh()->materialEstimate->is_edited);
    }

    public function test_editing_a_product_without_the_estimate_fields_leaves_its_saved_numbers_alone(): void
    {
        $p = $this->product('King Bed Frame');
        $p->materialEstimate->forceFill(['wood' => 111, 'is_edited' => true])->save();

        $payload = $this->productPayload(['_modal' => 'product-edit-' . $p->id, 'product_name' => 'King Bed Frame']);
        $this->actingAs($this->admin(), 'admin')->put(route('admin.products.update', $p->id), $payload)->assertSessionHasNoErrors();

        $this->assertEquals(111, $p->fresh()->materialEstimate->wood);
    }

    public function test_a_product_with_no_saved_estimate_gets_one_the_first_time_it_is_confirmed(): void
    {
        $p = Product::create(['product_name' => 'Panel Door', 'category' => 'Door', 'material' => 'Narra', 'finish' => 'x', 'price' => 1, 'stock_quantity' => 1, 'product_status' => 'available']);
        $this->assertNull($p->materialEstimate);

        $this->confirm($this->catalogOrder($p, 1));

        $this->assertSame('Door', $p->fresh()->materialEstimate->type_name);
        $this->assertEqualsWithDelta(979, $this->qty('Wood (Narra)'), 0.001);    // Door: 21 sqft
    }

    public function test_a_rejected_product_reopens_its_modal_with_the_typed_values(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.products.index'))
            ->post(route('admin.products.store'), $this->productPayload(['product_name' => 'Typed Name', 'price' => '']))
            ->assertSessionHasErrors('price');

        $html = $this->get(route('admin.products.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="product-add"[^>]*data-open-on-load[^>]*data-force-dirty/', $html);
        $this->assertStringContainsString('value="Typed Name"', $html);
        $this->assertSame(0, Product::where('product_name', 'Typed Name')->count());
    }

    public function test_the_product_modal_carries_the_materials_section_and_the_same_matcher_as_the_server(): void
    {
        $p = $this->product();
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.products.index'))->assertOk()->getContent();

        foreach (['Materials Needed', 'Reset To Starter', 'What one piece uses from your inventory', 'Save Product', 'Materials Estimate', 'Add Product'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('name="est_wood"', $html);
        $this->assertStringContainsString('Starter: Bed, King', $html);
        $this->assertSame(1, preg_match('/<script type="application\/json" id="mt-matcher">(.*?)<\/script>/s', $html, $m));
        $data = json_decode(html_entity_decode($m[1]), true) ?? json_decode($m[1], true);
        $this->assertSame(MaterialEstimator::KEYWORDS, $data['keywords']);
        $this->assertSame(MaterialEstimator::CATEGORY_FALLBACK, $data['categories']);
        $this->assertSame(37, count($data['types']));
        $this->assertStringContainsString('id="mt-woods"', $html);
        $this->assertNotNull($p);
    }

    /* ───────────── H) Reports > Materials ───────────── */

    public function test_reports_materials_numbers(): void
    {
        $a = $this->catalogOrder($this->product(), 2);
        $this->confirm($a);                                  // wood 200 used
        $b = $this->catalogOrder($this->product('Narra Bench', 'Narra', 'Chair'), 1);
        $this->confirm($b);                                  // bench: 24 sqft
        $this->cancel($b, 'confirmed');                      // put back
        $this->actingAs($this->admin(), 'admin')->post(route('admin.inventory.restock', Inventory::where('material_name', 'Wood (Narra)')->value('id')), ['add_quantity' => 50]);

        $m = AdminReports::materials(30);
        $kpi = collect($m['kpis'])->pluck('value', 'label');
        $this->assertSame('2', $kpi['Orders Counted']);
        $this->assertSame('200 sqft', $kpi['Wood Used'], 'used = consumed minus restored');
        $this->assertSame('50 sqft', $kpi['Wood Restocked']);
        $this->assertSame('1', $kpi['Restock Entries']);
        $this->assertSame('0', $kpi['Materials Short']);

        $row = collect($m['table'])->firstWhere('name', 'Wood (Narra)');
        $this->assertEqualsWithDelta(200, $row['used'], 0.001);
        $this->assertEqualsWithDelta(50, $row['restocked'], 0.001);
        $this->assertEqualsWithDelta(-150, $row['net'], 0.001);
        $this->assertSame('850 sqft', $row['remaining']);

        $reasons = collect($m['recent'])->pluck('reason')->all();
        $this->assertContains('Restocked', $reasons);
        $this->assertTrue(collect($reasons)->contains(fn ($r) => str_contains($r, 'cancelled, put back')));
        $this->assertTrue(collect($reasons)->contains(fn ($r) => preg_match('/^Order \S+ confirmed$/', $r)));
        $this->assertLessThanOrEqual(8, count($m['recent']));
    }

    public function test_reports_most_needed_ranks_short_first_with_a_buy_hint(): void
    {
        $this->stock(['Wood (Lawaan)' => [10, 'sqft']]);
        $this->confirm($this->catalogOrder($this->product('King Bed Frame', 'Lawaan'), 1));     // Lawaan -90
        $this->confirm($this->catalogOrder($this->product('Narra Bench', 'Narra', 'Chair'), 1));

        $needs = AdminReports::materials(30)['needs'];
        $this->assertCount(5, $needs);
        $this->assertSame('Wood (Lawaan)', $needs[0]['name']);
        $this->assertSame('short', $needs[0]['tone']);
        $this->assertSame('Short by 90 sqft', $needs[0]['left']);
        $this->assertSame('Out', $needs[0]['pace']);
        $this->assertSame(100, $needs[0]['buy'], 'use 100 in the last 30 days, nothing left: 100, rounded up to 10');
        $this->assertSame(2, $needs[0]['pct']);

        foreach ($needs as $n) {
            if ($n['unit'] === 'liters') {
                $this->assertSame((int) $n['buy'], $n['buy'], 'liters are rounded up to 1');
            }
        }
    }

    public function test_reports_things_to_know_counts_unmapped_and_customer_supplied_wood(): void
    {
        $this->confirm($this->catalogOrder($this->product('Pine Bench', 'Pine', 'Chair'), 1));
        $this->confirmCustom($this->customOrder(['preferred_material' => 'Special request', 'wood_special_details' => 'Narra', 'wood_supply' => 'customer']));

        $m = AdminReports::materials(30);
        $this->assertSame(1, $m['unmappedOrders']);
        $this->assertSame(['Pine'], $m['unmappedNames']);
        $this->assertSame(1, $m['customerWoodOrders']);
    }

    public function test_reports_materials_periods_and_the_page(): void
    {
        $this->confirm($this->catalogOrder($this->product(), 1));
        InventoryMovement::query()->update(['created_at' => now()->subDays(60)]);      // everything happened two months ago
        Order::query()->update(['materials_deducted_at' => now()->subDays(60)]);

        $this->assertSame('0', collect(AdminReports::materials(30)['kpis'])->firstWhere('label', 'Orders Counted')['value']);
        $this->assertSame('1', collect(AdminReports::materials(90)['kpis'])->firstWhere('label', 'Orders Counted')['value']);
        $this->assertFalse(AdminReports::materials(30)['anyActivity']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.reports', ['tab' => 'materials']))->assertOk()->getContent();
        foreach (['Materials</button>', 'Most Needed Right Now', 'Wood Used And Restocked By Week', 'Recent Movements', 'Things To Know', 'Used And Restocked By Material', 'Last 30 Days', 'Last 3 Months', 'No materials were used in this period'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringNotContainsString('₱', substr($html, strpos($html, 'id="rp-materials"')), 'no prices in the Materials tab');

        $html90 = $this->get(route('admin.reports', ['tab' => 'materials', 'mperiod' => 90]))->getContent();
        $this->assertStringNotContainsString('No materials were used in this period', substr($html90, strpos($html90, 'Used And Restocked By Material')));
    }

    /* ───────────── permissions ───────────── */

    public function test_only_an_admin_can_reach_the_materials_pages(): void
    {
        $row = Inventory::first();
        $type = MaterialEstimate::first();
        $customer = $this->makeUser();

        $calls = [
            ['get', route('admin.inventory.index')], ['post', route('admin.inventory.restock', $row->id)], ['put', route('admin.inventory.update', $row->id)],
            ['delete', route('admin.inventory.destroy', $row->id)], ['get', route('admin.material-estimates.index')],
            ['put', route('admin.material-estimates.update', $type->id)], ['post', route('admin.material-estimates.reset', $type->id)],
            ['post', route('admin.material-estimates.store')], ['get', route('admin.reports', ['tab' => 'materials'])],
        ];
        foreach ($calls as [$method, $url]) {
            $this->get('/');         // reset nothing; guests first
            $this->{$method}($url)->assertRedirect();
            $this->actingAs($customer)->{$method}($url)->assertRedirect();
            auth()->logout();
        }
        $this->assertSame(50.0, $this->qty('Wood Glue'));
        $this->assertSame(0, InventoryMovement::where('type', 'restocked')->count());
    }
}
