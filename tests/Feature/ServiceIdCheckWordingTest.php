<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** Stage 21j: an unvalidated customer opening /services/request is told the ID check is for a request, not an order. */
class ServiceIdCheckWordingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    public function test_service_request_sends_unvalidated_customer_to_a_request_worded_page(): void
    {
        $u = $this->makeUser();
        $u->forceFill(['is_validated' => false])->save();

        $this->actingAs($u)->get('/services/request')->assertRedirect(route('validation.show'));
        $page = $this->actingAs($u)->get(route('validation.show'));
        $page->assertOk()
            ->assertSee('before you send a service request')
            ->assertSee('Continue ID Check')
            ->assertDontSee('place orders')
            ->assertDontSee('before your first order');
    }

    public function test_furniture_checkout_keeps_the_order_wording(): void
    {
        $u = $this->makeUser();
        $u->forceFill(['is_validated' => false])->save();

        $this->actingAs($u)->get(route('validation.show'))
            ->assertOk()->assertSee('before your first order')->assertDontSee('Continue ID Check');
        $this->actingAs($u)->get(route('checkout'))
            ->assertRedirect(route('validation.show'))->assertSessionHas('warning');
    }

    public function test_after_approval_the_customer_is_offered_the_service_request(): void
    {
        $u = $this->makeUser();
        $u->forceFill(['is_validated' => false])->save();
        $this->actingAs($u)->get('/services/request');
        $u->forceFill(['is_validated' => true])->save();

        $this->actingAs($u)->get(route('validation.show'))
            ->assertSee('Continue to Your Service Request')->assertSee(route('services.request'));
    }
}
