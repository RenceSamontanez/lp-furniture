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
 * Stage 20 part B: Luma answers in the language of the question and ends every answer with one closing line.
 */
class LumaLanguageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const CLOSING_EN = "You're welcome! Luma is always here to help.";
    private const CLOSING_TL = 'Walang anuman po! Nandito lang si Luma para tumulong.';

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
        $this->assertStringEndsWith(self::CLOSING_TL, $tl['response']);
    }

    public function test_every_answer_ends_with_the_closing_line_exactly_once(): void
    {
        $this->intent('price', 'Prices depend on the piece.', 'Nag-iiba po ang presyo.');

        // an answer ends "You're welcome! ..."; a miss ends with the fallback line, which never says "You're welcome"
        foreach ([
            'what is the price' => self::CLOSING_EN, 'magkano po' => self::CLOSING_TL,
            'xyzzy' => 'Luma is always here to help.', 'hindi ko alam po' => 'Nandito lang po si Luma para tumulong.', 'ewan po' => 'Nandito lang po si Luma para tumulong.',
        ] as $text => $closing) {
            $reply = $this->ask($text)['response'];

            $this->assertStringEndsWith($closing, $reply, $text);
            $this->assertSame(1, substr_count($reply, 'Luma is always here to help') + substr_count($reply, 'Nandito lang'), $text);
        }
    }

    public function test_the_closing_line_is_never_doubled_when_an_admin_typed_it_in_the_answer(): void
    {
        $this->intent('price', 'Prices depend. ' . self::CLOSING_EN, 'Nag-iiba po. ' . self::CLOSING_TL);

        $this->assertSame(1, substr_count($this->ask('price')['response'], self::CLOSING_EN));
        $this->assertSame(1, substr_count($this->ask('magkano po')['response'], self::CLOSING_TL));
    }

    public function test_the_closing_line_is_added_in_one_place_only(): void
    {
        $hits = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (str_contains($code, 'Luma is always here') || str_contains($code, 'luma.closing')) {
                $hits[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            }
        }

        $this->assertSame(['Support/Luma/Luma.php'], $hits);
        $this->assertStringContainsString('Luma is always here to help', file_get_contents(config_path('luma.php')));
    }

    public function test_the_fallback_is_bilingual_and_closes_too(): void
    {
        $en = $this->ask('xyzzy plugh');
        $tl = $this->ask('ewan ko po xyzzy');

        $this->assertStringContainsString("I couldn't quite find an answer", $en['response']);
        $this->assertStringEndsWith('Luma is always here to help.', $en['response']);
        $this->assertStringNotContainsString("You're welcome", $en['response']);

        $this->assertStringContainsString('Pasensya na po', $tl['response']);
        $this->assertStringContainsString('website', $tl['response']);
        $this->assertStringEndsWith('Nandito lang po si Luma para tumulong.', $tl['response']);
        $this->assertStringNotContainsString('Walang anuman', $tl['response']);
        $this->assertContains('Makausap ang isang tao', $tl['suggestions']);
        $this->assertCount(5, $tl['suggestions']);
    }

    public function test_finish_adds_the_closing_in_the_language_of_the_answer(): void
    {
        $this->assertSame("Hello.\n\n" . self::CLOSING_TL, Luma::finish(['text' => 'Hello.', 'lang' => 'tl'])['text']);
        $this->assertSame("Hello.\n\n" . self::CLOSING_EN, Luma::finish(['text' => 'Hello.', 'lang' => 'en'])['text']);
    }

    public function test_the_chat_greeting_introduces_luma_in_both_languages(): void
    {
        $widget = file_get_contents(public_path('assets/main.js'));

        $this->assertStringContainsString("Hi, I'm Luma, LP Furniture Shop's AI Assistant", $widget);
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
        $this->get(route('admin.chatbot.edit', $id))->assertOk()->assertSee('Gumagawa po kami ng sopa.');
    }
}
