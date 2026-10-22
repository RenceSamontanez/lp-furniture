<?php

namespace Tests\Feature;

use App\Models\AgreementPolicy;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\Product;
use App\Models\DesignAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 17 part B: where customers see the Terms and Conditions.
 */
class TermsPagesTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    private const WORDING = 'I have read and agree to the Terms and Conditions, including the cancellation and refund policy.';

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

    /* ───────────── /terms ───────────── */

    public function test_terms_page_is_public_and_shows_the_latest_policy(): void
    {
        $this->get('/terms')->assertOk()
            ->assertSee('Terms and Conditions')
            ->assertSee('Version 2')
            ->assertSee('Last updated')
            ->assertSee('What we will do')
            ->assertSee('Cancellation and refunds')
            ->assertSee('Your data and privacy');

        $this->assertSame(route('terms', [], false), '/terms');
    }

    public function test_terms_page_uses_the_newest_version_and_fills_in_contact_settings(): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::create(['phone' => '0917-555-0100', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/x']);
        Cache::forget(BusinessSetting::CACHE_KEY);

        $this->get('/terms')->assertOk()->assertSee('0917-555-0100')->assertSee('hello@lpfurniture.test')->assertDontSee('{contact_phone}', false);

        AgreementPolicy::create(['version' => 3, 'title' => 'Terms and Conditions', 'body' => "## Brand new\n\nThird wording."]);
        $this->get('/terms')->assertOk()->assertSee('Version 3')->assertSee('Brand new')->assertDontSee('What we will do');
    }

    public function test_terms_page_is_safe_when_there_is_no_policy_row(): void
    {
        DB::table('design_agreements')->delete();
        DB::table('agreement_policies')->delete();

        $this->get('/terms')->assertOk()->assertSee('not available right now');
    }

    public function test_footer_link_is_on_the_customer_layout_only(): void
    {
        $this->get(route('catalog'))->assertOk()->assertSee('href="' . route('terms') . '"', false)->assertSee('Terms and Conditions');
        $this->get(route('home'))->assertOk()->assertSee('href="' . route('terms') . '"', false);

        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('href="' . route('terms') . '"', false);
    }

    /* ───────────── the agreement ───────────── */

    private function quoted(): array
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);

        return [$customer, $r, $quote];
    }

    public function test_review_page_has_the_scrollable_policy_panel_and_the_new_wording(): void
    {
        [$customer, $r, $quote] = $this->quoted();

        $this->flushSession();
        $html = $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk()
            ->assertSee(self::WORDING)->assertDontSee('agree to these terms')->getContent();

        $this->assertMatchesRegularExpression('/<div class="co-policy-body" tabindex="0" role="region" aria-labelledby="policy-title">/', $html);
        $this->assertStringContainsString('What we will do', $html);
        $this->assertStringContainsString('Terms and Conditions', $html);

        $css = file_get_contents(public_path('assets/css/custom-orders.css'));
        $this->assertMatchesRegularExpression('/\.co-policy-body \{ max-height: 360px; overflow-y: auto;/', $css);
        $this->assertStringContainsString('.co-policy-body:focus-visible', $css);
    }

    public function test_accepting_without_ticking_still_fails_and_ticking_records_the_time(): void
    {
        [$customer, $r, $quote] = $this->quoted();

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), [])
            ->assertSessionHasErrors('agree');
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '0'])
            ->assertSessionHasErrors('agree');
        $this->assertSame(0, DesignAgreement::count());

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);
        $this->assertNotNull($a->accepted_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} /', $a->accepted_at_local);
        $this->assertSame(2, (int) $a->policy_version);
    }

    public function test_the_error_names_the_terms_and_conditions(): void
    {
        [$customer, $r, $quote] = $this->quoted();

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), [])
            ->assertSessionHasErrors(['agree' => 'Tick the box to confirm you agree to the Terms and Conditions.']);
    }

    public function test_agreement_page_shows_the_policy_in_the_panel(): void
    {
        [$customer, $r, $quote] = $this->quoted();
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);

        $this->flushSession();
        $html = $this->asCustomer($customer)->get(route('design.agreement', $a->id))->assertOk()->getContent();
        $this->assertStringContainsString('tabindex="0" role="region"', $html);
        $this->assertStringContainsString('Cancellation and refunds', $html);
    }

    public function test_the_print_view_shows_the_policy_version_used(): void
    {
        [$customer, $r, $quote] = $this->quoted();
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);

        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.agreement.print', $a->id))->assertOk()
            ->assertSee('Terms and Conditions')
            ->assertSee('Version 2')
            ->assertSee('Terms and Conditions (version 2)');
    }

    /* ───────────── the short reminders ───────────── */

    private function assertReminder(string $html, string $where): void
    {
        $this->assertStringContainsString('By continuing you agree to our', $html, $where);
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('terms'), '/') . '" target="_blank" rel="noopener" data-no-loading>Terms and Conditions<\/a>/', $html, $where);
    }

    public function test_reminders_are_on_the_send_form_custom_checkout_and_payment_pages(): void
    {
        [$customer, $r, $quote] = $this->quoted();

        // the send form needs a request that has not been sent yet
        [$r2, $s2] = $this->generatedRequest($customer);
        $this->flushSession();
        $this->assertReminder($this->asCustomer($customer)->get(route('design.sendForm', [$r2->id, 'suggestion' => $s2->id]))->assertOk()->getContent(), 'send form');

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);

        $this->flushSession();
        $this->assertReminder($this->asCustomer($customer)->get(route('custom.checkout', $a->id))->assertOk()->getContent(), 'custom checkout');

        $this->asCustomer($customer)->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = Order::where('design_agreement_id', $a->id)->sole();

        $this->flushSession();
        $this->assertReminder($this->asCustomer($customer)->get(route('payment.stripe', $order->id))->assertOk()->getContent(), 'Stripe page');

        $order->payment->update(['payment_method' => 'gcash']);
        $this->assertReminder($this->asCustomer($customer)->get(route('payment.gcash.paymongo', $order->id))->assertOk()->getContent(), 'GCash page');
    }

    public function test_the_reminder_link_never_triggers_the_loading_overlay(): void
    {
        $partial = file_get_contents(resource_path('views/partials/terms-reminder.blade.php'));
        $this->assertStringContainsString('data-no-loading', $partial);
        $this->assertStringContainsString('target="_blank"', $partial);

        $js = file_get_contents(public_path('assets/js/co-loading.js'));
        $this->assertStringContainsString("link.hasAttribute('data-no-loading')", $js);
    }
}
