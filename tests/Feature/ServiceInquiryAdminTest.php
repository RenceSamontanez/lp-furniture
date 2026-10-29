<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\ServiceInquiryDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21 part B: the admin "Other Services" section.
 */
class ServiceInquiryAdminTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea', 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function ticket(array $over = []): ServiceInquiry
    {
        static $n = 0;
        $n++;

        return ServiceInquiry::create(array_merge([
            'reference_number' => 'SI-2026-' . str_pad((string) (5000 + $n), 4, '0', STR_PAD_LEFT),
            'service_type' => 'labor_only_own_wood', 'furniture_type' => 'Table',
            'concern' => 'Build a table from my own narra planks.',
            'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'email' => 'juan@example.com',
            'preferred_call_window' => 'morning_8_12', 'status' => 'new',
        ], $over));
    }

    /* ───────────── list ───────────── */

    public function test_the_list_shows_tickets_counts_and_the_sidebar_badge(): void
    {
        $admin = $this->admin();
        $a = $this->ticket(['name' => 'Anna Reyes']);
        $b = $this->ticket(['name' => 'Ben Cruz', 'status' => 'closed']);

        $res = $this->actingAs($admin, 'admin')->get(route('admin.service-inquiries.index'))->assertOk();
        $res->assertSee('Other Services')->assertSee($a->reference_number)->assertSee('Anna Reyes')
            ->assertSee($b->reference_number)->assertSee('All Tickets');
        $res->assertSee('admin-nav-badge', false); // one new ticket
    }

    public function test_filters_by_status_and_searches_by_name_reference_and_phone(): void
    {
        $admin = $this->admin();
        $a = $this->ticket(['name' => 'Anna Reyes', 'phone' => '09170000001']);
        $b = $this->ticket(['name' => 'Ben Cruz', 'phone' => '09170000002', 'status' => 'unreachable']);

        $this->actingAs($admin, 'admin');

        $this->get(route('admin.service-inquiries.index', ['status' => 'unreachable']))
            ->assertSee('Ben Cruz')->assertDontSee('Anna Reyes');
        $this->get(route('admin.service-inquiries.index', ['status' => 'bogus']))
            ->assertSee('Ben Cruz')->assertSee('Anna Reyes'); // unknown status is ignored
        $this->get(route('admin.service-inquiries.index', ['q' => 'Anna']))->assertSee('Anna Reyes')->assertDontSee('Ben Cruz');
        $this->get(route('admin.service-inquiries.index', ['q' => $b->reference_number]))->assertSee('Ben Cruz')->assertDontSee('Anna Reyes');
        $this->get(route('admin.service-inquiries.index', ['q' => '09170000001']))->assertSee('Anna Reyes')->assertDontSee('Ben Cruz');
        $this->get(route('admin.service-inquiries.index', ['q' => '%']))->assertOk()->assertSee('No tickets match');
    }

    public function test_the_list_is_paginated_15_per_page(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 17; $i++) {
            $this->ticket(['name' => sprintf('Person %02d', $i)]);
        }

        $this->actingAs($admin, 'admin');
        $this->get(route('admin.service-inquiries.index'))->assertSee('Person 16')->assertDontSee('Person 00');
        $this->get(route('admin.service-inquiries.index', ['page' => 2]))->assertSee('Person 00')->assertSee('Person 01');
    }

    /* ───────────── detail ───────────── */

    public function test_the_detail_page_shows_the_ticket(): void
    {
        $admin = $this->admin();
        $t = $this->ticket(['preferred_call_date' => ServiceInquiryDesk::today()->addDay()->toDateString()]);

        $this->actingAs($admin, 'admin')->get(route('admin.service-inquiries.show', $t->id))->assertOk()
            ->assertSee($t->reference_number)->assertSee('Juan Dela Cruz')->assertSee('09171234567')
            ->assertSee('Build a table from my own narra planks.')->assertSee('Labor only (I bring my own wood)')
            ->assertSee('Send Call Update')->assertSee('Close This Ticket');

        $this->get(route('admin.service-inquiries.show', 999999))->assertNotFound();
    }

    /* ───────────── call update ───────────── */

    public function test_unreachable_sets_status_attempts_and_notifies_a_logged_in_customer_with_the_exact_message(): void
    {
        $admin = $this->admin();
        $c = $this->makeUser();
        $t = $this->ticket(['user_id' => $c->id]);
        \App\Models\BusinessSetting::current()->forceFill(['phone' => '0917-555-0100', 'email' => 'hello@lpfurniture.test'])->save();
        \Illuminate\Support\Facades\Cache::forget(\App\Models\BusinessSetting::CACHE_KEY);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'unreachable', 'attempts' => 3, 'note' => 'Try after lunch.'])
            ->assertSessionHasNoErrors();

        $t->refresh();
        $this->assertSame('unreachable', $t->status);
        $this->assertSame(3, $t->attempts);
        $this->assertNotNull($t->last_contacted_at);
        $this->assertStringContainsString('Unreachable, 3 times called. Try after lunch.', $t->admin_notes);
        $this->assertStringContainsString('Rhea', $t->admin_notes);

        $n = Notification::where('user_id', $c->id)->sole();
        $this->assertSame('We Could Not Reach You', $n->title);
        $expected = "We tried to call you 3 times about your service request {$t->reference_number}, but could not reach you. "
            . "Please call or email us so we can continue. You can reach us at 0917-555-0100 or hello@lpfurniture.test.\nTry after lunch.";
        $this->assertSame($expected, $n->message);
        $this->assertSame('general', $n->type);
    }

    public function test_ringing_and_answered_map_to_contacting_and_called(): void
    {
        $admin = $this->admin();
        $c = $this->makeUser();
        $t = $this->ticket(['user_id' => $c->id]);
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'ringing', 'attempts' => 2])->assertSessionHasNoErrors();
        $this->assertSame('contacting', $t->fresh()->status);
        $this->assertSame(2, $t->fresh()->attempts);
        $this->assertStringContainsString('2 times', Notification::where('user_id', $c->id)->latest('id')->first()->message);

        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'answered'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('called', $t->status);
        $this->assertSame(3, $t->attempts); // the answered call counts as one more attempt
        $this->assertSame(2, Notification::where('user_id', $c->id)->count());
        $this->assertStringContainsString('Thank you for speaking with us', Notification::where('user_id', $c->id)->latest('id')->first()->message);
    }

    public function test_a_ticket_without_an_account_records_the_call_but_sends_no_notification(): void
    {
        $admin = $this->admin();
        $t = $this->ticket(['user_id' => null]);
        Notification::query()->delete();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'ringing', 'attempts' => 1])
            ->assertSessionHas('success');

        $this->assertSame('contacting', $t->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    public function test_call_update_validation(): void
    {
        $admin = $this->admin();
        $t = $this->ticket();
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.service-inquiries.call-update', $t->id), [])->assertSessionHasErrors('outcome');
        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'bogus'])->assertSessionHasErrors('outcome');
        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'unreachable', 'attempts' => 99])->assertSessionHasErrors('attempts');
        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'unreachable'])->assertSessionHasErrors('ticket');
        $this->assertSame('new', $t->fresh()->status);
    }

    public function test_the_page_preview_templates_are_the_templates_that_are_sent(): void
    {
        $admin = $this->admin();
        $t = $this->ticket(['user_id' => $this->makeUser()->id]);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.service-inquiries.show', $t->id))->getContent();
        foreach (['inquiry_call_unreachable', 'inquiry_call_ringing', 'inquiry_call_answered'] as $key) {
            $body = \App\Support\NotificationTemplates::TEMPLATES[$key]['body'];
            $this->assertStringContainsString(substr(json_encode($body), 1, 40), $html);
        }
        $this->assertStringContainsString('The Customer Will Receive', $html);
    }

    /* ───────────── notes and close ───────────── */

    public function test_notes_are_appended_not_overwritten(): void
    {
        $admin = $this->admin();
        $t = $this->ticket();
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.service-inquiries.notes', $t->id), ['note' => 'Customer prefers Saturdays.'])->assertSessionHasNoErrors();
        $this->post(route('admin.service-inquiries.notes', $t->id), ['note' => "Has 12 planks.\nAll narra."])->assertSessionHasNoErrors();

        $lines = explode("\n", $t->fresh()->admin_notes);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Note: Customer prefers Saturdays.', $lines[0]);
        $this->assertStringContainsString('Note: Has 12 planks. All narra.', $lines[1]);
        $this->assertSame('new', $t->fresh()->status);

        $this->post(route('admin.service-inquiries.notes', $t->id), ['note' => ''])->assertSessionHasErrors('note');
    }

    public function test_closing_needs_a_reason_and_locks_the_ticket(): void
    {
        $admin = $this->admin();
        $t = $this->ticket(['status' => 'called']);
        $this->actingAs($admin, 'admin');

        $this->post(route('admin.service-inquiries.close', $t->id), [])->assertSessionHasErrors('reason');
        $this->assertSame('called', $t->fresh()->status);

        $this->post(route('admin.service-inquiries.close', $t->id), ['reason' => 'Agreed a price by phone.'])->assertSessionHas('success');
        $t->refresh();
        $this->assertSame('closed', $t->status);
        $this->assertSame('Agreed a price by phone.', $t->closed_reason);
        $this->assertStringContainsString('Closed. Agreed a price by phone.', $t->admin_notes);

        // closed is final: no call update, no second close
        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'answered'])->assertSessionHasErrors('ticket');
        $this->post(route('admin.service-inquiries.close', $t->id), ['reason' => 'Again'])->assertSessionHasErrors('ticket');
        $this->assertSame('closed', $t->fresh()->status);
        $this->get(route('admin.service-inquiries.show', $t->id))->assertSee('This ticket is closed')->assertDontSee('Send Call Update');
    }

    /* ───────────── admin-created ticket ───────────── */

    public function test_an_admin_can_create_a_ticket_for_a_walk_in_customer(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.service-inquiries.create'))->assertOk()->assertSee('walk-in or phone customer');

        $res = $this->post(route('admin.service-inquiries.store'), [
            'service_type' => 'repair', 'concern' => 'Wobbly chair leg, walk-in customer.',
            'name' => 'maria clara', 'phone' => '0917 765 4321',
        ]);

        $t = ServiceInquiry::sole();
        $res->assertRedirect(route('admin.service-inquiries.show', $t->id));
        $this->assertNull($t->user_id);
        $this->assertNull($t->email);                    // email is optional for staff-entered tickets
        $this->assertSame('09177654321', $t->phone);
        $this->assertSame('Maria Clara', $t->name);
        $this->assertSame($admin->id, $t->created_by_admin_id);
        $this->assertSame('new', $t->status);

        // the other admin is told, the author is not, nobody else is
        $this->assertSame(1, Notification::where('user_id', $other->id)->count());
        $this->assertSame(0, Notification::where('user_id', $admin->id)->count());
        $this->get(route('admin.service-inquiries.show', $t->id))->assertSee('entered by Rhea');
    }

    public function test_admin_created_tickets_are_validated_like_public_ones(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->post(route('admin.service-inquiries.store'), [])->assertSessionHasErrors(['service_type', 'concern', 'name', 'phone']);
        $this->post(route('admin.service-inquiries.store'), ['service_type' => 'repair', 'concern' => 'Long enough concern.', 'name' => 'X', 'phone' => '123'])
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, ServiceInquiry::count());
    }

    /* ───────────── link an existing customer ───────────── */

    public function test_the_customer_search_finds_customers_by_name_phone_and_email_only(): void
    {
        $admin = $this->admin();
        $maria = $this->makeUser();
        $maria->forceFill(['first_name' => 'Maria', 'last_name' => 'Clara', 'email' => 'maria@example.test', 'phone_number' => '09171112222'])->save();
        $this->makeUser('admin')->forceFill(['first_name' => 'Maria', 'last_name' => 'Admin'])->save();

        $this->actingAs($admin, 'admin');
        foreach (['Maria Cla', '0917111', 'maria@example'] as $q) {
            $res = $this->getJson(route('admin.service-inquiries.customer-search', ['q' => $q]))->assertOk();
            $this->assertSame([$maria->id], array_column($res->json('results'), 'id'), $q);
        }
        $this->assertSame([], $this->getJson(route('admin.service-inquiries.customer-search', ['q' => 'M']))->json('results'));
        $this->assertSame([], $this->getJson(route('admin.service-inquiries.customer-search', ['q' => 'nobody-here']))->json('results'));
    }

    public function test_a_ticket_can_be_linked_to_a_customer_who_is_then_notified_of_calls(): void
    {
        $admin    = $this->admin();
        $customer = $this->makeUser();
        $this->actingAs($admin, 'admin');

        $this->get(route('admin.service-inquiries.create'))->assertOk()->assertSee('Link an Existing Customer Account');

        $this->post(route('admin.service-inquiries.store'), [
            'service_type' => 'repair', 'concern' => 'Wobbly chair leg, walk-in customer.',
            'name' => 'Maria Clara', 'phone' => '09177654321', 'customer_id' => $customer->id,
        ]);

        $t = ServiceInquiry::sole();
        $this->assertSame($customer->id, $t->user_id);
        $this->assertSame(0, Notification::where('user_id', $customer->id)->count(), 'the customer is not told about a ticket an admin typed in');

        $this->post(route('admin.service-inquiries.call-update', $t->id), ['outcome' => 'answered'])->assertSessionHas('success');
        $this->assertSame(1, Notification::where('user_id', $customer->id)->count());
    }

    public function test_only_a_customer_account_can_be_linked(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $payload = ['service_type' => 'repair', 'concern' => 'Wobbly chair leg, walk-in customer.', 'name' => 'Maria Clara', 'phone' => '09177654321'];

        $this->post(route('admin.service-inquiries.store'), $payload + ['customer_id' => $this->makeUser('admin')->id])->assertSessionHasErrors('customer_id');
        $this->post(route('admin.service-inquiries.store'), $payload + ['customer_id' => 999999])->assertSessionHasErrors('customer_id');
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_without_a_linked_account_the_detail_and_call_modal_say_no_notification_is_sent(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $t = $this->ticket(['user_id' => null]);

        $this->get(route('admin.service-inquiries.show', $t->id))->assertOk()
            ->assertSee('No account (walk-in or an older ticket)')
            ->assertSee('no in-app notification is sent');
    }

    /* ───────────── RBAC: every route x every actor ───────────── */

    public function test_every_service_inquiry_route_is_admin_only(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->admin();
        $t        = $this->ticket();

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->getName() ?? '', 'admin.service-inquiries.'))
            ->values();
        $this->assertCount(12, $routes);   // 11 + the private reference photo (Stage 21i)

        foreach ($routes as $route) {
            $method = $route->methods()[0];
            $uri    = '/' . ltrim(str_replace(['{id}', '{n}'], [(string) $t->id, '0'], $route->uri()), '/');
            $label  = "{$method} {$uri}";

            // guest
            $this->flushSession();
            $this->assertLoginRedirect($this->call($method, $uri), "guest {$label}");

            // customer on the web guard
            $this->actingAs($customer, 'web');
            $this->assertLoginRedirect($this->call($method, $uri), "customer {$label}");

            // customer credentials presented to the admin guard
            $this->app['auth']->forgetGuards();
            $this->actingAs($customer, 'admin');
            $this->assertLoginRedirect($this->call($method, $uri), "customer on admin guard {$label}");

            // admin
            $this->app['auth']->forgetGuards();
            $this->actingAs($admin, 'admin');
            $res = $this->call($method, $uri);
            $this->assertNotContains($res->getStatusCode(), [401, 403, 419], "admin {$label}");
            $this->assertLessThan(500, $res->getStatusCode(), "admin {$label}");
            $this->assertFalse($res->isRedirect(route('admin.login')), "admin {$label} must not be sent to login");

            $this->app['auth']->forgetGuards();
        }

        $this->assertSame('new', $t->fresh()->status, 'no actor but the admin changed the ticket');
    }

    private function assertLoginRedirect($response, string $label): void
    {
        $this->assertTrue($response->isRedirect(route('admin.login')), "{$label} should redirect to the admin login, got {$response->getStatusCode()}");
    }
}
