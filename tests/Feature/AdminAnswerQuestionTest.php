<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Models\LumaUnansweredQuestion as Q;
use App\Support\Luma\UnansweredQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Chatbot Training, Unanswered Questions tab: answer a question in the same Add Intent modal. The save creates the intent with the
 * usual validation and marks the question taught (it leaves the list and the tab count) in the same transaction.
 */
class AdminAnswerQuestionTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->setUpLuma();
    }

    private function row(string $question, int $times = 1): Q
    {
        return Q::create([
            'question_key' => UnansweredQuestions::key($question), 'question' => $question, 'times_asked' => $times,
            'first_asked_at' => now(), 'last_asked_at' => now(),
        ]);
    }

    private function tab(): string
    {
        return $this->actingAs($this->admin, 'admin')->get(route('admin.chatbot.index', ['tab' => 'unanswered']))->assertOk()->getContent();
    }

    private function save(array $over = [])
    {
        return $this->actingAs($this->admin, 'admin')->from(route('admin.chatbot.index', ['tab' => 'unanswered']))->post(route('admin.chatbot.store'), $over + [
            '_modal' => 'intent-add', 'keywords' => 'do you make boats', 'response' => 'No, we only make furniture.', 'is_active' => 1,
        ]);
    }

    public function test_each_question_has_an_answer_button_and_a_clickable_question_with_its_keywords(): void
    {
        $row = $this->row('Do you make BOATS??', 3);

        $html = $this->tab();

        $this->assertStringContainsString('Unanswered Questions (1)', $html);
        // a real button for the question text and an Answer button, both reading the row's data
        $this->assertMatchesRegularExpression('~<button type="button" class="ua-q" data-answer-question[^>]*>Do you make BOATS\?\?</button>~', $html);
        $this->assertMatchesRegularExpression('~<button type="button" class="admin-btn admin-btn-gold" data-answer-question[^>]*>Answer</button>~', $html);
        $this->assertStringContainsString('data-keywords="do you make boats"', $html);              // the stored, cleaned form of the question
        $this->assertStringContainsString('data-id="' . $row->id . '"', $html);
        $this->assertStringContainsString('data-times="Asked 3 times"', $html);
        $this->assertStringContainsString('Dismiss', $html);                                          // Dismiss stays next to it
        // the one shared Add Intent modal is on this tab, with the hidden question id and the note area (hidden until used)
        $this->assertStringContainsString('id="intent-add"', $html);
        $this->assertStringContainsString('name="unanswered_id"', $html);
        $this->assertMatchesRegularExpression('~data-answer-note role="note"\s+hidden~', $html);
    }

    public function test_the_plain_add_intent_modal_on_the_intents_tab_has_no_note_and_no_question(): void
    {
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.chatbot.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~name="unanswered_id" value=""~', $html);
        $this->assertMatchesRegularExpression('~data-answer-note role="note"\s+hidden~', $html);
    }

    public function test_saving_creates_the_intent_and_the_question_leaves_the_list_and_the_count(): void
    {
        $answered = $this->row('Do you make boats?', 2);
        $other = $this->row('Do you sell pianos?', 1);

        $this->save(['unanswered_id' => $answered->id])
            ->assertRedirect(route('admin.chatbot.index', ['tab' => 'unanswered']))
            ->assertSessionHas('success', 'Intent added successfully.')->assertSessionHasNoErrors();

        $intent = ChatbotIntent::where('keywords', 'do you make boats')->firstOrFail();
        $this->assertTrue($intent->is_active);
        $this->assertNotNull($answered->fresh()->taught_at);
        $this->assertNull($answered->fresh()->dismissed_at);
        $this->assertNull($other->fresh()->taught_at);

        $html = $this->tab();
        $this->assertStringContainsString('Unanswered Questions (1)', $html);
        $this->assertStringNotContainsString('Do you make boats?', $html);
        $this->assertStringContainsString('Do you sell pianos?', $html);
    }

    public function test_the_saved_intent_answers_the_exact_question_in_luma(): void
    {
        $row = $this->row('Do you make boats?');
        $this->assertStringNotContainsString('only make furniture', $this->say('Do you make boats?')['response']);

        $this->save(['unanswered_id' => $row->id, 'keywords' => $row->question_key])->assertSessionHasNoErrors();

        $this->assertStringContainsString('No, we only make furniture.', $this->say('Do you make boats?')['response']);
        $this->assertStringContainsString('No, we only make furniture.', $this->say('do you make boats')['response']);
    }

    public function test_an_inactive_save_still_removes_the_question_because_the_admin_answered_it(): void
    {
        $row = $this->row('Do you make boats?');

        $this->save(['unanswered_id' => $row->id, 'is_active' => 0])->assertSessionHasNoErrors();

        $this->assertFalse(ChatbotIntent::where('keywords', 'do you make boats')->firstOrFail()->is_active);
        $this->assertNotNull($row->fresh()->taught_at);
    }

    public function test_a_question_already_gone_dismissed_or_handled_by_another_admin_still_saves_without_error(): void
    {
        $gone = $this->row('Do you make boats?');
        $id = $gone->id;
        $gone->delete();                                                  // another admin deleted it
        $this->save(['unanswered_id' => $id])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(1, ChatbotIntent::where('keywords', 'do you make boats')->count());

        $dismissed = $this->row('Do you sell pianos?');
        $dismissed->forceFill(['dismissed_at' => now()->subHour()])->save();
        $this->save(['unanswered_id' => $dismissed->id, 'keywords' => 'pianos'])->assertSessionHasNoErrors();
        $this->assertNull($dismissed->fresh()->taught_at);                // left as the first admin handled it
        $this->assertNotNull($dismissed->fresh()->dismissed_at);

        $taught = $this->row('Do you sell violins?');
        $taught->forceFill(['taught_at' => now()->subMinute()])->save();
        $stamp = $taught->fresh()->taught_at;
        $this->save(['unanswered_id' => $taught->id, 'keywords' => 'violins'])->assertSessionHasNoErrors();
        $this->assertEquals($stamp, $taught->fresh()->taught_at);

        $this->save(['unanswered_id' => 'abc', 'keywords' => 'weird id'])->assertSessionHasNoErrors();
        $this->save(['unanswered_id' => 999999, 'keywords' => 'missing id'])->assertSessionHasNoErrors();
        $this->assertSame(1, ChatbotIntent::where('keywords', 'weird id')->count());
        $this->assertSame(1, ChatbotIntent::where('keywords', 'missing id')->count());
    }

    public function test_a_rejected_save_keeps_the_question_and_reopens_the_modal_with_the_note(): void
    {
        $row = $this->row('Do you make boats?', 3);
        $before = ChatbotIntent::count();

        $this->save(['unanswered_id' => $row->id, 'response' => '', 'category' => str_repeat('C', 120)])
            ->assertRedirect(route('admin.chatbot.index', ['tab' => 'unanswered']))->assertSessionHasErrors(['response', 'category']);

        $this->assertSame($before, ChatbotIntent::count());
        $this->assertNull($row->fresh()->taught_at);

        $html = $this->tab();
        $this->assertStringContainsString('Unanswered Questions (1)', $html);                      // the question stays in the list
        $this->assertMatchesRegularExpression('/id="intent-add"[^>]*data-open-on-load/', $html);
        $this->assertStringContainsString('name="unanswered_id" value="' . $row->id . '"', $html);  // still tied to the question
        $this->assertStringContainsString('Answering a Question', $html);
        $this->assertStringContainsString('Asked 3 times', $html);
        $this->assertStringContainsString('do you make boats</textarea>', $html);                  // typed keywords kept
        $this->assertStringContainsString('The response field is required.', $html);
        $this->assertDoesNotMatchRegularExpression('~data-answer-note role="note"\s+hidden~', $html);
    }

    public function test_a_tagalog_looking_question_says_so_in_the_note_only(): void
    {
        $row = $this->row('Magkano po ang mesa?', 1);

        $this->save(['unanswered_id' => $row->id, 'keywords' => 'magkano mesa', 'response' => ''])->assertSessionHasErrors('response');

        $html = $this->tab();
        $this->assertMatchesRegularExpression('~<span data-answer-tl\s*>This question looks like Tagalog\.</span>~', $html);
        $this->assertStringNotContainsString('name="answer_tl"></textarea><script', $html);
        $this->assertMatchesRegularExpression('~name="answer_tl"[^>]*>\s*</textarea>~', $html);     // the Tagalog Response field stays empty

        // an English question keeps that sentence hidden
        $en = $this->row('Do you make boats?');
        $this->save(['unanswered_id' => $en->id, 'response' => ''])->assertSessionHasErrors('response');
        $this->assertMatchesRegularExpression('~<span data-answer-tl\s+hidden\s*>~', $this->tab());
    }

    public function test_dismiss_works_exactly_as_before_next_to_the_answer_button(): void
    {
        $row = $this->row('Do you make boats?');

        $this->actingAs($this->admin, 'admin')->post(route('admin.chatbot.unanswered.dismiss', $row->id))->assertRedirect()->assertSessionHas('success', 'Question dismissed.');

        $this->assertNotNull($row->fresh()->dismissed_at);
        $this->assertNull($row->fresh()->taught_at);
        $this->assertStringContainsString('Unanswered Questions (0)', $this->tab());
    }

    public function test_the_answer_markup_is_escaped_and_only_admins_can_save(): void
    {
        $this->row('<b onmouseover=alert(1)>boats</b> do you make them', 1);
        $html = $this->tab();

        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('&lt;b onmouseover=alert(1)&gt;', $html);

        auth('admin')->logout();
        $this->post(route('admin.chatbot.store'), ['keywords' => 'x y', 'response' => 'z', 'unanswered_id' => 1])->assertRedirect();   // guests
        $this->assertSame(0, ChatbotIntent::where('keywords', 'x y')->count());
    }

    public function test_customer_pages_do_not_load_the_answer_script_or_markup(): void
    {
        foreach (['/', '/terms', '/catalog'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('data-answer-question', $html, $url);
            $this->assertStringNotContainsString('admin-modal-forms.js', $html, $url);
        }
    }
}
