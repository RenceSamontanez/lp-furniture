<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use App\Models\ChatbotLog;
use App\Support\Luma\IntentMatcher;
use App\Support\Luma\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 20 part A: Luma matches whole words and phrases, scores them, and never answers a question
 * with a keyword that only sits inside a longer word.
 */
class LumaMatchingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function intent(string $keywords, string $response = 'An answer.', array $over = []): ChatbotIntent
    {
        return ChatbotIntent::create(array_merge([
            'keywords' => $keywords, 'response' => $response, 'suggestions' => '', 'category' => null, 'is_active' => true,
        ], $over));
    }

    /** The 20 intents that were live before this stage, under their real ids. */
    private function legacy(): void
    {
        foreach (require base_path('tests/Fixtures/LegacyIntents.php') as $id => $row) {
            $intent = new ChatbotIntent();
            $intent->forceFill($row + ['id' => $id, 'is_active' => true, 'category' => null])->save();
        }
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    private function loggedAs(): string
    {
        return ChatbotLog::query()->latest('id')->value('intent_detected');
    }

    /* ───────────── whole words ───────────── */

    public function test_a_keyword_inside_a_longer_word_does_not_match(): void
    {
        $this->intent('hi, hey', 'Hello there.');

        foreach (['Which wood is best?', 'They said it is late', 'a high chair please', 'we are hiring'] as $text) {
            $this->assertStringNotContainsString('Hello there.', $this->ask($text)['response'], $text);
        }

        $this->assertStringContainsString('Hello there.', $this->ask('hi')['response']);
        $this->assertStringContainsString('Hello there.', $this->ask('Hi!!')['response']);
        $this->assertStringContainsString('Hello there.', $this->ask('HEY, anyone?')['response']);
    }

    public function test_case_accents_and_punctuation_do_not_matter(): void
    {
        $this->intent('café, free delivery', 'Matched.');

        $this->assertStringContainsString('Matched.', $this->ask('CAFE???')['response']);
        $this->assertStringContainsString('Matched.', $this->ask('is it FREE... delivery?')['response']);
        $this->assertSame(['free', 'delivery'], Text::words('Free-Delivery!'));
    }

    public function test_plurals_fold_to_the_same_word(): void
    {
        $this->intent('door', 'Door answer.');

        $this->assertStringContainsString('Door answer.', $this->ask('do you make doors')['response']);
    }

    /* ───────────── the five wrong matches, against the real old intents ───────────── */

    public function test_the_five_wrong_matches_of_the_old_substring_matcher_are_fixed(): void
    {
        $this->legacy();

        // old matcher: 'hi' inside "Which" → the greeting (id 11)
        $this->assertSame('intent:21', $this->detect('Which wood is best for a bed?'));
        // old: 'id' inside "provide" → identity verification (id 13)
        $this->assertSame('intent:21', $this->detect('Can you provide the wood for my table?'));
        // old: 'id' inside "Did" → identity verification (id 13)
        $this->assertSame('intent:15', $this->detect('Did my payment go through?'));
        // old: 'id' inside "kid" → identity verification (id 13); nothing in the list is about this
        $this->assertSame('unmatched', $this->detect('I want a cartoon-themed bed for my kid'));
        // old: 'hey' inside "They" → the greeting (id 11)
        $this->assertSame('intent:17', $this->detect('They said the delivery is late'));
    }

    private function detect(string $message): string
    {
        $this->ask($message);

        return $this->loggedAs();
    }

    public function test_real_greetings_and_the_old_answers_still_work(): void
    {
        $this->legacy();

        $this->assertSame('intent:11', $this->detect('hi'));
        $this->assertSame('intent:12', $this->detect('how to order'));
        $this->assertSame('intent:18', $this->detect('where is my order'));
        $this->assertSame('intent:16', $this->detect('can I pay in installments'));
    }

    /* ───────────── scoring ───────────── */

    public function test_a_longer_phrase_scores_higher_than_a_single_word(): void
    {
        $single = ['order'];
        $phrase = ['how', 'order'];

        $this->assertGreaterThan(IntentMatcher::keywordScore($single), IntentMatcher::keywordScore($phrase));

        $general = $this->intent('order', 'General order answer.');
        $howTo   = $this->intent('how to order', 'Step by step answer.');

        $this->assertStringContainsString('Step by step answer.', $this->ask('How to order?')['response']);
        $this->assertStringContainsString('General order answer.', $this->ask('my order is late')['response']);

        $this->assertSame($howTo->id, IntentMatcher::best('how to order', ChatbotIntent::all())['intent']->id);
        $this->assertNotSame($general->id, IntentMatcher::best('how to order', ChatbotIntent::all())['intent']->id);
    }

    public function test_more_matched_keywords_add_up(): void
    {
        $one  = $this->intent('wood', 'One keyword.');
        $many = $this->intent('wood, gmelina, mahogany', 'Three keywords.');

        $best = IntentMatcher::best('gmelina or mahogany wood', ChatbotIntent::all());

        $this->assertSame($many->id, $best['intent']->id);
        $this->assertGreaterThan(IntentMatcher::keywordScore(['wood']) * 2, $best['score']);
        $this->assertNotSame($one->id, $best['intent']->id);
    }

    public function test_a_keyword_inside_a_longer_matched_keyword_is_not_counted_twice(): void
    {
        $words = Text::words('what payment methods do you accept');

        $both   = IntentMatcher::score($words, 'payment, payment method');
        $longer = IntentMatcher::score($words, 'payment method');

        $this->assertSame($longer['score'], $both['score']);
        $this->assertSame(['payment method'], $both['matched']);
    }

    public function test_ties_go_to_the_most_recently_edited_then_the_newest(): void
    {
        $old = $this->intent('delivery', 'Old answer.');
        $new = $this->intent('delivery', 'New answer.');

        // same instant: the newer row wins
        $this->assertSame($new->id, IntentMatcher::best('delivery', ChatbotIntent::all())['intent']->id);

        // an admin edit makes the older one the most recently edited
        $old->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();
        $this->assertSame($old->id, IntentMatcher::best('delivery', ChatbotIntent::all())['intent']->id);
    }

    public function test_the_result_never_depends_on_row_order(): void
    {
        $a = $this->intent('delivery', 'A.');
        $b = $this->intent('delivery', 'B.');

        $forward  = IntentMatcher::best('delivery', ChatbotIntent::orderBy('id')->get())['intent']->id;
        $backward = IntentMatcher::best('delivery', ChatbotIntent::orderByDesc('id')->get())['intent']->id;

        $this->assertSame($forward, $backward);
    }

    public function test_filler_only_keywords_and_inactive_intents_never_match(): void
    {
        $this->intent('po, the, ba', 'Filler.');
        $this->intent('sofa', 'Off.', ['is_active' => false]);

        $this->assertStringNotContainsString('Filler.', $this->ask('po')['response']);
        $this->assertStringNotContainsString('Off.', $this->ask('sofa')['response']);
    }

    /* ───────────── synonyms and typos ───────────── */

    public function test_filipino_and_english_spellings_reach_the_same_intent(): void
    {
        $this->intent('price', 'Price answer.');

        foreach (['magkano po', 'Presyo?', 'how much', 'how much is it po', 'pricing', 'cost'] as $text) {
            $this->assertStringContainsString('Price answer.', $this->ask($text)['response'], $text);
        }
    }

    public function test_typos_and_tagalog_words_from_the_synonym_list(): void
    {
        $this->intent('wood', 'Wood answer.');
        $this->intent('free delivery', 'Free delivery answer.');
        $this->intent('how', 'How answer.');

        $this->assertStringContainsString('Wood answer.', $this->ask('anong kahoy po')['response']);
        $this->assertStringContainsString('Free delivery answer.', $this->ask('libre ba ang delivery')['response']);
        $this->assertStringContainsString('Free delivery answer.', $this->ask('free shipping?')['response']);
        $this->assertStringContainsString('How answer.', $this->ask('pano po?')['response']);
        $this->assertStringContainsString('How answer.', $this->ask('paano')['response']);
    }

    public function test_the_synonym_list_is_one_config_file(): void
    {
        config(['luma_synonyms.words' => ['bahay' => 'house']]);
        Text::reset();

        $this->assertSame(['house'], Text::words('Bahay'));

        Text::reset(); // back to the real file for the other tests
    }

    /* ───────────── fallback and logging ───────────── */

    public function test_the_fallback_offers_topics_and_the_shop_contact_details(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-123-4567', 'email' => 'shop@lp.test']);

        $json = $this->ask('xyzzy plugh');

        $this->assertCount(5, $json['suggestions']);   // four topics and "Talk to a person"
        $this->assertStringContainsString('0917-123-4567', $json['response']);
        $this->assertStringContainsString('shop@lp.test', $json['response']);
        $this->assertSame('unmatched', $this->loggedAs());
    }

    public function test_the_fallback_says_through_our_website_when_contact_settings_are_unset(): void
    {
        // the table's placeholder values count as "not set"
        $json = $this->ask('xyzzy plugh');

        $this->assertStringContainsString('through our website', $json['response']);
        $this->assertStringNotContainsString('0917-000-0000', $json['response']);
        $this->assertStringNotContainsString('contact@example.com', $json['response']);
    }

    public function test_unmatched_questions_are_logged_and_shown_to_the_admin(): void
    {
        $this->ask('Do you make boats?');
        $this->ask('do you make boats?');
        $this->intent('hello', 'Hi.');
        $this->ask('hello');

        $this->assertSame(2, ChatbotLog::where('intent_detected', 'unmatched')->count());
        $this->assertSame('Do you make boats?', ChatbotLog::where('intent_detected', 'unmatched')->value('message'));

        $page = $this->actingAs($this->makeUser('admin'), 'admin')->get(route('admin.chatbot.index'));
        $page->assertOk()->assertSee('Questions Luma could not answer')->assertSee('do you make boats?')->assertSee('asked 2 times');
        $page->assertDontSee('<li>hello', false);
    }

    /* ───────────── what already worked still works ───────────── */

    public function test_the_admin_training_screen_still_adds_edits_and_lists_intents(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'admin')->post(route('admin.chatbot.store'), [
            'keywords' => 'sofa, couch', 'response' => 'We make sofas.', 'suggestions' => 'Browse catalog', 'is_active' => 1,
        ])->assertRedirect(route('admin.chatbot.index'));

        $intent = ChatbotIntent::where('keywords', 'sofa, couch')->firstOrFail();
        $this->assertStringContainsString('We make sofas.', $this->ask('Do you have a couch?')['response']);

        $this->put(route('admin.chatbot.update', $intent->id), [
            'keywords' => 'sofa, couch', 'response' => 'Sofas, yes.', 'suggestions' => '', 'is_active' => 1,
        ])->assertRedirect(route('admin.chatbot.index'));

        $this->get(route('admin.chatbot.index'))->assertOk()->assertSee('sofa, couch');
        $this->assertStringContainsString('Sofas, yes.', $this->ask('couch')['response']);
    }

    public function test_the_model_matches_helper_uses_whole_words(): void
    {
        $intent = $this->intent('hi');

        $this->assertTrue($intent->matches('hi there'));
        $this->assertFalse($intent->matches('which one'));
    }

    public function test_a_chat_reply_is_plain_text_so_database_html_is_never_trusted(): void
    {
        $this->intent('script', '<script>alert(1)</script> hello');

        // the server sends the text untouched; the widget turns it into text, never into HTML
        $json = $this->ask('script');
        $this->assertStringContainsString('<script>', $json['response']);
        $widget = file_get_contents(public_path('assets/main.js'));
        $this->assertStringContainsString('chatEsc(msg)', $widget);
        $this->assertStringNotContainsString('${msg.replace', $widget);
    }
}
