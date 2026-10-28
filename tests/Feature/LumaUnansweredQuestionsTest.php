<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Models\LumaUnansweredQuestion as Q;
use App\Support\Luma\UnansweredQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * "Questions Luma could not answer": what is kept (and what never is), how repeats are grouped, the per-visitor limit, the admin
 * list with Dismiss, the automatic exit when an intent is taught, and the 30-day cleanup.
 */
class LumaUnansweredQuestionsTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();   // the rate limiter lives in the cache
        $this->setUpLuma();
    }

    private function row(string $question, int $times = 1, array $over = []): Q
    {
        return Q::create($over + [
            'question_key' => UnansweredQuestions::key($question), 'question' => $question, 'times_asked' => $times,
            'first_asked_at' => now(), 'last_asked_at' => now(),
        ]);
    }

    private function admin()
    {
        return $this->makeUser('admin');
    }

    /* ───────────── what is never stored ───────────── */

    public function test_links_and_domains_are_skipped(): void
    {
        foreach (['visit https://spam.example/win now', 'go to www.spam.example', 'check spam.com please', 'is it on lazada.ph or not', 'open bit.ly/abc123 for me'] as $text) {
            $this->assertSame('link', UnansweredQuestions::skipReason($text), $text);
            $this->assertFalse(UnansweredQuestions::record($text), $text);
        }
        $this->assertSame(0, Q::count());
    }

    public function test_profanity_is_skipped_in_english_tagalog_and_adult_terms(): void
    {
        foreach (['what the fuck is this shop', 'putangina ang tagal ng order ko', 'tanginang delivery yan', 'gago ka ba', 'do you sell nsfw items', 'sex toys for sale'] as $text) {
            $this->assertSame('profanity', UnansweredQuestions::skipReason($text), $text);
            $this->assertFalse(UnansweredQuestions::record($text), $text);
        }
        $this->assertSame(0, Q::count());
        $this->assertNull(UnansweredQuestions::skipReason('do you make boats'));   // an ordinary question passes
    }

    public function test_too_short_and_too_long_are_skipped(): void
    {
        $this->assertSame('too_short', UnansweredQuestions::skipReason('hi'));
        $this->assertSame('too_short', UnansweredQuestions::skipReason('  a  '));
        $this->assertSame('too_long', UnansweredQuestions::skipReason(str_repeat('do you sell pianos ', 12)));   // 228 characters
        $this->assertNull(UnansweredQuestions::skipReason(str_repeat('a', 3) . ' ok'));
        $this->assertNull(UnansweredQuestions::skipReason(substr(str_repeat('do you sell pianos ', 12), 0, 200)));
    }

    public function test_gibberish_and_repeated_characters_are_skipped(): void
    {
        foreach (['asdfghjkl', 'qwertyuiop', 'zxcvbnm', 'aaaaaaaaaa', 'hahahahaha!!!!!!!', 'bcdfgh jklmnp', '12345 67890', '!!!???...', 'lkjhgfdsa poiuytrewq', 'thisisonereallylongwordwithoutanyspaces'] as $text) {
            $this->assertSame('gibberish', UnansweredQuestions::skipReason($text) ?? 'gibberish', $text);
            $this->assertFalse(UnansweredQuestions::record($text), $text);
        }
        foreach (['do you sell pianos', 'may piano po ba kayo', 'can you make a coffin', 'magkano ang rocking horse'] as $ok) {
            $this->assertNull(UnansweredQuestions::skipReason($ok), $ok);
        }
    }

    /* ───────────── what is removed from what is stored ───────────── */

    public function test_phone_numbers_emails_and_addresses_are_removed(): void
    {
        $this->assertTrue(UnansweredQuestions::record('do you sell pianos call me at 0917 123 4567 or mail juan@example.com'));
        $this->assertTrue(UnansweredQuestions::record('can you deliver a piano to Brgy. San Isidro Lipa City'));
        $this->assertTrue(UnansweredQuestions::record('do you repair violins at 123 Mabini Street Batangas'));

        $stored = Q::orderBy('id')->pluck('question')->all();

        $this->assertSame('do you sell pianos call me at or mail', $stored[0]);
        $this->assertStringNotContainsString('0917', $stored[0]);
        $this->assertStringNotContainsString('@', $stored[0]);
        $this->assertSame('can you deliver a piano to', $stored[1]);
        $this->assertStringNotContainsString('San Isidro', $stored[1]);
        $this->assertStringNotContainsString('Mabini', $stored[2]);
        $this->assertStringNotContainsString('123', $stored[2]);
    }

    public function test_a_question_that_is_only_personal_details_is_not_kept(): void
    {
        $this->assertFalse(UnansweredQuestions::record('juan@example.com'));
        $this->assertFalse(UnansweredQuestions::record('0917 123 4567'));
        $this->assertSame(0, Q::count());
    }

    /* ───────────── grouping ───────────── */

    public function test_repeats_are_grouped_and_counted(): void
    {
        $this->assertTrue(UnansweredQuestions::record('Do you sell pianos?'));
        $this->assertTrue(UnansweredQuestions::record('do you sell PIANOS'));
        $this->assertTrue(UnansweredQuestions::record('  do   you sell pianos!!  '));
        $this->assertTrue(UnansweredQuestions::record('do you sell violins'));

        $this->assertSame(2, Q::count());
        $pianos = Q::where('question_key', 'do you sell pianos')->first();
        $this->assertSame(3, $pianos->times_asked);
        $this->assertSame('Do you sell pianos?', $pianos->question);   // as first typed
        $this->assertSame('do you sell pianos', UnansweredQuestions::key('  Do you sell PIANOS?!  '));
    }

    public function test_a_question_asked_again_after_it_was_dismissed_comes_back(): void
    {
        $row = $this->row('do you sell pianos', 2, ['dismissed_at' => now()->subDay()]);

        $this->assertTrue(UnansweredQuestions::record('do you sell pianos'));

        $this->assertNull($row->fresh()->dismissed_at);
        $this->assertSame(3, $row->fresh()->times_asked);
    }

    /* ───────────── flooding ───────────── */

    public function test_one_visitor_cannot_flood_the_table(): void
    {
        $subjects = ['pianos', 'violins', 'guitars', 'drums', 'flutes', 'harps', 'tubas', 'cellos', 'banjos', 'organs'];
        $saved = 0;
        foreach ($subjects as $s) {
            $saved += UnansweredQuestions::record("do you sell {$s}") ? 1 : 0;
        }

        $this->assertSame(UnansweredQuestions::LIMIT_PER_HOUR, $saved);
        $this->assertSame(UnansweredQuestions::LIMIT_PER_HOUR, Q::count());
        // a question that is already in the table still counts again: the limit is on new rows
        $this->assertTrue(UnansweredQuestions::record('do you sell pianos'));
        $this->assertSame(2, Q::where('question_key', 'do you sell pianos')->value('times_asked'));
    }

    public function test_the_limit_is_per_visitor(): void
    {
        foreach (['pianos', 'violins', 'guitars', 'drums', 'flutes', 'harps', 'tubas', 'cellos'] as $s) {
            UnansweredQuestions::record("do you sell {$s}");
        }
        $this->assertFalse(UnansweredQuestions::record('do you sell banjos'));

        $this->actingAs($this->makeUser());   // another visitor (a signed-in customer has her own allowance)
        $this->assertTrue(UnansweredQuestions::record('do you sell banjos'));
    }

    /* ───────────── through the chat ───────────── */

    public function test_the_chat_saves_an_unknown_question_and_says_so(): void
    {
        $reply = $this->say('do you sell pianos');
        $this->assertStringContainsString('I saved your question so our team can teach me the answer.', $reply['response']);
        $this->say('Do you sell pianos?');

        $this->assertSame(1, Q::count());
        $this->assertSame(2, Q::first()->times_asked);
    }

    public function test_answered_questions_are_not_kept(): void
    {
        $this->say('contact information');
        $this->say('how much is a door');

        $this->assertSame(0, Q::count());
    }

    /* ───────────── the admin list ───────────── */

    public function test_the_list_is_sorted_by_times_then_recency_shows_the_count_and_is_capped_at_50(): void
    {
        $this->row('old but popular question', 9, ['last_asked_at' => now()->subDays(5)]);
        $this->row('newer less popular question', 2, ['last_asked_at' => now()->subHour()]);
        $this->row('same count but older one', 2, ['last_asked_at' => now()->subDays(2)]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'newer less popular question'), strpos($html, 'old but popular question'));
        $this->assertLessThan(strpos($html, 'same count but older one'), strpos($html, 'newer less popular question'));
        $this->assertStringContainsString('Asked 9 times', $html);
        $this->assertStringContainsString(now()->subDays(5)->timezone('Asia/Manila')->format('M j, Y'), $html);

        for ($i = 0; $i < 55; $i++) {
            $this->row('bulk question ' . str_pad((string) $i, 2, 'x', STR_PAD_LEFT) . ' words', 1);
        }
        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->getContent();
        $this->assertSame(50, preg_match_all('/chatbot\/unanswered\/\d+\/dismiss/', $page));
    }

    public function test_everything_in_the_list_is_escaped_and_never_clickable(): void
    {
        $this->row('<script>alert(1)</script> <a href="https://evil.example">click</a> https://evil.example', 1);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('href="https://evil.example"', $html);
    }

    public function test_the_empty_state_says_so(): void
    {
        $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->assertOk()->assertSee('No unanswered questions right now.');
    }

    /* ───────────── dismiss, and who may ───────────── */

    public function test_dismiss_hides_the_question_but_keeps_the_row(): void
    {
        $row = $this->row('do you sell pianos', 3);
        $other = $this->row('do you sell violins', 1);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.chatbot.unanswered.dismiss', $row->id))->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull($row->fresh()->dismissed_at);
        $this->assertNull($other->fresh()->dismissed_at);
        $this->assertSame(2, Q::count());   // kept in the table until the 30-day cleanup
        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->getContent();
        $this->assertStringNotContainsString('do you sell pianos', $page);
        $this->assertStringContainsString('do you sell violins', $page);
    }

    public function test_customers_and_guests_can_never_dismiss_or_see_the_list(): void
    {
        $row = $this->row('do you sell pianos', 3);

        // a guest
        $this->post(route('admin.chatbot.unanswered.dismiss', $row->id))->assertRedirect();
        $this->get(route('admin.chatbot.index'))->assertRedirect();
        $this->assertNull($row->fresh()->dismissed_at);

        // a signed-in customer (web guard)
        $customer = $this->makeUser();
        $this->actingAs($customer)->post(route('admin.chatbot.unanswered.dismiss', $row->id))->assertRedirect();
        $this->actingAs($customer)->get(route('admin.chatbot.index'))->assertRedirect();
        $this->assertNull($row->fresh()->dismissed_at);

        // a customer account pushed through the admin guard still fails the role check
        $this->actingAs($customer, 'admin')->post(route('admin.chatbot.unanswered.dismiss', $row->id))->assertRedirect();
        $this->assertNull($row->fresh()->dismissed_at);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.chatbot.unanswered.dismiss', 999999))->assertNotFound();
    }

    /* ───────────── leaving the list when she is taught ───────────── */

    public function test_a_question_leaves_the_list_when_an_intent_with_a_matching_keyword_is_added(): void
    {
        $pianos  = $this->row('do you sell pianos', 4);
        $violins = $this->row('do you sell violins', 2);

        $this->actingAs($this->admin(), 'admin')->post(route('admin.chatbot.store'), [
            'keywords' => 'piano, pianos, keyboard', 'response' => 'We do not sell pianos, but we make piano benches.', 'category' => 'taught', 'is_active' => '1',
        ])->assertRedirect();

        $this->assertNotNull($pianos->fresh()->taught_at);
        $this->assertNull($violins->fresh()->taught_at);
        $this->assertNull($pianos->fresh()->dismissed_at);
        $page = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->getContent();
        $this->assertStringNotContainsString('do you sell pianos', $page);
        $this->assertStringContainsString('do you sell violins', $page);
    }

    public function test_editing_an_intent_to_match_also_clears_the_question_and_an_inactive_intent_does_not(): void
    {
        $violins = $this->row('do you sell violins', 2);
        $intent = ChatbotIntent::create(['keywords' => 'strings', 'response' => 'Ask the shop.', 'is_active' => false]);
        $this->assertNull($violins->fresh()->taught_at);

        // saving an inactive intent does not count
        $intent->update(['keywords' => 'violin, violins']);
        $this->assertNull($violins->fresh()->taught_at);

        $this->actingAs($this->admin(), 'admin')->put(route('admin.chatbot.update', $intent->id), [
            'keywords' => 'violin, violins', 'response' => 'No violins.', 'is_active' => '1',
        ])->assertRedirect();

        $this->assertNotNull($violins->fresh()->taught_at);
    }

    /* ───────────── cleanup and clearing ───────────── */

    public function test_storage_cleanup_deletes_dismissed_and_taught_questions_after_30_days(): void
    {
        $oldDismissed = $this->row('old dismissed question', 1, ['dismissed_at' => now()->subDays(31)]);
        $oldTaught    = $this->row('old taught question', 1, ['taught_at' => now()->subDays(31)]);
        $newDismissed = $this->row('new dismissed question', 1, ['dismissed_at' => now()->subDays(29)]);
        $oldOpen      = $this->row('old open question', 5, ['first_asked_at' => now()->subDays(90), 'last_asked_at' => now()->subDays(90)]);

        // a dry run only counts
        $this->artisan('storage:cleanup')->expectsOutputToContain('Would remove dismissed or taught Luma questions older than 30 days')->assertSuccessful();
        $this->assertSame(4, Q::count());

        $this->artisan('storage:cleanup', ['--force' => true])->assertSuccessful();

        $this->assertNull(Q::find($oldDismissed->id));
        $this->assertNull(Q::find($oldTaught->id));
        $this->assertNotNull(Q::find($newDismissed->id));
        $this->assertNotNull(Q::find($oldOpen->id));   // a question nobody has handled is never thrown away
    }

    public function test_the_chat_logs_nothing_personal_in_the_question_table_even_from_a_chat_message(): void
    {
        $this->say('do you sell pianos 0917 123 4567 juan@example.com');

        $this->assertSame('do you sell pianos', Q::value('question'));
        $this->assertSame(0, DB::table('luma_unanswered_questions')->where('question', 'like', '%0917%')->count());
    }
}
