<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use App\Support\Luma\Contact;
use App\Support\Luma\DefaultIntents;
use App\Support\Luma\Luma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Answer format v2: contact details come from Contact Settings (the footer's source), the contact answer and the unknown-question
 * reply are label/value rows, the reply carries safe HTML, and the reworded answers reach the stored rows without touching an
 * admin's edits.
 */
class LumaAnswerFormatTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLuma();   // no shop.fallback contact details unless a test sets them
    }

    private function settings(array $values): void
    {
        BusinessSetting::query()->first()->update($values + ['phone' => '', 'email' => '', 'facebook_url' => '']);
    }

    /* ───────────── contact information ───────────── */

    public function test_the_contact_answer_is_label_value_rows_for_the_fields_that_are_set(): void
    {
        $this->settings(['phone' => '0917-222-3333', 'email' => 'owner@lp.test', 'facebook_url' => 'https://facebook.com/lpfurnituresj', 'shop_address' => 'Sitio Pulo Ilaya, San Juan, Batangas']);

        $reply = $this->say('contact information');

        $this->assertSame("You can reach our team here:\n\nEmail: owner@lp.test\nPhone: 0917 222 3333\nFacebook: [LP Furniture on Facebook](https://facebook.com/lpfurnituresj)\nShop: Sitio Pulo Ilaya, San Juan, Batangas\nOpen: 8:00 AM to 5:00 PM, Monday to Friday\n\nOr keep chatting with me. I can help with most questions.", $reply['response']);
        $this->assertStringContainsString('<dl class="lf-kv"><dt>Email</dt><dd><a href="mailto:owner@lp.test">owner@lp.test</a></dd>', $reply['html']);
        $this->assertStringContainsString('<a href="tel:09172223333" class="lf-num">0917 222 3333</a>', $reply['html']);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer nofollow">LP Furniture on Facebook</a>', $reply['html']);
        $this->assertNull($reply['contact']);   // the rows are the card
        $this->assertStringNotContainsString('always here to help', $reply['response']);
    }

    public function test_a_missing_field_has_no_row(): void
    {
        $this->settings(['email' => 'owner@lp.test']);

        $reply = $this->say('contact information');

        $this->assertStringContainsString("Email: owner@lp.test\nOpen:", $reply['response']);
        foreach (['Phone', 'Facebook', 'Shop'] as $label) {
            $this->assertStringNotContainsString("{$label}:", $reply['response'], $label);
        }
    }

    public function test_with_nothing_set_the_answer_points_to_the_website_not_to_fake_details(): void
    {
        $this->settings([]);

        $reply = $this->say('contact information');

        $this->assertStringContainsString('You can reach us through our website.', $reply['response']);
        $this->assertStringNotContainsString('Email:', $reply['response']);
        $this->assertStringNotContainsString('contact@example.com', $reply['response']);
        $this->assertStringNotContainsString('0917 000 0000', $reply['response']);
    }

    public function test_luma_reads_the_same_source_as_the_footer(): void
    {
        $this->settings([]);
        config(['shop.fallback.phone' => '09912394770', 'shop.fallback.email' => 'fallback@lp.test']);   // used by the footer when Contact Settings are empty

        $footer = \App\Support\FooterData::build();
        $this->assertSame('0991 239 4770', $footer['phone']['display']);
        $this->assertSame($footer['phone']['display'], Contact::phone());
        $this->assertSame($footer['email'], Contact::email());
        $this->assertStringContainsString("Email: fallback@lp.test\nPhone: 0991 239 4770", $this->say('contact information')['response']);

        $this->settings(['phone' => '0917-111-2222']);
        $this->assertSame('0917 111 2222', Contact::phone());
        $this->assertSame('0917 111 2222', \App\Support\FooterData::build()['phone']['display']);
    }

    public function test_the_contact_tokens_are_filled_at_reply_time(): void
    {
        $this->settings(['phone' => '0917-222-3333', 'email' => 'owner@lp.test', 'facebook_url' => 'https://facebook.com/lpfurnituresj', 'shop_address' => 'San Juan']);

        $full  = Luma::fill("A\n\n[[contact]]\n\nB", 'en');
        $short = Luma::fill('[[contact:short]]', 'en');
        $tl    = Luma::fill('[[contact:short]]', 'tl');

        $this->assertStringContainsString("Shop: San Juan\nOpen:", $full);
        $this->assertSame("Email: owner@lp.test\nPhone number: 0917 222 3333\nFacebook account: [LP Furniture on Facebook](https://facebook.com/lpfurnituresj)", $short);
        $this->assertSame("Email: owner@lp.test\nTelepono: 0917 222 3333\nFacebook page: [LP Furniture sa Facebook](https://facebook.com/lpfurnituresj)", $tl);
        $this->assertStringNotContainsString('[[', $full . $short);
    }

    /* ───────────── the unknown-question reply ───────────── */

    private const SORRY = "Sorry, I can't answer that one. I haven't been trained on that topic yet, and I don't want to guess and give you wrong information.";
    private const SAVED = ' I saved your question so our team can teach me the answer.';

    public function test_the_unknown_reply_when_the_question_was_saved(): void
    {
        $this->settings(['phone' => '0917-222-3333', 'email' => 'owner@lp.test', 'facebook_url' => 'https://facebook.com/lpfurnituresj', 'shop_address' => 'San Juan']);

        $reply = $this->say('do you sell pianos');

        $this->assertSame(
            self::SORRY . self::SAVED . "\n\nIf you need help sooner, you can reach the business directly:\n\n"
            . "Email: owner@lp.test\nPhone number: 0917 222 3333\nFacebook account: [LP Furniture on Facebook](https://facebook.com/lpfurnituresj)"
            . "\n\nYou can also ask me about our products, prices, ordering, delivery or payment.",
            $reply['response']
        );
        $this->assertStringNotContainsString('Shop:', $reply['response']);   // the short block: email, phone, Facebook only
        $this->assertStringNotContainsString("don't know", $reply['response']);
        $this->assertDatabaseHas('luma_unanswered_questions', ['question_key' => 'do you sell pianos', 'times_asked' => 1]);
    }

    public function test_the_unknown_reply_when_the_question_was_not_saved_has_no_saved_sentence(): void
    {
        $this->settings(['email' => 'owner@lp.test']);

        foreach (['asdfghjkl', 'visit https://spam.example now', 'qq', 'aaaaaaaaaaaa'] as $junk) {
            $reply = $this->say($junk);

            $this->assertStringStartsWith(self::SORRY . "\n\nIf you need help sooner", $reply['response'], $junk);
            $this->assertStringNotContainsString('I saved your question', $reply['response'], $junk);
            $this->assertStringContainsString("reach the business directly:\n\nEmail: owner@lp.test\n\nYou can also ask me", $reply['response'], $junk);
        }
        $this->assertDatabaseCount('luma_unanswered_questions', 0);
    }

    public function test_the_unknown_reply_has_rows_only_for_set_fields_and_html_links(): void
    {
        $this->settings(['facebook_url' => 'https://facebook.com/lpfurnituresj']);

        $reply = $this->say('do you sell pianos');

        $this->assertStringContainsString("directly:\n\nFacebook account: [LP Furniture on Facebook](", $reply['response']);
        $this->assertStringNotContainsString('Email:', $reply['response']);
        $this->assertStringNotContainsString('Phone number:', $reply['response']);
        $this->assertStringContainsString('<dt>Facebook account</dt><dd><a href="https://facebook.com/lpfurnituresj" target="_blank" rel="noopener noreferrer nofollow">LP Furniture on Facebook</a></dd>', $reply['html']);
    }

    public function test_the_tagalog_unknown_reply_has_the_same_structure(): void
    {
        $this->settings(['email' => 'owner@lp.test']);

        $reply = $this->say('may piano po ba kayo');

        $this->assertStringStartsWith('Pasensya na po, hindi ko po masagot iyan.', $reply['response']);
        $this->assertStringContainsString('Na-save ko po ang tanong ninyo', $reply['response']);
        $this->assertStringContainsString("sa shop:\n\nEmail: owner@lp.test\n\nMaaari rin po kayong magtanong", $reply['response']);
    }

    public function test_the_chat_never_breaks_when_saving_fails(): void
    {
        \App\Models\LumaUnansweredQuestion::creating(fn () => throw new \RuntimeException('table is missing'));

        $reply = $this->say('do you sell pianos');

        $this->assertStringStartsWith(self::SORRY, $reply['response']);
        $this->assertStringNotContainsString('I saved your question', $reply['response']);   // never claims a save that did not happen
    }

    /* ───────────── safe html on the wire ───────────── */

    public function test_every_reply_carries_safe_html_and_nothing_raw(): void
    {
        $this->settings([]);
        ChatbotIntent::create(['keywords' => 'xss test', 'response' => "<script>alert(1)</script> **bold** [bad](javascript:alert(1))\n- <img src=x onerror=alert(1)>", 'is_active' => true]);

        $reply = $this->say('xss test');

        $this->assertStringNotContainsString('<script', $reply['html']);
        $this->assertStringNotContainsString('<img', $reply['html']);
        $this->assertStringNotContainsString('href="javascript', $reply['html']);
        $this->assertStringContainsString('<strong>bold</strong>', $reply['html']);
        $this->assertStringContainsString('<script>', $reply['response']);   // the plain text is untouched; only the html field is for the page
    }

    public function test_the_chat_widget_renders_the_server_html_and_escapes_the_fallback(): void
    {
        $js = file_get_contents(public_path('assets/main.js'));

        $this->assertStringContainsString('extra.html', $js);
        $this->assertStringContainsString("chatEsc(part)", $js);   // without html the text is escaped before it is shown
        $this->assertStringNotContainsString('fonts.googleapis.com', file_get_contents(public_path('assets/css/fonts.css')));
    }

    /* ───────────── the reworded answers reach the stored rows ───────────── */

    public function test_the_migration_rewords_untouched_rows_and_leaves_edited_ones(): void
    {
        $previous = json_decode(file_get_contents(database_path('data/luma-previous-defaults.json')), true);

        // put the OLD texts back, as a live database has them
        foreach (['luma_greeting', 'luma_delivery_fee', 'luma_contact_shop'] as $name) {
            ChatbotIntent::where('category', $name)->update(['response' => $previous[$name]['en'], 'answer_tl' => $previous[$name]['tl']]);
        }
        ChatbotIntent::where('category', 'luma_payment_methods')->update(['response' => 'The owner wrote this one himself.']);
        $legacy = $previous['__legacy'][1];   // "how to order"
        $row = ChatbotIntent::create(['keywords' => $legacy['keywords'], 'response' => $legacy['response'], 'is_active' => true]);
        $edited = ChatbotIntent::create(['keywords' => $previous['__legacy'][4]['keywords'], 'response' => 'Our own payment words.', 'is_active' => true]);

        (require database_path('migrations/2026_11_02_090100_reword_luma_answers.php'))->up();

        $this->assertSame(DefaultIntents::all()['luma_greeting']['en'], ChatbotIntent::where('category', 'luma_greeting')->value('response'));
        $this->assertSame(DefaultIntents::all()['luma_delivery_fee']['tl'], ChatbotIntent::where('category', 'luma_delivery_fee')->value('answer_tl'));
        $this->assertStringContainsString('[[contact]]', ChatbotIntent::where('category', 'luma_contact_shop')->value('response'));
        $this->assertSame('The owner wrote this one himself.', ChatbotIntent::where('category', 'luma_payment_methods')->value('response'));
        $this->assertStringStartsWith('Here is how ordering works:', $row->fresh()->response);
        $this->assertSame('Our own payment words.', $edited->fresh()->response);

        // running it again changes nothing
        (require database_path('migrations/2026_11_02_090100_reword_luma_answers.php'))->up();
        $this->assertStringStartsWith('Here is how ordering works:', $row->fresh()->response);
    }

    public function test_no_built_in_answer_has_typed_contact_details_or_a_sign_off(): void
    {
        foreach (DefaultIntents::all() as $name => $def) {
            foreach (['en', 'tl'] as $lang) {
                $text = $def[$lang];
                $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[a-z]{2,}|https?:\/\/|facebook\.com|\b09\d{2}[\s-]?\d{3}[\s-]?\d{4}\b/i', $text, "{$name} {$lang} has typed contact details");
                $this->assertDoesNotMatchRegularExpression('/always here to help|Nandito lang si Luma|You.re welcome!/', $text, "{$name} {$lang}");
                $this->assertDoesNotMatchRegularExpression('/!\s|!$/', str_replace('Kumusta po!', 'Kumusta po', $text), "{$name} {$lang} has an exclamation mark");   // one friendly Tagalog greeting is allowed
            }
        }
        $legacy = require database_path('data/luma-legacy-rewrites.php');
        foreach ($legacy as $kw => $text) {
            $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[a-z]{2,}|https?:\/\/|facebook\.com|\b09\d{2}[\s-]?\d{3}[\s-]?\d{4}\b/i', $text, $kw);
            $this->assertDoesNotMatchRegularExpression('/!\s|!$/', $text, $kw);
        }
    }

    public function test_the_approved_formats_for_ordering_and_delivery(): void
    {
        // "how to order" is one of the 20 hand-seeded rows of the live database (no built-in twin): add the old row, reword it
        $legacy = json_decode(file_get_contents(database_path('data/luma-previous-defaults.json')), true)['__legacy'][1];
        ChatbotIntent::create(['keywords' => $legacy['keywords'], 'response' => $legacy['response'], 'is_active' => true]);
        DefaultIntents::refreshUntouched();

        $order = $this->say('how do i order');
        $this->assertStringStartsWith('Here is how ordering works:', $order['response']);
        $this->assertStringContainsString('<ol class="lf-list">', $order['html']);

        $deliver = $this->say('do you deliver');
        $this->assertStringStartsWith('Yes', $deliver['response']);
        $this->assertStringContainsString('<ul class="lf-list"><li><strong>Free delivery</strong>', $deliver['html']);
        $this->assertStringContainsString('<p class="lf-note">', $deliver['html']);
    }
}
