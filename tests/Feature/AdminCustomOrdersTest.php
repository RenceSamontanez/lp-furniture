<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 4: admin Custom Orders (list, detail, call log, unreachable,
 * decline) and every access path to the new routes.
 */
class AdminCustomOrdersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea'])->save();

        return $a;
    }

    private function customer(string $first = 'Ana', string $last = 'Cruz'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => $last, 'phone_number' => '09170000001'])->save();

        return $u;
    }

    private function submitted(User $owner, array $overrides = []): CustomDesignRequest
    {
        return CustomDesignRequest::create(array_merge([
            'user_id'               => $owner->id,
            'title'                 => 'Dining Table — ' . $owner->first_name,
            'furniture_type'        => 'Dining Table',
            'description'           => 'A six-seater narra table',
            'status'                => 'approved',
            'pipeline_status'       => 'submitted',
            'contact_phone'         => '09171234567',
            'contact_email'         => 'ana@example.com',
            'preferred_call_window' => 'morning_8_12',
            'request_mode'          => 'exact',
            'submitted_at'          => now(),
        ], $overrides));
    }

    private function act(User $admin, string $route, CustomDesignRequest $r, array $data = [], ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')
            ->from(route('admin.custom-orders.show', $r->id))
            ->post(route($route, $r->id), array_merge(['lock_version' => $version ?? $r->fresh()->lock_version], $data));
    }

    private function logCall(User $admin, CustomDesignRequest $r, bool $answered, ?string $note = null, ?int $v = null)
    {
        return $this->act($admin, 'admin.custom-orders.calls', $r, ['answered' => $answered ? '1' : '0', 'note' => $note], $v);
    }

    /* ───────────── RBAC: every identity × every route, fresh app each ───────────── */

    public static function accessMatrix(): array
    {
        $routes = [
            'index'       => ['get',  'admin.custom-orders.index'],
            'show'        => ['get',  'admin.custom-orders.show'],
            'calls'       => ['post', 'admin.custom-orders.calls'],
            'unreachable' => ['post', 'admin.custom-orders.unreachable'],
            'decline'     => ['post', 'admin.custom-orders.decline'],
        ];
        $cases = [];
        foreach (['guest', 'customer_web', 'customer_on_admin_guard'] as $who) {
            foreach ($routes as $name => [$method, $route]) {
                $cases["$who → $name"] = [$who, $method, $route];
            }
        }

        return $cases;
    }

    /** @dataProvider accessMatrix */
    public function test_non_admins_are_redirected_to_admin_login(string $who, string $method, string $route): void
    {
        $owner   = $this->customer();
        $request = $this->submitted($owner);
        $before  = $request->fresh()->toArray();

        $visitor = $this->customer('Ben', 'Reyes');
        if ($who === 'customer_web') {
            $this->actingAs($visitor);
        } elseif ($who === 'customer_on_admin_guard') {
            $this->actingAs($visitor, 'admin');
        }

        $url  = str_contains($route, 'index') ? route($route) : route($route, $request->id);
        $resp = $method === 'get'
            ? $this->get($url)
            : $this->post($url, ['lock_version' => 0, 'answered' => '1', 'reason' => 'x']);

        $resp->assertRedirect(route('admin.login'));

        $this->assertSame($before, $request->fresh()->toArray());
        $this->assertSame(0, CustomOrderCallLog::count());
        $this->assertSame(0, CustomOrderEvent::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_admin_can_reach_the_pages(): void
    {
        $admin   = $this->admin();
        $request = $this->submitted($this->customer());

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index'))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $request->id))->assertOk();
    }

    public function test_unsubmitted_request_is_404_for_admin(): void
    {
        $admin = $this->admin();
        $draft = $this->submitted($this->customer(), ['pipeline_status' => null, 'status' => 'ai_generated', 'submitted_at' => null]);

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $draft->id))->assertNotFound();
        $this->act($admin, 'admin.custom-orders.decline', $draft, ['reason' => 'x'])->assertNotFound();
    }

    /* ───────────── detail page ───────────── */

    public function test_detail_page_shows_the_request(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer(), [
            'request_mode' => 'modified', 'modification_notes' => 'Make it 20 cm longer',
            'preferred_call_window' => 'afternoon_12_5',
        ]);
        CustomOrderEvent::create([
            'request_id' => $r->id, 'type' => 'submitted', 'actor_type' => 'customer',
            'actor_id' => $r->user_id, 'occurred_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))
            ->assertOk()
            ->assertSee('href="tel:09171234567"', false)
            ->assertSee('href="mailto:ana@example.com"', false)
            ->assertSee('Afternoon, 12 PM to 5 PM')
            ->assertSee('Make it 20 cm longer')
            ->assertSee('A six-seater narra table')
            ->assertSee('No image generated')
            ->assertSee('Customer sent the request to the team')
            ->assertSee('Send call update')
            ->assertSee('Create quote');
    }

    /* ───────────── call log ───────────── */

    public function test_first_call_moves_submitted_to_contacting_and_is_recorded(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->logCall($admin, $r, false, 'Rang, no answer')->assertRedirect(route('admin.custom-orders.show', $r->id));

        $fresh = $r->fresh();
        $this->assertSame('contacting', $fresh->pipeline_status);
        $this->assertSame(1, $fresh->lock_version);

        $log = CustomOrderCallLog::where('request_id', $r->id)->sole();
        $this->assertSame(1, $log->attempt_no);
        $this->assertFalse($log->answered);
        $this->assertSame('Rang, no answer', $log->note);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertEqualsWithDelta(now()->timestamp, $log->called_at->timestamp, 5);
        $this->assertSame('Asia/Manila', $log->called_at->getTimezone()->getName());

        $event = CustomOrderEvent::where('request_id', $r->id)->sole();
        $this->assertSame('call_logged', $event->type);
        $this->assertSame('admin', $event->actor_type);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('contacting', $event->data['to']);
    }

    public function test_attempt_numbers_count_per_request(): void
    {
        $admin = $this->admin();
        $a = $this->submitted($this->customer());
        $b = $this->submitted($this->customer('Ben', 'Reyes'));

        $this->logCall($admin, $a, false);
        $this->logCall($admin, $a, true);
        $this->logCall($admin, $b, false);
        $this->logCall($admin, $a, false);

        $this->assertSame([1, 2, 3], CustomOrderCallLog::where('request_id', $a->id)->orderBy('attempt_no')->pluck('attempt_no')->all());
        $this->assertSame([1], CustomOrderCallLog::where('request_id', $b->id)->pluck('attempt_no')->all());
        $this->assertSame('contacting', $a->fresh()->pipeline_status);
    }

    public function test_call_requires_an_answered_choice_and_changes_nothing_without_it(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->act($admin, 'admin.custom-orders.calls', $r, ['note' => 'x'])->assertSessionHasErrors('answered');
        $this->act($admin, 'admin.custom-orders.calls', $r, ['answered' => 'maybe'])->assertSessionHasErrors('answered');

        $this->assertSame('submitted', $r->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderCallLog::count());
    }

    public function test_answered_call_after_unreachable_resumes_contacting_but_unanswered_does_not(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->logCall($admin, $r, false);
        $this->act($admin, 'admin.custom-orders.unreachable', $r);
        $this->assertSame('unreachable', $r->fresh()->pipeline_status);

        $this->logCall($admin, $r, false);
        $this->assertSame('unreachable', $r->fresh()->pipeline_status);

        $this->logCall($admin, $r, true);
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
    }

    /* ───────────── unreachable ───────────── */

    public function test_unreachable_needs_an_unanswered_call(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasErrors('pipeline');
        $this->assertSame('submitted', $r->fresh()->pipeline_status);

        $this->logCall($admin, $r, true); // answered only
        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasErrors('pipeline');

        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(0, Notification::count());
        $this->assertSame(1, CustomOrderEvent::count()); // only the call
    }

    public function test_unreachable_after_unanswered_call_notifies_customer(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->submitted($owner);

        $this->logCall($admin, $r, false);
        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasNoErrors();

        $this->assertSame('unreachable', $r->fresh()->pipeline_status);

        $n = Notification::where('user_id', $owner->id)->sole();
        $this->assertSame('custom_order', $n->type);
        $this->assertStringContainsString('We could not reach you by phone about your request for Custom Furniture — Ana.', $n->message);
        $this->assertStringContainsString('Please check that 09171234567 is the right number.', $n->message);

        $event = CustomOrderEvent::where('type', 'marked_unreachable')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame('contacting', $event->data['from']);
    }

    public function test_cannot_mark_unreachable_twice(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());
        $this->logCall($admin, $r, false);
        $this->act($admin, 'admin.custom-orders.unreachable', $r);

        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasErrors('pipeline');
        $this->assertSame(1, Notification::count());
    }

    /* ───────────── decline ───────────── */

    public function test_decline_requires_a_reason(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => '   '])->assertSessionHasErrors('reason');

        $this->assertSame('submitted', $r->fresh()->pipeline_status);
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    /** @dataProvider openStatuses */
    public function test_decline_works_from_every_open_status(string $from): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->submitted($owner, ['pipeline_status' => $from]);

        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'Outside our range'])->assertSessionHasNoErrors();

        $fresh = $r->fresh();
        $this->assertSame('declined_by_admin', $fresh->pipeline_status);
        $this->assertSame('declined', $fresh->status);
        $this->assertSame('Outside our range', $fresh->decline_reason);

        $n = Notification::where('user_id', $owner->id)->sole();
        $this->assertStringStartsWith('We are unable to take on your request for Custom Furniture — Ana. Reason: Outside our range.', $n->message);
        $this->assertSame('custom_order', $n->type);

        $event = CustomOrderEvent::where('type', 'declined_by_admin')->sole();
        $this->assertSame($from, $event->data['from']);
    }

    public static function openStatuses(): array
    {
        return ['submitted' => ['submitted'], 'contacting' => ['contacting'], 'unreachable' => ['unreachable']];
    }

    public function test_declined_is_terminal(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'No']);
        $notifications = Notification::count();
        $events = CustomOrderEvent::count();

        $this->logCall($admin, $r, true)->assertSessionHasErrors('pipeline');
        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasErrors('pipeline');
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'Again'])->assertSessionHasErrors('pipeline');

        $this->assertSame('declined_by_admin', $r->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderCallLog::count());
        $this->assertSame($notifications, Notification::count());
        $this->assertSame($events, CustomOrderEvent::count());
    }

    /** @dataProvider closedStatuses */
    public function test_no_stage4_action_is_allowed_from_later_statuses(string $status): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer(), ['pipeline_status' => $status]);

        $this->logCall($admin, $r, false)->assertSessionHasErrors('pipeline');
        $this->act($admin, 'admin.custom-orders.unreachable', $r)->assertSessionHasErrors('pipeline');
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'x'])->assertSessionHasErrors('pipeline');

        $this->assertSame($status, $r->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderEvent::count());
        $this->assertSame(0, Notification::count());
    }

    public static function closedStatuses(): array
    {
        $all = ['quoted', 'accepted', 'ordered', 'expired', 'declined_by_customer', 'declined_by_admin', 'cancelled'];

        return array_combine($all, array_map(fn ($s) => [$s], $all));
    }

    /* ───────────── stale save ───────────── */

    public function test_stale_lock_version_is_rejected_for_every_action(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());
        $stale = (int) $r->fresh()->lock_version; // 0

        $this->logCall($admin, $r, false); // someone else moves it on: version now 1

        $resp = $this->logCall($admin, $r, true, 'late', $stale);
        $resp->assertSessionHasErrors('pipeline');
        $this->assertStringContainsString('changed by someone else', session('errors')->first('pipeline'));

        $this->act($admin, 'admin.custom-orders.unreachable', $r, [], $stale)->assertSessionHasErrors('pipeline');
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'x'], $stale)->assertSessionHasErrors('pipeline');

        $fresh = $r->fresh();
        $this->assertSame('contacting', $fresh->pipeline_status);
        $this->assertSame(1, $fresh->lock_version);
        $this->assertSame(1, CustomOrderCallLog::count());
        $this->assertSame(1, CustomOrderEvent::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_lock_version_increments_on_every_change(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->logCall($admin, $r, false);
        $this->logCall($admin, $r, false);
        $this->act($admin, 'admin.custom-orders.unreachable', $r);

        $this->assertSame(3, $r->fresh()->lock_version);
    }

    /* ───────────── list ───────────── */

    public function test_list_filters_by_status_newest_first_with_counts(): void
    {
        $admin = $this->admin();
        $old = $this->submitted($this->customer('Old', 'One'), ['submitted_at' => now()->subDays(3)]);
        $new = $this->submitted($this->customer('New', 'Two'), ['submitted_at' => now()->subHour()]);
        $con = $this->submitted($this->customer('Con', 'Three'), ['pipeline_status' => 'contacting']);
        $this->submitted($this->customer('Legacy', 'Four'), ['pipeline_status' => null, 'submitted_at' => null]);

        $resp = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index'));
        $resp->assertOk()->assertSeeInOrder(['New', 'Old'])->assertDontSee('Legacy');
        $this->assertSame(3, $resp->viewData('total'));
        $this->assertSame(2, $resp->viewData('counts')['submitted']);
        $this->assertSame(1, $resp->viewData('counts')['contacting']);

        $only = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index', ['status' => 'contacting']));
        $this->assertSame([$con->id], $only->viewData('requests')->pluck('id')->all());

        $sub = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index', ['status' => 'submitted']));
        $this->assertSame([$new->id, $old->id], $sub->viewData('requests')->pluck('id')->all());

        // an unknown status value is ignored, not an error
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index', ['status' => 'bogus']))->assertOk();
    }

    public function test_list_search_by_name_email_and_phone(): void
    {
        $admin = $this->admin();
        $ana = $this->customer('Ana', 'Cruz');
        $ana->forceFill(['email' => 'ana.cruz@shop.test'])->save();
        $ben = $this->customer('Ben', 'Reyes');
        $ra = $this->submitted($ana, ['contact_phone' => '09171110000', 'contact_email' => 'ana.contact@mail.test']);
        $rb = $this->submitted($ben, ['contact_phone' => '09182220000', 'contact_email' => 'ben@mail.test']);

        $ids = fn (string $q) => $this->actingAs($admin, 'admin')
            ->get(route('admin.custom-orders.index', ['q' => $q]))
            ->viewData('requests')->pluck('id')->sort()->values()->all();

        $this->assertSame([$ra->id], $ids('Cruz'));
        $this->assertSame([$ra->id], $ids('Ana Cruz'));
        $this->assertSame([$ra->id], $ids('ana.cruz@shop'));
        $this->assertSame([$ra->id], $ids('ana.contact'));
        $this->assertSame([$rb->id], $ids('09182220000'));
        $this->assertSame([$rb->id], $ids('Reyes'));
        $this->assertSame([], $ids('nobody-here'));
        $this->assertCount(2, $ids(''));
        // % and _ are literal, not wildcards
        $this->assertSame([], $ids('%'));
    }

    public function test_list_paginates_at_15(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        foreach (range(1, 17) as $i) {
            $this->submitted($owner, ['submitted_at' => now()->subMinutes($i)]);
        }

        $p1 = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index'));
        $this->assertCount(15, $p1->viewData('requests'));
        $this->assertSame(17, $p1->viewData('requests')->total());

        $p2 = $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index', ['page' => 2]));
        $this->assertCount(2, $p2->viewData('requests'));
    }

    /* ───────────── sidebar badge and old pages ───────────── */

    public function test_sidebar_badge_counts_submitted_only(): void
    {
        $admin = $this->admin();
        $this->submitted($this->customer());
        $this->submitted($this->customer('Ben', 'Reyes'), ['pipeline_status' => 'contacting']);

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.index'))
            ->assertOk()
            ->assertSee('Custom Orders')
            ->assertSeeInOrder(['Custom Orders', 'admin-nav-badge">1<'], false);
    }

    public function test_old_list_links_to_new_page_and_old_buttons_hand_over(): void
    {
        $admin = $this->admin();
        $r = $this->submitted($this->customer());

        $this->actingAs($admin, 'admin')->get(route('admin.design-requests.index'))
            ->assertOk()
            ->assertSee(route('admin.custom-orders.show', $r->id), false)
            ->assertDontSee(route('admin.design-requests.contacted', $r->id), false);

        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.contacted', $r->id))
            ->assertRedirect(route('admin.custom-orders.show', $r->id));
        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.reject', $r->id), ['admin_notes' => 'x'])
            ->assertRedirect(route('admin.custom-orders.show', $r->id));

        $fresh = $r->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('submitted', $fresh->pipeline_status);
        $this->assertNull($fresh->admin_contacted_at);
    }

    public function test_old_buttons_still_work_on_legacy_requests(): void
    {
        $admin = $this->admin();
        $legacy = $this->submitted($this->customer(), ['pipeline_status' => null, 'submitted_at' => null]);

        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.contacted', $legacy->id))->assertRedirect();
        $this->assertNotNull($legacy->fresh()->admin_contacted_at);

        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.reject', $legacy->id), ['admin_notes' => 'No'])->assertRedirect();
        $this->assertSame('declined', $legacy->fresh()->status);
    }

    public function test_declined_request_shows_to_the_customer_with_the_reason(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->submitted($owner);
        $this->act($admin, 'admin.custom-orders.decline', $r, ['reason' => 'We do not build outdoor sheds']);

        $this->actingAs($owner)->get(route('design.show', $r->id))
            ->assertOk()
            ->assertSee('We do not build outdoor sheds');
    }
}
