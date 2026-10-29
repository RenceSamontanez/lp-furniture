<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Support\Luma\Language;
use App\Support\Luma\Luma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 20 part B: Luma answers in the language of the question. Since answer format v2 no answer ends with a sign-off line.
 */
class LumaLanguageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.fallback.phone' => null, 'shop.fallback.email' => null, 'shop.fallback.facebook_url' => null, 'shop.fallback.shop_address' => null]);   // Luma tests start with no contact details, as before
    }

    private const RETIRED_EN = "You're welcome! Luma is always here to help.";
    private const RETIRED_TL = 'Walang anuman po! Nandito lang si Luma para tumulong.';

    private function intent(string $keywords, string $en, ?string $tl = null): ChatbotIntent
    {
        return ChatbotIntent::create(['keywords' => $keywords, 'response' => $en, 'answer_tl' => $tl, 'suggestions' => 'Prices, Delivery', 'is_active' => true]);
    }

    private function ask(string $message): array
    {
        return $this->postJson(route('chatbot.respond'), ['message' => $message])->assertOk()->json();
    }

    /* ───────────── detection ───────────── */

    public function test_tagalog_and_english_are_told_apart(): void
    {
        foreach (['Magkano po ang pintuan?', 'gaano katagal gawin ang mesa', 'pwede po bang magpagawa ng kama', 'Kumusta po', 'meron ba kayong upuan', 'salamat po', 'Paano mag-order?', 'libre ba ang delivery'] as $text) {
            $this->assertSame('tl', Language::detect($text), $text);
        }

        foreach (['How much is a door?', 'Do you have a sofa', 'where is my order', 'hello', 'Can I customize the size?', '', '12345', 'delivery', 'What is the best wood for a table'] as $text) {
            $this->assertSame('en', Language::detect($text), $text);
        }
    }

    public function test_english_is_the_default_when_there_are_no_tagalog_words(): void
    {
        $this->assertSame('en', Language::detect('xyzzy'));
        $this->assertSame('en', Language::detect('GCash or Stripe?'));
    }

    /* ───────────── answers ───────────── */

    public function test_the_tagalog_answer_is_returned_for_a_tagalog_question(): void
    {
        $this->intent('price', 'Prices depend on the piece.', 'Nag-iiba po ang presyo depende sa piraso.');

        $en = $this->ask('what is the price');
        $tl = $this->ask('magkano po');

        $this->assertStringContainsString('Prices depend on the piece.', $en['response']);
        $this->assertStringNotContainsString('Nag-iiba', $en['response']);
        $this->assertStringContainsString('Nag-iiba po ang presyo depende sa piraso.', $tl['response']);
        $this->assertSame('tl', $tl['lang']);
        $this->assertSame('en', $en['lang']);
    }

    public function test_a_tagalog_question_without_a_tagalog_answer_gets_the_english_one(): void
    {
        $this->intent('price', 'Prices depend on the piece.');

        $tl = $this->ask('magkano po');

        $this->assertStringContainsString('Prices depend on the piece.', $tl['response']);
        $this->assertStringEndsWith('Prices depend on the piece.', $tl['response']);
    }

    public function test_no_answer_ends_with_a_sign_off_line(): void
    {
        $this->intent('price', 'Prices depend on the piece.', 'Nag-iiba po ang presyo.');

        foreach (['what is the price' => 'Prices depend on the piece.', 'magkano po' => 'Nag-iiba po ang presyo.'] as $text => $ends) {
            $reply = $this->ask($text)['response'];

            $this->assertStringEndsWith($ends, $reply, $text);
            $this->assertStringNotContainsString('always here to help', $reply, $text);
            $this->assertStringNotContainsString('Nandito lang', $reply, $text);
        }

        // a miss and thanks are plain too
        $this->assertStringNotContainsString('always here to help', $this->ask('xyzzy')['response']);
        $this->assertSame("You're welcome.", $this->ask('thank you')['response']);
    }

    public function test_a_retired_sign_off_an_admin_typed_into_an_answer_is_removed(): void
    {
        $this->intent('price', 'Prices depend. ' . self::RETIRED_EN, 'Nag-iiba po. ' . self::RETIRED_TL);

        $this->assertSame('Prices depend.', $this->ask('price')['response']);
        $this->assertSame('Nag-iiba po.', $this->ask('magkano po')['response']);
    }

    public function test_the_sign_off_is_gone_from_the_code_and_only_listed_as_retired_in_config(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $this->assertStringNotContainsString('Luma is always here', file_get_contents($file->getPathname()), $file->getFilename());
        }

        $this->assertNull(config('luma.closing'));
        $this->assertContains(self::RETIRED_EN, config('luma.retired_closings'));
    }

    public function test_the_fallback_is_bilingual_and_explains_itself(): void
    {
        $en = $this->ask('xyzzy plugh');
        $tl = $this->ask('ewan ko po xyzzy');

        $this->assertStringStartsWith("Sorry, I can't answer that one.", $en['response']);
        $this->assertStringContainsString('You can also ask me about our products, prices, ordering, delivery or payment.', $en['response']);
        $this->assertStringNotContainsString("You're welcome", $en['response']);

        $this->assertStringStartsWith('Pasensya na po', $tl['response']);
        $this->assertStringContainsString('website', $tl['response']);
        $this->assertStringNotContainsString('Walang anuman', $tl['response']);
        $this->assertContains('Makausap ang isang tao', $tl['suggestions']);
        $this->assertCount(5, $tl['suggestions']);
    }

    public function test_finish_removes_retired_sign_offs_and_adds_safe_html(): void
    {
        $this->assertSame('Hello.', Luma::finish(['text' => "Hello.\n\n" . self::RETIRED_TL, 'lang' => 'tl'])['text']);
        $this->assertSame('Hello.', Luma::finish(['text' => 'Hello. ' . self::RETIRED_EN, 'lang' => 'en'])['text']);
        $this->assertSame('<p>&lt;b&gt;Hello&lt;/b&gt;</p>', Luma::finish(['text' => '<b>Hello</b>', 'lang' => 'en'])['html']);
    }

    public function test_the_chat_greeting_introduces_luma_in_both_languages(): void
    {
        $widget = file_get_contents(public_path('assets/main.js'));

        $this->assertStringContainsString("Hi, I'm Luma, LP Furniture's shop assistant.", $widget);
        $this->assertStringContainsString('Kumusta po', $widget);
    }

    /* ───────────── column ───────────── */

    public function test_the_tagalog_column_exists_and_the_migration_is_idempotent_and_reversible(): void
    {
        $this->assertTrue(Schema::hasColumn('chatbot_intents', 'answer_tl'));
        $before = Schema::getColumnListing('chatbot_intents');

        $migration = require database_path('migrations/2026_10_18_090000_add_answer_tl_to_chatbot_intents.php');

        $migration->up(); // already there: nothing happens
        $this->assertSame($before, Schema::getColumnListing('chatbot_intents'));

        $migration->down(); // drops only that column
        $this->assertFalse(Schema::hasColumn('chatbot_intents', 'answer_tl'));
        $this->assertSame(array_values(array_diff($before, ['answer_tl'])), Schema::getColumnListing('chatbot_intents'));

        $migration->down(); // twice is fine
        $migration->up();
        $this->assertTrue(Schema::hasColumn('chatbot_intents', 'answer_tl'));
    }

    public function test_the_admin_can_save_a_tagalog_answer(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'admin')->post(route('admin.chatbot.store'), [
            'keywords' => 'sofa', 'response' => 'We make sofas.', 'answer_tl' => 'Gumagawa po kami ng sopa.', 'is_active' => 1,
        ])->assertRedirect(route('admin.chatbot.index'));

        $id = ChatbotIntent::where('keywords', 'sofa')->value('id');
        $this->assertSame('Gumagawa po kami ng sopa.', ChatbotIntent::find($id)->answer_tl);
        // the old Edit URL lands on the list with that intent's modal open (the Tagalog answer is in the modal's form)
        $this->get(route('admin.chatbot.edit', $id))->assertRedirect(route('admin.chatbot.index', ['edit' => $id]));
        $this->get(route('admin.chatbot.index', ['edit' => $id]))->assertOk()->assertSee('Gumagawa po kami ng sopa.')->assertSee('data-open-on-load', false);
    }
}
