<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 13 part C: image and prompt on the quote form and detail page,
 * the heading, proper-case names (display only) and lining numerals.
 */
class QuotePageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $imageRel;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->imageRel = 'images/ai-designs/phpunit-stage13c-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents(public_path($this->imageRel), base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->imageRel) && is_file(public_path($this->imageRel))) {
            unlink(public_path($this->imageRel));
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'RHEA', 'last_name' => 'SANTOS'])->save();

        return $a;
    }

    private function request(array $over = [], bool $withImage = true): CustomDesignRequest
    {
        $c = $this->makeUser();
        $c->forceFill(['first_name' => 'RENCE', 'last_name' => 'SAMONTANEZ'])->save();

        $r = CustomDesignRequest::create(array_merge([
            'user_id' => $c->id, 'title' => 'Dining Table - Rence', 'furniture_type' => 'Dining Table',
            'description' => 'A six-seater narra dining table with rounded corners', 'status' => 'approved',
            'pipeline_status' => 'submitted', 'contact_phone' => '09171234567', 'contact_email' => 'r@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact', 'submitted_at' => now(),
        ], $over));

        if ($withImage) {
            $s = AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => $this->imageRel, 'generated_at' => now(), 'is_selected' => true]);
            $r->forceFill(['selected_suggestion_id' => $s->id])->save();
        }

        return $r->fresh();
    }

    public function test_quote_form_shows_the_design_image_and_prompt(): void
    {
        $admin = $this->admin();
        $r = $this->request();

        $page = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk();

        $page->assertSee($this->imageRel, false)
            ->assertSee('A six-seater narra dining table with rounded corners')
            ->assertDontSee('No image generated');
    }

    public function test_quote_form_falls_back_to_the_customers_reference_image_then_to_no_image(): void
    {
        $admin = $this->admin();

        $withReference = $this->request(['reference_image' => 'design-refs/ref.png'], false);
        Storage::disk('public')->put('design-refs/ref.png', 'IMG');
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $withReference->id))
            ->assertOk()->assertSee(route('admin.reference-photo', $withReference->id), false)->assertDontSee('storage/design-refs/ref.png', false)->assertDontSee('No image generated');

        $none = $this->request([], false);
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $none->id))
            ->assertOk()->assertSee('No image generated');
    }

    public function test_detail_page_shows_image_with_the_prompt_beside_it(): void
    {
        $admin = $this->admin();
        $r = $this->request();

        $html = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();

        $this->assertStringContainsString($this->imageRel, $html);
        $this->assertMatchesRegularExpression('/co-design-summary.*?Customer.s prompt.*?A six-seater narra dining table/s', $html);
    }

    public function test_heading_names_the_customer_and_shows_a_version_badge_only_above_one(): void
    {
        $admin = $this->admin();
        $r = $this->request();

        $first = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk();
        $first->assertSee('Customized order: Rence Samontanez')->assertDontSee('Version 1 for');
        $this->assertStringNotContainsString('co-version-badge">Version', $first->getContent());

        // a second version
        $this->makeQuote($r, ['version' => 1, 'status' => 'expired', 'price' => 10000]);
        $r->forceFill(['pipeline_status' => 'expired'])->save();

        $second = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk();
        $second->assertSee('Customized order: Rence Samontanez');
        $this->assertStringContainsString('<span class="co-version-badge">Version 2</span>', $second->getContent());
    }

    public function test_names_are_shown_in_proper_case_and_stored_names_are_unchanged(): void
    {
        $admin = $this->admin();
        $r = $this->request();

        foreach ([
            route('admin.custom-orders.show', $r->id),
            route('admin.custom-orders.index'),
            route('admin.custom-orders.quotes.create', $r->id),
        ] as $url) {
            $html = $this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Rence Samontanez', $html, $url);
            $this->assertStringNotContainsString('RENCE SAMONTANEZ', $html, $url);
        }

        $stored = $r->user->fresh();
        $this->assertSame('RENCE', $stored->first_name);
        $this->assertSame('SAMONTANEZ', $stored->last_name);
    }

    public function test_notifications_use_proper_case_without_touching_stored_names(): void
    {
        $this->admin();
        $r = $this->request([], true);
        $c = $r->user;

        // the customer edits their request: the admin message uses the proper-case name
        $this->actingAs($c)->post(route('design.update', $r->id), [
            'contact_phone' => '09171234567', 'contact_email' => 'r@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact',
        ])->assertSessionHasNoErrors();

        $n = Notification::where('title', 'Custom Request Updated')->sole();
        $this->assertStringContainsString('Rence Samontanez updated their request', $n->message);
        $this->assertSame('RENCE', $c->fresh()->first_name);
        $this->assertSame(1, CustomOrderEvent::where('type', 'request_updated')->count());
    }

    public function test_numerals_are_lining_and_card_titles_use_the_sans_font(): void
    {
        $admin = $this->admin();
        $r = $this->request();

        // the pages carry the wrapper the typography rules hang on
        foreach ([route('admin.custom-orders.show', $r->id), route('admin.custom-orders.quotes.create', $r->id)] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertOk()->assertSee('class="co-page"', false);
        }

        $css = file_get_contents(public_path('assets/css/admin-custom-orders.css'));
        $this->assertStringContainsString('font-variant-numeric: lining-nums tabular-nums', $css);
        $this->assertMatchesRegularExpression('/\.co-page \.admin-panel-title\s*\{[^}]*font-family:\s*var\(--font-body\)/', $css);

        $customer = file_get_contents(public_path('assets/css/custom-orders.css'));
        $this->assertStringContainsString('font-variant-numeric: lining-nums tabular-nums', $customer);
    }
}
