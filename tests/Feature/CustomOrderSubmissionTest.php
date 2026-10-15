<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 3: the customer "Send to our team" form.
 *
 * Suggestions are only "loadable" when their image file exists under
 * public/images/ai-designs, so setUp writes ONE throwaway file there
 * with a phpunit- prefix and tearDown deletes exactly that file (DB
 * rollback doesn't undo filesystem writes). No existing image is read,
 * changed or removed. The generation job is faked, so no provider is
 * ever called.
 */
class CustomOrderSubmissionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $imageRel;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->imageRel = 'images/ai-designs/phpunit-stage3-' . bin2hex(random_bytes(6)) . '.png';
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

    /* ───────────── fixtures ───────────── */

    private function customer(string $phone = '09171234567'): User
    {
        $user = $this->makeUser();
        $user->forceFill(['first_name' => 'Ana', 'last_name' => 'Cruz', 'phone_number' => $phone])->save();

        return $user;
    }

    /** A generated request with two loadable concepts. */
    private function generatedRequest(User $owner): array
    {
        $request = CustomDesignRequest::create([
            'user_id'        => $owner->id,
            'title'          => 'Dining Table — Ana',
            'furniture_type' => 'Dining Table',
            'description'    => 'A six-seater narra dining table',
            'status'         => 'ai_generated',
        ]);

        $suggestions = collect([1, 2])->map(fn () => AiDesignSuggestion::create([
            'design_id'    => $request->id,
            'image_path'   => $this->imageRel,
            'generated_at' => now(),
        ]));

        return [$request, $suggestions];
    }

    private function failedRequest(User $owner): CustomDesignRequest
    {
        return CustomDesignRequest::create([
            'user_id'        => $owner->id,
            'title'          => 'Bookshelf — Ana',
            'furniture_type' => 'Bookshelf',
            'description'    => 'A tall bookshelf with five shelves',
            'status'         => 'rejected',
            'failure_reason' => 'provider_error',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'contact_phone'         => '09171234567',
            'contact_email'         => 'ana@example.com',
            'preferred_call_window' => 'morning_8_12',
            'request_mode'          => 'exact',
        ], $overrides);
    }

    private function assertNothingSubmitted(CustomDesignRequest $request, string $status = 'ai_generated'): void
    {
        $fresh = $request->fresh();
        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->pipeline_status);
        $this->assertNull($fresh->submitted_at);
        $this->assertNull($fresh->contact_phone);
        $this->assertSame(0, CustomOrderEvent::where('request_id', $request->id)->count());
        $this->assertSame(0, Notification::where('type', 'custom_order')->count());
    }

    /* ───────────── opening the form ───────────── */

    public function test_choosing_a_concept_opens_the_form_without_sending(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.select', $request->id), ['suggestion_id' => $s[1]->id])
            ->assertRedirect(route('design.sendForm', [$request->id, 'suggestion' => $s[1]->id]));

        $this->assertNothingSubmitted($request);
        $this->assertSame(0, AiDesignSuggestion::where('design_id', $request->id)->where('is_selected', true)->count());
    }

    public function test_form_is_prefilled_and_says_it_is_a_request(): void
    {
        $ana = $this->customer('+639181234567');
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->get(route('design.sendForm', [$request->id, 'suggestion' => $s[0]->id]))
            ->assertOk()
            ->assertSee('Send to our team')
            ->assertSee('value="09181234567"', false)
            ->assertSee('value="' . $ana->email . '"', false)
            ->assertSee('This is a request, not an order. No payment is due yet.')
            ->assertSee('name="suggestion_id" value="' . $s[0]->id . '"', false);
    }

    /* ───────────── successful submission ───────────── */

    public function test_submission_saves_everything_writes_event_and_notifies(): void
    {
        $ana    = $this->customer();
        $admin1 = $this->makeUser('admin');
        $admin2 = $this->makeUser('admin');
        $other  = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload([
            'suggestion_id'         => $s[1]->id,
            'contact_phone'         => '+639181234567',
            'preferred_call_window' => 'afternoon_12_5',
        ]))->assertRedirect(route('design.show', $request->id));

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('submitted', $fresh->pipeline_status);
        $this->assertSame('09181234567', $fresh->contact_phone);
        $this->assertSame('ana@example.com', $fresh->contact_email);
        $this->assertSame('afternoon_12_5', $fresh->preferred_call_window);
        $this->assertSame('exact', $fresh->request_mode);
        $this->assertNull($fresh->modification_notes);
        $this->assertSame($s[1]->id, $fresh->selected_suggestion_id);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertTrue($s[1]->fresh()->is_selected);
        $this->assertFalse($s[0]->fresh()->is_selected);

        // profile phone is not changed by the form
        $this->assertSame('09171234567', $ana->fresh()->phone_number);

        $event = CustomOrderEvent::where('request_id', $request->id)->sole();
        $this->assertSame('submitted', $event->type);
        $this->assertSame('customer', $event->actor_type);
        $this->assertSame($ana->id, $event->actor_id);
        $this->assertSame('afternoon_12_5', $event->data['preferred_call_window']);

        $mine = Notification::where('user_id', $ana->id)->where('type', 'custom_order')->sole();
        $this->assertSame(
            "We received your request for Dining Table — Ana. We'll call you at 09181234567 during your preferred time (Afternoon, 12 PM to 5 PM).",
            $mine->message
        );

        foreach ([$admin1, $admin2] as $admin) {
            $n = Notification::where('user_id', $admin->id)->where('type', 'custom_order')->sole();
            $this->assertSame(
                'New custom order request: Dining Table — Ana from Ana Cruz. Preferred call: Afternoon, 12 PM to 5 PM.',
                $n->message
            );
            $this->assertStringContainsString('/admin/design-requests/' . $request->id, $n->url);
        }

        $this->assertSame(0, Notification::where('user_id', $other->id)->count());
        $this->assertSame(3, Notification::where('type', 'custom_order')->count());
    }

    public function test_show_page_has_the_status_line_after_submission(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id),
            $this->payload(['suggestion_id' => $s[0]->id, 'preferred_call_window' => 'anytime']));

        $this->actingAs($ana)->get(route('design.show', $request->id))
            ->assertOk()
            ->assertSee('Request sent, we will call you at 09171234567 during Anytime, 8 AM to 5 PM.')
            ->assertDontSee(route('design.regenerate', $request->id), false)
            ->assertDontSee('₱');
    }

    public function test_modified_mode_saves_notes(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload([
            'suggestion_id'      => $s[0]->id,
            'request_mode'       => 'modified',
            'modification_notes' => '  Make it 20 cm longer  ',
        ]))->assertSessionHasNoErrors();

        $fresh = $request->fresh();
        $this->assertSame('modified', $fresh->request_mode);
        $this->assertSame('Make it 20 cm longer', $fresh->modification_notes);
    }

    public function test_exact_mode_drops_stray_notes(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload([
            'suggestion_id' => $s[0]->id, 'modification_notes' => 'left over text',
        ]));

        $this->assertNull($request->fresh()->modification_notes);
    }

    /* ───────────── validation ───────────── */

    public function test_required_fields(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->from(route('design.sendForm', [$request->id, 'suggestion' => $s[0]->id]))
            ->post(route('design.sendToTeam', $request->id), ['suggestion_id' => $s[0]->id])
            ->assertSessionHasErrors(['contact_phone', 'contact_email', 'preferred_call_window', 'request_mode']);

        $this->assertNothingSubmitted($request);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        foreach ([
            ['contact_phone' => '12345'],
            ['contact_phone' => '(043) 723-1234'],
            ['contact_email' => 'not-an-email'],
            ['preferred_call_window' => 'midnight'],
            ['request_mode' => 'surprise_me'],
        ] as $bad) {
            $this->actingAs($ana)->post(route('design.sendToTeam', $request->id),
                $this->payload(array_merge(['suggestion_id' => $s[0]->id], $bad)))
                ->assertSessionHasErrors(array_keys($bad));
        }

        $this->assertNothingSubmitted($request);
    }

    public function test_modified_mode_needs_notes(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id),
            $this->payload(['suggestion_id' => $s[0]->id, 'request_mode' => 'modified', 'modification_notes' => '']))
            ->assertSessionHasErrors('modification_notes');

        $this->assertNothingSubmitted($request);
    }

    /* ───────────── double submission ───────────── */

    public function test_second_submission_changes_nothing(): void
    {
        $ana = $this->customer();
        $this->makeUser('admin');
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id),
            $this->payload(['suggestion_id' => $s[0]->id]));
        $first = $request->fresh();

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload([
            'suggestion_id'         => $s[1]->id,
            'contact_phone'         => '09999999999',
            'preferred_call_window' => 'anytime',
        ]))->assertRedirect(route('design.show', $request->id))
           ->assertSessionHas('info');

        $again = $request->fresh();
        $this->assertSame('09171234567', $again->contact_phone);
        $this->assertSame('morning_8_12', $again->preferred_call_window);
        $this->assertSame($s[0]->id, $again->selected_suggestion_id);
        $this->assertEquals($first->submitted_at, $again->submitted_at);
        $this->assertSame(1, CustomOrderEvent::where('request_id', $request->id)->count());
        $this->assertSame(2, Notification::where('type', 'custom_order')->count());

        // the form and the concept picker both bounce back to the existing state
        $this->actingAs($ana)->get(route('design.sendForm', [$request->id, 'suggestion' => $s[1]->id]))
            ->assertRedirect(route('design.show', $request->id));
        $this->actingAs($ana)->post(route('design.select', $request->id), ['suggestion_id' => $s[1]->id])
            ->assertRedirect(route('design.show', $request->id));
        $this->assertSame($s[0]->id, $request->fresh()->selected_suggestion_id);
    }

    public function test_legacy_one_click_request_cannot_be_resubmitted(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);
        $request->update(['status' => 'approved']); // sent under the old flow, no pipeline_status

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload(['suggestion_id' => $s[0]->id]))
            ->assertRedirect(route('design.show', $request->id));

        $this->assertNull($request->fresh()->pipeline_status);
        $this->assertSame(0, CustomOrderEvent::count());
    }

    /* ───────────── regenerate after submission ───────────── */

    public function test_regenerate_is_refused_after_submission(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload(['suggestion_id' => $s[0]->id]));

        $this->actingAs($ana)->post(route('design.regenerate', $request->id))
            ->assertRedirect(route('design.show', $request->id))
            ->assertSessionHas('info');

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('submitted', $fresh->pipeline_status);
        $this->assertSame(2, AiDesignSuggestion::where('design_id', $request->id)->count());
        Queue::assertNotPushed(GenerateAiDesign::class);
    }

    public function test_regenerate_still_works_before_submission(): void
    {
        $ana = $this->customer();
        [$request] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.regenerate', $request->id))
            ->assertRedirect(route('design.show', $request->id));

        $this->assertSame('pending', $request->fresh()->status);
        Queue::assertPushed(GenerateAiDesign::class);
    }

    /* ───────────── ownership (real ids) ───────────── */

    public function test_another_customer_cannot_view_or_submit_the_request(): void
    {
        $ana = $this->customer();
        $ben = $this->customer('09221234567');
        [$request, $s] = $this->generatedRequest($ana);
        $failed = $this->failedRequest($ana);

        $this->actingAs($ben)->get(route('design.show', $request->id))->assertNotFound();
        $this->actingAs($ben)->get(route('design.sendForm', [$request->id, 'suggestion' => $s[0]->id]))->assertNotFound();
        $this->actingAs($ben)->post(route('design.select', $request->id), ['suggestion_id' => $s[0]->id])->assertNotFound();
        $this->actingAs($ben)->post(route('design.sendToTeam', $request->id), $this->payload(['suggestion_id' => $s[0]->id]))->assertNotFound();
        $this->actingAs($ben)->post(route('design.regenerate', $request->id))->assertNotFound();
        $this->actingAs($ben)->post(route('design.sendWithoutImage', $failed->id))->assertNotFound();
        $this->actingAs($ben)->get(route('design.sendForm', $failed->id))->assertNotFound();
        $this->actingAs($ben)->post(route('design.sendToTeam', $failed->id), $this->payload())->assertNotFound();

        $this->assertNothingSubmitted($request);
        $this->assertNothingSubmitted($failed, 'rejected');
        $this->assertSame(2, AiDesignSuggestion::where('design_id', $request->id)->count());
    }

    public function test_cannot_send_another_customers_concept_on_own_request(): void
    {
        $ana = $this->customer();
        $ben = $this->customer('09221234567');
        [, $anaSuggestions] = $this->generatedRequest($ana);
        [$benRequest]       = $this->generatedRequest($ben);

        $this->actingAs($ben)->post(route('design.select', $benRequest->id), ['suggestion_id' => $anaSuggestions[0]->id])
            ->assertNotFound();
        $this->actingAs($ben)->get(route('design.sendForm', [$benRequest->id, 'suggestion' => $anaSuggestions[0]->id]))
            ->assertNotFound();
        $this->actingAs($ben)->post(route('design.sendToTeam', $benRequest->id), $this->payload(['suggestion_id' => $anaSuggestions[0]->id]))
            ->assertNotFound();

        $this->assertNothingSubmitted($benRequest);
        $this->assertFalse($anaSuggestions[0]->fresh()->is_selected);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $ana = $this->customer();
        [$request, $s] = $this->generatedRequest($ana);

        $this->get(route('design.sendForm', [$request->id, 'suggestion' => $s[0]->id]))->assertRedirect();
        $this->post(route('design.sendToTeam', $request->id), $this->payload(['suggestion_id' => $s[0]->id]))->assertRedirect();

        $this->assertNull($request->fresh()->pipeline_status);
    }

    /* ───────────── send without an image ───────────── */

    public function test_send_without_image_path(): void
    {
        $ana = $this->customer();
        $admin = $this->makeUser('admin');
        $request = $this->failedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendWithoutImage', $request->id))
            ->assertRedirect(route('design.sendForm', $request->id));
        $this->assertNothingSubmitted($request, 'rejected');

        $this->actingAs($ana)->get(route('design.sendForm', $request->id))
            ->assertOk()
            ->assertSee('No image')
            ->assertDontSee('name="suggestion_id"', false);

        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload([
            'request_mode' => 'modified', 'modification_notes' => 'Use mahogany instead',
        ]))->assertRedirect(route('design.show', $request->id));

        $fresh = $request->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('submitted', $fresh->pipeline_status);
        $this->assertNull($fresh->selected_suggestion_id);
        $this->assertSame('Use mahogany instead', $fresh->modification_notes);
        $this->assertSame(1, CustomOrderEvent::where('request_id', $request->id)->count());
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'custom_order')->count());

        $this->actingAs($ana)->get(route('design.show', $request->id))
            ->assertOk()
            ->assertSee('Sent Without an Image')
            ->assertSee('Request sent, we will call you at 09171234567 during Morning, 8 AM to 12 PM.');

        // and it can't be retried into a new generation now
        $this->actingAs($ana)->post(route('design.regenerate', $request->id))->assertSessionHas('info');
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_no_image_path_only_for_failed_requests(): void
    {
        $ana = $this->customer();
        [$request] = $this->generatedRequest($ana);

        $this->actingAs($ana)->post(route('design.sendWithoutImage', $request->id))->assertNotFound();
        $this->actingAs($ana)->get(route('design.sendForm', $request->id))->assertNotFound();
        $this->actingAs($ana)->post(route('design.sendToTeam', $request->id), $this->payload())->assertNotFound();

        $this->assertNothingSubmitted($request);
    }

    /* ───────────── old admin buttons still work ───────────── */

    public function test_admin_mark_contacted_and_reject_still_work_on_submitted_requests(): void
    {
        $ana   = $this->customer();
        $admin = $this->makeUser('admin');
        [$request, $s] = $this->generatedRequest($ana);
        [$second, $s2] = $this->generatedRequest($ana);

        foreach ([[$request, $s], [$second, $s2]] as [$r, $sg]) {
            $this->actingAs($ana)->post(route('design.sendToTeam', $r->id), $this->payload(['suggestion_id' => $sg[0]->id]));
        }

        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.contacted', $request->id))->assertRedirect();
        $this->assertNotNull($request->fresh()->admin_contacted_at);

        $this->actingAs($admin, 'admin')->post(route('admin.design-requests.reject', $second->id), ['admin_notes' => 'Not feasible'])
            ->assertRedirect();
        $this->assertSame('declined', $second->fresh()->status);
    }
}
