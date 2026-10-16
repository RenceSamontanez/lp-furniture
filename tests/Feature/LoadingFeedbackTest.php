<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 13 part D: the shared loading feedback is on every page of the
 * custom order flow, and the script keeps the existing guards intact.
 */
class LoadingFeedbackTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    public function test_script_and_stylesheet_exist_with_the_required_behaviour(): void
    {
        $js = file_get_contents(public_path('assets/js/co-loading.js'));

        $this->assertStringContainsString("addEventListener('submit'", $js);
        $this->assertStringContainsString('button.disabled = true', $js);          // disables
        $this->assertStringContainsString('co-spinner', $js);                      // spinner
        $this->assertStringContainsString("'Sending...'", $js);                    // short labels
        $this->assertStringContainsString("'Saving...'", $js);
        $this->assertStringContainsString("'Processing...'", $js);
        $this->assertStringContainsString('e.defaultPrevented', $js);              // a cancelled confirm is left alone
        $this->assertStringContainsString("form.getAttribute('data-submitting') === '1'", $js); // no double submit
        $this->assertStringContainsString('pageshow', $js);                        // re-enabled when restored
        $this->assertStringContainsString('data-co-hidden', $js);                  // keeps a named button's value

        $css = file_get_contents(public_path('assets/css/co-loading.css'));
        $this->assertStringContainsString('@keyframes co-spin', $css);
    }

    public function test_every_flow_page_includes_it(): void
    {
        $admin = $this->makeUser('admin');
        $c = $this->makeUser();
        $r = CustomDesignRequest::create([
            'user_id' => $c->id, 'title' => 'Table', 'furniture_type' => 'Table', 'description' => 'A table',
            'status' => 'approved', 'pipeline_status' => 'submitted', 'contact_phone' => '09171234567',
            'contact_email' => 'c@example.com', 'preferred_call_window' => 'anytime', 'request_mode' => 'exact',
            'submitted_at' => now(),
        ]);

        $customerPages = [
            route('design.show', $r->id),
            route('design.edit', $r->id),
            route('notifications.index'),
        ];
        foreach ($customerPages as $url) {
            $this->actingAs($c)->get($url)->assertOk()->assertSee('assets/js/co-loading.js', false);
        }

        $adminPages = [
            route('admin.custom-orders.index'),
            route('admin.custom-orders.show', $r->id),
            route('admin.custom-orders.quotes.create', $r->id),
            route('admin.notifications.index'),
        ];
        foreach ($adminPages as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertOk()->assertSee('assets/js/co-loading.js', false);
        }
    }

    public function test_existing_one_time_token_form_is_untouched(): void
    {
        $admin = $this->makeUser('admin');
        $c = $this->makeUser();
        $order = $this->makeOrder($c, ['total_amount' => 1000]);
        $this->makePayment($order, ['amount' => 1000, 'payment_method' => 'onsite']);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.orders.show', $order->id))->assertOk()->getContent();

        $this->assertStringContainsString('name="form_token"', $html);
        $this->assertStringContainsString('assets/js/co-loading.js', $html);
    }
}
