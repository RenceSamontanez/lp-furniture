<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 20 part E: labor-only questions explain, show the shop's contact card and link to /services/request.
 */
class LumaServicesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.fallback.phone' => null, 'shop.fallback.email' => null, 'shop.fallback.facebook_url' => null, 'shop.fallback.shop_address' => null]);   // Luma tests start with no contact details, as before

        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    public function test_labor_only_answers_explain_show_the_contact_card_and_offer_the_request_button(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);

        foreach ([
            'I will provide the wood',
            'labor only',
            'how much for a door if wood is provided',
            'how much is labor for a table',
        ] as $question) {
            $reply = $this->ask($question);

            $this->assertStringContainsString('Labor-only prices vary by furniture type', $reply['response'], $question);
            $this->assertStringContainsString('by phone', $reply['response'], $question);
            $this->assertSame(['phone' => '0917 222 3333', 'email' => 'owner@lp.test'], $reply['contact'], $question);
            $this->assertSame([['label' => 'Log in to send a request', 'url' => '/services/request']], $reply['buttons'], $question);
            $this->assertStringContainsString('You need an account to send a request', $reply['response'], $question);
            $this->assertDoesNotMatchRegularExpression('/(PHP|₱)\s?\d/', $reply['response'], $question);
        }
    }

    public function test_the_button_label_depends_on_whether_the_visitor_is_logged_in(): void
    {
        $en = 'labor only';
        $tl = 'pwede po ba labor lang, ako na sa kahoy';

        // guest: log in first; the link still goes to /services/request, which redirects to login
        $this->assertSame(['label' => 'Log in to send a request', 'url' => '/services/request'], $this->ask($en)['buttons'][0]);
        $this->assertSame(['label' => 'Mag-log in para magpadala ng request', 'url' => '/services/request'], $this->ask($tl)['buttons'][0]);
        $this->assertStringContainsString('Kailangan po ng account', $this->ask($tl)['response']);
        $this->get('/services/request')->assertRedirect(route('login'));

        // customer: the plain label
        $this->actingAs($this->makeUser());
        $this->assertSame(['label' => 'Send a request', 'url' => '/services/request'], $this->ask($en)['buttons'][0]);
        $this->assertSame(['label' => 'Magpadala ng request', 'url' => '/services/request'], $this->ask($tl)['buttons'][0]);
        $this->assertStringContainsString('You need an account to send a request', $this->ask($en)['response']);
    }

    /** Stage 21 part C (logged in): the button Luma shows leads to the real ticket form, English and Tagalog. */
    public function test_the_send_a_request_button_resolves_to_the_real_form_and_it_works(): void
    {
        $this->actingAs($this->makeUser());

        foreach (['labor only' => 'Send a request', 'pwede po ba labor lang, ako na sa kahoy' => 'Magpadala ng request'] as $question => $label) {
            $button = $this->ask($question)['buttons'][0];

            $this->assertSame($label, $button['label']);
            $this->assertSame(route('services.request', [], false), $button['url']);

            $this->get($button['url'])->assertOk()
                ->assertSee('Other Services Request')
                ->assertSee('name="service_type"', false)
                ->assertSee('value="labor_only_own_wood"', false)
                ->assertSee(route('services.request.submit'), false)
                ->assertDontSee('Coming soon');
        }

        // and a request made through that form is recorded
        $this->post(route('services.request.submit'), [
            'service_type' => 'labor_only_own_wood', 'concern' => 'A door built from my own narra wood, about 90 cm wide.',
            'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com', 'terms' => '1',
        ])->assertRedirect(route('services.request.sent'));
        $this->assertSame(1, \App\Models\ServiceInquiry::count());
    }

    public function test_the_tagalog_answer_has_the_same_card_and_button(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);

        $reply = $this->ask('pwede po ba labor lang, ako na sa kahoy');

        $this->assertSame('tl', $reply['lang']);
        $this->assertStringContainsString('Magkakaiba po ang presyo ng labor', $reply['response']);
        $this->assertSame('Mag-log in para magpadala ng request', $reply['buttons'][0]['label']);
        $this->assertSame('/services/request', $reply['buttons'][0]['url']);
        $this->assertSame('0917 222 3333', $reply['contact']['phone']);
    }

    public function test_without_contact_settings_the_answer_says_through_our_website(): void
    {
        $reply = $this->ask('labor only');

        $this->assertNull($reply['contact']);
        $this->assertStringContainsString('through our website', $reply['response']);
        $this->assertStringContainsString('/services/request', $reply['buttons'][0]['url']);
    }

    public function test_the_request_link_opens_the_real_form_not_a_placeholder(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333']);

        $this->actingAs($this->makeUser());
        $this->get('/services/request')->assertOk()
            ->assertDontSee('Coming soon')
            ->assertSee('Other Services Request')
            ->assertSee('0917 222 3333');
    }

    public function test_the_widget_draws_a_business_card_and_clickable_buttons(): void
    {
        $widget = file_get_contents(public_path('assets/main.js'));

        $this->assertStringContainsString('chat-contact', $widget);
        $this->assertStringContainsString('tel:', $widget);
        $this->assertStringContainsString('chat-btn--primary', $widget);
    }
}
