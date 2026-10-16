<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use App\Models\ChatbotLog;
use App\Support\Luma\DefaultIntents;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 20 part C: `php artisan chatbot:sync-defaults` adds Luma's built-in English and Tagalog answers.
 */
class LumaDefaultsTest extends TestCase
{
    use RefreshDatabase;

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

    private function named(string $message): ?string
    {
        $this->ask($message);
        $detected = ChatbotLog::query()->latest('id')->value('intent_detected');

        if (!str_starts_with($detected, 'intent:')) {
            return $detected;
        }

        return ChatbotIntent::find((int) substr($detected, 7))->category ?? 'legacy:' . substr($detected, 7);
    }

    /* ───────────── the command ───────────── */

    public function test_the_command_adds_every_default_and_says_what_it_added(): void
    {
        $total  = count(DefaultIntents::all());
        $before = ChatbotIntent::count(); // the Contact Settings migration already put one intent in

        $this->artisan('chatbot:sync-defaults')
            ->expectsOutputToContain('added   luma_greeting')
            ->expectsOutputToContain("{$total} added, 0 already there.")
            ->assertSuccessful();

        $this->assertSame($before + $total, ChatbotIntent::count());
        $this->assertGreaterThanOrEqual(20, $total);
        $this->assertSame(0, ChatbotIntent::where('category', 'like', 'luma_%')->whereNull('answer_tl')->count());
    }

    public function test_it_skips_names_that_exist_and_never_overwrites_an_edited_one(): void
    {
        $before = ChatbotIntent::count();
        $edited = ChatbotIntent::create([
            'category' => 'luma_greeting', 'keywords' => 'my own words', 'response' => 'My own edited answer.',
            'answer_tl' => 'Sarili kong sagot po.', 'suggestions' => 'Mine', 'is_active' => false,
        ]);
        $snapshot = $edited->fresh()->getAttributes();

        $this->artisan('chatbot:sync-defaults')->expectsOutputToContain('exists  luma_greeting')->assertSuccessful();

        $this->assertSame($snapshot, $edited->fresh()->getAttributes());
        $this->assertSame(1, ChatbotIntent::where('category', 'luma_greeting')->count());
        $this->assertSame($before + count(DefaultIntents::all()), ChatbotIntent::count());
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
        $count = ChatbotIntent::count();
        $ids   = ChatbotIntent::orderBy('id')->pluck('id')->all();

        $this->artisan('chatbot:sync-defaults')->expectsOutputToContain('0 added')->assertSuccessful();

        $this->assertSame($count, ChatbotIntent::count());
        $this->assertSame($ids, ChatbotIntent::orderBy('id')->pluck('id')->all());
    }

    public function test_it_does_not_touch_the_old_intents(): void
    {
        $this->legacy();
        $before = ChatbotIntent::orderBy('id')->get()->map->getAttributes()->all();

        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        $after = ChatbotIntent::whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map->getAttributes()->all();
        $this->assertSame($before, $after);
    }

    /* ───────────── what the defaults say ───────────── */

    public function test_every_default_has_english_and_tagalog_text_and_no_invented_facts(): void
    {
        foreach (DefaultIntents::all() as $name => $def) {
            $this->assertNotSame('', trim($def['keywords']), $name);
            $this->assertGreaterThan(40, strlen($def['en']), $name);
            $this->assertGreaterThan(40, strlen($def['tl']), $name);
            $this->assertStringNotContainsString('Luma is always here', $def['en'] . $def['tl'], $name);

            // no peso amounts, and none of the old wording
            $this->assertDoesNotMatchRegularExpression('/(PHP|₱)\s?\d/i', $def['en'] . $def['tl'], $name);
            $this->assertDoesNotMatchRegularExpression('/\b(depth|version 1|narra|oak|pine|upload your receipt)\b/i', $def['en'] . $def['tl'], $name);
            $this->assertStringNotContainsString('{{phone}} {{phone}}', $def['en'], $name);
        }

        // the Tagalog is polite
        foreach (DefaultIntents::all() as $name => $def) {
            $this->assertStringContainsStringIgnoringCase('po', $def['tl'], $name);
        }
    }

    public function test_the_confirmed_values_come_from_config_and_appear_in_the_answers(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
        $this->legacy();

        $howLong = $this->ask('how long does it take to finish a table?')['response'];
        $this->assertStringContainsString('roughly 15 to 30 days', $howLong);
        $this->assertStringContainsString('estimate', $howLong);
        $this->assertStringContainsString('quote', $howLong);
        $this->assertStringContainsString('made to order', $howLong);

        config(['luma.production_days' => ['min' => 20, 'max' => 40]]);
        $this->assertStringContainsString('roughly 20 to 40 days', $this->ask('how long does it take')['response']);

        $free = $this->ask('which locations get free delivery?')['response'];
        $this->assertStringContainsString('roughly 5 to 15 km away or about a 10 to 30 minute drive', $free);
        $this->assertStringContainsString('San Juan, Batangas', $free);
        $this->assertStringContainsString('shop sets the delivery fee and confirms it', $free);
        $this->assertDoesNotMatchRegularExpression('/(PHP|₱)\s?\d/', $free);
    }

