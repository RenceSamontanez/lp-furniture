<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 20 part E: labor-only questions explain, show the shop's contact card and link to /services/request.
 */
class LumaServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
            $this->assertSame(['phone' => '0917-222-3333', 'email' => 'owner@lp.test'], $reply['contact'], $question);
            $this->assertSame([['label' => 'Send a request', 'url' => '/services/request']], $reply['buttons'], $question);
            $this->assertDoesNotMatchRegularExpression('/(PHP|₱)\s?\d/', $reply['response'], $question);
        }
    }

    public function test_the_tagalog_answer_has_the_same_card_and_button(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);

        $reply = $this->ask('pwede po ba labor lang, ako na sa kahoy');

        $this->assertSame('tl', $reply['lang']);
        $this->assertStringContainsString('Magkakaiba po ang presyo ng labor', $reply['response']);
        $this->assertSame('Magpadala ng request', $reply['buttons'][0]['label']);
        $this->assertSame('/services/request', $reply['buttons'][0]['url']);
        $this->assertSame('0917-222-3333', $reply['contact']['phone']);
    }

    public function test_without_contact_settings_the_answer_says_through_our_website(): void
    {
        $reply = $this->ask('labor only');

        $this->assertNull($reply['contact']);
        $this->assertStringContainsString('through our website', $reply['response']);
        $this->assertStringContainsString('/services/request', $reply['buttons'][0]['url']);
    }

    public function test_the_request_link_opens_a_coming_soon_page(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333']);

        $this->get('/services/request')->assertOk()->assertSee('Coming soon')->assertSee('0917-222-3333');
    }

    public function test_the_widget_draws_a_business_card_and_clickable_buttons(): void
    {
        $widget = file_get_contents(public_path('assets/main.js'));

        $this->assertStringContainsString('chat-contact', $widget);
        $this->assertStringContainsString('tel:', $widget);
        $this->assertStringContainsString('chat-link-btn', $widget);
    }
}
