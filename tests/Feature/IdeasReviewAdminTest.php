<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\DesignIdea;
use App\Models\ExtraFurnitureType;
use App\Models\UnansweredIdea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/** Customize studio part 2: Ideas to review, the quick ideas editor, and the request pages (style, wood, call badge, private photo). */
class IdeasReviewAdminTest extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    private function idea(string $kind, string $text, array $over = []): UnansweredIdea
    {
        $row = UnansweredIdea::record($kind, $text);
        if ($over) {
            $row->forceFill($over)->save();
        }

        return $row->fresh();
    }

    /** The embedded page data of the Ideas page (rows, routes, first tab...). */
    private function pageData(string $html): array
    {
        preg_match('#<script type="application/json" id="ir-data">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? '{}', true);
    }

    private function submitCustomize(User $u, array $over = [])
    {
        Queue::fake();

        return $this->asCustomer($u)->post(route('design.submit'), array_merge([
            'prompt' => 'A sturdy wooden table with a smooth natural finish and rounded edges',
            'furniture_type' => 'Table', 'preferred_material' => 'Mahogany',
        ], $over));
    }

    /** A submitted custom order with a reference photo on the private disk. */
    private function requestWithPhoto(array $over = []): array
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $path = 'design-references/' . $customer->id . '/ref.png';
        Storage::disk('local')->put($path, base64_decode(self::PNG));
        $r->forceFill(array_merge(['reference_image' => $path, 'style_preference' => 'Rustic'], $over))->save();
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();

        return [$admin, $customer, $r->fresh(), $path];
    }

    /* ───────────── who may see these pages ───────────── */

    public function test_only_an_admin_reaches_the_pages_everyone_else_is_sent_to_the_admin_login(): void
    {
        $row = $this->idea('furniture_type', 'Pergola');
        $type = ExtraFurnitureType::first();
        $idea = DesignIdea::first();
        [, $customer, $r] = $this->requestWithPhoto();

        $gets = [route('admin.ideas.index'), route('admin.quick-ideas.index'), route('admin.reference-photo', $r->id), route('admin.reference-photo', ['id' => $r->id, 'download' => 1])];
        $posts = [
            [route('admin.ideas.answer', $row->id), ['answer' => 'hello there']], [route('admin.ideas.option', $row->id), ['wording' => 'Pergola']],
            [route('admin.ideas.dismiss', $row->id), []], [route('admin.ideas.undo', $row->id), []], [route('admin.ideas.delete', $row->id), []],
            [route('admin.quick-ideas.store'), ['scope' => 'general', 'text' => 'new thing']], [route('admin.quick-ideas.update', $idea->id), ['text' => 'edited']],
            [route('admin.quick-ideas.toggle', $idea->id), []], [route('admin.quick-ideas.move', $idea->id), ['dir' => 'down']], [route('admin.quick-ideas.delete', $idea->id), []],
            [route('admin.extra-types.store'), ['name' => 'Pergola']], [route('admin.extra-types.update', $type->id), ['name' => 'Edited']],
            [route('admin.extra-types.toggle', $type->id), []], [route('admin.extra-types.move', $type->id), ['dir' => 'down']], [route('admin.extra-types.delete', $type->id), []],
        ];

        foreach (['guest' => $this, 'customer' => $this->asCustomer($customer)] as $who => $client) {
            foreach ($gets as $url) {
                $res = $client->get($url);
                $res->assertRedirect(route('admin.login'));
                $this->assertStringNotContainsString('image/png', (string) $res->headers->get('Content-Type'), "$who $url");
            }
            foreach ($posts as [$url, $data]) {
                $client->post($url, $data)->assertRedirect(route('admin.login'));
            }
        }

        $this->assertSame('new', $row->fresh()->status);
        $this->assertNotNull(ExtraFurnitureType::find($type->id));
        $this->assertNotNull(DesignIdea::find($idea->id));
    }

    /* ───────────── Ideas to review: the list ───────────── */

    public function test_the_list_shows_the_columns_and_the_menu_badge_counts_new_items(): void
    {
        $admin = $this->flowAdmin();
        $a = $this->idea('furniture_type', 'Pergola');
        UnansweredIdea::record('furniture_type', 'Pergola');
        $this->idea('wood', 'Teak');
        $this->idea('idea', 'secret drawer box', ['status' => 'answered', 'answer' => 'Yes.']);

        $html = $this->asAdmin($admin)->get(route('admin.ideas.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Ideas to Review', $html);
        $this->assertMatchesRegularExpression('/Ideas to Review<\/span>\s*<span class="mnu-pill admin-nav-badge">2<\/span>/', $html, 'two new rows');
        $this->assertStringContainsString('Pergola', $html);
        $this->assertStringContainsString('2 times', $html);
        $this->assertStringContainsString('Furniture Type', $html);
        $this->assertStringContainsString('data-status="answered"', $html, 'every idea is on the page; the tabs show and hide them');
        $this->assertSame('new', $this->pageData($html)['initial']['tab'], 'Needs Review is the first tab');

        $this->assertSame('answered', $this->pageData($this->asAdmin($admin)->get(route('admin.ideas.index', ['status' => 'answered']))->assertOk()->getContent())['initial']['tab']);
        $this->assertSame('all', $this->pageData($this->asAdmin($admin)->get(route('admin.ideas.index', ['status' => 'all']))->assertOk()->getContent())['initial']['tab']);
    }

    public function test_filters_search_and_sorting(): void
    {
        $admin = $this->flowAdmin();
        $this->idea('furniture_type', 'Pergola', ['times_asked' => 3, 'first_seen_at' => now()->subDays(5)]);
        $this->idea('wood', 'Teak', ['times_asked' => 9, 'first_seen_at' => now()->subDays(9)]);
        $this->idea('idea', 'jewelry compartment', ['times_asked' => 1, 'first_seen_at' => now()->subDay()]);

        $order = fn ($html) => array_map(fn ($t) => $t, array_values(array_filter(['Pergola', 'Teak', 'jewelry compartment'], fn ($n) => str_contains($html, $n))));
        $pos = fn ($html, $n) => strpos($html, $n);

        $most = $this->asAdmin($admin)->get(route('admin.ideas.index'))->getContent();
        $this->assertTrue($pos($most, 'Teak') < $pos($most, 'Pergola') && $pos($most, 'Pergola') < $pos($most, 'jewelry compartment'), 'most asked first');

        // search, type and sort are live in the browser; old links still set their starting values
        $this->assertSame('wood', $this->pageData($this->asAdmin($admin)->get(route('admin.ideas.index', ['kind' => 'wood']))->getContent())['initial']['kind']);
        $this->assertSame('perg', $this->pageData($this->asAdmin($admin)->get(route('admin.ideas.index', ['q' => 'perg']))->getContent())['initial']['q']);
        $this->asAdmin($admin)->get(route('admin.ideas.index', ['sort' => 'newest']))->assertSee('<option value="recent" selected>', false);
    }

    public function test_empty_state_and_the_whole_list_on_one_page(): void
    {
        $admin = $this->flowAdmin();
        $this->asAdmin($admin)->get(route('admin.ideas.index'))->assertOk()->assertSee('No customer has typed anything we had no answer for yet');

        foreach (range(1, 20) as $i) {
            $this->idea('furniture_type', 'Thing number ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $html = $this->asAdmin($admin)->get(route('admin.ideas.index'))->getContent();
        $this->assertSame(20, substr_count($html, 'class="ir2-topic"'), 'no paging: the tabs and search work on the whole list');
        $this->assertStringNotContainsString('page=2', $html);
    }

    public function test_the_same_idea_typed_with_other_case_or_spaces_counts_once(): void
    {
        $this->idea('furniture_type', 'Pergola');
        UnansweredIdea::record('furniture_type', '  PERGOLA ');
        UnansweredIdea::record('furniture_type', "perg\tola");   // a different word: stays separate
        UnansweredIdea::record('furniture_type', 'pergola');

        $row = UnansweredIdea::where('text_key', 'pergola')->get();
        $this->assertCount(1, $row);
        $this->assertSame(3, $row->first()->times_asked);
        $this->assertSame(2, UnansweredIdea::count());

        // typed through the real form too
        $u = $this->flowCustomer();
        $this->submitCustomize($u, ['furniture_type' => 'Other', 'furniture_type_other' => ' Pergola ']);
        $this->assertSame(4, UnansweredIdea::where('text_key', 'pergola')->value('times_asked'));
    }

    public function test_all_output_is_escaped(): void
    {
        $admin = $this->flowAdmin();
        $this->idea('idea', '<script>alert(1)</script> nice', ['answer' => '<img src=x onerror=alert(2)>', 'status' => 'answered']);
        $html = $this->asAdmin($admin)->get(route('admin.ideas.index', ['status' => 'all']))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
    }

    /* ───────────── write answer ───────────── */

    public function test_an_answer_is_saved_and_shown_to_the_next_customer(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('furniture_type', 'Pergola');

        $this->asAdmin($admin)->post(route('admin.ideas.answer', $row->id), ['answer' => '  We build pergolas in Acacia.  Our team confirms size by phone. '])->assertSessionHasNoErrors();
        $row = $row->fresh();
        $this->assertSame('answered', $row->status);
        $this->assertSame('We build pergolas in Acacia. Our team confirms size by phone.', preg_replace('/\s+/', ' ', $row->answer));
        $this->assertNotNull($row->answered_at);
        $this->assertSame($admin->id, (int) $row->handled_by);

        $u = $this->flowCustomer();
        $this->asCustomer($u)->getJson(route('design.ideaAnswer', ['kind' => 'furniture_type', 'text' => 'pergola']))
            ->assertJson(['known' => true])->assertJsonPath('answer', fn ($a) => str_contains($a, 'pergolas in Acacia'));

        // the customer's own page still saves what they type
        $this->submitCustomize($u, ['furniture_type' => 'Other', 'furniture_type_other' => 'Pergola']);
        $this->assertSame(2, $row->fresh()->times_asked);
        $this->assertSame('answered', $row->fresh()->status);
    }

    public function test_an_answer_is_validated_and_cleaned(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('idea', 'glass top');
        $post = fn ($answer) => $this->asAdmin($admin)->post(route('admin.ideas.answer', $row->id), ['answer' => $answer]);

        $post('')->assertSessionHasErrors('answer');
        $post('ab')->assertSessionHasErrors('answer');
        $post(str_repeat('x', 501))->assertSessionHasErrors('answer');
        $post('See https://shop.example for details')->assertSessionHasErrors('answer');
        $post('Mail us at team@example.com')->assertSessionHasErrors('answer');
        $this->assertSame('new', $row->fresh()->status);

        $post('<b>Glass</b> tops are possible<script>x</script>')->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('<', $row->fresh()->answer);
    }

    public function test_an_answered_idea_phrase_is_found_inside_a_longer_description(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('idea', 'glass top');
        $this->asAdmin($admin)->post(route('admin.ideas.answer', $row->id), ['answer' => 'Glass tops are possible.']);
        $this->asCustomer($this->flowCustomer())->getJson(route('design.ideaAnswer', ['kind' => 'idea', 'text' => 'A coffee table with a glass top and oak legs']))
            ->assertJson(['known' => true, 'answer' => 'Glass tops are possible.']);
    }

    /* ───────────── add as option ───────────── */

    public function test_an_idea_phrase_is_added_to_the_quick_ideas_and_shows_on_the_customer_page(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('idea', 'glass top');

        $this->asAdmin($admin)->post(route('admin.ideas.option', $row->id), ['scope' => 'type', 'key' => 'Table', 'wording' => ' glass  top '])->assertSessionHasNoErrors();
        $this->assertSame('added_as_option', $row->fresh()->status);
        $new = DesignIdea::where('text', 'glass top')->firstOrFail();
        $this->assertSame(['type', 'Table'], [$new->scope, $new->idea_key]);
        $this->assertTrue($new->is_active);

        $html = $this->asCustomer($this->flowCustomer())->get(route('design.index'))->getContent();
        $this->assertStringContainsString('"glass top"', $html, 'the page data carries it');

        // again for the same place: refused
        $row2 = $this->idea('idea', 'glass tabletop');
        $this->asAdmin($admin)->post(route('admin.ideas.option', $row2->id), ['scope' => 'type', 'key' => 'Table', 'wording' => 'GLASS TOP'])->assertSessionHas('error');
        $this->assertSame(1, DesignIdea::where('text', 'like', 'glass top')->count());
        $this->assertSame('new', $row2->fresh()->status);

        // style scope
        $this->asAdmin($admin)->post(route('admin.ideas.option', $row2->id), ['scope' => 'style', 'key' => 'Modern', 'wording' => 'glass top'])->assertSessionHasNoErrors();
        $this->assertTrue(DesignIdea::where('scope', 'style')->where('idea_key', 'Modern')->where('text', 'glass top')->exists());
    }

    public function test_idea_option_input_is_validated(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('idea', 'glass top');
        $post = fn (array $d) => $this->asAdmin($admin)->post(route('admin.ideas.option', $row->id), $d);

        $post(['scope' => 'type', 'key' => 'Table', 'wording' => ''])->assertSessionHasErrors('wording');
        $post(['scope' => 'type', 'key' => 'Table', 'wording' => str_repeat('a', 81)])->assertSessionHasErrors('wording');
        $post(['scope' => 'type', 'key' => 'Table', 'wording' => 'see shop.com'])->assertSessionHasErrors('wording');
        $post(['scope' => 'nope', 'key' => 'Table', 'wording' => 'glass top'])->assertSessionHasErrors('scope');
        $post(['scope' => 'type', 'key' => 'Spaceship', 'wording' => 'glass top'])->assertSessionHas('error');
        $post(['scope' => 'type', 'key' => 'Modern', 'wording' => 'glass top'])->assertSessionHas('error');   // a style name is not a type
        $post(['scope' => 'type', 'wording' => 'glass top'])->assertSessionHasErrors('key');
        $this->assertSame(0, DesignIdea::where('text', 'glass top')->count());
        $this->assertSame('new', $row->fresh()->status);
    }

    public function test_a_furniture_type_is_added_as_a_something_else_quick_pick(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('furniture_type', 'Pergola');

        $this->asAdmin($admin)->post(route('admin.ideas.option', $row->id), ['wording' => ' Pergola '])->assertSessionHasNoErrors();
        $this->assertSame('added_as_option', $row->fresh()->status);
        $type = ExtraFurnitureType::where('name', 'Pergola')->firstOrFail();
        $this->assertTrue($type->is_active);
        $this->assertSame($row->id, (int) $type->source_idea_id);

        $page = $this->asCustomer($this->flowCustomer())->get(route('design.index'))->getContent();
        $this->assertMatchesRegularExpression('/data-example="Pergola"/', $page);

        // the customer is told we know it, with no answer needed
        $this->asCustomer($this->flowCustomer())->getJson(route('design.ideaAnswer', ['kind' => 'furniture_type', 'text' => 'Pergola']))->assertJson(['known' => true]);

        // a built-in tile or an existing pick cannot be added again
        $dupe = $this->idea('furniture_type', 'sofa');
        $this->asAdmin($admin)->post(route('admin.ideas.option', $dupe->id), ['wording' => 'Sofa'])->assertSessionHas('error');
        $this->asAdmin($admin)->post(route('admin.ideas.option', $dupe->id), ['wording' => 'gazebo'])->assertSessionHas('error');
        $this->asAdmin($admin)->post(route('admin.ideas.option', $dupe->id), ['wording' => ''])->assertSessionHasErrors('wording');
        $this->asAdmin($admin)->post(route('admin.ideas.option', $dupe->id), ['wording' => 'mail me@x.com'])->assertSessionHasErrors('wording');
        $this->assertSame(0, ExtraFurnitureType::where('name_key', 'sofa')->count());
    }

    public function test_a_wood_can_never_be_added_as_an_option(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('wood', 'Teak');
        $before = [DesignIdea::count(), ExtraFurnitureType::count()];

        foreach ([['wording' => 'Teak'], ['scope' => 'type', 'key' => 'Table', 'wording' => 'Teak']] as $data) {
            $this->asAdmin($admin)->post(route('admin.ideas.option', $row->id), $data)->assertSessionHas('error');
        }
        $this->assertSame($before, [DesignIdea::count(), ExtraFurnitureType::count()]);
        $this->assertSame('new', $row->fresh()->status);

        $html = $this->asAdmin($admin)->get(route('admin.ideas.index'))->getContent();
        $data = collect($this->pageData($html)['rows'])->firstWhere('id', $row->id);
        $this->assertFalse($data['canOption'], 'no Add as Option for a wood');
        $this->assertStringContainsString('data-view="' . $row->id . '"', $html, 'View (and so Send Reply) is still there');

        // the wood list itself did not move
        $this->assertSame(['Gmelina', 'Mahogany', 'Acacia', 'Special request'], \App\Support\WoodChoices::LIST);
    }

    /* ───────────── dismiss, undo, delete ───────────── */

    public function test_dismiss_and_undo(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('furniture_type', 'Pergola');
        $this->asAdmin($admin)->post(route('admin.ideas.answer', $row->id), ['answer' => 'We build these.']);

        $this->asAdmin($admin)->post(route('admin.ideas.dismiss', $row->id))->assertSessionHas('undo_idea', $row->id);
        $this->assertSame('dismissed', $row->fresh()->status);
        $this->assertSame('answered', $row->fresh()->previous_status);

        // a dismissed idea shows no answer to customers
        $this->asCustomer($this->flowCustomer())->getJson(route('design.ideaAnswer', ['kind' => 'furniture_type', 'text' => 'Pergola']))->assertJson(['known' => false]);
        // and cannot be added as an option until restored
        $this->asAdmin($admin)->post(route('admin.ideas.option', $row->id), ['wording' => 'Pergola'])->assertSessionHas('error');
        $page = $this->asAdmin($admin)->withSession(['undo_idea' => $row->id])->get(route('admin.ideas.index', ['status' => 'dismissed']))->assertOk();
        $page->assertSee('Undo');

        $this->asAdmin($admin)->post(route('admin.ideas.undo', $row->id))->assertSessionHasNoErrors();
        $this->assertSame('answered', $row->fresh()->status);
        $this->assertNull($row->fresh()->previous_status);
        $this->asCustomer($this->flowCustomer())->getJson(route('design.ideaAnswer', ['kind' => 'furniture_type', 'text' => 'Pergola']))->assertJson(['known' => true]);

        // undo on something not dismissed changes nothing; dismissing twice keeps the first previous status
        $this->asAdmin($admin)->post(route('admin.ideas.undo', $row->id));
        $this->assertSame('answered', $row->fresh()->status);
        $new = $this->idea('wood', 'Teak');
        $this->asAdmin($admin)->post(route('admin.ideas.dismiss', $new->id));
        $this->asAdmin($admin)->post(route('admin.ideas.dismiss', $new->id));
        $this->assertSame('new', $new->fresh()->previous_status);
        $this->asAdmin($admin)->post(route('admin.ideas.undo', $new->id));
        $this->assertSame('new', $new->fresh()->status);
    }

    public function test_dismiss_and_delete_sit_behind_a_confirmation_modal_and_delete_removes_the_row(): void
    {
        $admin = $this->flowAdmin();
        $row = $this->idea('furniture_type', 'Pergola');
        $html = $this->asAdmin($admin)->get(route('admin.ideas.index'))->getContent();

        // Delete sits behind the one shared confirmation window; no row carries a direct delete button
        $this->assertStringContainsString('<div class="lp-modal lp-modal--mid" id="ir-delete"', $html);
        $this->assertStringContainsString('id="ir-delete-form"', $html);
        $this->assertStringNotContainsString('<form method="POST" action="' . route('admin.ideas.delete', $row->id) . '"', $html, 'no direct submit button');
        $this->assertSame(route('admin.ideas.delete', ['id' => '__ID__']), $this->pageData($html)['routes']['delete']);

        $this->asAdmin($admin)->post(route('admin.ideas.delete', $row->id))->assertSessionHasNoErrors();
        $this->assertNull(UnansweredIdea::find($row->id));
        $this->asAdmin($admin)->post(route('admin.ideas.delete', 99999))->assertNotFound();
    }

    /* ───────────── the heuristic switch ───────────── */

    public function test_the_config_flag_turns_phrase_capture_off(): void
    {
        $this->assertTrue(config('customize.capture_idea_phrases'), 'on by default');
        $prompt = 'A sturdy wooden table, hidden magic compass, glossy finish';

        $this->submitCustomize($this->flowCustomer(), ['prompt' => $prompt])->assertSessionHasNoErrors();
        $this->assertSame(1, UnansweredIdea::where('kind', 'idea')->count());

        UnansweredIdea::query()->delete();
        config(['customize.capture_idea_phrases' => false]);
        $this->submitCustomize($this->flowCustomer(), [
            'prompt' => $prompt, 'furniture_type' => 'Other', 'furniture_type_other' => 'Pergola',
            'preferred_material' => 'Special request', 'wood_special_details' => 'Teak', 'wood_supply' => 'shop',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, UnansweredIdea::where('kind', 'idea')->count(), 'phrases are not captured');
        $this->assertSame(1, UnansweredIdea::where('kind', 'furniture_type')->count(), 'Something else is still captured');
        $this->assertSame(1, UnansweredIdea::where('kind', 'wood')->count(), 'Other wood is still captured');
    }

    /* ───────────── quick ideas editor ───────────── */

    public function test_the_editor_lists_every_group_and_its_validation_works(): void
    {
        $admin = $this->flowAdmin();
        $html = $this->asAdmin($admin)->get(route('admin.quick-ideas.index'))->assertOk()->getContent();
        foreach (['Sofa', 'Shelf', 'Desk', 'Modern', 'Contemporary', 'Anything else', 'soft rounded cushions', 'visible wood grain', 'Staircase railing'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }

        $add = fn (array $d) => $this->asAdmin($admin)->post(route('admin.quick-ideas.store'), $d);
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => ''])->assertSessionHasErrors('text');
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => 'x'])->assertSessionHasErrors('text');
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => str_repeat('a', 81)])->assertSessionHasErrors('text');
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => 'see www.shop.com'])->assertSessionHasErrors('text');
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => 'ask me@mail.com'])->assertSessionHasErrors('text');
        $add(['scope' => 'type', 'idea_key' => 'Sofa', 'text' => 'SOFT ROUNDED CUSHIONS'])->assertSessionHas('error');
        $add(['scope' => 'type', 'idea_key' => 'Armchair', 'text' => 'wide arms'])->assertSessionHasErrors('idea_key');
        $add(['scope' => 'style', 'idea_key' => 'Sofa', 'text' => 'wide arms'])->assertSessionHasErrors('idea_key');
        $add(['scope' => 'weird', 'text' => 'wide arms'])->assertSessionHasErrors('scope');
        $this->assertSame(0, DesignIdea::where('text', 'wide arms')->count());
    }

    public function test_quick_ideas_crud_and_the_customer_page_reads_it(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        $studio = fn () => \App\Models\DesignIdea::forStudio();

        // create (tags stripped, spaces tidied)
        $this->asAdmin($admin)->post(route('admin.quick-ideas.store'), ['scope' => 'type', 'idea_key' => 'Sofa', 'text' => '  <b>reclining</b>   ends '])->assertSessionHasNoErrors();
        $idea = DesignIdea::where('text', 'reclining ends')->firstOrFail();
        $this->assertSame(['type', 'Sofa', true], [$idea->scope, $idea->idea_key, $idea->is_active]);
        $this->assertContains('reclining ends', $studio()['type']['Sofa']);
        $this->assertStringContainsString('reclining ends', $this->asCustomer($customer)->get(route('design.index'))->getContent());

        // general (no key) and style
        $this->asAdmin($admin)->post(route('admin.quick-ideas.store'), ['scope' => 'general', 'text' => 'with a shelf below'])->assertSessionHasNoErrors();
        $this->assertContains('with a shelf below', $studio()['general']);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.store'), ['scope' => 'style', 'idea_key' => 'Rustic', 'text' => 'rough sawn top'])->assertSessionHasNoErrors();
        $this->assertContains('rough sawn top', $studio()['style']['Rustic']);

        // edit
        $this->asAdmin($admin)->post(route('admin.quick-ideas.update', $idea->id), ['text' => 'reclining end seats'])->assertSessionHasNoErrors();
        $this->assertSame('reclining end seats', $idea->fresh()->text);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.update', $idea->id), ['text' => 'a'])->assertSessionHasErrors('text');
        $this->asAdmin($admin)->post(route('admin.quick-ideas.update', $idea->id), ['text' => 'see a@b.com'])->assertSessionHasErrors('text');
        $this->asAdmin($admin)->post(route('admin.quick-ideas.update', $idea->id), ['text' => 'solid wood legs'])->assertSessionHas('error');   // already in Sofa
        $this->assertSame('reclining end seats', $idea->fresh()->text);

        // turn off: gone for customers, still in the editor
        $this->asAdmin($admin)->post(route('admin.quick-ideas.toggle', $idea->id));
        $this->assertFalse($idea->fresh()->is_active);
        $this->assertNotContains('reclining end seats', $studio()['type']['Sofa']);
        $this->assertStringContainsString('reclining end seats', $this->asAdmin($admin)->get(route('admin.quick-ideas.index'))->getContent());
        $this->asAdmin($admin)->post(route('admin.quick-ideas.toggle', $idea->id));
        $this->assertTrue($idea->fresh()->is_active);

        // reorder: move the last one to the top
        $this->assertSame(['soft rounded cushions', 'solid wood legs', 'seats three people', 'reclining end seats'], $studio()['type']['Sofa']);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.move', $idea->id), ['dir' => 'up']);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.move', $idea->id), ['dir' => 'up']);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.move', $idea->id), ['dir' => 'up']);
        $this->assertSame(['reclining end seats', 'soft rounded cushions', 'solid wood legs', 'seats three people'], $studio()['type']['Sofa']);
        $this->asAdmin($admin)->post(route('admin.quick-ideas.move', $idea->id), ['dir' => 'up']);   // already first: no change, no error
        $this->asAdmin($admin)->post(route('admin.quick-ideas.move', $idea->id), ['dir' => 'sideways'])->assertSessionHasErrors('dir');
        $this->assertSame('reclining end seats', $studio()['type']['Sofa'][0]);

        // delete (confirmation modal on the page)
        $this->assertStringContainsString('data-lp-modal-open="qi-del-' . $idea->id . '"', $this->asAdmin($admin)->get(route('admin.quick-ideas.index'))->getContent());
        $this->asAdmin($admin)->post(route('admin.quick-ideas.delete', $idea->id));
        $this->assertNull(DesignIdea::find($idea->id));
        $this->assertNotContains('reclining end seats', $studio()['type']['Sofa']);
    }

    public function test_extra_furniture_types_crud_and_the_something_else_chips_read_it(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        $chips = fn () => ExtraFurnitureType::chips();

        $this->assertSame(['Staircase railing', 'Altar', 'TV wall panel', 'Headboard', 'Kitchen counter', 'Gazebo'], $chips(), 'seeded from the old examples');

        $post = fn (string $route, array $d = [], $id = null) => $this->asAdmin($admin)->post($id ? route($route, $id) : route($route), $d);
        $post('admin.extra-types.store', ['name' => 'Pergola'])->assertSessionHasNoErrors();
        $t = ExtraFurnitureType::where('name', 'Pergola')->firstOrFail();
        $this->assertSame('Pergola', last($chips()));
        $this->assertStringContainsString('data-example="Pergola"', $this->asCustomer($customer)->get(route('design.index'))->getContent());

        $post('admin.extra-types.store', ['name' => 'pergola'])->assertSessionHas('error');
        $post('admin.extra-types.store', ['name' => 'Table'])->assertSessionHas('error');
        $post('admin.extra-types.store', ['name' => 'Shelf'])->assertSessionHas('error');
        $post('admin.extra-types.store', ['name' => 'a'])->assertSessionHasErrors('name');
        $post('admin.extra-types.store', ['name' => 'visit shop.com'])->assertSessionHasErrors('name');
        $post('admin.extra-types.store', ['name' => str_repeat('b', 61)])->assertSessionHasErrors('name');

        $post('admin.extra-types.update', ['name' => 'Pergola set'], $t->id)->assertSessionHasNoErrors();
        $this->assertSame('pergola set', $t->fresh()->name_key);
        $post('admin.extra-types.update', ['name' => 'Altar'], $t->id)->assertSessionHas('error');

        $post('admin.extra-types.toggle', [], $t->id);
        $this->assertNotContains('Pergola set', $chips());
        $post('admin.extra-types.toggle', [], $t->id);
        $post('admin.extra-types.move', ['dir' => 'up'], $t->id);
        $this->assertSame('Pergola set', $chips()[count($chips()) - 2] ?? $chips()[0]);

        $post('admin.extra-types.delete', [], $t->id);
        $this->assertNull(ExtraFurnitureType::find($t->id));
        $this->assertNotContains('Pergola set', $chips());

        // the customer page shows at most the first 8, so the layout never grows
        foreach (range(1, 12) as $i) { $post('admin.extra-types.store', ['name' => "Piece number $i"]); }
        $this->assertCount(ExtraFurnitureType::CHIPS_SHOWN, $chips());
        $this->assertSame(ExtraFurnitureType::CHIPS_SHOWN, substr_count($this->asCustomer($customer)->get(route('design.index'))->getContent(), 'data-example="'));
    }

    /* ───────────── request pages: style, wood, call badge, photo ───────────── */

    public function test_the_request_page_shows_style_something_else_and_the_call_badge_for_customer_supplied_wood(): void
    {
        [$admin, $customer, $r] = $this->requestWithPhoto([
            'furniture_type' => 'Other', 'furniture_type_other' => 'Staircase railing',
            'preferred_material' => 'Special request', 'wood_special_details' => 'Old narra beams', 'wood_supply' => 'customer',
        ]);

        $page = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk();
        $page->assertSee('Rustic')->assertSee('Other: Staircase railing')->assertSee('Old narra beams')
            ->assertSee('Customer chose: I will provide the wood')
            ->assertSee('Call customer about wood quantity')->assertSee('09171234567')->assertSee('href="tel:09171234567"', false);

        $list = $this->asAdmin($admin)->get(route('admin.custom-orders.index'))->assertOk();
        $list->assertSee('Rustic style')->assertSee('Call About Wood Quantity')->assertSee('Reference Photo');
    }

    public function test_no_call_badge_when_the_shop_buys_the_wood_or_a_fixed_wood_is_chosen(): void
    {
        [$admin, , $shop] = $this->requestWithPhoto(['preferred_material' => 'Special request', 'wood_special_details' => 'Teak', 'wood_supply' => 'shop']);
        $this->asAdmin($admin)->get(route('admin.custom-orders.show', $shop->id))->assertOk()
            ->assertSee('Customer chose: LP Furniture buys the wood')->assertDontSee('Call customer about wood quantity');

        [$admin2, , $fixed] = $this->requestWithPhoto(['preferred_material' => 'Acacia']);
        $this->asAdmin($admin2)->get(route('admin.custom-orders.show', $fixed->id))->assertOk()
            ->assertSee('Acacia')->assertDontSee('Call customer about wood quantity')->assertDontSee('Customer chose:');
    }

    public function test_the_photo_has_a_thumbnail_a_larger_view_and_a_download_link_all_through_the_admin_route(): void
    {
        [$admin, , $r, $path] = $this->requestWithPhoto();
        $html = $this->asAdmin($admin)->get(route('admin.custom-orders.show', $r->id))->assertOk()->getContent();

        $url = route('admin.reference-photo', $r->id);
        $this->assertStringContainsString('data-lp-modal-open="ref-photo-' . $r->id . '"', $html);
        $this->assertStringContainsString('<img src="' . $url . '" alt="' . "Customer's reference photo" . '"', $html);
        $this->assertStringContainsString('href="' . route('admin.reference-photo', ['id' => $r->id, 'download' => 1]) . '"', $html);
        $this->assertStringContainsString('id="ref-photo-' . $r->id . '"', $html);
        $this->assertStringNotContainsString('storage/design-references', $html, 'no public URL anywhere');
        $this->assertStringNotContainsString($path, $html);
    }

    public function test_the_photo_route_serves_the_bytes_to_an_admin_only_and_the_file_is_private(): void
    {
        [$admin, $customer, $r, $path] = $this->requestWithPhoto();

        $res = $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertOk();
        $res->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('inline', $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));

        $down = $this->asAdmin($admin)->get(route('admin.reference-photo', ['id' => $r->id, 'download' => 1]))->assertOk();
        $this->assertStringContainsString('attachment; filename="reference-photo-request-' . $r->id . '.png"', $down->headers->get('Content-Disposition'));

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertFalse(Storage::disk('public')->exists($path), 'not in the public disk, so no /storage URL can reach it');
        $this->asAdmin($admin)->get(route('admin.reference-photo', 99999))->assertNotFound();

        // a request with no photo, or a photo that is not a JPEG/PNG, is a 404 and never served
        $r->forceFill(['reference_image' => null])->save();
        $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertNotFound();
        Storage::disk('local')->put('design-references/1/evil.png', '<?php echo 1; ?>');
        $r->forceFill(['reference_image' => 'design-references/1/evil.png'])->save();
        $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertNotFound();
        $r->forceFill(['reference_image' => '../../.env'])->save();
        $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertNotFound();
    }

    public function test_new_uploads_are_private_and_the_customer_sees_only_their_own_photo(): void
    {
        $u = $this->flowCustomer();
        $upload = \Illuminate\Http\UploadedFile::fake()->createWithContent('ref.png', base64_decode(self::PNG));
        $this->submitCustomize($u, ['reference_image' => $upload])->assertSessionHasNoErrors();
        $r = CustomDesignRequest::latest('id')->firstOrFail();

        $this->assertTrue(Storage::disk('local')->exists($r->reference_image));
        $this->assertFalse(Storage::disk('public')->exists($r->reference_image));

        $this->asCustomer($u)->get(route('design.reference', $r->id))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->asCustomer($this->flowCustomer('Ben'))->get(route('design.reference', $r->id))->assertNotFound();
    }

    public function test_a_photo_uploaded_before_the_change_is_still_served_to_admins_and_the_command_moves_it(): void
    {
        [$admin, , $r, $path] = $this->requestWithPhoto();
        $legacy = 'design-references/7/legacy.png';
        Storage::disk('public')->put($legacy, base64_decode(self::PNG));
        $r->forceFill(['reference_image' => $legacy])->save();

        $this->assertTrue(\App\Support\ReferencePhoto::isPublicLegacy($legacy));
        $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->artisan('design:privatize-references', ['--dry-run' => true])->assertSuccessful();
        $this->assertTrue(Storage::disk('public')->exists($legacy), 'dry run changes nothing');

        $this->artisan('design:privatize-references')->assertSuccessful();
        $this->assertFalse(Storage::disk('public')->exists($legacy));
        $this->assertTrue(Storage::disk('local')->exists($legacy));
        $this->assertSame($legacy, $r->fresh()->reference_image, 'the stored path does not change');
        $this->asAdmin($admin)->get(route('admin.reference-photo', $r->id))->assertOk();
    }

    public function test_the_new_views_do_not_say_ai_design(): void
    {
        foreach (['admin/ideas/index', 'admin/ideas/quick', 'admin/custom-orders/_reference-photo'] as $v) {
            $this->assertDoesNotMatchRegularExpression('/AI designs?\b/i', file_get_contents(resource_path('views/' . $v . '.blade.php')), $v);
        }
    }
}
