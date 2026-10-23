<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * My Designs: History / Gallery / Favorites, the Order vs View concept
 * button and Try again. Loadable suggestions need a real file under
 * public/images/ai-designs, so one phpunit- prefixed throwaway file is
 * written in setUp and removed in tearDown.
 */
class MyDesignsTabsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private string $imageRel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageRel = 'images/ai-designs/phpunit-mydesigns-' . bin2hex(random_bytes(6)) . '.png';
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

    private function design(User $owner, string $description, array $attrs = [], bool $withImage = true, bool $favorited = false): CustomDesignRequest
    {
        $request = CustomDesignRequest::create(array_merge([
            'user_id'     => $owner->id,
            'title'       => 'Custom Furniture',
            'description' => $description,
            'status'      => 'ai_generated',
        ], $attrs));

        if ($withImage) {
            AiDesignSuggestion::create([
                'design_id'    => $request->id,
                'image_path'   => $this->imageRel,
                'is_favorited' => $favorited,
                'generated_at' => now(),
            ]);
        }

        return $request;
    }

    private function fixtures(User $me): array
    {
        return [
            'ready'     => $this->design($me, 'READY plain walnut shelf'),
            'favorite'  => $this->design($me, 'FAVORITE oak chair', [], true, true),
            'failed'    => $this->design($me, 'FAILED bed frame', ['status' => 'rejected', 'failure_reason' => 'provider_error'], false),
            'busy'      => $this->design($me, 'BUSY coffee table', ['status' => 'pending'], false),
        ];
    }

    public function test_history_shows_every_prompt_with_counts_and_failure_reason(): void
    {
        $me = $this->makeUser();
        $this->fixtures($me);

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'history']))
            ->assertOk()
            ->assertSee('READY plain walnut shelf')
            ->assertSee('FAVORITE oak chair')
            ->assertSee('FAILED bed frame')
            ->assertSee('BUSY coffee table')
            ->assertSee('Our AI providers were unavailable')
            ->assertSee('Try again')
            ->assertSee('Today')
            ->assertSeeInOrder(['History', '4', 'Gallery', '2', 'Favorites', '1']);
    }

    public function test_history_filter_keeps_only_ready_or_only_failed(): void
    {
        $me = $this->makeUser();
        $this->fixtures($me);

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'history', 'filter' => 'failed']))
            ->assertSee('FAILED bed frame')
            ->assertDontSee('READY plain walnut shelf')
            ->assertDontSee('BUSY coffee table');

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'history', 'filter' => 'ready']))
            ->assertSee('READY plain walnut shelf')
            ->assertSee('FAVORITE oak chair')
            ->assertDontSee('FAILED bed frame');
    }

    public function test_gallery_shows_only_finished_designs(): void
    {
        $me = $this->makeUser();
        $this->fixtures($me);

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'gallery']))
            ->assertOk()
            ->assertSee('READY plain walnut shelf')
            ->assertSee('FAVORITE oak chair')
            ->assertDontSee('FAILED bed frame')
            ->assertDontSee('BUSY coffee table');
    }

    public function test_favorites_shows_only_finished_and_favorited_designs(): void
    {
        $me = $this->makeUser();
        $this->fixtures($me);
        // A favorited row whose image is gone is not a finished design.
        $broken = $this->design($me, 'BROKEN favorited', [], false);
        AiDesignSuggestion::create(['design_id' => $broken->id, 'image_path' => 'images/ai-designs/phpunit-missing.png', 'is_favorited' => true, 'generated_at' => now()]);

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'favorites']))
            ->assertOk()
            ->assertSee('FAVORITE oak chair')
            ->assertDontSee('READY plain walnut shelf')
            ->assertDontSee('FAILED bed frame')
            ->assertDontSee('BROKEN favorited');
    }

    public function test_favorites_empty_state_explains_itself(): void
    {
        $me = $this->makeUser();
        $this->design($me, 'READY plain walnut shelf');

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'favorites']))
            ->assertOk()
            ->assertSee('No favorites yet')
            ->assertSee('Tap the bookmark');
    }

    public function test_tabs_never_show_another_customers_designs(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $this->design($other, 'SOMEONE ELSES wardrobe', [], true, true);

        foreach (['history', 'gallery', 'favorites'] as $tab) {
            $this->actingAs($me)->get(route('design.gallery', ['tab' => $tab]))->assertOk()->assertDontSee('SOMEONE ELSES wardrobe');
        }
    }

    public function test_ready_design_offers_order_until_it_is_sent_then_view_concept(): void
    {
        $me = $this->makeUser();
        $open = $this->design($me, 'OPEN design');
        $sent = $this->design($me, 'SENT design', ['status' => 'approved', 'pipeline_status' => 'submitted']);

        $html = $this->actingAs($me)->get(route('design.gallery', ['tab' => 'gallery']))->assertOk()->getContent();

        // Not sent: a form posting to the existing select route.
        $this->assertStringContainsString('action="' . route('design.select', $open->id) . '"', $html);
        $this->assertStringContainsString('Order this design', $html);
        $this->assertStringNotContainsString('action="' . route('design.select', $sent->id) . '"', $html);

        // Sent: a link to the existing concept page.
        $this->assertStringContainsString('href="' . route('design.show', $sent->id) . '" class="lp-btn lp-btn--primary md-act-primary">View concept', $html);
        $this->assertStringNotContainsString('href="' . route('design.show', $open->id) . '" class="lp-btn lp-btn--primary md-act-primary"', $html);
    }

    public function test_preview_payload_says_whether_the_design_is_ordered(): void
    {
        $me = $this->makeUser();
        $open = $this->design($me, 'OPEN design', ['style_preference' => 'Rustic']);
        $sent = $this->design($me, 'SENT design', ['status' => 'approved', 'pipeline_status' => 'submitted']);

        $a = $open->fresh('suggestions')->previewPayload($open->suggestions()->first());
        $b = $sent->fresh('suggestions')->previewPayload($sent->suggestions()->first());

        $this->assertFalse($a['ordered']);
        $this->assertSame('Rustic', $a['style']);
        $this->assertSame('OPEN design', $a['description']);
        $this->assertTrue($b['ordered']);
        $this->assertSame('No preference', $b['style']);
        $this->assertSame(route('design.show', $sent->id), $b['conceptUrl']);
    }

    public function test_ordering_still_goes_to_the_send_form_and_a_sent_design_cannot_be_ordered_again(): void
    {
        $me = $this->makeUser();
        $open = $this->design($me, 'OPEN design');
        $suggestion = $open->suggestions()->first();

        $this->actingAs($me)->post(route('design.select', $open->id), ['suggestion_id' => $suggestion->id])
            ->assertRedirect(route('design.sendForm', [$open->id, 'suggestion' => $suggestion->id]));

        $open->update(['status' => 'approved', 'pipeline_status' => 'submitted']);
        $this->actingAs($me)->post(route('design.select', $open->id), ['suggestion_id' => $suggestion->id])
            ->assertRedirect(route('design.show', $open->id));
    }

    public function test_try_again_links_to_the_form_with_the_original_prompt(): void
    {
        $me = $this->makeUser();
        $this->fixtures($me);

        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'history']))
            ->assertSee(e(route('design.index', ['prompt' => 'FAILED bed frame'])), false);

        $this->actingAs($me)->get(route('design.index', ['prompt' => 'FAILED bed frame']))
            ->assertOk()
            ->assertSee('FAILED bed frame');
    }

    public function test_favoriting_from_the_page_toggles_and_moves_the_design_between_tabs(): void
    {
        $me = $this->makeUser();
        $req = $this->design($me, 'READY plain walnut shelf');
        $suggestion = $req->suggestions()->first();

        $this->actingAs($me)->postJson(route('design.favorite', $suggestion->id))->assertOk()->assertJson(['favorited' => true]);
        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'favorites']))->assertSee('READY plain walnut shelf');

        $this->actingAs($me)->postJson(route('design.favorite', $suggestion->id))->assertOk()->assertJson(['favorited' => false]);
        $this->actingAs($me)->get(route('design.gallery', ['tab' => 'favorites']))->assertDontSee('READY plain walnut shelf');
    }
}