    public function test_the_default_delivery_fee_is_used_only_if_business_settings_has_one(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        // Business Settings has no delivery fee field today, so the answer never invents one
        $this->assertStringNotContainsString('usual delivery fee', $this->ask('how much is the delivery fee')['response']);
    }

    public function test_contact_details_come_from_contact_settings_with_a_website_fallback(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        $unset = $this->ask('how can I contact you?');
        $this->assertStringContainsString('through our website', $unset['response']);
        $this->assertNull($unset['contact']);

        BusinessSetting::query()->first()->update(['phone' => '0917-555-1234', 'email' => 'luma@shop.test']);

        $set = $this->ask('how can I contact you?');
        $this->assertStringContainsString('0917-555-1234', $set['response']);
        $this->assertStringContainsString('luma@shop.test', $set['response']);
        $this->assertSame(['phone' => '0917-555-1234', 'email' => 'luma@shop.test'], $set['contact']);

        $tl = $this->ask('pwede ko po ba kayong makausap');
        $this->assertStringContainsString('tumawag sa 0917-555-1234', $tl['response']);
    }

    public function test_open_and_closed_are_worked_out_from_the_manila_time_of_the_question(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        // Monday 10:00 Manila = 02:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
        $open = $this->ask('are you still open?')['response'];
        $this->assertStringContainsString('8:00 AM to 5:00 PM, Monday to Friday', $open);
        $this->assertStringContainsString("We're open right now", $open);

        // Monday 18:30 Manila: closed, next working day is Tuesday
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:30:00', 'UTC'));
        $closed = $this->ask('are you still active?')['response'];
        $this->assertStringContainsString('closed right now', $closed);
        $this->assertStringContainsString('next working day, Tuesday', $closed);

        // Friday 18:00 Manila: closed until Monday
        Carbon::setTestNow(Carbon::parse('2026-10-23 10:00:00', 'UTC'));
        $this->assertStringContainsString('next working day, Monday', $this->ask('are you open')['response']);

        // Saturday 10:00 Manila: closed (the shop is open Monday to Friday)
        Carbon::setTestNow(Carbon::parse('2026-10-24 02:00:00', 'UTC'));
        $this->assertStringContainsString('closed right now', $this->ask('are you open')['response']);

        // Sunday evening Manila is already Monday in UTC-6 terms: 22:00 Manila Sunday = 14:00 UTC Sunday
        Carbon::setTestNow(Carbon::parse('2026-10-25 14:00:00', 'UTC'));
        $this->assertStringContainsString('next working day, Monday', $this->ask('are you open')['response']);

        // Monday 07:00 Manila (= Sunday 23:00 UTC): not open yet, replies today
        Carbon::setTestNow(Carbon::parse('2026-10-18 23:00:00', 'UTC'));
        $this->assertStringContainsString('when we open at 8:00 AM today', $this->ask('are you open yet')['response']);

        Carbon::setTestNow();
    }

    public function test_tagalog_hours_answer(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse('2026-10-19 10:30:00', 'UTC'));

        $reply = $this->ask('bukas pa ba kayo po?')['response'];
        $this->assertStringContainsString('8:00 AM hanggang 5:00 PM, Lunes hanggang Biyernes', $reply);
        $this->assertStringContainsString('susunod na araw ng trabaho, Martes', $reply);

