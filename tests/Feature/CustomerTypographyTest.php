<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer typography (docs/fonts/typography-specimen-v2.html): the customer layouts load the self-hosted fonts and no Google
 * Fonts URL, the admin layout is unchanged, and every customer "back" control is the one shared component.
 */
class CustomerTypographyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        $u = new User();
        $u->forceFill([
            'first_name' => 'Type', 'last_name' => 'Tester', 'email' => 'type' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => 'correct-horse-battery', 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
            'email_verified_at' => now(), 'phone_number' => '09171234567',
        ])->save();

        return $u;
    }

    public function test_storefront_layout_loads_self_hosted_fonts_and_no_google_fonts(): void
    {
        $html = $this->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString('assets/css/fonts.css', $html);
        $this->assertStringContainsString('assets/css/customer-type.css', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*class="[^"]*\blp-type\b/', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_focus_layout_loads_self_hosted_fonts_and_no_google_fonts(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('assets/css/fonts.css', $html);
        $this->assertStringContainsString('assets/css/customer-type.css', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*class="[^"]*\blp-type\b/', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_admin_layout_never_loads_the_customer_stylesheet(): void
    {
        // the admin has its own typography sheet (AdminTypographyTest); it must still never load the customer one
        $admin = $this->user('admin');
        $html = $this->actingAs($admin, 'admin')->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringNotContainsString('customer-type.css', $html);
        $this->assertDoesNotMatchRegularExpression('/<body[^>]*lp-type/', $html);
        $this->assertStringContainsString('assets/css/admin.css', $html);
    }

    public function test_font_faces_cover_the_weights_the_pages_use(): void
    {
        $css = file_get_contents(public_path('assets/css/fonts.css'));

        $this->assertStringContainsString("font-family: 'Cormorant Garamond'", $css);
        $this->assertStringContainsString('size-adjust: 122%', $css);
        $this->assertStringContainsString('font-display: swap', $css);
        $this->assertStringNotContainsString('googleapis', $css);
        $this->assertStringNotContainsString('latin-700', $css);
    }

    public function test_customer_stylesheet_is_scoped_to_the_customer_body(): void
    {
        $css = file_get_contents(public_path('assets/css/customer-type.css'));
        // nothing outside .lp-type: no bare body / html / :root / heading / .lp-btn rule can reach the admin
        $stripped = preg_replace('~/\*.*?\*/~s', '', $css);
        $this->assertStringContainsString('.lp-type {', $css);
        $this->assertDoesNotMatchRegularExpression('/^(body|html|:root|h1|\.lp-btn)\b/m', $stripped);
    }

    public function test_back_button_component_renders_as_a_ghost_link_and_as_a_button(): void
    {
        $link = view('components.back-link', ['href' => '/orders', 'label' => 'My Orders', 'text' => null, 'type' => null, 'attributes' => new \Illuminate\View\ComponentAttributeBag()])->render();
        $this->assertStringContainsString('href="/orders"', $link);
        $this->assertStringContainsString('lp-btn lp-btn--secondary lp-btn--back', $link);
        $this->assertStringContainsString('Back to My Orders', $link);

        $btn = \Illuminate\Support\Facades\Blade::render('<x-back-link type="button" label="cart" onclick="go()" />');
        $this->assertStringContainsString('<button type="button"', $btn);
        $this->assertStringContainsString('onclick="go()"', $btn);
        $this->assertStringContainsString('Back', $btn);
    }

    public function test_back_button_appears_on_customer_pages(): void
    {
        foreach (['/login' => 'Back to Home', '/register' => 'Back to Sign In'] as $url => $words) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('lp-btn--back', $html, $url);
            $this->assertStringContainsString($words, $html, $url);
            $this->assertStringNotContainsString('auth-back-btn', $html, $url);
        }

        $user = $this->user();
        $html = $this->actingAs($user)->get('/orders')->assertOk()->getContent();
        $this->assertStringContainsString('lp-btn--back', $html);
        $this->assertStringContainsString('Back to Home', $html);
        $this->assertStringNotContainsString('orders-back-link', $html);

        $html = $this->actingAs($user)->get('/design')->assertOk()->getContent();
        $this->assertStringContainsString('lp-btn--back', $html);
    }

    public function test_no_customer_view_still_hand_styles_a_back_link(): void
    {
        foreach (['auth-back-btn', 'cd-back-btn', 'orders-back-link', 'payment-back-link', 'stripe-back-btn', 'checkout-back-link', 'checkout-screen-back"'] as $old) {
            $hits = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
            foreach ($it as $f) {
                if ($f->isFile() && str_contains($f->getPathname(), 'admin') === false && str_contains(file_get_contents($f->getPathname()), 'class="' . $old)) {
                    $hits[] = $f->getFilename();
                }
            }
            $this->assertSame([], $hits, "old back markup '{$old}' is still used");
        }
    }

    public function test_error_page_uses_self_hosted_fonts(): void
    {
        $src = file_get_contents(resource_path('views/errors/500.blade.php'));
        $this->assertStringContainsString('/assets/css/fonts.css', $src);
        $this->assertStringNotContainsString('DM Sans', $src);
        $this->assertStringNotContainsString('Playfair', $src);
    }
}
