<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Delivery;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 9 part A: agreement checkout and placeAgreementOrder.
 */
class CustomCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function customer(string $first = 'Ana'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    /**
     * An agreement created through the real accept endpoint.
     * Default: delivery, ₱20,000 + ₱500, ₱10,250 deposit, 2 months / 30 days.
     */
    private function accepted(?User $customer = null, array $quote = [], array $request = []): array
    {
        $customer ??= $this->customer();
        $admin = User::where('role', 'admin')->first() ?? $this->admin();

        $r = CustomDesignRequest::create(array_merge([
            'user_id' => $customer->id, 'title' => 'Dining Table - ' . $customer->first_name,
            'furniture_type' => 'Dining Table', 'description' => 'A six-seater narra table',
            'status' => 'approved', 'pipeline_status' => 'quoted',
            'contact_phone' => '09171234567', 'contact_email' => 'ana@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact', 'submitted_at' => now(),
            'reference_image' => 'design-refs/ref.png',
        ], $request));
        Storage::disk('public')->put('design-refs/ref.png', 'IMG');

        $q = CustomQuote::create(array_merge([
            'request_id' => $r->id, 'version' => 1, 'status' => 'sent',
            'final_description' => 'Six-seater dining table in narra',
            'width_cm' => 180, 'depth_cm' => 90, 'height_cm' => 75,
            'material' => 'Narra', 'finish' => 'Matte varnish', 'price' => 20000,
            'delivery_option' => 'delivery', 'delivery_fee' => 500, 'delivery_address' => '12 Mabini St, Batangas City',
            'production_days' => 20, 'payment_terms' => 'deposit', 'deposit_amount' => 10250,
            'installment_duration_months' => 2, 'installment_frequency_days' => 30,
            'created_by' => $admin->id, 'sent_by' => $admin->id,
            'sent_at' => now()->subHour(), 'expires_at' => now()->addDays(5),
        ], $quote));

        $this->actingAs($customer)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertRedirect();

        return [$customer, $r->fresh(), DesignAgreement::where('quote_id', $q->id)->firstOrFail()];
    }

    private function place(User $user, DesignAgreement $a, array $data = ['payment_method' => 'stripe'])
    {
        return $this->actingAs($user)->post(route('custom.checkout.place', $a->id), $data);
    }

    /* ───────────── page ───────────── */

    public function test_checkout_page_is_locked_and_offers_only_stripe_and_gcash(): void
    {
        [$c, , $a] = $this->accepted();

        $res = $this->actingAs($c)->get(route('custom.checkout', $a->id));

        $res->assertOk();
        $res->assertSee($a->agreement_number);
        $res->assertSee('agreed on', false);
        $res->assertSee('Six-seater dining table in narra');
        $res->assertSee('₱20,500.00', false);
        $res->assertSee('Amount due now: ₱10,250.00', false);
        $res->assertSee('Payment 1: ₱5,125.00', false);
        $res->assertSee('value="stripe"', false);
        $res->assertSee('value="gcash"', false);
        $res->assertDontSee('value="onsite"', false);
        $res->assertDontSee('name="quantity"', false);
        $res->assertDontSee('name="delivery_option"', false);
        $res->assertDontSee('installment_duration', false);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_design_page_links_to_payment_then_to_the_order(): void
    {
        [$c, $r, $a] = $this->accepted();

        $this->actingAs($c)->get(route('design.show', $r->id))
            ->assertOk()->assertSee('Proceed to payment')
            ->assertSee(route('custom.checkout', $a->id), false)
            ->assertDontSee('Payment step coming soon');

        $this->place($c, $a)->assertRedirect();
        $order = Order::where('design_agreement_id', $a->id)->firstOrFail();

        $this->actingAs($c)->get(route('design.show', $r->id))
            ->assertOk()->assertDontSee('Proceed to payment')
            ->assertSee(route('orders.show', $order->id), false);

        $this->actingAs($c)->get(route('orders.show', $order->id))
            ->assertOk()->assertSee('Custom order agreement')
            ->assertSee(route('design.agreement', $a->id), false);
    }

    /* ───────────── ownership / access ───────────── */

    public function test_other_customer_gets_404_and_nothing_is_created(): void
    {
        [, , $a] = $this->accepted($this->customer('Alice'));
        $bob = $this->customer('Bob');

        $this->actingAs($bob)->get(route('custom.checkout', $a->id))->assertNotFound();
        $this->place($bob, $a)->assertNotFound();

        $this->assertSame(0, Order::count());
    }

    public function test_guest_is_sent_to_login(): void
    {
        [, , $a] = $this->accepted();
        auth()->logout();

        $this->get(route('custom.checkout', $a->id))->assertRedirect(route('login'));
        $this->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])->assertRedirect(route('login'));
        $this->assertSame(0, Order::count());
    }

    /* ───────────── placing the order ───────────── */

    public function test_deposit_flow_creates_order_payment_schedule_and_item_from_the_snapshot(): void
    {
        [$c, $r, $a] = $this->accepted();
        $product = Product::create(['product_name' => 'Stock Chair', 'price' => 900, 'stock_quantity' => 7, 'product_status' => 'available']);
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        // the form posts lies about everything but the method
        $res = $this->place($c, $a, [
            'payment_method' => 'stripe', 'price' => '1', 'total' => '1', 'total_amount' => '1',
            'amount' => '1', 'payment_type' => 'full', 'delivery_option' => 'pickup', 'delivery_fee' => '0',
            'installment_duration' => '1', 'installment_frequency' => '15', 'quantity' => '99',
        ]);

        $order = Order::where('design_agreement_id', $a->id)->firstOrFail();
        $res->assertRedirect(route('payment.stripe', $order->id));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->assertSame($c->id, (int) $order->user_id);
        $this->assertSame($r->id, (int) $order->design_request_id);
        $this->assertSame('20500.00', (string) $order->total_amount);
        $this->assertSame('delivery', $order->delivery_option);
        $this->assertSame('12 Mabini St, Batangas City', $order->delivery_address);
        $this->assertSame('500.00', (string) $order->delivery_fee);
        $this->assertSame('pending', $order->order_status);
        $this->assertMatchesRegularExpression('/^LP-\d{4}-\d{5}$/', $order->order_number);

        $item = $order->items()->sole();
        $this->assertNull($item->product_id);
        $this->assertSame('Custom Furniture — Ana', $item->product_name);
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame('20000.00', (string) $item->unit_price);
        $this->assertMatchesRegularExpression('#^agreements/r\d+-q\d+-[A-Za-z0-9]{16}\.png$#', $item->product_image);

        $p = $order->payment;
        $this->assertSame('10250.00', (string) $p->amount);
        $this->assertSame('installment', $p->payment_type);
        $this->assertSame('stripe', $p->payment_method);
        $this->assertSame(2, (int) $p->installment_duration_months);
        $this->assertSame(30, (int) $p->installment_frequency_days);
        $this->assertSame('pending', $p->payment_status);
        $this->assertSame($a->id, (int) $p->design_agreement_id);

        $rows = InstallmentSchedule::where('order_id', $order->id)->orderBy('installment_no')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('10250.00', number_format($rows->sum('amount_due'), 2, '.', ''));
        $this->assertSame('5125.00', (string) $rows[0]->amount_due);

        $this->assertSame('ordered', $r->fresh()->pipeline_status);
        $e = CustomOrderEvent::where('type', 'order_placed')->sole();
        $this->assertSame($order->id, (int) $e->order_id);
        $this->assertSame('customer', $e->actor_type);

        $n = Notification::where('user_id', $c->id)->where('title', 'Order Created')->sole();
        $this->assertSame('custom_order', $n->type);
        $this->assertStringContainsString($order->order_number, $n->message);
        $this->assertStringContainsString('₱10,250.00', $n->message);

        $d = Delivery::where('order_id', $order->id)->sole();
        $this->assertSame('12 Mabini St, Batangas City', $d->delivery_address);

        // no stock handling at all
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('`products`', $sql, 'agreement checkout never reads or writes products');
            $this->assertStringNotContainsString('`cart_items`', $sql);
        }
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);
    }

    public function test_full_payment_flow_with_gcash(): void
    {
        [$c, , $a] = $this->accepted(null, [
            'payment_terms' => 'full', 'deposit_amount' => null,
            'installment_duration_months' => null, 'installment_frequency_days' => null,
            'delivery_option' => 'pickup', 'delivery_fee' => 0, 'delivery_address' => null,
        ]);

        $res = $this->place($c, $a, ['payment_method' => 'gcash']);

        $order = Order::where('design_agreement_id', $a->id)->firstOrFail();
        $res->assertRedirect(route('payment.gcash.paymongo', $order->id));
        $this->assertSame('20000.00', (string) $order->total_amount);
        $this->assertSame('pickup', $order->delivery_option);
        $this->assertSame('full', $order->payment->payment_type);
        $this->assertSame('20000.00', (string) $order->payment->amount);
        $this->assertSame('gcash', $order->payment->payment_method);
        $this->assertNull($order->payment->installment_duration_months);
        $this->assertSame(0, InstallmentSchedule::where('order_id', $order->id)->count());
        $this->assertSame(0, Delivery::where('order_id', $order->id)->count());
    }

    /** @dataProvider schedules */
    public function test_every_schedule_shape_is_built_from_the_snapshot(int $months, int $days, int $count): void
    {
        [$c, , $a] = $this->accepted(null, [
            'deposit_amount' => 10250.01, 'installment_duration_months' => $months, 'installment_frequency_days' => $days,
        ]);

        $this->place($c, $a)->assertRedirect();

        $order = Order::where('design_agreement_id', $a->id)->firstOrFail();
        $rows = InstallmentSchedule::where('order_id', $order->id)->get();
        $this->assertCount($count, $rows);
        $this->assertSame('10249.99', number_format($rows->sum('amount_due'), 2, '.', ''));
        $this->assertSame($months, (int) $order->payment->installment_duration_months);
        $this->assertSame($days, (int) $order->payment->installment_frequency_days);
    }

    public static function schedules(): array
    {
        return [
            '1 month / 15 days' => [1, 15, 2], '1 month / 30 days' => [1, 30, 1],
            '2 months / 15 days' => [2, 15, 4], '2 months / 30 days' => [2, 30, 2],
            '3 months / 15 days' => [3, 15, 6], '3 months / 30 days' => [3, 30, 3],
        ];
    }

    public function test_onsite_and_other_methods_are_refused(): void
    {
        [$c, , $a] = $this->accepted();

        foreach (['onsite', 'cash', 'bank_transfer', ''] as $m) {
            $this->place($c, $a, ['payment_method' => $m])->assertSessionHasErrors('payment_method');
        }
        $this->place($c, $a, [])->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Order::count());
        $this->assertSame('accepted', CustomDesignRequest::first()->pipeline_status);
    }

    public function test_double_submit_and_two_tabs_make_one_order(): void
    {
        [$c, , $a] = $this->accepted();

        $first = $this->place($c, $a, ['payment_method' => 'stripe']);
        $second = $this->place($c, $a, ['payment_method' => 'gcash']); // second tab, other method

        $this->assertSame(1, Order::where('design_agreement_id', $a->id)->count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CustomOrderEvent::where('type', 'order_placed')->count());

        $order = Order::first();
        $first->assertRedirect(route('payment.stripe', $order->id));
        $second->assertRedirect(route('payment.stripe', $order->id));   // sent to the existing order, its method kept
        $this->assertSame('stripe', $order->payment->payment_method);

        // and the checkout page itself now points at the order
        $this->actingAs($c)->get(route('custom.checkout', $a->id))->assertRedirect(route('orders.show', $order->id));
    }

    public function test_order_number_collision_is_retried(): void
    {
        [$c, , $a] = $this->accepted();
        // the next generated number is LP-<year>-00002 if one order exists
        $this->makeOrder($this->customer('Other'), ['order_number' => 'LP-' . date('Y') . '-00002']);
        $this->makeOrder($this->customer('Other2'), ['order_number' => 'LP-' . date('Y') . '-00001']);
        // count()+1 is now 3; occupy 3 so the first attempt collides
        $this->makeOrder($this->customer('Other3'), ['order_number' => 'LP-' . date('Y') . '-00004']);

        $this->place($c, $a)->assertRedirect();

        $order = Order::where('design_agreement_id', $a->id)->firstOrFail();
        $this->assertSame('LP-' . date('Y') . '-00005', $order->order_number);
    }

    /* ───────────── guards ───────────── */

    public function test_hash_tamper_blocks_checkout(): void
    {
        [$c, $r, $a] = $this->accepted();
        $s = $a->terms_snapshot;
        $s['money']['total'] = '1.00';
        $s['money']['upfront'] = '1.00';
        DB::table('design_agreements')->where('id', $a->id)->update(['terms_snapshot' => json_encode($s)]);

        $this->actingAs($c)->get(route('custom.checkout', $a->id))
            ->assertRedirect(route('design.show', $r->id))->assertSessionHasErrors('quote');
        $this->place($c, $a)->assertSessionHasErrors('quote');

        $this->assertSame(0, Order::count());
        $this->assertSame('accepted', $r->fresh()->pipeline_status);
        $this->assertSame(1, Notification::where('title', 'Agreement Integrity Check Failed')->count());
    }

    public function test_payment_window_is_enforced_before_and_after_the_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));
        [$c, $r, $a] = $this->accepted(null, ['expires_at' => now()->addHours(2)]);
        $due = $r->fresh()->payment_due_at;                      // acceptance + 24h
        $this->assertSame('2026-10-17 10:00:00', $due->timezone('Asia/Manila')->format('Y-m-d H:i:s'));

        Carbon::setTestNow($due->copy()->addMinute());
        $this->actingAs($c)->get(route('custom.checkout', $a->id))->assertRedirect(route('design.show', $r->id));
        $this->place($c, $a)->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::count());

        Carbon::setTestNow($due->copy()->subMinute());
        $this->actingAs($c)->get(route('custom.checkout', $a->id))->assertOk();
        $this->place($c, $a)->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_only_the_current_agreement_can_be_paid(): void
    {
        [$c, $r, $a] = $this->accepted();
        $admin = User::where('role', 'admin')->first();

        // a newer agreement on the same request (what an amendment would create)
        $q2 = $this->makeQuote($r, ['version' => 2, 'status' => 'accepted', 'sent_by' => $admin->id]);
        $this->makeAgreement($q2, $c, $admin);

        $this->place($c, $a)->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::count());
    }

    public function test_request_must_be_accepted(): void
    {
        [$c, $r, $a] = $this->accepted();
        $r->forceFill(['pipeline_status' => 'cancelled'])->save();

        $this->place($c, $a)->assertSessionHasErrors('quote');
        $this->assertSame(0, Order::count());
    }

    public function test_one_order_per_agreement_is_unique_in_the_database(): void
    {
        [$c, , $a] = $this->accepted();
        $this->place($c, $a)->assertRedirect();

        $dup = new Order();
        $dup->forceFill([
            'user_id' => $c->id, 'order_number' => 'LP-X-1', 'total_amount' => 1, 'delivery_option' => 'pickup',
            'order_status' => 'pending', 'design_agreement_id' => $a->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $dup->save();
    }
}
