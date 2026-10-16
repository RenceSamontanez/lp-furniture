<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Support\CallUpdateMessage;
use App\Support\NotificationTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 16 part C: every notification of the custom order flow comes from
 * App\Support\NotificationTemplates and reads professionally.
 */
class NotificationTemplatesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    /** Everything a template might want, so every optional sentence gets exercised. */
    private function full(): array
    {
        return [
            'order' => 'Custom Furniture — Rence', 'customer' => 'Rence Samontanez', 'phone' => '09171234567',
            'order_number' => 'LP-2026-00007', 'agreement' => 'LP-A-2026-0003', 'times' => '2 times',
            'when' => 'Nov 09, 2026 10:30 AM', 'due' => 'Nov 10, 2026 10:30 AM', 'expiry' => 'Nov 16, 2026 10:30 AM',
            'total' => 20500, 'upfront' => 10250, 'amount' => 10250, 'balance' => 10250, 'confirmed' => true,
            'ready_date' => 'Dec 05, 2026', 'reason' => 'Outside our range', 'window' => 'Morning, 8 AM to 12 PM',
            'previous_total' => 20000,
        ];
    }

    private function configure(?string $phone, ?string $email): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::create(['phone' => $phone ?? '', 'email' => $email ?? '', 'facebook_url' => 'https://facebook.com/x']);
        \Illuminate\Support\Facades\Cache::forget(BusinessSetting::CACHE_KEY);
    }

    private function assertProfessional(string $text, string $where): void
    {
        $this->assertNotSame('', trim($text), $where);
        $this->assertStringNotContainsString('{', $text, "{$where}: a placeholder was left behind");
        $this->assertStringNotContainsString('}', $text, $where);
        $this->assertStringNotContainsString('!', $text, "{$where}: no exclamation marks");
        $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $text, "{$where}: no emojis");
        $this->assertDoesNotMatchRegularExpression('/\b[A-Z]{4,}\b/', $text, "{$where}: no shouting capitals");
        $this->assertDoesNotMatchRegularExpression('/pipeline|lock_version|payload|null|undefined|\bN\/A\b/i', $text, "{$where}: no internal terms");
        $this->assertDoesNotMatchRegularExpression('/ {2,}/', $text, "{$where}: no double spaces");
        $this->assertDoesNotMatchRegularExpression('/\s[.,]/', $text, "{$where}: no space before punctuation");
        $this->assertDoesNotMatchRegularExpression('/(\. ){2}|\.\./', $text, "{$where}: no stray punctuation");
    }

    public function test_every_template_renders_cleanly_with_and_without_optional_data(): void
    {
        $this->configure('0917-555-0100', 'hello@lpfurniture.test');

        foreach (array_keys(NotificationTemplates::TEMPLATES) as $key) {
            foreach (['full' => $this->full(), 'bare' => []] as $label => $data) {
                $t = NotificationTemplates::render($key, $data);

                $this->assertProfessional($t['title'], "{$key} title ({$label})");
                $this->assertProfessional($t['message'], "{$key} message ({$label})");
                $this->assertLessThan(420, mb_strlen($t['message']), "{$key} ({$label}) stays short");
            }
        }
    }

    public function test_customer_messages_carry_the_business_contact_details_when_configured(): void
    {
        $this->configure('0917-555-0100', 'hello@lpfurniture.test');

        foreach (array_keys(NotificationTemplates::TEMPLATES) as $key) {
            if (str_starts_with($key, 'admin_')) {
                continue;
            }
            $m = NotificationTemplates::message($key, $this->full());
            $this->assertStringContainsString('0917-555-0100', $m, $key);
            $this->assertStringContainsString('hello@lpfurniture.test', $m, $key);
        }
    }

    public function test_admin_messages_do_not_need_contact_details(): void
    {
        $this->configure('0917-555-0100', 'hello@lpfurniture.test');

        foreach (array_keys(NotificationTemplates::TEMPLATES) as $key) {
            if (str_starts_with($key, 'admin_')) {
                $this->assertStringNotContainsString('0917-555-0100', NotificationTemplates::message($key, $this->full()), $key);
            }
        }
    }

    public function test_it_falls_back_sensibly_when_contact_settings_are_incomplete(): void
    {
        $this->configure('0917-555-0100', null);
        $this->assertStringContainsString('You can reach us at 0917-555-0100.', NotificationTemplates::message('quote_sent', $this->full()));

        $this->configure(null, 'hello@lpfurniture.test');
        $this->assertStringContainsString('You can reach us at hello@lpfurniture.test.', NotificationTemplates::message('quote_sent', $this->full()));

        $this->configure('', '');
        $none = NotificationTemplates::message('quote_sent', $this->full());
        $this->assertStringContainsString('You can reach our team through our website.', $none);
        $this->assertStringNotContainsString('contact@example.com', $none);
    }

    public function test_the_built_in_placeholder_contact_is_never_printed(): void
    {
        BusinessSetting::query()->delete();      // no row: the model hands out placeholder defaults
        \Illuminate\Support\Facades\Cache::forget(BusinessSetting::CACHE_KEY);

        foreach (array_keys(NotificationTemplates::TEMPLATES) as $key) {
            $m = NotificationTemplates::message($key, $this->full());
            $this->assertStringNotContainsString('contact@example.com', $m, $key);
            $this->assertStringNotContainsString('0917-000-0000', $m, $key);
        }
        $this->assertStringContainsString('through our website', NotificationTemplates::message('call_answered', $this->full()));
    }

    public function test_optional_sentences_appear_only_with_their_data(): void
    {
        $this->configure('0917-555-0100', 'hello@lpfurniture.test');

        // declined: reason optional
        $this->assertStringContainsString('Reason: Outside our range.', NotificationTemplates::message('request_declined', $this->full()));
        $this->assertStringNotContainsString('Reason', NotificationTemplates::message('request_declined', ['order' => 'Custom Furniture']));

        // request received: preferred time optional
        $this->assertStringContainsString('during your preferred time (Morning, 8 AM to 12 PM)', NotificationTemplates::message('request_received', $this->full()));
        $this->assertStringNotContainsString('preferred time', NotificationTemplates::message('request_received', ['order' => 'Custom Furniture', 'phone' => '0917']));

        // payment received: balance, confirmation and ready date optional
        $full = NotificationTemplates::message('payment_received', $this->full());
        $this->assertStringContainsString('The remaining balance is ₱10,250.00.', $full);
        $this->assertStringContainsString('Your order is now confirmed.', $full);
        $this->assertStringContainsString('The expected ready date is about Dec 05, 2026.', $full);

        $paid = NotificationTemplates::message('payment_received', ['amount' => 1000, 'order_number' => 'LP-1', 'balance' => 0]);
        $this->assertStringContainsString('Your order is paid in full.', $paid);
        $this->assertStringNotContainsString('confirmed', $paid);

        $plain = NotificationTemplates::message('payment_received', ['amount' => 1000, 'order_number' => 'LP-1']);
        $this->assertStringNotContainsString('balance', $plain);
        $this->assertStringNotContainsString('paid in full', $plain);

        // quote: expiry optional
        $this->assertStringContainsString('Please review and accept it by Nov 16, 2026 10:30 AM.', NotificationTemplates::message('quote_sent', $this->full()));
        $this->assertStringContainsString('Please review and accept it as soon as you can.', NotificationTemplates::message('quote_sent', ['order' => 'X', 'total' => 100]));
    }

    public function test_titles_follow_the_existing_convention_and_order_status_titles_name_the_order(): void
    {
        $this->assertSame('Custom Request Received', NotificationTemplates::render('request_received')['title']);
        $this->assertSame('Your Custom Quote Is Ready', NotificationTemplates::render('quote_sent')['title']);
        $this->assertSame('Payment Successful', NotificationTemplates::render('payment_confirmed')['title']);
        $this->assertSame('Order Update — LP-2026-00007', NotificationTemplates::render('order_ready', ['order_number' => 'LP-2026-00007'])['title']);
    }

    public function test_an_unknown_template_is_a_loud_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NotificationTemplates::render('does_not_exist');
    }

    /* ───────────── the admin call update preview ───────────── */

    public function test_the_modal_preview_is_exactly_the_message_that_is_sent(): void
    {
        $this->configure('0917-555-0100', 'hello@lpfurniture.test');
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $customer->forceFill(['first_name' => 'RENCE', 'last_name' => 'SAMONTANEZ'])->save();
        $request = \App\Models\CustomDesignRequest::create([
            'user_id' => $customer->id, 'title' => 'x', 'furniture_type' => 'Chair', 'description' => 'A chair',
            'status' => 'approved', 'pipeline_status' => 'submitted', 'contact_phone' => '09171234567',
            'contact_email' => 'c@example.com', 'preferred_call_window' => 'anytime', 'request_mode' => 'exact',
            'submitted_at' => now(),
        ]);

        $data = CallUpdateMessage::previewData($request);

        foreach (array_keys(CallUpdateMessage::LABELS) as $outcome) {
            // what the page's script does: fill every {placeholder} from the values (times chosen by the admin)
            $values = $data['values'] + ['times' => CallUpdateMessage::times(2)];
            $preview = preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => $values[$m[1]] ?? '', $data['templates'][$outcome]);
            $preview = trim(preg_replace('/[ \t]{2,}/', ' ', $preview));

            $sent = CallUpdateMessage::build($outcome, $request->fresh('user'), 2);

            $this->assertSame($sent, $preview, "preview for {$outcome} equals the sent message");
            $this->assertSame(NotificationTemplates::render('call_' . $outcome)['title'], $data['titles'][$outcome]);
        }

        $html = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $request->id))->assertOk()
            ->assertSee('The customer will receive')->assertSee('id="co-preview-title"', false)->getContent();
        $this->assertStringContainsString('Custom Furniture — Rence', $html);
        $this->assertStringContainsString('0917-555-0100', $html);
    }

    public function test_the_modal_script_fills_every_placeholder(): void
    {
        $html = $this->actingAs($this->makeUser('admin'), 'admin');
        $view = file_get_contents(resource_path('views/admin/custom-orders/show.blade.php'));

        $this->assertStringContainsString('replace(/\{([a-z_]+)\}/g', $view);
        $this->assertStringContainsString('previewTitle.textContent', $view);
    }
}
