<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * The concept page (design.show for a design sent to the team): chip and next-step banner follow the
 * real state, and the contact strip reads Contact Settings (via FooterData), never a hard-coded number.
 */
class ConceptPageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function sent(User $c, string $pipeline, array $extra = []): CustomDesignRequest
    {
        return CustomDesignRequest::create(array_merge([
            'user_id' => $c->id, 'title' => 'Custom', 'description' => 'A walnut shelf', 'status' => 'approved',
            'pipeline_status' => $pipeline, 'contact_phone' => '09171234567', 'preferred_call_window' => 'anytime',
            'request_mode' => 'exact', 'submitted_at' => now(),
        ], $extra));
    }

    private function page(User $c, CustomDesignRequest $r)
    {
        return $this->actingAs($c)->get(route('design.show', $r->id))->assertOk();
    }

    /** An accepted agreement with an order in the given status and amount paid. */
    private function ordered(User $c, string $orderStatus, float $paid, array $orderExtra = []): CustomDesignRequest
    {
        $admin = $this->makeUser('admin');
        $r = $this->sent($c, 'ordered');
        $quote = $this->makeQuote($r, ['status' => 'accepted', 'expires_at' => now()->addDays(3)]);
        $ag = $this->makeAgreement($quote, $c, $admin);
        $order = $this->makeOrder($c);
        $order->forceFill(array_merge(['design_agreement_id' => $ag->id, 'order_status' => $orderStatus, 'amount_paid' => $paid, 'total_amount' => 20000], $orderExtra))->save();

        return $r;
    }

    public function test_request_just_sent_says_we_will_call(): void
    {
        $c = $this->makeUser();
        $this->page($c, $this->sent($c, 'submitted'))
            ->assertSee('WAITING FOR OUR CALL')
            ->assertSee('Request sent, we will call you at 09171234567 during Anytime, 8 AM to 5 PM.')
            ->assertSee('No payment is due yet')
            ->assertSee('Edit my request')
            ->assertSee('Back to My Designs')
            ->assertSee('Your design concept');
    }

    public function test_contacting_and_unreachable_have_their_own_banners(): void
    {
        $c = $this->makeUser();
        $this->page($c, $this->sent($c, 'contacting'))->assertSee('WE ARE IN TOUCH')->assertSee('Our team has been in touch with you.');
        $this->page($c, $this->sent($c, 'unreachable'))->assertSee('WE COULD NOT REACH YOU')->assertSee("We couldn&#039;t reach you by phone.", false);
    }

    public function test_quote_ready_shows_total_validity_and_review_button(): void
    {
        $c = $this->makeUser();
        $r = $this->sent($c, 'quoted');
        $q = $this->makeQuote($r, ['expires_at' => now()->addDays(3)]);

        $this->page($c, $r)
            ->assertSee('QUOTE READY')
            ->assertSee('Total ₱20,000.00, valid until')
            ->assertSee('Review and accept')
            ->assertSee(route('design.quote.review', [$r->id, $q->id]), false);
    }

    public function test_expired_quote_and_declined_quote_and_expired_agreement(): void
    {
        $c = $this->makeUser();

        $r = $this->sent($c, 'expired');
        $this->makeQuote($r, ['status' => 'expired', 'expires_at' => now()->subDay()]);
        $this->page($c, $r)->assertSee('QUOTE EXPIRED')->assertSee('This quote expired on');

        $r2 = $this->sent($c, 'declined_by_customer');
        $this->page($c, $r2)->assertSee('QUOTE DECLINED')->assertSee('You declined this quote.');

        $r3 = $this->sent($c, 'expired');
        CustomOrderEvent::create(['request_id' => $r3->id, 'type' => 'agreement_expired', 'actor_type' => 'system', 'occurred_at' => now(), 'data' => []]);
        $this->page($c, $r3)->assertSee('AGREEMENT EXPIRED')->assertSee('The time to start payment passed');
    }

    public function test_agreed_but_unpaid_says_next_step_is_payment(): void
    {
        $c = $this->makeUser();
        $admin = $this->makeUser('admin');
        $r = $this->sent($c, 'accepted', ['payment_due_at' => now()->addDay()]);
        $ag = $this->makeAgreement($this->makeQuote($r, ['status' => 'accepted', 'expires_at' => now()->addDays(3)]), $c, $admin);

        $this->page($c, $r)
            ->assertSee('WAITING FOR PAYMENT')
            ->assertSee('next step is payment')
            ->assertSee('Proceed to payment')
            ->assertSee(route('custom.checkout', $ag->id), false)
            ->assertSee(route('design.agreement', $ag->id), false);
    }

    public function test_order_created_but_unpaid_still_says_next_step_is_payment(): void
    {
        $c = $this->makeUser();
        $r = $this->ordered($c, 'pending', 0);

        $this->page($c, $r)->assertSee('WAITING FOR PAYMENT')->assertSee('next step is payment')->assertSee('has been created')->assertSee('View order');
    }

    public function test_after_payment_the_banner_stops_saying_next_step_is_payment(): void
    {
        $c = $this->makeUser();
        $r = $this->ordered($c, 'confirmed', 20000, ['expected_ready_date' => '2026-11-20']);

        $this->page($c, $r)
            ->assertSee('PAYMENT RECEIVED')
            ->assertSee('Payment received. We will start building your order.')
            ->assertSee('We expect it to be ready by Nov 20, 2026.')
            ->assertDontSee('next step is payment')
            ->assertDontSee('Proceed to payment')
            ->assertDontSee('WAITING FOR PAYMENT')
            ->assertSee('View order')
            ->assertSee('View agreement');
    }

    public function test_paid_with_a_balance_mentions_what_is_left(): void
    {
        $c = $this->makeUser();
        $r = $this->ordered($c, 'confirmed', 10000);

        $this->page($c, $r)->assertSee('A balance of ₱10,000.00 is still due')->assertDontSee('next step is payment');
    }

    public function test_later_order_states_have_matching_banners(): void
    {
        $c = $this->makeUser();
        $this->page($c, $this->ordered($c, 'in_production', 20000))->assertSee('IN PRODUCTION')->assertSee('We are building your order.')->assertDontSee('next step is payment');
        $this->page($c, $this->ordered($c, 'ready', 20000))->assertSee('ORDER READY')->assertSee('We will contact you about picking it up');
        $this->page($c, $this->ordered($c, 'out_for_delivery', 20000, ['delivery_option' => 'delivery']))->assertSee('OUT FOR DELIVERY');
        $this->page($c, $this->ordered($c, 'completed', 20000))->assertSee('COMPLETED')->assertSee('Your order is completed.');
        $this->page($c, $this->ordered($c, 'cancelled', 0))->assertSee('ORDER CANCELLED')->assertSee('Your order was cancelled.')->assertDontSee('next step is payment');
    }

    public function test_contact_strip_reads_contact_settings_not_a_hard_coded_number(): void
    {
        $row = BusinessSetting::query()->orderBy('id')->first() ?? new BusinessSetting();
        $row->forceFill(['phone' => '09171112222', 'email' => 'shop@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture'])->save();

        $c = $this->makeUser();
        $html = $this->page($c, $this->sent($c, 'submitted'))
            ->assertSee('Questions about your design? Reach our team')
            ->assertSee('0917 111 2222')
            ->assertSee('tel:+639171112222', false)
            ->assertSee('shop@lpfurniture.test')
            ->assertSee('mailto:shop@lpfurniture.test', false)
            ->assertSee('Message Us on Facebook')
            ->assertSee('m.me/lpfurniture', false)
            ->getContent();

        // The built-in fallbacks are only for an empty Contact Settings row.
        $this->assertStringNotContainsString(config('shop.fallback.phone'), $html);
        $this->assertStringNotContainsString(config('shop.fallback.email'), $html);
    }

    public function test_contact_strip_falls_back_like_the_footer_and_hides_what_is_missing(): void
    {
        $row = BusinessSetting::query()->orderBy('id')->first() ?? new BusinessSetting();
        $row->forceFill(['phone' => '', 'email' => '', 'facebook_url' => ''])->save();
        config(['shop.fallback' => ['phone' => '09170001111', 'email' => 'fallback@lpfurniture.test', 'facebook_url' => null]]);

        $c = $this->makeUser();
        $this->page($c, $this->sent($c, 'submitted'))->assertSee('0917 000 1111')->assertSee('fallback@lpfurniture.test')->assertDontSee('Message Us on Facebook');

        config(['shop.fallback' => ['phone' => null, 'email' => null, 'facebook_url' => null]]);
        $this->page($c, $this->sent($c, 'submitted'))->assertSee('Reach our team')->assertDontSee('tel:', false)->assertDontSee('mailto:', false)->assertDontSee('example.com');
    }

    public function test_the_description_is_always_visible_and_the_old_dropdown_is_gone(): void
    {
        $c = $this->makeUser();
        $r = $this->sent($c, 'submitted', ['description' => 'A walnut shelf with three tiers', 'ai_prompt' => 'Studio photo of a walnut shelf']);

        $this->page($c, $r)->assertSee('YOUR DESCRIPTION')->assertSee('A walnut shelf with three tiers')->assertSee('Studio photo of a walnut shelf')
            ->assertDontSee('View AI Prompt')->assertDontSee('ai-prompt-toggle');
    }

    public function test_another_customer_cannot_open_the_concept_page(): void
    {
        $owner = $this->makeUser();
        $r = $this->sent($owner, 'submitted');
        $this->actingAs($this->makeUser())->get(route('design.show', $r->id))->assertNotFound();
    }
}
