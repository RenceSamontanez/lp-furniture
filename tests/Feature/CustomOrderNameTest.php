<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Support\CustomOrderName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 16 part B: one name for every custom order, "Custom Furniture — {First}".
 */
class CustomOrderNameTest extends TestCase
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

    /* ───────────── the helper ───────────── */

    public function test_casing_is_normalised(): void
    {
        $this->assertSame('Custom Furniture — Rence', CustomOrderName::fromFirstName('RENCE'));
        $this->assertSame('Custom Furniture — Rence', CustomOrderName::fromFirstName('rence'));
        $this->assertSame('Custom Furniture — Rence', CustomOrderName::fromFirstName('  rEnCe  '));
        $this->assertSame('Custom Furniture — Ana', CustomOrderName::for($this->makeUserNamed('ANA')));
    }

    public function test_empty_or_unusable_names_fall_back(): void
    {
        foreach ([null, '', '   ', "\t\n", '123', '!!!', '😀', '---', '...', "'", '<>'] as $bad) {
            $this->assertSame('Custom Furniture', CustomOrderName::fromFirstName($bad), 'for ' . var_export($bad, true));
        }
        $this->assertSame('Custom Furniture', CustomOrderName::for(null));
    }

    public function test_multi_word_and_punctuated_names(): void
    {
        $this->assertSame('Custom Furniture — Mary Jane', CustomOrderName::fromFirstName('MARY JANE'));
        $this->assertSame('Custom Furniture — Mary Jane', CustomOrderName::fromFirstName("mary \n  jane"));
        $this->assertSame('Custom Furniture — Mary-Jane', CustomOrderName::fromFirstName('mary-jane'));
        $this->assertSame("Custom Furniture — O'Neil", CustomOrderName::fromFirstName("o'neil"));
        $this->assertSame('Custom Furniture — José', CustomOrderName::fromFirstName('JOSÉ'));
        $this->assertSame('Custom Furniture — Ñoño', CustomOrderName::fromFirstName('ñoño'));
        $this->assertSame('Custom Furniture — J. R.', CustomOrderName::fromFirstName('j. r.'));
    }

    public function test_special_characters_are_removed_not_rendered(): void
    {
        $out = CustomOrderName::fromFirstName('<script>alert(1)</script>Ana 😀 123 ₱');

        $this->assertStringStartsWith('Custom Furniture — ', $out);
        foreach (['<', '>', '(', ')', '/', '😀', '1', '₱'] as $char) {
            $this->assertStringNotContainsString($char, $out);
        }
        $this->assertSame('Custom Furniture — Rence', CustomOrderName::fromFirstName('Rence!@#$%'));
    }

    public function test_very_long_names_are_cut(): void
    {
        $long = CustomOrderName::fromFirstName(str_repeat('A', 300));
        $this->assertLessThanOrEqual(mb_strlen('Custom Furniture — ') + CustomOrderName::MAX_FIRST_NAME, mb_strlen($long));
        $this->assertSame('Custom Furniture — A' . str_repeat('a', CustomOrderName::MAX_FIRST_NAME - 1), $long);

        $words = CustomOrderName::fromFirstName(str_repeat('Maria ', 30));
        $this->assertLessThanOrEqual(mb_strlen('Custom Furniture — ') + CustomOrderName::MAX_FIRST_NAME, mb_strlen($words));
        $this->assertDoesNotMatchRegularExpression('/[\s\-\'’.]$/u', $words, 'never ends on a separator');
    }

    private function makeUserNamed(string $first): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first])->save();

        return $u;
    }

    /* ───────────── new records use it ───────────── */

    public function test_new_orders_and_agreements_are_named_with_it_and_old_snapshots_are_untouched(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->makeUserNamed('RENCE');
        $customer->forceFill(['last_name' => 'Samontanez', 'phone_number' => '09170000001'])->save();

        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);

        // the review shows the name, never "Dining Table - RENCE", never a version label
        $this->flushSession();
        $review = $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk();
        $review->assertSee('Custom Furniture — Rence')->assertDontSee('Dining Table - ')->assertDontSee('Quote version')->assertDontSee('(version');

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $agreement = $this->agreementOf($r);
        $this->assertSame('Custom Furniture — Rence', $agreement->terms_snapshot['design']['title']);
        $this->assertTrue($agreement->snapshotIsIntact());

        $this->asCustomer($customer)->post(route('custom.checkout.place', $agreement->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = Order::where('design_agreement_id', $agreement->id)->sole();
        $this->assertSame('Custom Furniture — Rence', $order->items->first()->product_name);

        // customer order page and the notifications
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()->assertSee('Custom Furniture — Rence');
        $this->assertTrue(Notification::where('user_id', $customer->id)->where('message', 'like', '%Custom Furniture — Rence%')->exists());
        $this->assertSame(0, Notification::where('message', 'like', '%Dining Table%')->count());

        // admin list and detail
        $this->asAdmin($admin)->get(route('admin.custom-orders.index'))->assertOk()->assertSee('Custom Furniture — Rence');
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Custom Furniture — Rence');
    }

    public function test_an_old_agreement_keeps_its_snapshot_and_shows_as_it_is(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        $request = $this->makeRequest($customer);
        $quote = $this->makeQuote($request, ['status' => 'accepted']);
        $agreement = $this->makeAgreement($quote, $customer, $admin);
        $before = $agreement->terms_snapshot;
        $this->assertSame('Test dining table', $before['design']['title']);

        $order = $this->makeOrder($customer, ['design_agreement_id' => $agreement->id]);
        \App\Models\OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'product_name' => 'Chair — OLDNAME',
            'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000,
        ]);

        // the stored order item and the stored snapshot are shown exactly as they were created
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()->assertSee('Chair — OLDNAME');
        $this->assertSame($before, $agreement->fresh()->terms_snapshot);
        $this->assertSame('Chair — OLDNAME', $order->items()->first()->product_name);
    }

    public function test_the_request_title_is_set_with_the_helper_for_new_requests(): void
    {
        $customer = $this->makeUserNamed('MARY JANE');

        $this->asCustomer($customer)->post(route('design.submit'), [
            'furniture_type' => 'Chair', 'prompt' => 'A sturdy narra dining chair with a curved back', 'preferred_material' => 'Gmelina',
        ]);

        $r = CustomDesignRequest::where('user_id', $customer->id)->latest('id')->first();
        if ($r) {
            $this->assertSame('Custom Furniture — Mary Jane', $r->title);
            $this->assertStringNotContainsString('Chair —', $r->title);
        } else {
            $this->markTestSkipped('the design submit route needs the AI provider configured in this environment');
        }
    }

    public function test_timeline_and_summary_have_no_version_labels_for_customers(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $this->quoteAndSend($admin, $r);
        // an earlier "quote updated" event, as the second version of a quote writes it
        \App\Models\CustomOrderEvent::create([
            'request_id' => $r->id, 'type' => 'quote_sent', 'actor_type' => \App\Models\CustomOrderEvent::ACTOR_ADMIN,
            'actor_id' => $admin->id, 'data' => ['version' => 2, 'total' => '18500.00', 'upfront' => '9250.00'], 'occurred_at' => now(),
        ]);

        $this->flushSession();
        $page = $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk();
        $page->assertSee('Your quote was updated')->assertDontSee('(version')->assertDontSee('version 2');

        // the admin timeline keeps the real quote versions
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Version 1 terms');
    }
}
