<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\Concerns\GivesDeliveryDetails;
use Tests\TestCase;

/** A customer cancels their own unpaid order with a reason (no admin approval). */
class CustomerCancelOrderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;
    use GivesDeliveryDetails;

    private function unpaidOrder(User $customer, int $qty = 2): array
    {
        $product = Product::create(['product_name' => 'Chair', 'category' => 'Chairs', 'price' => 500, 'stock_quantity' => 10, 'product_status' => 'available']);
        $order = $this->makeOrder($customer, ['total_amount' => 500 * $qty]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Chair', 'quantity' => $qty, 'unit_price' => 500, 'subtotal' => 500 * $qty]);
        $this->makePayment($order);
        // the items were taken from stock when the order was placed
        $product->update(['stock_quantity' => 10 - $qty]);

        return [$order, $product];
    }

    public function test_unpaid_order_can_be_cancelled_with_each_reason(): void
    {
        foreach (OrderTracking::CUSTOMER_CANCEL_REASONS as $reason) {
            $customer = $this->makeUser();
            [$order] = $this->unpaidOrder($customer);

            $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => $reason, 'reason_other' => $reason === 'Other' ? 'Too far away' : null])
                ->assertRedirect(route('orders.show', $order->id))->assertSessionHas('success');

            $this->assertSame('cancelled', $order->fresh()->order_status, $reason);
            $event = OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->sole();
            $this->assertSame('Order Cancelled', $event->title);
            $this->assertNull($event->admin_user_id);
            $this->assertStringContainsString('Reason: ' . $reason, $event->note);
            if ($reason === 'Other') {
                $this->assertStringContainsString('Too far away', $event->note);
            }
        }
    }

    public function test_reason_is_required_and_must_be_one_of_the_list(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), [])->assertSessionHas('error');
        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Because I said so'])->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame(0, OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->count());
    }

    public function test_other_text_is_limited_to_200_characters(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Other', 'reason_other' => str_repeat('a', 201)])
            ->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->order_status);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Other', 'reason_other' => str_repeat('a', 200)])
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->order_status);
    }

    public function test_other_text_cannot_hold_a_link_and_is_ignored_for_the_fixed_reasons(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Other', 'reason_other' => 'see https://spam.example.com'])->assertSessionHas('error');
        $this->assertSame('pending', $order->fresh()->order_status);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind', 'reason_other' => 'ignored text'])->assertSessionHas('success');
        $this->assertStringNotContainsString('ignored text', OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->value('note'));
    }

    public function test_a_paid_order_cannot_be_cancelled_this_way(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);
        PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $order->payment()->value('id'), 'method' => 'stripe', 'gateway_reference' => 'pi_test_1', 'amount' => 1000, 'paid_on' => now(), 'recorded_by' => null]);
        $order->payment()->update(['payment_status' => 'paid']);
        $order->forceFill(['order_status' => 'confirmed', 'amount_paid' => 1000])->save();

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertSessionHas('error');
        $this->assertSame('confirmed', $order->fresh()->order_status);

        // also refused when only the payment row says paid (stale or odd data) while the order is still pending
        [$second] = $this->unpaidOrder($customer);
        $second->payment()->update(['payment_status' => 'paid']);
        $this->actingAs($customer)->post(route('orders.cancel', $second->id), ['reason' => 'Changed My Mind'])->assertSessionHas('error');
        $this->assertSame('pending', $second->fresh()->order_status);
    }

    public function test_an_order_that_moved_on_or_is_already_cancelled_is_refused(): void
    {
        $customer = $this->makeUser();
        foreach (['confirmed', 'in_production', 'cancelled', 'completed'] as $status) {
            [$order] = $this->unpaidOrder($customer);
            $order->forceFill(['order_status' => $status])->save();

            $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertSessionHas('error');
            $this->assertSame($status, $order->fresh()->order_status);
            $this->assertSame(0, OrderStatusEvent::where('order_id', $order->id)->where('title', 'Order Cancelled')->count(), $status);
        }
    }

    public function test_another_customers_order_cannot_be_cancelled(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        [$order] = $this->unpaidOrder($owner);

        $this->actingAs($other)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertNotFound();
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_a_guest_cannot_cancel(): void
    {
        $owner = $this->makeUser();
        [$order] = $this->unpaidOrder($owner);

        $this->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertRedirect();
        $this->assertSame('pending', $order->fresh()->order_status);
    }

    public function test_stock_is_released_once_even_if_cancel_is_sent_twice(): void
    {
        $customer = $this->makeUser();
        [$order, $product] = $this->unpaidOrder($customer, 3);
        $this->assertSame(7, $product->fresh()->stock_quantity);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertSessionHas('success');
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])->assertSessionHas('error');
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertNotNull($order->fresh()->stock_restored_at);
        $this->assertSame(1, OrderStatusEvent::where('order_id', $order->id)->where('type', 'stock')->count());
    }

    public function test_pending_payment_is_marked_failed_and_a_notification_is_made(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);

        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Found a Better Price'])->assertSessionHas('success');

        $this->assertSame('failed', $order->payment()->value('payment_status'));
        $n = Notification::where('user_id', $customer->id)->where('type', 'order_update')->sole();
        $this->assertStringContainsString($order->order_number, $n->title);
        $this->assertStringContainsString('Found a Better Price', $n->message);
        $this->assertSame(route('orders.show', $order->id), $n->url);
        $this->assertSame('order', \App\Support\NotificationKind::for($n));
    }

    // A payment that still arrives after the cancel is covered by the existing PaymentSafetyTest cases (recorded as paid, flagged
    // for review, the order is never flipped back): test_stripe_payment_on_cancelled_order_is_recorded_but_not_confirmed and its GCash twin.

    public function test_admin_sees_the_reason_on_the_order_page(): void
    {
        $customer = $this->makeUser();
        $admin = $this->makeUser('admin');
        [$order] = $this->unpaidOrder($customer);
        $this->actingAs($customer)->post(route('orders.cancel', $order->id), ['reason' => 'Other', 'reason_other' => 'Moving abroad'])->assertSessionHas('success');

        $html = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()->getContent();
        $this->assertStringContainsString('Order Cancelled', $html);
        $this->assertStringContainsString('Cancelled by the customer. Reason: Other - Moving abroad', $html);
    }

    public function test_cancel_button_shows_only_for_cancellable_orders_and_uses_the_shared_modal(): void
    {
        $customer = $this->makeUser();
        [$open] = $this->unpaidOrder($customer);
        [$paid] = $this->unpaidOrder($customer);
        $paid->forceFill(['order_status' => 'confirmed'])->save();

        // the My Orders list never shows the button or the dialog: View Details is its only action
        $list = $this->actingAs($customer)->get(route('orders.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('cancel-order-', $list);
        $this->assertStringNotContainsString('Cancel This Order?', $list);
        $this->assertStringNotContainsString('Cancel Order', $list);
        $this->assertStringContainsString('View Details', $list);

        $view = $this->actingAs($customer)->get(route('orders.show', $open->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-lp-modal-open="cancel-order-' . $open->id . '"', $view);
        $this->assertStringContainsString('id="cancel-order-' . $open->id . '"', $view);
        $this->assertStringContainsString('Keep Order', $view);
        $this->assertStringContainsString('Cancel This Order?', $view);
        foreach (OrderTracking::CUSTOMER_CANCEL_REASONS as $reason) {
            $this->assertStringContainsString('value="' . $reason . '"', $view);
        }
        $this->assertStringContainsString('maxlength="200"', $view);

        // a paid / moved-on order has no button on its order view
        $this->assertStringNotContainsString('cancel-order-', $this->actingAs($customer)->get(route('orders.show', $paid->id))->getContent());
    }

    public function test_cancel_request_sent_by_the_dialog_gets_json_answers(): void
    {
        $customer = $this->makeUser();
        [$order] = $this->unpaidOrder($customer);

        $this->actingAs($customer)->postJson(route('orders.cancel', $order->id), [])
            ->assertStatus(422)->assertJsonStructure(['message']);
        $this->assertSame('pending', $order->fresh()->order_status);

        $this->actingAs($customer)->postJson(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])
            ->assertOk()->assertJson(['redirect' => route('orders.show', $order->id)]);
        $this->assertSame('cancelled', $order->fresh()->order_status);

        $this->actingAs($customer)->postJson(route('orders.cancel', $order->id), ['reason' => 'Changed My Mind'])
            ->assertStatus(409)->assertJsonStructure(['message']);
    }

    public function test_the_admin_cancel_path_is_unchanged(): void
    {
        $customer = $this->makeUser();
        $admin = $this->makeUser('admin');
        [$order] = $this->unpaidOrder($customer);

        app(OrderTracking::class)->cancel($order->id, $admin, 'pending', 'Customer asked us to stop');
        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame($admin->id, OrderStatusEvent::where('order_id', $order->id)->where('type', 'cancel')->value('admin_user_id'));
    }
}
