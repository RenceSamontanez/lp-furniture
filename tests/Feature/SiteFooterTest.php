<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** The shared customer footer (partials/site-footer) and the TikTok/Facebook fields in Contact Settings. */
class SiteFooterTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function settings(array $values): void
    {
        $row = BusinessSetting::query()->orderBy('id')->first() ?? new BusinessSetting();
        $row->forceFill($values + ['phone' => '', 'email' => '', 'facebook_url' => '', 'tiktok_url' => null])->save();
    }

    private function home(): string
    {
        return $this->get(route('home'))->assertOk()->getContent();
    }

    public function test_footer_renders_once_on_the_home_page_and_an_inner_page_and_is_dark(): void
    {
        foreach ([route('home'), route('catalog'), route('terms')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="sf"'), $url);
            $this->assertStringContainsString('data-nav="dark" aria-label="Site footer"', $html);
            $this->assertStringContainsString('assets/css/site-footer.css', $html);
            $this->assertStringNotContainsString('site-footer"', str_replace('assets/css/site-footer.css', '', $html));
            $this->assertStringNotContainsString('class="lp-footer"', $html);
            $this->assertStringContainsString('Send a request', $html);
            $this->assertStringContainsString(route('services.request'), $html);
            $this->assertStringContainsString('Assistant in the menu', $html);
            $this->assertStringContainsString('&copy; ' . date('Y') . ' LP Furniture', str_replace('©', '&copy;', $html));
        }
    }

    public function test_footer_is_absent_on_focus_pages_and_the_admin(): void
    {
        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $this->assertStringNotContainsString('class="sf"', $this->get($url)->assertOk()->getContent());
        }

        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('class="sf"', $html);
    }

    public function test_socials_are_hidden_when_blank_and_follow_row_disappears(): void
    {
        $this->settings(['phone' => '', 'email' => '']);
        $html = $this->home();
        $this->assertStringNotContainsString('Follow us', $html);
        $this->assertStringNotContainsString('on Facebook', $html);
        $this->assertStringNotContainsString('on TikTok', $html);
    }

    public function test_socials_show_with_target_and_rel_only_when_set(): void
    {
        $this->settings(['facebook_url' => 'https://facebook.com/lpfurniture']);
        $html = $this->home();
        $this->assertStringContainsString('Follow us', $html);
        $this->assertStringContainsString('href="https://facebook.com/lpfurniture" target="_blank" rel="noopener noreferrer" aria-label="LP Furniture on Facebook (opens in a new tab)"', $html);
        $this->assertStringNotContainsString('on TikTok', $html);

        $this->settings(['tiktok_url' => 'https://www.tiktok.com/@lpfurniture']);
        $html = $this->home();
        $this->assertStringContainsString('Follow us', $html);
        $this->assertStringContainsString('href="https://www.tiktok.com/@lpfurniture" target="_blank" rel="noopener noreferrer" aria-label="LP Furniture on TikTok (opens in a new tab)"', $html);
        $this->assertStringNotContainsString('on Facebook', $html);
    }

    public function test_a_stored_value_that_is_not_a_valid_link_or_is_the_placeholder_renders_nothing(): void
    {
        $this->settings(['facebook_url' => 'javascript:alert(1)', 'tiktok_url' => 'http://tiktok.com/@x']);
        $html = $this->home();
        $this->assertStringNotContainsString('Follow us', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        // the starting placeholders from the migration must never reach customers
        $this->settings(BusinessSetting::DEFAULTS);
        $html = $this->home();
        $this->assertStringNotContainsString('0917-000-0000', $html);
        $this->assertStringNotContainsString('contact@example.com', $html);
        $this->assertStringNotContainsString('your-page', $html);
        $this->assertStringNotContainsString('Follow us', $html);
    }

    public function test_phone_and_email_lines_are_links_and_hidden_when_blank(): void
    {
        $this->settings(['phone' => '0917 123 4567', 'email' => 'hello@lpfurniture.test']);
        $html = $this->home();
        $this->assertStringContainsString('href="tel:09171234567"', $html);
        $this->assertStringContainsString('href="mailto:hello@lpfurniture.test"', $html);

        $this->settings(['phone' => '', 'email' => 'hello@lpfurniture.test']);
        $html = $this->home();
        $this->assertStringNotContainsString('href="tel:', $html);
        $this->assertStringContainsString('mailto:hello@lpfurniture.test', $html);

        $this->settings(['phone' => '0917 123 4567', 'email' => '']);
        $html = $this->home();
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringContainsString('href="tel:', $html);
    }

    public function test_contact_column_is_hidden_when_nothing_to_show(): void
    {
        config(['luma.hours' => null]);
        $this->settings([]);
        $html = $this->home();
        $this->assertStringNotContainsString('>Contact</h3>', $html);
        $this->assertStringContainsString('aria-label="Policies"', $html);
    }

    public function test_hours_text_comes_from_config(): void
    {
        $this->settings(['phone' => '0917 123 4567']);
        $this->assertStringContainsString('Mon to Fri, 8:00 AM to 5:00 PM', $this->home());

        config(['luma.hours' => ['open' => '09:30', 'close' => '18:00', 'days' => [1, 2, 3, 4, 5, 6]]]);
        $html = $this->home();
        $this->assertStringContainsString('Mon to Sat, 9:30 AM to 6:00 PM', $html);
        $this->assertStringNotContainsString('Mon to Fri, 8:00 AM', $html);
    }

    public function test_policy_and_explore_links_point_to_existing_pages(): void
    {
        $html = $this->home();
        foreach (['terms', 'services.terms', 'guide', 'catalog', 'design.index', 'services.request', 'feedback.index', 'orders.index'] as $name) {
            $this->assertStringContainsString('href="' . route($name) . '"', $html, $name);
        }
        $this->get(route('terms'))->assertOk();
        $this->get(route('services.terms'))->assertOk();
    }

    public function test_contact_settings_validate_tiktok_and_facebook_urls(): void
    {
        $admin = $this->makeUser('admin');
        $base = ['phone' => '09171234567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture'];
        $put  = fn (array $over) => $this->actingAs($admin, 'admin')->from(route('admin.settings.contact.edit'))
            ->put(route('admin.settings.contact.update'), $over + $base);

        $put(['tiktok_url' => 'https://www.tiktok.com/@lpfurniture'])->assertSessionHasNoErrors();
        $this->assertSame('https://www.tiktok.com/@lpfurniture', BusinessSetting::query()->first()->tiktok_url);

        // blank clears it
        $put(['tiktok_url' => ''])->assertSessionHasNoErrors();
        $this->assertNull(BusinessSetting::query()->first()->tiktok_url);

        foreach (['http://tiktok.com/@x', 'https://tiktok.com.evil.io/@x', 'https://example.com/@x', 'https://tiktok.com', 'not a url'] as $bad) {
            $put(['tiktok_url' => $bad])->assertSessionHasErrors('tiktok_url');
        }

        foreach (['http://facebook.com/x', 'https://evil.io/lp', 'https://facebook.com.evil.io/lp'] as $bad) {
            $put(['tiktok_url' => '', 'facebook_url' => $bad])->assertSessionHasErrors('facebook_url');
        }

        // old input is kept on error
        $page = $this->actingAs($admin, 'admin')->withSession(['_old_input' => ['tiktok_url' => 'https://bad.example/x']])
            ->get(route('admin.settings.contact.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('value="https://bad.example/x"', $page);
        $this->assertStringContainsString('TikTok Page URL (optional)', $page);
    }
}
