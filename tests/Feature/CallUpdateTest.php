<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 13 part B: the quick call update tool.
 */
class CallUpdateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function customer(): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => 'RENCE', 'last_name' => 'SAMONTANEZ', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    private function submitted(?User $c = null, array $over = []): CustomDesignRequest
    {
        return CustomDesignRequest::create(array_merge([
            'user_id' => ($c ?? $this->customer())->id, 'title' => 'Dining Table - Rence',
            'furniture_type' => 'Dining Table', 'description' => 'A table', 'status' => 'approved',
            'pipeline_status' => 'submitted', 'contact_phone' => '09171234567', 'contact_email' => 'r@example.com',
            'preferred_call_window' => 'anytime', 'request_mode' => 'exact', 'submitted_at' => now(),
        ], $over));
    }

    private function send(User $admin, CustomDesignRequest $r, array $data = [], ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')
            ->from(route('admin.custom-orders.show', $r->id))
            ->post(route('admin.custom-orders.call-update', $r->id), array_merge([
                'lock_version' => $version ?? (int) $r->fresh()->lock_version,
            ], $data));
    }

    private function business(): BusinessSetting
    {
        $b = BusinessSetting::current();
        $b->forceFill(['phone' => '0917-555-0100', 'email' => 'hello@lpfurniture.test'])->save();

        return $b;
    }

    /* ───────────── each outcome: status, wording, one notification ───────────── */

    public function test_unreachable_sets_unreachable_and_sends_the_exact_message(): void
    {
        $admin = $this->admin();
        $this->business();
        $r = $this->submitted();
        Notification::query()->delete();

        $this->send($admin, $r, ['outcome' => 'unreachable', 'attempts' => 3])->assertSessionHasNoErrors();

        $this->assertSame('unreachable', $r->fresh()->pipeline_status);
        $n = Notification::where('user_id', $r->user_id)->sole();
        $this->assertSame('custom_order', $n->type);
        $this->assertSame(
            'We tried to call you 3 times about your request for Custom Furniture — Rence, but could not reach you. '
            . 'Please call or email us so we can continue. '
            . 'Mention your account name (Rence Samontanez) so we can find your request. '
            . 'You can reach us at 0917-555-0100 or hello@lpfurniture.test.',
            $n->message
        );
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
    }

    public function test_ringing_sets_contacting_and_sends_the_exact_message(): void
    {
        $admin = $this->admin();
        $this->business();
        $r = $this->submitted();
        Notification::query()->delete();

        $this->send($admin, $r, ['outcome' => 'ringing', 'attempts' => 1])->assertSessionHasNoErrors();

        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(
            'We called you 1 time about your request for Custom Furniture — Rence, but there was no answer. '
            . 'Please call us back or send us an email. '
            . 'Mention your account name (Rence Samontanez) so we can find your request. '
            . 'You can reach us at 0917-555-0100 or hello@lpfurniture.test.',
            Notification::where('user_id', $r->user_id)->sole()->message
        );
    }

    public function test_answered_sets_contacting_and_needs_no_attempts(): void
    {
        $admin = $this->admin();
        $this->business();
        $r = $this->submitted();
        Notification::query()->delete();

        $this->send($admin, $r, ['outcome' => 'answered'])->assertSessionHasNoErrors();

        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(
            'Thank you for speaking with us about your request for Custom Furniture — Rence. '
            . 'We are preparing your quote and will notify you here as soon as it is ready. '
            . 'You can reach us at 0917-555-0100 or hello@lpfurniture.test.',
            Notification::where('user_id', $r->user_id)->sole()->message
        );
        $this->assertTrue((bool) CustomOrderCallLog::where('request_id', $r->id)->sole()->answered);
    }

    public function test_no_placeholders_are_left_and_the_note_is_a_separate_line(): void
    {
        $admin = $this->admin();
        $this->business();
        $r = $this->submitted();
        Notification::query()->delete();

        $this->send($admin, $r, ['outcome' => 'unreachable', 'attempts' => 2, 'note' => 'We are open Mon to Sat, 8 to 5.']);

        $msg = Notification::where('user_id', $r->user_id)->sole()->message;
        $this->assertDoesNotMatchRegularExpression('/\{[a-z]+\}/', $msg);
        $this->assertStringEndsWith("\nWe are open Mon to Sat, 8 to 5.", $msg);
        $this->assertStringContainsString('2 times', $msg);
    }

    public function test_it_writes_a_call_log_and_an_event_and_updates_the_last_call_line(): void
    {
        $admin = $this->admin();
        $r = $this->submitted();

        $this->send($admin, $r, ['outcome' => 'ringing', 'attempts' => 4, 'note' => 'Rang till voicemail'])->assertSessionHasNoErrors();

        $log = CustomOrderCallLog::where('request_id', $r->id)->sole();
        $this->assertFalse((bool) $log->answered);
        $this->assertSame($admin->id, (int) $log->admin_id);
        $this->assertSame(1, (int) $log->attempt_no);
        $this->assertStringContainsString('4 times called', $log->note);
        $this->assertStringContainsString('Rang till voicemail', $log->note);

        $e = CustomOrderEvent::where('type', 'call_update')->sole();
        $this->assertSame('ringing', $e->data['outcome']);
        $this->assertSame(4, $e->data['attempts']);
        $this->assertSame('admin', $e->actor_type);
        $this->assertSame((int) $r->lock_version + 1, (int) $r->fresh()->lock_version);

        $page = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk();
        $page->assertSee('Last Call Update')->assertSee('Ringing but not answering, 4 times called')
            ->assertSee('Contacting')->assertSee('1 update so far');
    }

    public function test_status_chip_changes_after_each_update(): void
    {
        $admin = $this->admin();
        $r = $this->submitted();

        $this->send($admin, $r, ['outcome' => 'unreachable', 'attempts' => 1]);
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertSee('co-chip--unreachable', false);

        $this->send($admin, $r, ['outcome' => 'answered']);
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertSee('co-chip--contacting', false);
        $this->assertSame(2, CustomOrderCallLog::where('request_id', $r->id)->count());
    }

    /* ───────────── guards ───────────── */

    public function test_attempts_are_validated(): void
    {
        $admin = $this->admin();
        $r = $this->submitted();

        foreach (['0', '21', '-1', 'abc'] as $bad) {
            $this->send($admin, $r, ['outcome' => 'unreachable', 'attempts' => $bad])->assertSessionHasErrors('attempts');
        }
        // missing attempts for an outcome that needs them
        $this->send($admin, $r, ['outcome' => 'ringing'])->assertSessionHasErrors('pipeline');
        $this->send($admin, $r, ['outcome' => 'nonsense', 'attempts' => 1])->assertSessionHasErrors('outcome');

        $this->assertSame('submitted', $r->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderCallLog::count());
        $this->assertSame(0, Notification::where('type', 'custom_order')->count());
    }

    public function test_stale_lock_version_is_rejected(): void
    {
        $admin = $this->admin();
        $r = $this->submitted();
        $v = (int) $r->lock_version;

        $this->send($admin, $r, ['outcome' => 'ringing', 'attempts' => 1], $v)->assertSessionHasNoErrors();
        $before = Notification::count();

        $this->send($admin, $r, ['outcome' => 'unreachable', 'attempts' => 2], $v)->assertSessionHasErrors('pipeline');

        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame($before, Notification::count());
        $this->assertSame(1, CustomOrderEvent::where('type', 'call_update')->count());
    }

    public function test_closed_requests_refuse_call_updates(): void
    {
        $admin = $this->admin();

        foreach (['quoted', 'accepted', 'ordered', 'declined_by_admin', 'expired'] as $status) {
            $r = $this->submitted(null, ['pipeline_status' => $status]);
            $this->send($admin, $r, ['outcome' => 'answered'])->assertSessionHasErrors('pipeline');
            $this->assertSame($status, $r->fresh()->pipeline_status);
        }
        $this->assertSame(0, CustomOrderCallLog::count());
    }

    public function test_decline_still_works_from_the_secondary_section(): void
    {
        $admin = $this->admin();
        $r = $this->submitted();

        $page = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk();
        $page->assertSee('More Actions')->assertSee('Decline Request');
        $page->assertDontSee('Log a call')->assertDontSee('Mark unreachable')->assertDontSee('Work this request');

        $this->actingAs($admin, 'admin')->post(route('admin.custom-orders.decline', $r->id), [
            'lock_version' => $r->fresh()->lock_version, 'reason' => 'Outside our range',
        ])->assertSessionHasNoErrors();

        $this->assertSame('declined_by_admin', $r->fresh()->pipeline_status);
        $this->actingAs($admin, 'admin')->post(route('admin.custom-orders.decline', $r->id), [
            'lock_version' => $r->fresh()->lock_version, 'reason' => '',
        ])->assertSessionHasErrors();
    }

    public function test_modal_and_preview_data_are_on_the_page(): void
    {
        $admin = $this->admin();
        $this->business();
        $r = $this->submitted();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk()
            ->assertSee('id="co-call-modal"', false)
            ->assertSee('Send Notification')
            ->assertSee('Ringing but not answering')
            ->assertSee('0917-555-0100')
            ->assertSee('hello@lpfurniture.test')
            ->assertSee('Rence Samontanez');
    }

    /* ───────────── access ───────────── */

    public static function access(): array
    {
        return ['guest' => ['guest'], 'customer' => ['customer_web'], 'customer on admin guard' => ['customer_on_admin_guard']];
    }

    /** @dataProvider access */
    public function test_non_admins_cannot_send_call_updates(string $who): void
    {
        $r = $this->submitted();
        $visitor = $this->makeUser();

        match ($who) {
            'customer_web'            => $this->actingAs($visitor),
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            default                   => null,
        };
        Auth::shouldUse('web');

        $this->post(route('admin.custom-orders.call-update', $r->id), [
            'lock_version' => 0, 'outcome' => 'answered',
        ])->assertRedirect(route('admin.login'));

        $this->assertSame('submitted', $r->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderEvent::count());
        $this->assertSame(0, Notification::count());
    }
}
