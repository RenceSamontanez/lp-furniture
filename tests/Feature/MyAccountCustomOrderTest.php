<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * The agreement (custom design) checkout is an order path too: it needs a phone and a complete delivery address,
 * and the order keeps a snapshot of the address used.
 */
class MyAccountCustomOrderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;
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

    /** A customer with an accepted agreement but no phone and no saved address. */
    private function acceptedForBareCustomer(array $quote = []): array
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        $customer->forceFill([
            'phone_number' => null, 'phone_e164' => null, 'phone_country' => null,
            'address_province_id' => null, 'address_city_id' => null, 'address_barangay_id' => null,
            'address_street' => null, 'address_postal_code' => null, 'address_landmark' => null,
        ])->save();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertRedirect();
        $quote = $this->quoteAndSend($admin, $r->fresh(), $quote);
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();

        return [$customer->fresh(), $this->agreementOf($r)];
    }

    public function test_the_agreement_checkout_page_asks_for_the_order_details(): void
    {
        [$customer, $agreement] = $this->acceptedForBareCustomer();
        $this->asCustomer($customer)->get(route('custom.checkout', $agreement->id))->assertOk()
            ->assertSee('Recipient Name')->assertSee('name="addr_province_id"', false)->assertSee('Use this as my permanent delivery address?');
    }

    public function test_an_agreement_order_without_phone_and_address_is_refused_on_the_server(): void
    {
        [$customer, $agreement] = $this->acceptedForBareCustomer();

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])
            ->assertSessionHasErrors(['contact_phone', 'addr_province_id']);
        $this->assertSame(0, Order::count());

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe', 'contact_phone' => '0917 123 4567']
            + $this->addressInput('addr_', ['postal_code' => '']))->assertSessionHasErrors('addr_postal_code');
        $this->assertSame(0, Order::count());
    }

    public function test_an_agreement_order_with_the_details_keeps_a_snapshot_and_can_save_the_address(): void
    {
        [$customer, $agreement] = $this->acceptedForBareCustomer();

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), [
            'payment_method' => 'stripe', 'recipient_name' => 'Ana Cruz', 'contact_phone' => '0917 123 4567', 'phone_country' => 'PH',
            'address_mode' => 'new', 'keep_address' => 'yes',
        ] + $this->addressInput('addr_'))->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $this->assertSame('Marauoy', $order->delivery_snapshot['barangay']);
        $this->assertSame('+639171234567', $order->delivery_snapshot['phone']);
        $this->assertSame('Ana Cruz', $order->delivery_snapshot['recipient']);
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $customer->fresh()->address_street);
        $this->assertSame('+639171234567', $customer->fresh()->phone_e164);
    }

    public function test_a_second_post_for_an_agreement_that_already_has_an_order_needs_no_new_details(): void
    {
        [$customer, $agreement] = $this->acceptedForBareCustomer();
        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), [
            'payment_method' => 'stripe', 'contact_phone' => '0917 123 4567', 'address_mode' => 'new', 'keep_address' => 'no',
        ] + $this->addressInput('addr_'))->assertSessionHasNoErrors();

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Order::where('design_agreement_id', $agreement->id)->count());
    }

    public function test_a_pickup_agreement_needs_a_phone_but_no_address_and_keeps_no_snapshot(): void
    {
        [$customer, $agreement] = $this->acceptedForBareCustomer(['delivery_option' => 'pickup', 'delivery_fee' => '0', 'delivery_address' => '']);

        $this->asCustomer($customer)->get(route('custom.checkout', $agreement->id))->assertOk()->assertSee('Pick up at our shop')->assertSee('data-od-pickup', false);

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertSessionHasErrors('contact_phone');
        $this->assertSame(0, Order::count());

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe', 'contact_phone' => '0917 123 4567'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $this->assertSame('pickup', $order->delivery_option);
        $this->assertNull($order->delivery_snapshot);
        $this->assertSame(0, Delivery::count());
    }
}
