<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\ServiceInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Materials Inventory (Add / Edit Material) and Other Services (New Ticket) as modals on their list pages, built like the Chatbot
 * Training modals: shared x-modal, a rejected submit reopens the same modal with the typed values, the old URLs redirect and open it.
 */
class AdminInventoryTicketModalTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin()
    {
        return $this->makeUser('admin');
    }

    private function material(array $over = []): Inventory
    {
        return Inventory::create($over + [
            'material_name' => 'Narra Planks', 'material_quantity' => 340, 'unit' => 'pcs',
            'low_stock_threshold' => 20, 'inventory_status' => 'in_stock',
        ]);
    }

    /* ───────────── Materials Inventory ───────────── */

    public function test_the_inventory_list_has_an_add_modal_and_one_edit_modal_per_material(): void
    {
        $this->material();
        $this->material(['material_name' => 'Wood Varnish']);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.inventory.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lp-modal-open="material-add"', $html);
        $this->assertStringContainsString('id="material-add"', $html);
        $this->assertSame(2, preg_match_all('/id="material-edit-\d+"/', $html));
        $this->assertStringContainsString('lp-modal lp-modal--mid', $html);
        foreach (['Add Material', 'Edit Material', 'Material Name', 'Starting Quantity', 'Correct The Count', 'Unit', 'Low Stock At', 'Save Material'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('assets/css/admin-modals.css', $html);
        $this->assertStringContainsString('assets/js/admin-modal-forms.js', $html);
        $this->assertStringNotContainsString(route('admin.inventory.create'), $html);   // no link to a full page any more
        $this->assertStringNotContainsString('style="width:70px;"', $html);             // the clipped quantity box
        $this->assertStringContainsString('value="340.00"', $html);
    }

    public function test_the_quantity_box_in_the_list_is_wide_enough_for_its_number(): void
    {
        $css = file_get_contents(public_path('assets/css/admin.css'));
        $this->assertMatchesRegularExpression('/\.admin-quick-stock-input\s*\{[^}]*width:\s*96px;[^}]*min-width:\s*96px;/', $css);
    }

    public function test_the_old_inventory_urls_redirect_to_the_list_and_open_the_modal(): void
    {
        $m = $this->material();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.inventory.create'))->assertRedirect(route('admin.inventory.index', ['add' => 1]));
        $this->get(route('admin.inventory.edit', $m->id))->assertRedirect(route('admin.inventory.index', ['edit' => $m->id]));
        $this->get(route('admin.inventory.edit', 99999))->assertNotFound();

        $this->assertMatchesRegularExpression('/id="material-add"[^>]*data-open-on-load/', $this->get(route('admin.inventory.index', ['add' => 1]))->getContent());
        $edit = $this->get(route('admin.inventory.index', ['edit' => $m->id]))->getContent();
        $this->assertMatchesRegularExpression('/id="material-edit-' . $m->id . '"[^>]*data-open-on-load/', $edit);
        $this->assertDoesNotMatchRegularExpression('/id="material-add"[^>]*data-open-on-load/', $edit);
    }

    public function test_adding_and_editing_a_material_still_saves_and_sets_the_status(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->post(route('admin.inventory.store'), ['_modal' => 'material-add', 'material_name' => 'Nails', 'material_quantity' => 5, 'unit' => 'kg', 'low_stock_threshold' => 10])
            ->assertRedirect(route('admin.inventory.index'));
        $nails = Inventory::where('material_name', 'Nails')->firstOrFail();
        $this->assertSame('low_stock', $nails->inventory_status);

        $this->put(route('admin.inventory.update', $nails->id), ['_modal' => 'material-edit-' . $nails->id, 'material_name' => 'Nails', 'material_quantity' => 50, 'unit' => 'kg', 'low_stock_threshold' => 10])
            ->assertRedirect(route('admin.inventory.index'));
        $this->assertSame('in_stock', $nails->fresh()->inventory_status);
    }

    public function test_a_rejected_add_reopens_the_add_modal_with_the_typed_values_and_messages(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->from(route('admin.inventory.index'))
            ->post(route('admin.inventory.store'), ['_modal' => 'material-add', 'material_name' => 'Glue', 'material_quantity' => '', 'unit' => 'bottles', 'low_stock_threshold' => 5])
            ->assertRedirect(route('admin.inventory.index'))
            ->assertSessionHasErrors('material_quantity');

        $html = $this->get(route('admin.inventory.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="material-add"[^>]*data-open-on-load[^>]*data-force-dirty/', $html);
        $this->assertStringContainsString('value="Glue"', $html);
        $this->assertStringContainsString('value="bottles"', $html);
        $this->assertMatchesRegularExpression('/am-field[^"]*is-invalid/', $html);
        $this->assertSame(0, Inventory::count());
    }

    public function test_a_rejected_edit_reopens_only_that_materials_modal(): void
    {
        $a = $this->material();
        $b = $this->material(['material_name' => 'Wood Varnish']);
        $this->actingAs($this->admin(), 'admin');

        $this->from(route('admin.inventory.index'))
            ->put(route('admin.inventory.update', $b->id), ['_modal' => 'material-edit-' . $b->id, 'material_name' => '', 'material_quantity' => 3, 'unit' => 'l', 'low_stock_threshold' => 1])
            ->assertSessionHasErrors('material_name');

        $html = $this->get(route('admin.inventory.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="material-edit-' . $b->id . '"[^>]*data-open-on-load/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="material-edit-' . $a->id . '"[^>]*data-open-on-load/', $html);
        $this->assertSame('Wood Varnish', $b->fresh()->material_name);
    }

    public function test_the_old_inventory_form_view_is_gone(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/admin/inventory/form.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/service-inquiries/create.blade.php'));
    }

    /* ───────────── Other Services: New Ticket ───────────── */

    public function test_the_other_services_list_has_a_new_ticket_modal(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.service-inquiries.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lp-modal-open="ticket-new"', $html);
        $this->assertStringContainsString('id="ticket-new"', $html);
        $this->assertStringContainsString('lp-modal lp-modal--wide', $html);
        foreach (['New Service Ticket', 'Link an Existing Customer Account (Optional)', 'Furniture Type (Optional)', 'Concern', 'Mobile Number', 'Preferred Call Time', 'Preferred Date (Optional)', 'Create Ticket'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('assets/css/admin-modals.css', $html);
        $this->assertStringNotContainsString(route('admin.service-inquiries.create'), $html);
        $this->assertDoesNotMatchRegularExpression('/id="ticket-new"[^>]*data-open-on-load/', $html);
    }

    public function test_the_old_new_ticket_url_redirects_and_opens_the_modal(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.service-inquiries.create'))->assertRedirect(route('admin.service-inquiries.index', ['new' => 1]));

        $this->assertMatchesRegularExpression('/id="ticket-new"[^>]*data-open-on-load/', $this->get(route('admin.service-inquiries.index', ['new' => 1]))->getContent());
    }

    public function test_a_rejected_new_ticket_reopens_the_modal_with_values_and_messages(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->from(route('admin.service-inquiries.index'))
            ->post(route('admin.service-inquiries.store'), ['_modal' => 'ticket-new', 'service_type' => 'repair', 'concern' => 'short', 'name' => 'Maria Clara', 'phone' => '09171234567'])
            ->assertRedirect(route('admin.service-inquiries.index'))
            ->assertSessionHasErrors('concern');

        $html = $this->get(route('admin.service-inquiries.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="ticket-new"[^>]*data-open-on-load[^>]*data-force-dirty/', $html);
        $this->assertStringContainsString('value="Maria Clara"', $html);
        $this->assertStringContainsString('>short</textarea>', $html);
        $this->assertMatchesRegularExpression('/name="service_type" value="repair" checked/', $html);
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_a_linked_customer_survives_a_rejected_new_ticket(): void
    {
        $customer = $this->makeUser();
        $this->actingAs($this->admin(), 'admin');

        $this->from(route('admin.service-inquiries.index'))
            ->post(route('admin.service-inquiries.store'), ['_modal' => 'ticket-new', 'customer_id' => $customer->id, 'service_type' => 'repair', 'concern' => 'short', 'name' => 'X Y', 'phone' => '09171234567']);

        $html = $this->get(route('admin.service-inquiries.index'))->getContent();
        $this->assertStringContainsString('name="customer_id" id="nt-customer-id" value="' . $customer->id . '"', $html);
        $this->assertMatchesRegularExpression('/id="nt-linked"\s*>/', $html);   // shown, not hidden
    }

    public function test_a_valid_new_ticket_is_still_created_from_the_modal_form(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $res = $this->post(route('admin.service-inquiries.store'), [
            '_modal' => 'ticket-new', 'service_type' => 'repair', 'concern' => 'Wobbly chair leg, walk-in customer.',
            'name' => 'Maria Clara', 'phone' => '09171234567',
        ]);

        $ticket = ServiceInquiry::firstOrFail();
        $res->assertRedirect(route('admin.service-inquiries.show', $ticket->id));
    }

    /* ───────────── GCash redirect page: Back is as wide and tall as Continue ───────────── */

    public function test_the_gcash_stylesheet_is_versioned_so_a_browser_cannot_keep_an_old_copy(): void
    {
        foreach (['gcash-paymongo', 'stripe', 'confirmed'] as $view) {
            $this->assertStringContainsString("assets/css/payment.css') }}?v={{ @filemtime(public_path('assets/css/payment.css')) }}", file_get_contents(resource_path("views/payment/{$view}.blade.php")), $view);
        }
        $css = file_get_contents(public_path('assets/css/payment.css'));
        $this->assertMatchesRegularExpression('/\.gcash-redirect-overlay \.gcash-redirect-actions \.btn-submit-gcash,\s*\.gcash-redirect-overlay \.gcash-redirect-actions \.lp-btn--back\s*\{[^}]*width: 100%;[^}]*min-height: 56px/', $css);
    }
}
