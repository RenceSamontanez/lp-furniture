<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 16 part A: the saved-contact toggles. The server validates whatever
 * contact is submitted and writes the users columns only when saving was
 * asked for, whatever state the toggles were in on screen.
 */
class ContactToggleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $imageRel;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->imageRel = 'images/ai-designs/phpunit-stage16-' . bin2hex(random_bytes(6)) . '.png';
        file_put_contents(public_path($this->imageRel), base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    protected function tearDown(): void
    {
        if (isset($this->imageRel) && is_file(public_path($this->imageRel))) {
            unlink(public_path($this->imageRel));
        }
        parent::tearDown();
    }

    private function customer(bool $saved): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => 'Ana', 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();
        if ($saved) {
            $u->forceFill(['saved_contact_phone' => '09181112222', 'saved_contact_email' => 'saved@example.com', 'contact_saved_at' => now()])->save();
        }

        return $u;
    }

    private function request(User $owner): array
    {
        $r = CustomDesignRequest::create([
            'user_id' => $owner->id, 'title' => 'Dining Table', 'furniture_type' => 'Dining Table',
            'description' => 'A narra table', 'status' => 'ai_generated',
        ]);
        $s = AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => $this->imageRel, 'generated_at' => now()]);

        return [$r, $s];
    }

    private function send(User $u, array $extra = [])
    {
        [$r, $s] = $this->request($u);

        $resp = $this->actingAs($u)->post(route('design.sendToTeam', $r->id), array_merge([
            'suggestion_id' => $s->id, 'contact_phone' => '09183334444', 'contact_email' => 'other@example.com',
            'preferred_call_window' => 'morning_8_12', 'request_mode' => 'exact',
        ], $extra));

        return [$r->fresh(), $resp];
    }

    /* ───────────── the form ───────────── */

    public function test_saved_contact_form_shows_readonly_fields_and_the_two_toggles(): void
    {
        $u = $this->customer(true);
        [$r, $s] = $this->request($u);

        $html = $this->actingAs($u)->get(route('design.sendForm', [$r->id, 'suggestion' => $s->id]))->assertOk()->getContent();

        $this->assertStringContainsString('value="09181112222"', $html);
        $this->assertStringContainsString('value="saved@example.com"', $html);
        $this->assertMatchesRegularExpression('/id="contact_phone"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/id="contact_email"[^>]*readonly/', $html);

        // real checkbox inputs with role="switch", off by default
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*id="use_different" name="use_different"(?![^>]*checked)[^>]*\/>/', $html);
        $this->assertStringContainsString('Use a different number or email for this request', $html);
        $this->assertMatchesRegularExpression('/id="co-update-saved"\s+hidden/', $html);
        $this->assertStringContainsString('Also update my saved contact', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*id="update_saved" name="update_saved"(?![^>]*checked)[^>]*\/>/', $html);
        $this->assertStringNotContainsString('Save this number and email for my next custom orders', $html);
        $this->assertStringNotContainsString('type="checkbox" name="save_contact"', $html);
    }

    public function test_no_saved_contact_form_shows_editable_fields_and_a_save_toggle_that_is_on(): void
    {
        $u = $this->customer(false);
        [$r, $s] = $this->request($u);

        $html = $this->actingAs($u)->get(route('design.sendForm', [$r->id, 'suggestion' => $s->id]))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="contact_phone"[^>]*readonly/', $html);
        $this->assertStringContainsString('Save this number and email for my next custom orders', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*id="save_contact" name="save_contact"[^>]*checked/', $html);
        $this->assertStringNotContainsString('Use a different number or email for this request', $html);
    }

    public function test_a_failed_submit_reopens_the_fields(): void
    {
        $u = $this->customer(true);
        [$r, $s] = $this->request($u);

        $html = $this->actingAs($u)->from(route('design.sendForm', [$r->id, 'suggestion' => $s->id]))->followingRedirects()
            ->post(route('design.sendToTeam', $r->id), [
                'suggestion_id' => $s->id, 'use_different' => '1', 'contact_phone' => '12345', 'contact_email' => 'other@example.com',
                'preferred_call_window' => 'morning_8_12', 'request_mode' => 'exact',
            ])->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="contact_phone"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*id="use_different"[^>]*checked/', $html);
    }

    /* ───────────── every combination saves or not ───────────── */

    public function test_saved_contact_different_off_uses_the_saved_values_and_saves_nothing(): void
    {
        $u = $this->customer(true);
        [$r] = $this->send($u, ['contact_phone' => '09181112222', 'contact_email' => 'saved@example.com']);

        $this->assertSame('09181112222', $r->contact_phone);
        $this->assertSame('09181112222', $u->fresh()->saved_contact_phone);
        $this->assertSame('saved@example.com', $u->fresh()->saved_contact_email);
    }

    public function test_saved_contact_different_off_ignores_a_tampered_update_flag(): void
    {
        $u = $this->customer(true);
        [$r] = $this->send($u, ['update_saved' => '1']);   // different contact posted, use_different missing

        $this->assertSame('09183334444', $r->contact_phone, 'the submitted (validated) contact is used for the request');
        $this->assertSame('09181112222', $u->fresh()->saved_contact_phone, 'but the account is not changed');
        $this->assertSame('saved@example.com', $u->fresh()->saved_contact_email);
    }

    public function test_saved_contact_different_on_update_off_uses_it_once(): void
    {
        $u = $this->customer(true);
        [$r] = $this->send($u, ['use_different' => '1']);

        $this->assertSame('09183334444', $r->contact_phone);
        $this->assertSame('other@example.com', $r->contact_email);
        $this->assertSame('09181112222', $u->fresh()->saved_contact_phone);
        $this->assertSame('saved@example.com', $u->fresh()->saved_contact_email);
    }

    public function test_saved_contact_different_on_update_on_replaces_the_saved_contact(): void
    {
        $u = $this->customer(true);
        [$r, $resp] = $this->send($u, ['use_different' => '1', 'update_saved' => '1']);

        $resp->assertSessionHasNoErrors();
        $this->assertSame('09183334444', $r->contact_phone);
        $this->assertSame('09183334444', $u->fresh()->saved_contact_phone);
        $this->assertSame('other@example.com', $u->fresh()->saved_contact_email);
    }

    public function test_no_saved_contact_save_on_saves_and_save_off_does_not(): void
    {
        $on = $this->customer(false);
        $this->send($on, ['save_contact' => '1']);
        $this->assertSame('09183334444', $on->fresh()->saved_contact_phone);
        $this->assertSame('other@example.com', $on->fresh()->saved_contact_email);
        $this->assertNotNull($on->fresh()->contact_saved_at);

        $off = $this->customer(false);
        [$r] = $this->send($off);
        $this->assertSame('09183334444', $r->contact_phone);
        $this->assertNull($off->fresh()->saved_contact_phone);
        $this->assertNull($off->fresh()->saved_contact_email);
    }

    /* ───────────── validation is never skipped ───────────── */

    public function test_bad_numbers_and_emails_fail_in_every_combination_and_save_nothing(): void
    {
        foreach ([false, true] as $hasSaved) {
            foreach ([['save_contact' => '1'], ['use_different' => '1', 'update_saved' => '1'], []] as $flags) {
                $u = $this->customer($hasSaved);
                [$r, $resp] = $this->send($u, $flags + ['contact_phone' => '12345']);
                $resp->assertSessionHasErrors('contact_phone');
                $this->assertNull($r->pipeline_status);

                [$r, $resp] = $this->send($u, $flags + ['contact_email' => 'not-an-email']);
                $resp->assertSessionHasErrors('contact_email');

                $fresh = $u->fresh();
                $this->assertSame($hasSaved ? '09181112222' : null, $fresh->saved_contact_phone);
                $this->assertSame($hasSaved ? 'saved@example.com' : null, $fresh->saved_contact_email);
            }
        }
    }

    public function test_accepts_the_other_philippine_formats(): void
    {
        $u = $this->customer(false);
        [$r] = $this->send($u, ['contact_phone' => '+63 918 333 4444', 'save_contact' => '1']);
        $this->assertSame('09183334444', $r->contact_phone);
        $this->assertSame('09183334444', $u->fresh()->saved_contact_phone);
    }

    /* ───────────── a second request reuses the saved contact ───────────── */

    public function test_a_second_request_reuses_the_saved_contact(): void
    {
        $u = $this->customer(false);
        $this->send($u, ['save_contact' => '1']);

        [$r2, $s2] = $this->request($u);
        $html = $this->actingAs($u)->get(route('design.sendForm', [$r2->id, 'suggestion' => $s2->id]))->assertOk()->getContent();

        $this->assertStringContainsString('value="09183334444"', $html);
        $this->assertStringContainsString('value="other@example.com"', $html);
        $this->assertMatchesRegularExpression('/id="contact_phone"[^>]*readonly/', $html);
        $this->assertStringContainsString('Use a different number or email for this request', $html);
    }

    public function test_the_edit_form_uses_the_same_toggles(): void
    {
        $u = $this->customer(true);
        [$r] = $this->send($u, ['use_different' => '1']);   // this request used another contact

        $html = $this->actingAs($u)->get(route('design.edit', $r->id))->assertOk()->getContent();
        $this->assertStringContainsString('value="09183334444"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="contact_phone"[^>]*readonly/', $html, 'a request with another contact opens with the toggle on');
        $this->assertMatchesRegularExpression('/<input type="checkbox" role="switch"[^>]*id="use_different"[^>]*checked/', $html);

        // editing and asking to update the saved contact
        $this->actingAs($u)->post(route('design.update', $r->id), [
            'use_different' => '1', 'update_saved' => '1', 'contact_phone' => '09185556666', 'contact_email' => 'new@example.com',
            'preferred_call_window' => 'morning_8_12', 'request_mode' => 'exact',
        ])->assertSessionHasNoErrors();
        $this->assertSame('09185556666', $u->fresh()->saved_contact_phone);
    }

    public function test_the_stylesheet_has_the_switch_with_a_visible_focus_state(): void
    {
        $css = file_get_contents(public_path('assets/css/custom-orders.css'));
        $this->assertStringContainsString('.lp-switch-input:focus-visible + .lp-switch-track', $css);
        $this->assertStringContainsString('.lp-switch-input:checked + .lp-switch-track', $css);
        $this->assertStringContainsString('min-height: 44px', $css);   // touch-sized target
    }
}
