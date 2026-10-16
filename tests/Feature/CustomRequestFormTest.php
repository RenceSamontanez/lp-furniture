<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 13 part A: legacy one-click requests, saved contact, the
 * customization idea field, and editing before a quote is sent.
 */
class CustomRequestFormTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $imageRel;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->imageRel = 'images/ai-designs/phpunit-stage13-' . bin2hex(random_bytes(6)) . '.png';
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

    private function customer(string $first = 'Ana'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    private function request(User $owner, array $over = []): array
    {
        $r = CustomDesignRequest::create(array_merge([
            'user_id' => $owner->id, 'title' => 'Dining Table - ' . $owner->first_name,
            'furniture_type' => 'Dining Table', 'description' => 'A narra table', 'status' => 'ai_generated',
        ], $over));
        $s = AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => $this->imageRel, 'generated_at' => now()]);

        return [$r, $s];
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'contact_phone' => '09171234567', 'contact_email' => 'ana@example.com',
            'preferred_call_window' => 'morning_8_12', 'request_mode' => 'exact',
        ], $over);
    }

    /* ═════════ legacy requests ═════════ */

    public function test_legacy_approved_request_opens_the_form_and_submits_once(): void
    {
        $ana = $this->customer();
        [$r, $s] = $this->request($ana, ['status' => 'approved']);   // old one-click flow, nothing else
        $s->update(['is_selected' => true]);
        $this->assertNull($r->pipeline_status);

        // the design page now offers Continue, not a dead end, and no Regenerate
        $this->actingAs($ana)->get(route('design.show', $r->id))->assertOk()
            ->assertSee('Continue')->assertDontSee('Regenerate');

        $this->actingAs($ana)->get(route('design.sendForm', $r->id))->assertOk()->assertSee('Send to our team');

        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $s->id]))
            ->assertRedirect(route('design.show', $r->id))->assertSessionHasNoErrors();

        $fresh = $r->fresh();
        $this->assertSame('submitted', $fresh->pipeline_status);
        $this->assertSame('09171234567', $fresh->contact_phone);
        $this->assertSame($s->id, (int) $fresh->selected_suggestion_id);
        $this->assertSame(1, CustomOrderEvent::where('request_id', $r->id)->where('type', 'submitted')->count());

        // now a normal request: a second submit is refused
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $s->id]))
            ->assertRedirect(route('design.show', $r->id))->assertSessionHas('info');
        $this->assertSame(1, CustomOrderEvent::where('type', 'submitted')->count());
    }

    public function test_legacy_request_without_a_selected_concept_can_still_submit(): void
    {
        $ana = $this->customer();
        $r = CustomDesignRequest::create([
            'user_id' => $ana->id, 'title' => 'Shelf', 'furniture_type' => 'Shelf', 'description' => 'A shelf', 'status' => 'approved',
        ]);

        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame('submitted', $r->fresh()->pipeline_status);
    }

    public function test_legacy_requests_already_handled_stay_blocked(): void
    {
        $ana = $this->customer();
        [$handled, $s1] = $this->request($ana, ['status' => 'approved', 'admin_contacted_at' => now()]);
        [$declined, $s2] = $this->request($ana, ['status' => 'declined']);

        // handled in the old admin list: "already sent", as before
        $this->actingAs($ana)->get(route('design.sendForm', [$handled->id, 'suggestion' => $s1->id]))
            ->assertRedirect(route('design.show', $handled->id));
        $this->actingAs($ana)->post(route('design.sendToTeam', $handled->id), $this->payload(['suggestion_id' => $s1->id]))
            ->assertRedirect(route('design.show', $handled->id));
        // declined: no concept can be sent for it, as before
        $this->actingAs($ana)->get(route('design.sendForm', [$declined->id, 'suggestion' => $s2->id]))->assertNotFound();
        $this->actingAs($ana)->post(route('design.sendToTeam', $declined->id), $this->payload(['suggestion_id' => $s2->id]))->assertNotFound();

        foreach ([$handled, $declined] as $r) {
            $this->assertNull($r->fresh()->pipeline_status);
        }
        $this->assertSame(0, CustomOrderEvent::count());
    }

    public function test_legacy_request_of_another_customer_is_404(): void
    {
        $ana = $this->customer();
        $bob = $this->customer('Bob');
        [$r, $s] = $this->request($ana, ['status' => 'approved']);

        $this->actingAs($bob)->get(route('design.sendForm', $r->id))->assertNotFound();
        $this->actingAs($bob)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $s->id]))->assertNotFound();
        $this->assertNull($r->fresh()->pipeline_status);
    }

    /* ═════════ saved contact ═════════ */

    public function test_without_saved_contact_the_form_asks_and_offers_to_remember(): void
    {
        $ana = $this->customer();
        [$r, $s] = $this->request($ana);

        $this->actingAs($ana)->get(route('design.sendForm', [$r->id, 'suggestion' => $s->id]))->assertOk()
            ->assertSee('name="contact_phone"', false)
            ->assertSee('name="contact_email"', false)
            ->assertSee('Save this number and email for my next custom orders')
            ->assertDontSee('Use a different number or email for this request');
    }

    public function test_checkbox_saves_the_contact_and_the_next_form_shows_a_summary(): void
    {
        $ana = $this->customer();
        [$r, $s] = $this->request($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload([
            'suggestion_id' => $s->id, 'contact_phone' => '+639181112222', 'contact_email' => 'saved@example.com', 'save_contact' => '1',
        ]))->assertSessionHasNoErrors();

        $u = $ana->fresh();
        $this->assertSame('09181112222', $u->saved_contact_phone);
        $this->assertSame('saved@example.com', $u->saved_contact_email);
        $this->assertNotNull($u->contact_saved_at);
        $this->assertSame('09181112222', $r->fresh()->contact_phone, 'the request keeps the contact it was sent with');

        [$r2, $s2] = $this->request($ana);
        $page = $this->actingAs($ana)->get(route('design.sendForm', [$r2->id, 'suggestion' => $s2->id]))->assertOk();
        $page->assertSee('Use a different number or email for this request')->assertSee('09181112222')->assertSee('saved@example.com');
        // the idea and the call window are still asked every time
        $page->assertSee('What would you like to change or add to this design?')->assertSee('When should we call?');
        $this->assertMatchesRegularExpression('/id="co-update-saved"\s+hidden/', $page->getContent(), 'the update toggle stays hidden until a different contact is chosen');
    }

    public function test_saved_contact_does_not_skip_validation_or_the_call_window(): void
    {
        $ana = $this->customer();
        $ana->forceFill(['saved_contact_phone' => '09181112222', 'saved_contact_email' => 'saved@example.com'])->save();
        [$r, $s] = $this->request($ana);

        // phone changed through "Change" to something invalid
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload([
            'suggestion_id' => $s->id, 'contact_phone' => '12345',
        ]))->assertSessionHasErrors('contact_phone');

        // no call window
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload([
            'suggestion_id' => $s->id, 'preferred_call_window' => '',
        ]))->assertSessionHasErrors('preferred_call_window');

        // modified without an idea
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload([
            'suggestion_id' => $s->id, 'request_mode' => 'modified', 'modification_notes' => '',
        ]))->assertSessionHasErrors('modification_notes');

        $this->assertNull($r->fresh()->pipeline_status);
    }

    public function test_not_ticking_the_checkbox_saves_nothing(): void
    {
        $ana = $this->customer();
        [$r, $s] = $this->request($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $s->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($ana->fresh()->saved_contact_phone);
        $this->assertTrue(Schema::hasColumn('users', 'contact_saved_at'));
    }

    /* ═════════ editing before a quote ═════════ */

    public static function editableStatuses(): array
    {
        return ['submitted' => ['submitted', true], 'contacting' => ['contacting', true], 'unreachable' => ['unreachable', true],
            'quoted' => ['quoted', false], 'accepted' => ['accepted', false], 'ordered' => ['ordered', false],
            'expired' => ['expired', false], 'declined_by_admin' => ['declined_by_admin', false]];
    }

    /** @dataProvider editableStatuses */
    public function test_edit_is_allowed_only_before_a_quote(string $status, bool $allowed): void
    {
        $ana = $this->customer();
        [$r] = $this->request($ana, [
            'status' => 'approved', 'pipeline_status' => $status, 'contact_phone' => '09171234567',
            'contact_email' => 'ana@example.com', 'preferred_call_window' => 'anytime', 'request_mode' => 'exact',
            'submitted_at' => now(),
        ]);
        $admin = $this->makeUser('admin');
        $version = (int) $r->fresh()->lock_version;

        $form = $this->actingAs($ana)->get(route('design.edit', $r->id));
        $save = $this->actingAs($ana)->post(route('design.update', $r->id), $this->payload([
            'request_mode' => 'modified', 'modification_notes' => 'Add two drawers', 'preferred_call_window' => 'afternoon_12_5',
        ]));

        if ($allowed) {
            $form->assertOk()->assertSee('Edit your request')->assertSee('Save changes');
            $save->assertRedirect(route('design.show', $r->id))->assertSessionHas('success');

            $f = $r->fresh();
            $this->assertSame('modified', $f->request_mode);
            $this->assertSame('Add two drawers', $f->modification_notes);
            $this->assertSame('afternoon_12_5', $f->preferred_call_window);
            $this->assertSame($version + 1, (int) $f->lock_version);
            $this->assertSame($status, $f->pipeline_status, 'editing never changes the status');
            $this->assertSame(1, CustomOrderEvent::where('request_id', $r->id)->where('type', 'request_updated')->count());

            $n = Notification::where('user_id', $admin->id)->sole();
            $this->assertStringContainsString('Ana Cruz updated their request for Custom Furniture — Ana', $n->message);
            $this->assertSame(route('admin.custom-orders.show', $r->id), $n->url);
        } else {
            $form->assertRedirect(route('design.show', $r->id))->assertSessionHas('info');
            $save->assertRedirect(route('design.show', $r->id));
            $f = $r->fresh();
            $this->assertNull($f->modification_notes);
            $this->assertSame($version, (int) $f->lock_version);
            $this->assertSame(0, CustomOrderEvent::where('type', 'request_updated')->count());
        }
    }

    public function test_edit_form_is_prefilled_and_design_page_links_to_it(): void
    {
        $ana = $this->customer();
        [$r, $s] = $this->request($ana);
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload([
            'suggestion_id' => $s->id, 'request_mode' => 'modified', 'modification_notes' => 'Darker stain',
            'preferred_call_window' => 'afternoon_12_5',
        ]));

        $this->actingAs($ana)->get(route('design.show', $r->id))->assertOk()
            ->assertSee('Edit my request')->assertSee(route('design.edit', $r->id), false);

        $page = $this->actingAs($ana)->get(route('design.edit', $r->id))->assertOk();
        $page->assertSee('Darker stain')->assertSee('value="09171234567"', false)->assertSee('ana@example.com');
        $this->assertMatchesRegularExpression('/value="afternoon_12_5"\s+required\s+checked/', $page->getContent());

        // once a quote is sent the link is gone
        $r->forceFill(['pipeline_status' => 'quoted'])->save();
        $this->actingAs($ana)->get(route('design.show', $r->id))->assertOk()->assertDontSee('Edit my request');
    }

    public function test_edit_validation_and_ownership(): void
    {
        $ana = $this->customer();
        $bob = $this->customer('Bob');
        [$r, $s] = $this->request($ana);
        $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $s->id]));

        $this->actingAs($ana)->post(route('design.update', $r->id), $this->payload(['contact_phone' => 'abc']))
            ->assertSessionHasErrors('contact_phone');

        $this->actingAs($bob)->get(route('design.edit', $r->id))->assertNotFound();
        $this->actingAs($bob)->post(route('design.update', $r->id), $this->payload(['request_mode' => 'modified', 'modification_notes' => 'hijack']))
            ->assertNotFound();
        $this->assertNull($r->fresh()->modification_notes);
    }

    public static function editAccess(): array
    {
        $cases = [];
        foreach (['guest' => 'login', 'customer_on_admin_guard' => 'login', 'admin_user_on_web_guard' => '404', 'other_customer' => '404'] as $who => $outcome) {
            foreach (['edit', 'update'] as $route) {
                $cases["$who -> $route"] = [$who, $route, $outcome];
            }
        }

        return $cases;
    }

    /** @dataProvider editAccess */
    public function test_edit_routes_rbac(string $who, string $route, string $outcome): void
    {
        $ana = $this->customer();
        [$r] = $this->request($ana, ['pipeline_status' => 'submitted', 'status' => 'approved']);
        $visitor = $this->customer('Visitor');
        $admin = $this->makeUser('admin');

        match ($who) {
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            'admin_user_on_web_guard' => $this->actingAs($admin),
            'other_customer'          => $this->actingAs($visitor),
            default                   => null,
        };
        \Illuminate\Support\Facades\Auth::shouldUse('web');

        $resp = $route === 'edit'
            ? $this->get(route('design.edit', $r->id))
            : $this->post(route('design.update', $r->id), $this->payload(['request_mode' => 'modified', 'modification_notes' => 'hijack']));

        $outcome === 'login' ? $resp->assertRedirect(route('login')) : $resp->assertNotFound();

        $this->assertNull($r->fresh()->modification_notes);
        $this->assertSame(0, CustomOrderEvent::where('type', 'request_updated')->count());
    }
}
