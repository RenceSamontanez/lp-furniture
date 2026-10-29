<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Polish sweep part 1 (markup level; the pixel/size/contrast/tap checks live in tests/UiLinks/browser-polish-part1.cjs):
 * the shared password Show/Hide, the admin ticket photo link, the 44px "Mark read" buttons, the Order Now colour and the
 * removed dead nav rules.
 */
class PolishPartOneTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function assertToggle(string $html, int $count): void
    {
        $this->assertSame($count, substr_count($html, 'data-password-toggle="'), 'toggle buttons');
        $this->assertSame($count, substr_count($html, 'aria-pressed="false"'), 'aria-pressed on each');
        $this->assertSame($count, substr_count($html, 'aria-label="Show password"'), 'accessible name on each');
        $this->assertStringContainsString('type="button" class="password-toggle"', $html);
        $this->assertStringContainsString('assets/js/password-toggle.js', $html);
        $this->assertStringContainsString('assets/css/password-toggle.css', $html);
        $this->assertStringNotContainsString('togglePassword', $html, 'no per-page inline handler');
    }

    public function test_every_password_page_uses_the_shared_toggle(): void
    {
        $this->assertToggle($this->get(route('login'))->assertOk()->getContent(), 1);
        $this->assertToggle($this->get(route('register'))->assertOk()->getContent(), 2);
        $this->assertToggle($this->get(route('password.reset', 'sometoken'))->assertOk()->getContent(), 2);
        $this->assertToggle($this->get(route('admin.login'))->assertOk()->getContent(), 1);

        $this->actingAs($this->makeUser(), 'web');
        $this->assertToggle($this->get(route('profile'))->assertOk()->getContent(), 3);   // current, new and confirm on the Security tab
    }

    public function test_the_toggle_script_only_flips_type_and_label_and_never_touches_the_value(): void
    {
        $js = file_get_contents(public_path('assets/js/password-toggle.js'));
        $this->assertStringContainsString("aria-pressed", $js);
        $this->assertStringNotContainsString('.value', $js);
        $this->assertStringNotContainsString('localStorage', $js);
        $this->assertStringNotContainsString('fetch(', $js);
        $this->assertStringNotContainsString('console.', $js);
        $css = file_get_contents(public_path('assets/css/password-toggle.css'));
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertStringContainsString(':focus-visible', $css);
    }

    public function test_the_admin_ticket_photo_link_opens_safely_in_a_new_tab_and_stays_admin_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('service-photos/leg.jpg', 'x');
        $ticket = ServiceInquiry::create([
            'reference_number' => 'SI-2026-' . random_int(10000, 99999), 'service_type' => 'repair', 'concern' => 'Needs work.',
            'name' => 'Juan', 'phone' => '09171234567', 'email' => 'juan@example.com', 'status' => 'new',
            'user_id' => $this->makeUser()->id, 'reference_photos' => ['service-photos/leg.jpg'],
        ]);

        $this->actingAs($this->makeUser('admin'), 'admin');
        $page = $this->get(route('admin.service-inquiries.show', $ticket->id))->assertOk();
        $page->assertSee('target="_blank" rel="noopener noreferrer" class="si-photo"', false);
        $page->assertSee('aria-label="Open reference photo 1 of ' . $ticket->reference_number . ' in a new tab"', false);
        $page->assertSee('alt="Reference photo 1 of ' . $ticket->reference_number . '"', false);
        $this->assertMatchesRegularExpression('/\.si-photo \{[^}]*width: 120px; height: 120px/', file_get_contents(public_path('assets/css/admin-service-invoices.css')));

        $this->app['auth']->forgetGuards();
        $this->get(route('admin.service-inquiries.photo', [$ticket->id, 0]))->assertRedirect(route('admin.login'));
    }

    public function test_admin_mark_read_buttons_use_the_shared_44px_button(): void
    {
        $admin = $this->makeUser('admin');
        Notification::create(['user_id' => $admin->id, 'title' => 'Unread', 'message' => 'x', 'type' => 'custom_order', 'is_read' => false]);
        $this->actingAs($admin, 'admin');
        $html = $this->get(route('admin.notifications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('class="lp-btn lp-btn--secondary" aria-label="Mark read: Unread">Mark Read</button>', $html);
        $this->assertStringNotContainsString('admin-btn-outline">Mark read', $html);
    }

    public function test_card_buttons_use_the_dark_brown_text_not_the_low_contrast_gold(): void
    {
        $css = file_get_contents(public_path('assets/css/product-card.css'));
        $this->assertStringContainsString('.lp-pcard__btn.lp-btn.lp-btn--secondary { border-color: var(--pc-gold); color: #6A3E1E; }', $css);
        $this->assertStringNotContainsString('btn-view-details', file_get_contents(public_path('assets/style.css')));
    }

    public function test_the_related_product_card_opens_quick_view_from_the_picture_and_never_navigates_by_script(): void
    {
        $blade = file_get_contents(resource_path('views/components/product-card.blade.php'));
        $this->assertStringContainsString('class="lp-pcard__media" data-quick-view=', $blade);
        $this->assertStringNotContainsString('window.location', $blade);
    }

    public function test_the_old_nav_rules_are_gone_from_the_stylesheet_but_the_bare_nav_rule_stays(): void
    {
        $css = file_get_contents(public_path('assets/style.css'));
        foreach (['.nav-links', '.nav-btn', '.nav-burger', '.nav-overlay', '.nav-icon', '.nav-brand', '.nav-user', '.nav-account', '.nav-logout-form'] as $dead) {
            $this->assertStringNotContainsString($dead, $css, "$dead should be removed");
        }
        $this->assertMatchesRegularExpression('/^nav \{/m', $css);
    }
}
