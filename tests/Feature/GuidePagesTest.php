<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Support\FurnitureTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 18 part D: the measuring guide, the tutorial page, and the corrected payment line.
 */
class GuidePagesTest extends TestCase
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

    private function business(?string $phone, ?string $email): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::create(['phone' => $phone ?? '', 'email' => $email ?? '', 'facebook_url' => 'https://facebook.com/x']);
        Cache::forget(BusinessSetting::CACHE_KEY);
    }

    public function test_the_guide_is_public_and_has_every_step(): void
    {
        $html = $this->get('/guide')->assertOk()->getContent();

        $this->assertSame('/guide', route('guide', [], false));
        foreach ([
            'How to request a custom design', 'Choose the furniture type', 'Describe it', 'Add the size and the wood', 'Generate',
            'Review', 'Send it to our team', 'Wait for our call', 'Accept the quote', 'Pay', 'How to measure',
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertSame(9, substr_count($html, '<li><h3>'), 'the nine steps are a numbered list');
        $this->assertStringContainsString('<ol class="guide-steps">', $html);
    }

    public function test_the_measuring_diagram_is_an_accessible_original_svg(): void
    {
        $html = $this->get('/guide')->assertOk()->getContent();

        $this->assertStringContainsString('<svg class="gm-svg"', $html);
        $this->assertStringContainsString('role="img" aria-labelledby="gm-title gm-desc"', $html);
        $this->assertStringContainsString('<title id="gm-title">', $html);
        $this->assertStringContainsString('<desc id="gm-desc">', $html);
        foreach (['>Length<', '>Width<', '>Height<'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        // a plain text version sits next to the picture
        $this->assertStringContainsString('Length is the longest side across, Width is the shorter side across, and Height is from the floor to the top.', $html);
        $this->assertStringNotContainsString('<img', substr($html, strpos($html, 'class="guide-card"'), 6000), 'drawn inline, no borrowed image');
    }

    public function test_it_explains_the_three_sides_for_every_type_with_hints_as_suggestions(): void
    {
        $html = $this->get('/guide')->assertOk()->getContent();

        foreach (FurnitureTypes::LIST as $type) {
            $this->assertArrayHasKey($type, FurnitureTypes::GUIDE, "{$type} has guidance");
            $this->assertStringContainsString('<dt>' . e($type) . '</dt>', $html);
        }
        $this->assertStringContainsString('A table is usually 70 to 80 cm high.', $html);
        $this->assertStringContainsString('only suggestions', $html);
        foreach (['table', 'chair', 'bed', 'cabinet', 'door'] as $needle) {
            $this->assertStringContainsStringIgnoringCase('<dt>' . $needle, $html);
        }
    }

    public function test_the_ask_us_line_uses_contact_settings_with_a_fallback(): void
    {
        $this->business('0917-555-0100', 'hello@lpfurniture.test');
        $this->get('/guide')->assertOk()->assertSee('Not sure? Ask us before you send the request.')
            ->assertSee('Call 0917-555-0100 or email')->assertSee('hello@lpfurniture.test');

        $this->business('0917-555-0100', null);
        $this->get('/guide')->assertOk()->assertSee('Call 0917-555-0100.');

        $this->business(null, null);
        $page = $this->get('/guide')->assertOk()->assertSee('You can reach our team through our website.');
        $page->assertDontSee('contact@example.com')->assertDontSee('0917-000-0000');
    }

    public function test_the_footer_links_to_the_guide_on_both_layouts(): void
    {
        $this->get(route('catalog'))->assertOk()->assertSee('href="' . route('guide') . '"', false)->assertSee('How to request a custom design');

        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="' . route('guide') . '"', false);
    }

    public function test_the_design_form_has_the_collapsible_panel_and_the_guide_link(): void
    {
        $html = $this->asCustomer($this->flowCustomer())->get(route('design.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<details class="cd-measure" id="cd-measure">', $html);
        $this->assertStringContainsString('<summary>Need help measuring?</summary>', $html);
        $this->assertStringContainsString('<svg class="gm-svg"', $html);
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('guide'), '/') . '" target="_blank" rel="noopener" data-no-loading>Read the full guide/', $html);
        $this->assertStringContainsString('assets/css/guide.css', $html);
        // closed until opened, and a native <details> is keyboard operable
        $this->assertDoesNotMatchRegularExpression('/<details class="cd-measure"[^>]* open/', $html);
    }

    public function test_the_guide_links_opt_out_of_the_loading_overlay(): void
    {
        $html = $this->get('/guide')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a href="' . preg_quote(route('terms'), '/') . '"[^>]*data-no-loading/', $html);

        $css = file_get_contents(public_path('assets/css/guide.css'));
        $this->assertStringContainsString('.cd-measure > summary:focus-visible', $css);
        $this->assertStringContainsString('@media (max-width: 760px)', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    /* ───────────── the outdated payment line ───────────── */

    public function test_the_review_page_no_longer_says_payment_is_not_set_up(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);

        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.quote.review', [$r->id, $quote->id]))->assertOk()
            ->assertDontSee('Payment is not set up yet')
            ->assertDontSee('we will let you know when it is ready')
            ->assertSee('you can pay straight away')
            ->assertSee('Your order is confirmed once we receive your deposit.');

        // nowhere else in the app says it either
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile()) {
                $this->assertStringNotContainsString('Payment is not set up yet', file_get_contents($file->getPathname()), $file->getFilename());
            }
        }
    }
}
