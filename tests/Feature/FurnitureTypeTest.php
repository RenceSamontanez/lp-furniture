<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\User;
use App\Support\FurnitureTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 18 part A: the furniture type is required, comes from one list, is told to the AI
 * as a hard instruction, and is never worked out from the prompt text.
 */
class FurnitureTypeTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function submit(User $u, array $over = [])
    {
        return $this->asCustomer($u)->post(route('design.submit'), array_merge([
            'prompt' => 'A sturdy narra piece with a smooth natural finish and rounded edges',
            'furniture_type' => 'Chair', 'preferred_material' => 'Mahogany',
        ], $over));
    }

    private function prompt(CustomDesignRequest $r): string
    {
        $job = new GenerateAiDesign($r->id);
        $m = new \ReflectionMethod($job, 'buildPrompt');
        $m->setAccessible(true);

        return $m->invoke($job, $r->fresh());
    }

    /* ───────────── the list ───────────── */

    public function test_the_list_is_the_agreed_thirteen_in_one_place(): void
    {
        $this->assertSame(
            ['Chair', 'Table', 'Desk', 'Bed', 'Sofa', 'Cabinet', 'Shelf / Bookcase', 'Door', 'Wardrobe', 'TV stand', 'Bench', 'Stool', 'Other'],
            FurnitureTypes::LIST
        );
        $this->assertTrue(FurnitureTypes::isValid('Door'));
        $this->assertFalse(FurnitureTypes::isValid('door'));
        $this->assertFalse(FurnitureTypes::isValid(''));
        $this->assertFalse(FurnitureTypes::isValid(null));
        $this->assertSame('Not specified', FurnitureTypes::label(null));
        $this->assertSame('Not specified', FurnitureTypes::label('  '));
        $this->assertSame('Other: pergola', FurnitureTypes::label('Other', 'pergola'));
        $this->assertSame('Other', FurnitureTypes::label('Other', ''));
        $this->assertSame('a chair', FurnitureTypes::withArticle('Chair'));
        $this->assertSame('an elephant stool', FurnitureTypes::withArticle('Other', 'elephant stool'));
        $this->assertSame('a TV stand', FurnitureTypes::withArticle('TV stand'));
        $this->assertSame('a shelf or bookcase', FurnitureTypes::withArticle('Shelf / Bookcase'));
    }

    /* ───────────── required, validated on the server ───────────── */

    public function test_a_type_is_required_and_unknown_values_are_rejected(): void
    {
        $u = $this->flowCustomer();

        foreach ([null, '', '   ', 'Spaceship', 'chair', 'CHAIR', 'Chair; DROP TABLE'] as $bad) {
            $this->submit($u, ['furniture_type' => $bad])->assertSessionHasErrors('furniture_type');
        }

        $payload = ['prompt' => 'A sturdy narra piece with a smooth natural finish and rounded edges'];
        $this->asCustomer($u)->post(route('design.submit'), $payload)->assertSessionHasErrors('furniture_type');

        $this->assertSame(0, CustomDesignRequest::count());
        Queue::assertNothingPushed();
    }

    public function test_other_needs_a_short_text_and_others_ignore_it(): void
    {
        $u = $this->flowCustomer();

        $this->submit($u, ['furniture_type' => 'Other'])->assertSessionHasErrors('furniture_type_other');
        $this->submit($u, ['furniture_type' => 'Other', 'furniture_type_other' => '   '])->assertSessionHasErrors('furniture_type_other');
        $this->submit($u, ['furniture_type' => 'Other', 'furniture_type_other' => str_repeat('x', 61)])->assertSessionHasErrors('furniture_type_other');
        $this->assertSame(0, CustomDesignRequest::count());

        $this->submit($u, ['furniture_type' => 'Other', 'furniture_type_other' => 'pergola'])->assertSessionHasNoErrors();
        $r = CustomDesignRequest::latest('id')->first();
        $this->assertSame('Other', $r->furniture_type);
        $this->assertSame('pergola', $r->furniture_type_other);
        $this->assertSame('Other: pergola', $r->furniture_type_label);

        // text sent with another type is dropped
        $this->submit($u, ['furniture_type' => 'Desk', 'furniture_type_other' => 'ignored'])->assertSessionHasNoErrors();
        $this->assertNull(CustomDesignRequest::latest('id')->first()->furniture_type_other);
    }

    public function test_every_listed_type_is_accepted_and_stored(): void
    {
        foreach (FurnitureTypes::LIST as $type) {
            $u = $this->flowCustomer();   // one customer each: there is a daily cap on design attempts
            $extra = $type === 'Other' ? ['furniture_type_other' => 'altar'] : [];
            $this->submit($u, ['furniture_type' => $type] + $extra)->assertSessionHasNoErrors();
            $this->assertSame($type, CustomDesignRequest::latest('id')->first()->furniture_type);
        }
    }

    public function test_the_form_shows_the_type_tiles_and_something_else(): void
    {
        $u = $this->flowCustomer();
        $html = $this->asCustomer($u)->get(route('design.index'))->assertOk()->getContent();

        // the eight tiles from the studio, then the wide "Something else" button that stores "Other"
        foreach (FurnitureTypes::CHOICES as $value => $label) {
            $this->assertStringContainsString('<input type="radio" name="furniture_type" value="' . $value . '" data-label="' . $label . '" required', $html);
            $this->assertContains($value, FurnitureTypes::LIST, 'every tile is a value the server accepts');
        }
        $this->assertSame(['Sofa', 'Bed', 'Table', 'Chair', 'Cabinet', 'Door', 'Shelf / Bookcase', 'Desk'], array_keys(FurnitureTypes::CHOICES));
        $this->assertStringContainsString('<input type="radio" name="furniture_type" value="Other" data-label="Something else"', $html);
        $this->assertStringContainsString('Something else', $html);
        $this->assertStringContainsString('name="furniture_type_other"', $html);
        $this->assertStringNotContainsString('<select name="furniture_type"', $html);
    }

    /* ───────────── the AI prompt ───────────── */

    public function test_the_prompt_starts_with_the_hard_instruction_for_the_chosen_type(): void
    {
        $u = $this->flowCustomer();
        $this->submit($u, ['furniture_type' => 'TV stand']);
        $prompt = $this->prompt(CustomDesignRequest::latest('id')->first());

        $this->assertStringContainsString('The piece must be a TV stand, a single piece of furniture, and nothing else.', $prompt);
        $this->assertLessThan(strpos($prompt, 'sturdy narra'), strpos($prompt, 'must be a TV stand'), 'the type comes before the free text');
        $this->assertStringNotContainsString('Furniture type:', $prompt);
    }

    public function test_the_type_is_not_overwritten_by_what_the_prompt_text_says(): void
    {
        Queue::fake();
        $u = $this->flowCustomer();

        $this->submit($u, ['furniture_type' => 'Chair', 'prompt' => 'A tall wooden door with a carved narra frame and a brass handle, like a desk lid'])
            ->assertSessionHasNoErrors();

        $r = CustomDesignRequest::latest('id')->first();
        $this->assertSame('Chair', $r->furniture_type, 'stored exactly as chosen');
        $this->assertStringContainsString('door', $r->description);

        $prompt = $this->prompt($r);
        $this->assertStringContainsString('must be a chair', $prompt);
        $this->assertStringNotContainsString('must be a door', $prompt);
        $this->assertStringNotContainsString('must be a desk', $prompt);

        // and later reads, regeneration and the job never change it
        Queue::assertPushed(GenerateAiDesign::class);
        $this->asCustomer($u)->post(route('design.regenerate', $r->id));
        $this->assertSame('Chair', $r->fresh()->furniture_type);
        $this->assertNull($r->fresh()->furniture_type_other);
    }

    public function test_other_goes_into_the_prompt_as_the_customers_words(): void
    {
        $u = $this->flowCustomer();
        $this->submit($u, ['furniture_type' => 'Other', 'furniture_type_other' => 'elephant stool']);

        $this->assertStringContainsString('must be an elephant stool', $this->prompt(CustomDesignRequest::latest('id')->first()));
    }

    public function test_an_older_request_without_a_type_gets_no_invented_one(): void
    {
        $u = $this->flowCustomer();
        $r = CustomDesignRequest::create(['user_id' => $u->id, 'title' => 'x', 'description' => 'A wooden door with carvings', 'status' => 'pending']);

        $prompt = $this->prompt($r);
        $this->assertStringNotContainsString('must be', $prompt);
        $this->assertStringNotContainsString('Furniture type:', $prompt);
        $this->assertNull($r->fresh()->furniture_type);

        // an older free-text type keeps the older wording and is still not rewritten
        $r->forceFill(['furniture_type' => 'Dining Table'])->save();
        $this->assertStringContainsString('Furniture type: Dining Table.', $this->prompt($r));
    }

    /* ───────────── old requests, admin, quote, agreement ───────────── */

    private function submitted(?string $type): array
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $r->forceFill(['furniture_type' => $type])->save();
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();

        return [$admin, $customer, $r->fresh()];
    }

    public function test_old_requests_show_not_specified_safely_everywhere(): void
    {
        [$admin, $customer, $r] = $this->submitted(null);

        $this->assertSame('Not specified', $r->furniture_type_label);
        $this->assertSame('custom furniture', $r->furniture_type_noun);
        $this->asAdmin($admin)->get(route('admin.custom-orders.index'))->assertOk()->assertSee('Not specified');
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Not specified')->assertSee('Set the type');
        $this->asAdmin($admin)->get(route('admin.design-requests.index'))->assertOk()->assertSee('Not specified');
        $this->asAdmin($admin)->get(route('admin.design-requests.show', $r->id))->assertOk()->assertSee('Not specified');

        $quote = $this->quoteAndSend($admin, $r);
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('Not specified');

        // an old request's agreement records no type at all (nothing invented)
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $this->assertArrayNotHasKey('furniture_type', $this->agreementOf($r)->terms_snapshot['design']);
    }

    public function test_the_admin_can_set_a_missing_type_but_never_replace_the_customers(): void
    {
        [$admin, , $r] = $this->submitted(null);

        $this->asAdmin($admin)->post(route('admin.custom-orders.furniture-type', $r->id), ['furniture_type' => 'Spaceship'])->assertSessionHasErrors('furniture_type');
        $this->asAdmin($admin)->post(route('admin.custom-orders.furniture-type', $r->id), ['furniture_type' => 'Other'])->assertSessionHasErrors('furniture_type_other');
        $this->assertNull($r->fresh()->furniture_type);

        $this->asAdmin($admin)->post(route('admin.custom-orders.furniture-type', $r->id), ['furniture_type' => 'Door'])->assertSessionHasNoErrors();
        $this->assertSame('Door', $r->fresh()->furniture_type);

        $this->asAdmin($admin)->post(route('admin.custom-orders.furniture-type', $r->id), ['furniture_type' => 'Bed'])->assertSessionHasErrors('furniture_type');
        $this->assertSame('Door', $r->fresh()->furniture_type);
    }

    public function test_new_records_carry_the_type_to_the_quote_agreement_and_order(): void
    {
        [$admin, $customer, $r] = $this->submitted('Cabinet');

        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->assertSee('Cabinet')->assertDontSee('Set the type');

        $quote = $this->quoteAndSend($admin, $r);
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('Furniture type')->assertSee('Cabinet');

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);
        $this->assertSame('Cabinet', $a->terms_snapshot['design']['furniture_type']);
        $this->assertTrue($a->snapshotIsIntact());

        $this->asCustomer($customer)->post(route('custom.checkout.place', $a->id), ['payment_method' => 'stripe'])->assertRedirect();
        $order = \App\Models\Order::where('design_agreement_id', $a->id)->sole();
        $this->flushSession();
        $this->asCustomer($customer)->get(route('orders.show', $order->id))->assertOk()->assertSee('Furniture type: Cabinet');
        $this->asAdmin($admin)->get(route('admin.orders.show', $order->id))->assertOk()->assertSee('Furniture type: Cabinet');
    }

    /* ───────────── "Is this a chair?" ───────────── */

    public function test_the_review_page_asks_is_this_the_type_with_a_try_again_action(): void
    {
        $u = $this->flowCustomer();
        [$r] = $this->generatedRequest($u);
        $r->forceFill(['furniture_type' => 'Chair'])->save();

        $html = $this->asCustomer($u)->get(route('design.show', $r->id))->assertOk()
            ->assertSee('Is this a chair?')->assertSee('No, try again')
            ->assertSee('but it can get it wrong', false)->getContent();

        $this->assertStringNotContainsString('guarantee', strtolower($html));
        $this->assertMatchesRegularExpression('/action="' . preg_quote(route('design.regenerate', $r->id), '/') . '"[^>]*>\s*<input[^>]*>\s*<button type="submit" class="ai-action-btn ai-action-secondary cd-type-check-btn">No, try again/', $html);

        $r->forceFill(['furniture_type' => 'Other', 'furniture_type_other' => 'elephant stool'])->save();
        $this->asCustomer($u)->get(route('design.show', $r->id))->assertSee('Is this an elephant stool?');

        // the question is for pieces that have not been sent yet, and not for older requests without a type
        $r->forceFill(['furniture_type' => null, 'furniture_type_other' => null])->save();
        $this->asCustomer($u)->get(route('design.show', $r->id))->assertDontSee('No, try again');
    }

    public function test_no_try_again_after_the_request_is_sent(): void
    {
        [$admin, $customer, $r] = $this->submitted('Chair');
        $this->flushSession();
        $this->asCustomer($customer)->get(route('design.show', $r->id))->assertOk()->assertDontSee('No, try again');
    }
}
