<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Smart nav, focus pages and glass toasts (UI rework, session A).
 * Layout structure only: the look is checked in the browser walk (tests/UiLinks/browser-nav.cjs).
 */
class SmartNavTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function assertToastContainer(string $html): void
    {
        $this->assertStringContainsString('id="lp-toasts"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('assets/css/alerts.css', $html);
        $this->assertStringContainsString('assets/js/alerts.js', $html);
    }

    private function assertNoNavNoFooter(string $html): void
    {
        $this->assertStringNotContainsString('id="site-nav"', $html);
        $this->assertStringNotContainsString('class="site-nav"', $html);
        $this->assertStringNotContainsString('lp-footer', $html);
        $this->assertStringNotContainsString('id="chatbot-window"', $html);
        $this->assertStringContainsString('class="focus-logo"', $html);
        $this->assertToastContainer($html);
    }

    public function test_focus_pages_have_no_nav_and_no_footer(): void
    {
        foreach ([route('login'), route('register'), route('password.request'), route('password.reset', ['token' => 'abc'])] as $url) {
            $this->assertNoNavNoFooter($this->get($url)->assertOk()->getContent());
        }

        $user = $this->makeUser();
        $user->forceFill(['is_validated' => false])->save();
        $this->assertNoNavNoFooter($this->actingAs($user)->get(route('validation.show'))->assertOk()->getContent());

        $res = $this->actingAs($user)->get(route('verification.notice'));
        if ($res->status() === 200) {
            $this->assertNoNavNoFooter($res->getContent());
        }
    }

    public function test_focus_pages_keep_their_back_controls_and_forms(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('lp-btn--back', $html);
        $this->assertStringContainsString('Back to Home', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function test_customer_pages_render_the_smart_nav_with_cart_and_notification_labels(): void
    {
        $user = $this->makeUser();

        foreach ([route('home'), route('catalog'), route('cart.index'), route('profile')] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('id="site-nav"', $html);
            $this->assertStringContainsString('<span class="sn-label">Cart</span>', $html);
            $this->assertStringContainsString('<span class="sn-label">Notifications</span>', $html);
            $this->assertStringContainsString('>Reviews</a>', $html);
            $this->assertStringContainsString('>My Account</a>', $html);
            $this->assertStringContainsString('Customize', $html);
            $this->assertStringContainsString('nav-chatbot-reactivate', $html);
            $this->assertStringContainsString('assets/js/site-nav.js', $html);
            $this->assertStringContainsString('class="lp-toasts lp-toasts--nav"', $html);
            $this->assertToastContainer($html);
            $this->assertStringContainsString('<body class="lp-type" data-nav="light">', $html);
        }
    }

    public function test_guests_see_the_guest_set_and_the_assistant(): void
    {
        $html = $this->get(route('catalog'))->assertOk()->getContent();
        $this->assertStringContainsString('id="site-nav"', $html);
        $this->assertStringContainsString('nav-chatbot-reactivate', $html);
        $this->assertStringContainsString('>Login</a>', $html);
        $this->assertStringContainsString('>Register</a>', $html);
        $this->assertStringNotContainsString('<span class="sn-label">Cart</span>', $html);
        // the Assistant link is always visible now (it used to be hidden until Luma was dismissed)
        $this->assertStringNotContainsString('display:none', substr($html, strpos($html, 'nav-chatbot-reactivate'), 300));
    }

    /** Every guest-reachable page that uses the nav carries the same nav markup, theme markers and scripts as it does for a customer. */
    public function test_guests_get_the_same_nav_assets_and_theme_markers_as_customers(): void
    {
        $pages = [route('home'), route('catalog'), route('terms'), route('services.terms'), route('guide'), route('feedback.index')];
        $customer = $this->makeUser();

        $markers = function (string $html): array {
            return [
                'nav'      => substr_count($html, 'id="site-nav"'),
                'burger'   => substr_count($html, 'id="sn-burger"'),
                'navjs'    => preg_match('#assets/js/site-nav\.js\?v=\d+"\s+defer#', $html),
                'navcss'   => preg_match('#assets/css/site-nav\.css\?v=\d+#', $html),
                'body'     => substr_count($html, '<body class="lp-type" data-nav="light">'),
                'dark'     => substr_count($html, 'data-nav="dark"'),
                'overlays' => substr_count($html, 'lp-modal.js'),
            ];
        };

        foreach ($pages as $url) {
            $guest = $markers($this->get($url)->assertOk()->getContent());
            $this->assertSame(1, $guest['nav'], "guest nav missing on $url");
            $this->assertSame(1, $guest['navjs'], "site-nav.js missing for a guest on $url");
            $this->assertSame(1, $guest['navcss'], "site-nav.css missing for a guest on $url");
            $this->assertSame(1, $guest['body'], "body data-nav missing for a guest on $url");
            $this->assertGreaterThanOrEqual(1, $guest['dark'], "dark footer marker missing for a guest on $url");

            $signedIn = $markers($this->actingAs($customer)->get($url)->assertOk()->getContent());
            $this->assertSame($signedIn, $guest, "guest and customer differ on $url");
            auth()->logout();
        }

        // the product page too
        $product = \App\Models\Product::query()->first();
        if ($product) {
            $this->assertSame(1, $markers($this->get(route('product.show', $product->id))->assertOk()->getContent())['navjs']);
        }
    }

    public function test_the_home_hero_declares_itself_dark(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="hero-v2 lp-hero" data-nav="dark"', $html);
        $this->assertStringContainsString('id="sn-burger"', $html);
    }

    public function test_the_toast_container_exists_in_the_admin_without_a_nav(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->withSession(['error' => 'Nope', 'success' => 'Yes'])
            ->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertToastContainer($html);
        $this->assertStringContainsString('class="lp-toasts" id="lp-toasts"', $html);
        $this->assertStringNotContainsString('lp-toasts--nav', $html);
        $this->assertStringNotContainsString('id="site-nav"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('role="status"', $html);

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $login = $this->get(route('admin.login'))->assertOk()->getContent();
        $this->assertToastContainer($login);
    }

    public function test_toasts_use_status_for_the_calm_types_and_alert_for_errors_with_their_own_timers(): void
    {
        $html = $this->withSession(['success' => 'Saved', 'warning' => 'Careful', 'info' => 'FYI'])->get(route('catalog'))->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'role="status"'));
        $this->assertSame(0, substr_count($html, 'role="alert"'));
        $this->assertSame(3, substr_count($html, 'data-lp-toast-timeout="5000"'));

        $html = $this->withSession(['error' => 'Broken'])->get(route('catalog'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'role="alert"'));
        $this->assertStringContainsString('data-lp-toast-timeout="8000"', $html);
    }

    public function test_the_stylesheet_and_script_carry_the_approved_values(): void
    {
        $css = file_get_contents(public_path('assets/css/site-nav.css'));
        foreach ([
            'rgba(36, 21, 13, .6)', 'blur(22px) saturate(1.6)', 'rgba(255, 236, 200, .3)', 'rgba(255, 252, 246, .62)', 'rgba(90, 53, 36, .18)',
            '1.6px rgba(24, 12, 7, .62)', 'paint-order: stroke fill', 'scale(.84)', 'max-width: 1000px', 'body.modal-open nav.site-nav', 'prefers-reduced-motion',
        ] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }

        $js = file_get_contents(public_path('assets/js/site-nav.js'));
        foreach (['HIDE_AFTER = 140', 'DELTA = 8', 'TOP = 40', 'elementsFromPoint', 'data-nav', "toggleAttribute('inert'", '(pointer: coarse)'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }

        $this->assertStringContainsString("classList.add('lp-modal-open', 'modal-open')", file_get_contents(public_path('assets/js/lp-modal.js')));
        $this->assertStringContainsString("toggle('modal-open'", file_get_contents(public_path('assets/main.js')));
    }
}
