<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 13 end to end with the new tools: a legacy request and a new
 * one, saved contact, all three call updates, edit before the quote,
 * quote with image, accept, checkout (faked Stripe), cash payment,
 * completion. Statuses, notifications and events asserted at each step.
 */
class Stage13FlowTest extends TestCase
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

    private function callUpdate($admin, CustomDesignRequest $r, array $data)
    {
        return $this->adminPost($admin, 'admin.custom-orders.call-update', $r, $data);
    }

    public function test_full_flow_with_the_new_tools(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer('RENCE');
        $customer->forceFill(['last_name' => 'SAMONTANEZ'])->save();
        BusinessSetting::current()->forceFill(['phone' => '0917-555-0100', 'email' => 'hello@lpfurniture.test'])->save();

        // ── 1. a legacy one-click request (status approved, no pipeline)
        [$legacy, $legacyConcept] = $this->generatedRequest($customer);
        $legacy->forceFill(['status' => 'approved'])->save();
        $legacyConcept->forceFill(['is_selected' => true])->save();

        $this->asCustomer($customer)->get(route('design.sendForm', $legacy->id))->assertOk()
            ->assertSee('Save this number and email for my next custom orders')
            ->assertSee('What would you like to change or add to this design?');

        // ── 2. submit it once, saving the contact for the account
        $this->asCustomer($customer)->post(route('design.sendToTeam', $legacy->id), [
            'suggestion_id' => $legacyConcept->id, 'contact_phone' => '09171234567', 'contact_email' => 'rence@example.com',
            'preferred_call_window' => 'afternoon_12_5', 'request_mode' => 'modified',
            'modification_notes' => 'Make it 20 cm longer', 'save_contact' => '1',
        ])->assertSessionHasNoErrors();

        $legacy = $legacy->fresh();
        $this->assertSame('submitted', $legacy->pipeline_status);
        $this->assertSame('afternoon_12_5', $legacy->preferred_call_window);
        $this->assertSame('09171234567', $customer->fresh()->saved_contact_phone);
        $this->assertSame(1, CustomOrderEvent::where('request_id', $legacy->id)->where('type', 'submitted')->count());

        // ── 3. a second, brand new request: the form shows the saved contact, still asks the idea and window
        [$second, $secondConcept] = $this->generatedRequest($customer);
        $this->asCustomer($customer)->get(route('design.sendForm', [$second->id, 'suggestion' => $secondConcept->id]))->assertOk()
            ->assertSee('Use a different number or email for this request')->assertSee('09171234567')->assertSee('rence@example.com')
            ->assertSee('When should we call?')->assertSee('What would you like to change or add to this design?');
        $this->submitRequest($customer, $second, $secondConcept, [
            'contact_phone' => '09171234567', 'contact_email' => 'rence@example.com', 'preferred_call_window' => 'morning_8_12',
        ])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $second->fresh()->pipeline_status);

        // ── 4. call updates: all three outcomes, one notification each, exact status
        Notification::where('user_id', $customer->id)->delete();

        $this->callUpdate($admin, $legacy, ['outcome' => 'unreachable', 'attempts' => 2])->assertSessionHasNoErrors();
        $this->assertSame('unreachable', $legacy->fresh()->pipeline_status);
        $this->assertSame(1, Notification::where('user_id', $customer->id)->count());

        $this->callUpdate($admin, $legacy, ['outcome' => 'ringing', 'attempts' => 1])->assertSessionHasNoErrors();
        $this->assertSame('contacting', $legacy->fresh()->pipeline_status);
        $this->assertSame(2, Notification::where('user_id', $customer->id)->count());

        $this->callUpdate($admin, $legacy, ['outcome' => 'answered', 'note' => 'Wants dark narra'])->assertSessionHasNoErrors();
        $this->assertSame('contacting', $legacy->fresh()->pipeline_status);

        $messages = Notification::where('user_id', $customer->id)->orderBy('id')->pluck('message')->all();
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('2 times', $messages[0]);
        $this->assertStringContainsString('0917-555-0100', $messages[0]);
        $this->assertStringContainsString('Rence Samontanez', $messages[0]);
        $this->assertStringContainsString('1 time ', $messages[1]);
        $this->assertStringEndsWith("\nWants dark narra", $messages[2]);
        $this->assertSame(3, CustomOrderEvent::where('request_id', $legacy->id)->where('type', 'call_update')->count());

        // ── 5. the customer edits the request before any quote
        $this->asCustomer($customer)->post(route('design.update', $legacy->id), [
            'contact_phone' => '09171234567', 'contact_email' => 'rence@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'modified',
            'modification_notes' => 'Make it 30 cm longer and add two drawers',
        ])->assertSessionHasNoErrors();
        $legacy = $legacy->fresh();
        $this->assertSame('Make it 30 cm longer and add two drawers', $legacy->modification_notes);
        $this->assertSame('contacting', $legacy->pipeline_status);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('title', 'Custom Request Updated')->count());

        // ── 6. quote page: image, heading, customer name in proper case; then create and send
        $this->asAdmin($admin)->get(route('admin.custom-orders.quotes.create', $legacy->id))->assertOk()
            ->assertSee($this->flowImage, false)
            ->assertSee('Customized order: Rence Samontanez')
            ->assertSee('Make it 30 cm longer and add two drawers');

        // a quote can be sent straight from "submitted" on the other request (no call needed)
        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $second, $this->quoteFields())->assertSessionHasNoErrors();
        $this->assertSame(1, CustomQuote::where('request_id', $second->id)->count());

        $this->adminPost($admin, 'admin.custom-orders.quotes.store', $legacy, $this->quoteFields())->assertSessionHasNoErrors();
        $draft = CustomQuote::where('request_id', $legacy->id)->sole();
        $this->asAdmin($admin)->post(route('admin.custom-orders.quotes.send', [$legacy->id, $draft->id]), ['lock_version' => $legacy->fresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame('quoted', $legacy->fresh()->pipeline_status);

        // editing is now locked
        $this->asCustomer($customer)->get(route('design.edit', $legacy->id))->assertRedirect(route('design.show', $legacy->id));
        $this->asCustomer($customer)->post(route('design.update', $legacy->id), [
            'contact_phone' => '09171234567', 'contact_email' => 'rence@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact',
        ])->assertRedirect(route('design.show', $legacy->id));
        $this->assertSame('Make it 30 cm longer and add two drawers', $legacy->fresh()->modification_notes);

        // ── 7. accept, checkout with faked Stripe, deposit paid
        $this->asCustomer($customer)->post(route('design.quote.accept', [$legacy->id, $draft->id]), ['agree' => '1'])->assertRedirect();
        $this->assertSame('accepted', $legacy->fresh()->pipeline_status);
        $agreement = $this->agreementOf($legacy);

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $this->assertSame('ordered', $legacy->fresh()->pipeline_status);

        $this->asCustomer($customer)->post(route('payment.stripe.checkout', $order->id))->assertRedirect();
        $this->stripePaid($order, 'cs_e2e_1', 'evt_s13_1')->assertOk();
        $order = $order->fresh();
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame('10250.00', (string) $order->amount_paid);
        $this->assertSame(1, CustomOrderEvent::where('request_id', $legacy->id)->where('type', 'order_confirmed')->count());

        // ── 8. admin records the balance, then completes
        $this->recordCash($admin, $order, '5125.00')->assertSessionHasNoErrors();
        $this->recordCash($admin, $order->fresh(), '5125.00', null, ['method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $order = $order->fresh();
        $this->assertSame('20500.00', (string) $order->amount_paid);
        $this->assertSame(3, PaymentRecord::where('order_id', $order->id)->count());

        foreach (['in_production', 'ready', 'completed'] as $step) {
            $this->adminSetStatus($admin, $order->fresh(), $step)->assertSessionHasNoErrors();
        }
        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame('ordered', $legacy->fresh()->pipeline_status);

        // the other request is untouched by all of this
        $this->assertSame('submitted', $second->fresh()->pipeline_status);

        // timelines are consistent: customer sees the story, admin sees the call updates
        $this->asCustomer($customer)->get(route('design.show', $legacy->id))->assertOk()
            ->assertSee('You updated your request')->assertSee('Your order was completed');
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $legacy->id))->assertOk()
            ->assertSee('Call update: Unreachable, 2 times called')->assertSee('Customer updated the request');
    }
}