        Carbon::setTestNow();
    }

    /* ───────────── questions land on the right answer (old and new intents together) ───────────── */

    public static function questions(): array
    {
        return [
            // English
            'hi' => ['hi there', 'luma_greeting'],
            'who' => ['who are you?', 'luma_greeting'],
            'business' => ['tell me about your business', 'luma_about_business'],
            'active' => ['are you still active?', 'luma_shop_open'],
            'hours' => ['what are your store hours', 'luma_shop_open'],
            'pricelist' => ['can I see your price list', 'luma_price_list'],
            'range' => ['what is the price range of doors', 'luma_price_range'],
            'cheapest' => ['what is your cheapest table', 'luma_price_range'],
            'cost' => ['how much do your products cost', 'luma_products_cost'],
            'ready' => ['do you have ready made doors?', 'luma_ready_made'],
            'ordinary' => ['do you have ordinary designs of tables', 'luma_ready_made'],
            'long' => ['how long to finish a cabinet', 'luma_how_long'],
            'mto' => ['is it ready made or made to order', 'luma_made_to_order'],
            'start' => ['when do you start making it', 'luma_made_to_order'],
            'labor' => ['labor only please', 'luma_labor_only'],
            'ownwood' => ['I will provide the wood', 'luma_labor_only'],
            'doorwood' => ['how much for a door if wood is provided', 'luma_labor_only'],
            'free' => ['which locations get free delivery', 'luma_free_delivery'],
            'fee' => ['how much is the delivery fee', 'luma_delivery_fee'],
            'steps' => ['how does the custom design work step by step', 'luma_ai_design_steps'],
            'measure' => ['how do I know the dimensions', 'luma_dimensions'],
            'wood' => ['which wood is best for a bed', 'luma_best_wood'],
            'pay' => ['what payment methods do you accept', 'luma_payment_methods'],
            'deposit' => ['how much is the deposit', 'luma_deposit_rules'],
            'cancel' => ['can I cancel my order', 'luma_cancel_refund'],
            'refund' => ['do you give refunds', 'luma_cancel_refund'],
            'track' => ['where is my order', 'luma_track_order'],
            'change' => ['how do I change my phone number', 'luma_change_contact'],
            'warranty' => ['is there a warranty', 'luma_warranty'],
            'contact' => ['how can I contact the shop', 'luma_contact_shop'],
            // Tagalog
            'tl hi' => ['kumusta po', 'luma_greeting'],
            'tl who' => ['sino ka po?', 'luma_greeting'],
            'tl open' => ['bukas pa ba kayo?', 'luma_shop_open'],
            'tl how long' => ['gaano katagal gawin ang mesa?', 'luma_how_long'],
            'tl start' => ['kailan sisimulan ang paggawa', 'luma_made_to_order'],
            'tl price door' => ['magkano po ang pinto?', 'luma_products_cost'],
            'tl have' => ['meron ba kayong pinto', 'luma_ready_made'],
            'tl free' => ['libre ba ang delivery?', 'luma_free_delivery'],
            'tl wood' => ['anong kahoy ang maganda po', 'luma_best_wood'],
            'tl labor' => ['pwede po ba labor lang, ako na sa kahoy', 'luma_labor_only'],
            'tl pay' => ['paano magbayad po', 'luma_payment_methods'],
            'tl track' => ['saan na po ang order ko', 'luma_track_order'],
            'tl measure' => ['paano sukatin ang mesa', 'luma_dimensions'],
            'tl steps' => ['paano ang custom design po', 'luma_ai_design_steps'],
        ];
    }

    /**
     * @dataProvider questions
     */
    public function test_a_question_reaches_the_right_default_answer(string $question, string $expected): void
    {
        $this->legacy();
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        $this->assertSame($expected, $this->named($question), $question);
    }

    public function test_the_buttons_and_the_guide_link_come_with_the_answers(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();

        $steps = $this->ask('how does the custom design work');
        $urls  = array_column($steps['buttons'], 'url');
        $this->assertContains('/guide', $urls);
        $this->assertStringContainsString('Length x Width x Height', $steps['response']);
        foreach (['type', 'size', 'wood', 'describe', 'generate', 'review', 'send', 'call', 'quote', 'agreement', 'pay'] as $step) {
            $this->assertStringContainsStringIgnoringCase($step, $steps['response'], $step);
        }

        $this->assertContains('/guide', array_column($this->ask('how to measure')['buttons'], 'url'));
        $this->assertContains('/terms', array_column($this->ask('can I cancel')['buttons'], 'url'));
        $this->assertContains('/terms', array_column($this->ask('is there a warranty')['buttons'], 'url'));
        $this->assertStringNotContainsString('%', $this->ask('can I cancel')['response']);

        $wood = $this->ask('which wood is best')['response'];
        foreach (['Gmelina', 'Mahogany', 'Acacia', 'Special request'] as $w) {
            $this->assertStringContainsString($w, $wood);
        }
    }

    public function test_the_defaults_mention_made_to_order_where_the_owner_asked(): void
    {
        $this->artisan('chatbot:sync-defaults')->assertSuccessful();
        \App\Models\Product::create(['product_name' => 'Panel Door', 'category' => 'Doors', 'price' => 5000, 'stock_quantity' => 1, 'product_status' => 'available']);

        foreach (['how long does it take', 'how does the custom design work', 'do you have ready made doors'] as $q) {
            $this->assertStringContainsStringIgnoringCase('made to order', $this->ask($q)['response'], $q);
        }
        $this->assertStringContainsString('Gawa po kapag may order', $this->ask('gaano katagal po')['response']);
    }
}
