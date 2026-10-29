<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** The shared customer footer (v11 "Ember Glass", partials/site-footer) and its Contact Settings fields. */
class SiteFooterTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        $row = BusinessSetting::query()->orderBy('id')->first() ?? new BusinessSetting();
        $row->forceFill($values + [
            'phone' => '', 'email' => '', 'facebook_url' => '', 'tiktok_url' => null,
            'shop_address' => null, 'shop_landmarks' => null, 'map_lat' => null, 'map_lng' => null,
        ])->save();
    }

    /** No built-in fallbacks, so a test sees exactly what Contact Settings hold. */
    private function noFallbacks(): void
    {
        config(['shop.fallback' => array_fill_keys(['facebook_url', 'tiktok_url', 'phone', 'email', 'shop_address', 'shop_landmarks', 'map_lat', 'map_lng'], null)]);
    }

    private function home(): string
    {
        return $this->get(route('home'))->assertOk()->getContent();
    }

    public function test_footer_renders_once_on_the_home_page_and_inner_pages(): void
    {
        foreach ([route('home'), route('catalog'), route('terms')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<footer'), $url);
            $this->assertStringContainsString('<footer class="sf" id="footer-section" data-nav="dark">', $html);
            $this->assertStringContainsString('assets/css/site-footer.css', $html);
            $this->assertStringNotContainsString('class="lp-footer"', $html);
            $this->assertStringContainsString('Visit Our Shop', $html);
            $this->assertStringContainsString('Ways to Pay', $html);
            $this->assertStringContainsString('+ more ways to pay', $html);
            $this->assertStringContainsString('&copy; ' . now()->year . ' LP Furniture. All rights reserved.', str_replace('©', '&copy;', $html));
            // the giant wordmark is decoration only
            $this->assertStringContainsString('<div class="sf__word" aria-hidden="true">', $html);
            // removed from the old footer
            $this->assertStringNotContainsString('sf__about', $html);
            $this->assertStringNotContainsString('sf__logo', $html);
        }
    }

    public function test_the_three_columns_are_in_order_and_social_sits_under_contact(): void
    {
        $this->settings(['phone' => '0917 123 4567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lp', 'tiktok_url' => 'https://www.tiktok.com/@lp']);
        $html = $this->home();
        $order = array_map(fn ($s) => strpos($html, $s), ['<h2>Visit Our Shop', '<h2>Contact Us', 'Social Media</h2>', '<h2>Ways to Pay', 'sf__pol', 'sf__word"']);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertNotContains(false, $order);
    }

    public function test_footer_is_absent_on_focus_pages_and_the_admin(): void
    {
        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $this->assertStringNotContainsString('class="sf"', $this->get($url)->assertOk()->getContent());
        }

        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="sf"', $html);
        $this->assertStringNotContainsString('sf__word', $html);
    }

    public function test_placeholders_never_show_and_the_config_fallbacks_fill_in(): void
    {
        $this->settings(BusinessSetting::DEFAULTS);
        $html = $this->home();
        $this->assertStringNotContainsString('0917-000-0000', $html);
        $this->assertStringNotContainsString('contact@example.com', $html);
        $this->assertStringNotContainsString('your-page', $html);
        // fallbacks from config/shop.php
        $this->assertStringContainsString('0991 239 4770', $html);
        $this->assertStringContainsString('href="tel:+639912394770"', $html);
        $this->assertStringContainsString('href="mailto:johncedricklumanglas@gmail.com"', $html);
        $this->assertStringContainsString('href="https://www.facebook.com/lpfurnituresj"', $html);
        $this->assertStringContainsString('Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas', $html);
        $this->assertStringContainsString('San Juan Doctors Hospital', $html);
    }

    public function test_contact_settings_win_over_the_fallbacks(): void
    {
        $this->settings(['phone' => '0917 123 4567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture', 'shop_address' => 'Test Street 1, Test Town', 'shop_landmarks' => 'Test Landmark']);
        $html = $this->home();
        $this->assertStringContainsString('0917 123 4567', $html);
        $this->assertStringContainsString('href="tel:+639171234567"', $html);
        $this->assertStringContainsString('href="mailto:hello@lpfurniture.test"', $html);
        $this->assertStringContainsString('href="https://facebook.com/lpfurniture"', $html);
        $this->assertStringContainsString('Test Street 1, Test Town', $html);
        $this->assertStringContainsString('Test Landmark', $html);
        $this->assertStringNotContainsString('0991 239 4770', $html);
        $this->assertStringNotContainsString('Sitio Pulo Ilaya', $html);
    }

    public function test_rows_are_hidden_when_empty_and_the_whole_contact_block_goes_with_them(): void
    {
        $this->noFallbacks();
        $this->settings(['email' => 'hello@lpfurniture.test']);
        $html = $this->home();
        $this->assertStringNotContainsString('href="tel:', $html);
        $this->assertStringContainsString('mailto:hello@lpfurniture.test', $html);
        $this->assertStringNotContainsString('Social Media', $html);

        $this->settings([]);
        $html = $this->home();
        $this->assertStringNotContainsString('<h2>Contact Us</h2>', $html);
        $this->assertStringNotContainsString('aria-label="Contact"', $html);
        $this->assertStringContainsString('Ways to Pay', $html);
    }

    public function test_tiktok_is_hidden_when_empty_and_shown_with_target_and_rel_when_set(): void
    {
        $this->settings(['facebook_url' => 'https://facebook.com/lpfurniture']);
        $html = $this->home();
        $this->assertStringContainsString('Social Media', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer" aria-label="Facebook (opens in a new tab)"', $html);
        $this->assertStringNotContainsString('TikTok', $html);
        $this->assertStringNotContainsString('href="#"', $html);

        $this->settings(['facebook_url' => 'https://facebook.com/lpfurniture', 'tiktok_url' => 'https://www.tiktok.com/@lpfurniture']);
        $html = $this->home();
        $this->assertStringContainsString('href="https://www.tiktok.com/@lpfurniture" target="_blank" rel="noopener noreferrer" aria-label="TikTok (opens in a new tab)"', $html);
        // stacked: Facebook first, TikTok after
        $this->assertLessThan(strpos($html, 'aria-label="TikTok'), strpos($html, 'aria-label="Facebook'));
    }

    public function test_an_invalid_stored_link_renders_nothing(): void
    {
        $this->noFallbacks();
        $this->settings(['facebook_url' => 'javascript:alert(1)', 'tiktok_url' => 'http://tiktok.com/@x']);
        $html = $this->home();
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Social Media', $html);
    }

    public function test_policy_links_show_only_for_routes_that_exist(): void
    {
        $html = $this->home();
        $pol = substr($html, strpos($html, 'class="sf__pol"'));
        $pol = substr($pol, 0, strpos($pol, '</ul>'));

        $this->assertStringContainsString('href="' . route('terms') . '">Terms and Conditions</a>', $pol);
        $this->assertStringContainsString('href="' . route('services.terms') . '">Other Services Terms</a>', $pol);
        $this->assertStringContainsString('href="' . route('guide') . '">Custom Design Guide</a>', $pol);
        foreach (['Privacy Policy', 'Refund and Cancellation', 'Payment Terms', 'Delivery and Installation', 'Warranty and Care'] as $pending) {
            $this->assertStringNotContainsString($pending, $pol);
        }
        $this->assertStringNotContainsString('href="#"', $pol);

        // order is the config order; a pending entry pointing at a route that does not exist is still not rendered
        config(['shop.policies' => [['label' => 'Ghost', 'route' => 'no.such.route'], ['label' => 'Terms and Conditions', 'route' => 'terms']]]);
        $html = $this->home();
        $this->assertStringNotContainsString('Ghost', $html);
        $this->assertStringContainsString('>Terms and Conditions</a>', $html);

        config(['shop.policies' => [['label' => 'Ghost', 'route' => 'no.such.route']]]);
        $this->assertStringNotContainsString('sf__pol', $this->home());
    }

    /** An empty logo folder under public/, removed in tearDown. */
    private function logoDir(): string
    {
        $dir = public_path('images/_test_pay_' . uniqid());
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;
        config(['shop.logo_dir' => 'images/' . basename($dir)]);

        return $dir;
    }

    public function test_payment_tiles_show_text_without_a_logo_file_and_an_image_with_one(): void
    {
        $this->logoDir();
        $html = $this->home();
        $tiles = array_map(fn ($l) => $l, ['GCash', 'Maya', 'UnionBank', 'MariBank', 'Visa', 'Mastercard']);
        $pos = array_map(fn ($t) => strpos($html, '<li><span>' . $t . '</span></li>'), $tiles);
        $this->assertNotContains(false, $pos);
        $sorted = $pos;
        sort($sorted);
        $this->assertSame($sorted, $pos);
        $this->assertStringContainsString('<li class="sf__more">+ more ways to pay</li>', $html);
        $payList = substr($html, strpos($html, 'class="sf__pay"'));
        $this->assertStringNotContainsString('<button', substr($payList, 0, strpos($payList, '</ul>')));

        // a logo file for one method turns only that tile into an <img> (decided on the server)
        $dir = $this->logoDir();
        file_put_contents($dir . '/gcash.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');

        $html = $this->home();
        $this->assertMatchesRegularExpression('#<li class="sf__pay--logo sf__pay--gcash"><img src="' . preg_quote(asset('images/' . basename($dir) . '/gcash.svg'), '#') . '\?v=\d+" alt="GCash" loading="lazy"></li>#', $html);
        $this->assertStringNotContainsString('<li><span>GCash</span></li>', $html);
        $this->assertStringContainsString('<li><span>Maya</span></li>', $html);
        $payList = substr($html, strpos($html, 'class="sf__pay"'));
        $this->assertStringNotContainsString('onerror', substr($payList, 0, strpos($payList, '</ul>')));
    }

    public function test_the_map_has_a_title_lazy_loading_and_uses_coordinates_when_set(): void
    {
        // Contact Settings empty: the exact pin from config/shop.php (Plus Code RCH5+QX6) is used, the address text stays
        $this->settings([]);
        $html = $this->home();
        $this->assertStringContainsString('<iframe title="Map showing LP Furniture shop location" loading="lazy" referrerpolicy="no-referrer-when-downgrade"', $html);
        $this->assertStringContainsString('https://maps.google.com/maps?q=13.829413%2C121.409891&amp;z=17&amp;output=embed', $html);
        $this->assertStringContainsString('https://www.google.com/maps/search/?api=1&amp;query=13.829413%2C121.409891', $html);
        $this->assertStringContainsString('>Open in Google Maps</a>', $html);
        $this->assertStringContainsString('Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas', $html);
        $this->assertStringContainsString('San Juan Doctors Hospital, Calit-calit Elementary School, Calit-calit Barangay Hall', $html);

        $this->settings(['map_lat' => '13.8216', 'map_lng' => '121.3965']);
        $html = $this->home();
        $this->assertStringContainsString('https://maps.google.com/maps?q=13.8216%2C121.3965&amp;z=17&amp;output=embed', $html);
        $this->assertStringContainsString('query=13.8216%2C121.3965', $html);
    }

    public function test_payment_logos_accept_svg_png_jpg_jpeg_webp_in_that_order_and_any_extension_case(): void
    {
        $dir = $this->logoDir();
        $base = 'images/' . basename($dir);
        $src = '<svg xmlns="http://www.w3.org/2000/svg"/>';

        file_put_contents($dir . '/gcash.JPG', 'x');
        file_put_contents($dir . '/maya.Jpeg', 'x');
        file_put_contents($dir . '/visa.webp', 'x');
        file_put_contents($dir . '/mastercard.png', 'x');
        file_put_contents($dir . '/unionbank.svg', $src);
        file_put_contents($dir . '/unionbank.png', 'x');   // svg wins over png
        file_put_contents($dir . '/maribank.jpg', 'x');
        file_put_contents($dir . '/maribank.webp', 'x');   // jpg wins over webp

        $html = $this->home();
        $this->assertStringContainsString('src="' . asset($base . '/gcash.JPG') . '?v=', $html);
        $this->assertStringContainsString('src="' . asset($base . '/maya.Jpeg') . '?v=', $html);
        $this->assertStringContainsString('src="' . asset($base . '/visa.webp') . '?v=', $html);
        $this->assertStringContainsString('src="' . asset($base . '/mastercard.png') . '?v=', $html);
        $this->assertStringContainsString('src="' . asset($base . '/unionbank.svg') . '?v=', $html);
        $this->assertStringNotContainsString('unionbank.png', $html);
        $this->assertStringContainsString('src="' . asset($base . '/maribank.jpg') . '?v=', $html);
        $this->assertStringNotContainsString('maribank.webp', $html);
        $this->assertStringNotContainsString('<span>GCash</span>', $html);

        // the cache-busting query follows the file's modified time
        touch($dir . '/gcash.JPG', 1700000000);
        clearstatcache();
        $this->assertStringContainsString('gcash.JPG?v=1700000000', $this->home());

        // a file with an unsupported extension is ignored and the name is shown
        unlink($dir . '/visa.webp');
        file_put_contents($dir . '/visa.gif', 'x');
        $html = $this->home();
        $this->assertStringContainsString('<li><span>Visa</span></li>', $html);
        $this->assertStringNotContainsString('visa.gif', $html);
    }

    public function test_the_whole_visit_block_is_hidden_without_any_address(): void
    {
        $this->noFallbacks();
        $this->settings([]);
        $html = $this->home();
        $this->assertStringNotContainsString('Visit Our Shop', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_the_map_is_not_blocked_by_any_security_header(): void
    {
        $res = $this->get(route('home'))->assertOk();
        $this->assertFalse($res->headers->has('Content-Security-Policy'));
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_contact_settings_validate_tiktok_facebook_and_map_fields(): void
    {
        $admin = $this->makeUser('admin');
        $base = ['phone' => '09171234567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture'];
        $put  = fn (array $over) => $this->actingAs($admin, 'admin')->from(route('admin.settings.contact.edit'))
            ->put(route('admin.settings.contact.update'), $over + $base);

        $put(['tiktok_url' => 'https://www.tiktok.com/@lpfurniture', 'shop_address' => 'A Street', 'shop_landmarks' => 'Near B', 'map_lat' => '13.8216', 'map_lng' => '121.3965'])->assertSessionHasNoErrors();
        $row = BusinessSetting::query()->first();
        $this->assertSame('https://www.tiktok.com/@lpfurniture', $row->tiktok_url);
        $this->assertSame('A Street', $row->shop_address);
        $this->assertEquals(13.8216, (float) $row->map_lat);
        $this->assertEquals(121.3965, (float) $row->map_lng);

        // blank clears the optional ones
        $put(['tiktok_url' => '', 'shop_address' => '', 'shop_landmarks' => '', 'map_lat' => '', 'map_lng' => ''])->assertSessionHasNoErrors();
        $row = BusinessSetting::query()->first();
        $this->assertNull($row->tiktok_url);
        $this->assertNull($row->shop_address);
        $this->assertNull($row->map_lat);

        foreach (['http://tiktok.com/@x', 'https://tiktok.com.evil.io/@x', 'https://example.com/@x', 'https://tiktok.com', 'not a url'] as $bad) {
            $put(['tiktok_url' => $bad])->assertSessionHasErrors('tiktok_url');
        }
        foreach (['http://facebook.com/x', 'https://evil.io/lp', 'https://facebook.com.evil.io/lp'] as $bad) {
            $put(['facebook_url' => $bad])->assertSessionHasErrors('facebook_url');
        }

        $put(['map_lat' => '90.5', 'map_lng' => '10'])->assertSessionHasErrors('map_lat');
        $put(['map_lat' => '-91', 'map_lng' => '10'])->assertSessionHasErrors('map_lat');
        $put(['map_lat' => '10', 'map_lng' => '180.1'])->assertSessionHasErrors('map_lng');
        $put(['map_lat' => '10', 'map_lng' => '-181'])->assertSessionHasErrors('map_lng');
        $put(['map_lat' => 'abc', 'map_lng' => '10'])->assertSessionHasErrors('map_lat');
        $put(['map_lat' => '13.8', 'map_lng' => ''])->assertSessionHasErrors('map_lng');
        $put(['map_lat' => '-90', 'map_lng' => '180'])->assertSessionHasNoErrors();

        // old input is kept on error, in the card that was submitted (its modal reopens), and the form explains how to find the numbers
        $page = $this->actingAs($admin, 'admin')->withSession(['_old_input' => ['_section' => 'shop', 'map_lat' => '999', 'map_lng' => '10']])
            ->get(route('admin.settings.contact.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('value="999"', $page);
        $this->assertStringContainsString('right-click the exact spot of the shop', $page);
        $this->assertStringContainsString('Map Latitude', $page);
        $this->assertStringContainsString('(Optional)', $page);
        $page = $this->actingAs($admin, 'admin')->withSession(['_old_input' => ['_section' => 'business', 'tiktok_url' => 'https://bad.example/x']])
            ->get(route('admin.settings.contact.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('value="https://bad.example/x"', $page);
    }
}
