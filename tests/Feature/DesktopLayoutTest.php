<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 15 part A: the custom order pages use the two-column markup
 * (details left, design image in a sticky media column) and the CSS that
 * collapses it on narrow screens.
 */
class DesktopLayoutTest extends TestCase
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

    public function test_stylesheet_has_the_two_column_rules(): void
    {
        $css = file_get_contents(public_path('assets/css/custom-orders.css'));

        $this->assertStringContainsString('.co-layout--media { grid-template-columns: minmax(0, 1fr) minmax(0, 420px)', $css);
        $this->assertStringContainsString('@media (min-width: 900px)', $css);
        $this->assertStringContainsString('position: sticky', $css);
        $this->assertStringContainsString('.cd-wrap--wide { max-width: 1260px; }', $css);
    }

    public function test_customer_pages_put_the_image_in_a_media_column(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);

        $design = $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk();
        $design->assertSee('cd-wrap cd-wrap--wide', false)->assertSee('co-layout co-layout--media', false)
            ->assertSee('<aside class="co-media cc-media', false);

        $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk()
            ->assertSee('co-layout co-layout--media', false)->assertSee('<aside class="co-media">', false);

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);

        foreach ([route('design.agreement', $agreement->id), route('custom.checkout', $agreement->id)] as $url) {
            $this->asCustomer($customer)->get($url)->assertOk()
                ->assertSee('co-layout co-layout--media', false)
                ->assertSee(route('design.agreement.image', $agreement->id), false);
        }

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = \App\Models\Order::where('design_agreement_id', $agreement->id)->sole();
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()
            ->assertSee('orders-wrap--wide', false)->assertSee('co-media-card--wide', false);
    }

    public function test_admin_pages_show_the_design_and_prompt_beside_the_form(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();

        $show = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();
        // the design panel sits in the side column: after the request details, before the actions
        $this->assertNotFalse(strpos($show, 'Selected Design'));
        $this->assertGreaterThan(strpos($show, 'Preferred call window'), strpos($show, 'Selected Design'));
        $this->assertLessThan(strpos($show, 'This Request'), strpos($show, 'Selected Design'));

        $form = $this->asAdmin($admin)->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk()->getContent();
        $this->assertGreaterThan(strpos($form, 'id="quote-form"'), strpos($form, 'co-side-sticky'));
        $this->assertGreaterThan(strpos($form, 'co-side-sticky'), strpos($form, 'Selected Design'));
    }
}
