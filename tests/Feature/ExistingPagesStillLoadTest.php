<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Smoke test: after the Stage 1 schema/model changes, the existing
 * customer and admin pages still render. Read-only GETs against the
 * scratch database; nothing here touches furniture_db.
 */
class ExistingPagesStillLoadTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function seedCatalog(): Product
    {
        return Product::create([
            'product_name' => 'Narra Chair', 'category' => 'Chairs', 'price' => 2500,
            'stock_quantity' => 5, 'product_status' => 'available',
        ]);
    }

    public function test_public_pages_load(): void
    {
        $product = $this->seedCatalog();

        $this->get('/')->assertOk();
        $this->get(route('catalog'))->assertOk();
        $this->get(route('product.show', $product->id))->assertOk();
    }

    public function test_customer_checkout_orders_and_design_pages_load(): void
    {
        $product  = $this->seedCatalog();
        $customer = $this->makeUser();

        DB::table('cart_items')->insert([
            'user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->makeOrder($customer, ['total_amount' => 2500]);
        $this->makePayment($order, ['amount' => 2500]);
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Narra Chair',
            'quantity' => 1, 'unit_price' => 2500, 'subtotal' => 2500,
        ]);
        $request = $this->makeRequest($customer);

        $this->actingAs($customer)->get(route('cart.index'))->assertOk();
        $this->actingAs($customer)->get(route('checkout'))->assertOk();
        $this->actingAs($customer)->get(route('orders.index'))->assertOk();
        $this->actingAs($customer)->get(route('orders.show', $order->id))->assertOk();
        $this->actingAs($customer)->get(route('design.index'))->assertOk();
        $this->actingAs($customer)->get(route('design.show', $request->id))->assertOk();
        $this->actingAs($customer)->get(route('design.gallery'))->assertOk();
    }

    public function test_admin_pages_load(): void
    {
        $this->seedCatalog();
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $order    = $this->makeOrder($customer);
        $this->makePayment($order);
        $request  = $this->makeRequest($customer);

        foreach ([
            route('admin.dashboard'),
            route('admin.orders.index'),
            route('admin.orders.show', $order->id),
            route('admin.design-requests.index'),
            route('admin.design-requests.show', $request->id),
            route('admin.products.index'),
            route('admin.customers.index'),
            route('admin.installments.index'),
            route('admin.reports'),
            route('admin.chatbot.index'),
            route('admin.settings.contact.edit'),
        ] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertOk();
        }
    }

    public function test_customer_cannot_reach_admin_pages(): void
    {
        $customer = $this->makeUser();

        $this->actingAs($customer)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->actingAs($customer, 'admin')->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }
}
