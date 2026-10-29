<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\DesignIdea;
use App\Models\ExtraFurnitureType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\UnansweredIdea;
use App\Models\User;
use App\Support\AdminReports;
use App\Support\IdeaOrigins;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * The admin sidebar (grouped, text only), the rebuilt Ideas to Review page and Reports and Analytics
 * (docs/admin/admin-menu-v1.html). Customer pages are not touched by any of it.
 */
class AdminMenuIdeasReportsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return $this->makeUser('admin');
    }

    private function page(User $admin, string $url)
    {
        return $this->actingAs($admin, 'admin')->get($url);
    }

    private function pageData(string $html): array
    {
        preg_match('#<script type="application/json" id="ir-data">(.*?)</script>#s', $html, $m);

        return json_decode($m[1] ?? '{}', true);
    }

    private function sidebar(string $html): string
    {
        preg_match('#<aside class="admin-sidebar".*?</aside>#s', $html, $m);

        return $m[0] ?? '';
    }

    private function request(User $u, array $over = []): CustomDesignRequest
    {
        $r = new CustomDesignRequest();
        $r->forceFill(array_merge([
            'user_id' => $u->id, 'title' => 'T', 'furniture_type' => 'Table', 'description' => 'A sturdy table', 'status' => 'pending',
        ], $over))->save();

        return $r;
    }

    /* ───────────────────────── the menu ───────────────────────── */

    public function test_the_menu_has_the_seven_groups_in_order_with_every_item_and_no_icons(): void
    {
        $html = $this->page($this->admin(), route('admin.dashboard'))->assertOk()->getContent();
        $side = $this->sidebar($html);

        $groups = ['Overview', 'Orders & Payments', 'Catalog & Stock', 'Customers', 'Luma & Customize Studio', 'Team & Reports', 'Site Settings'];
        preg_match_all('#<span class="mnu-h__name">(.*?)</span>#', $side, $m);
        $this->assertSame($groups, array_map('html_entity_decode', $m[1]));

        $items = [
            'Dashboard', 'Notifications', 'Orders', 'Custom Orders', 'Customize Requests', 'Other Services', 'Installments', 'Cancellations & Refunds',
            'Products', 'Materials Inventory', 'Customers', 'Identity Validation', 'Reviews', 'Chatbot Training', 'Ideas to Review', 'Quick Ideas',
            'Employees', 'Reports', 'Contact Settings', 'System Health',
        ];
        preg_match_all('#class="[^"]*mnu-i[^"]*" data-label="(.*?)"#', $side, $labels);
        $this->assertSame($items, array_map('html_entity_decode', $labels[1]), 'same items as before, only regrouped');

        // text only: no icon on an item or on Sign Out (the magnifier is a CSS background; the only svgs are the seven group chevrons)
        $this->assertSame(7, substr_count($side, '<svg'));
        $this->assertSame(7, substr_count($side, 'class="mnu-chev"'));
        $this->assertDoesNotMatchRegularExpression('#<a [^>]*mnu-i.*?<svg.*?</a>#s', preg_replace('#<span class="mnu-chev".*?</span>#s', '', $side));
        $this->assertStringNotContainsString('<img', $side);
        $this->assertStringContainsString('placeholder="Find a page"', $side);
        $this->assertStringContainsString('No page matches that.', $side);
        $this->assertMatchesRegularExpression('#<button type="submit" class="mnu-signout">Sign Out</button>#', $side);
    }

    public function test_the_active_page_and_the_group_that_holds_it_are_marked(): void
    {
        $side = $this->sidebar($this->page($this->admin(), route('admin.ideas.index'))->getContent());

        $this->assertSame(1, substr_count($side, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#mnu-i active"[^>]*data-label="Ideas to Review"\s+aria-current="page"#', $side);
        $this->assertMatchesRegularExpression('#data-group="luma"\s+data-current#', $side, 'the group of the current page cannot be collapsed away');
        $this->assertSame(1, substr_count($side, ' data-current'));
        $this->assertSame(7, substr_count($side, 'aria-expanded="true"'));
    }

    public function test_red_counts_come_from_the_data_and_a_group_shows_their_sum(): void
    {
        UnansweredIdea::record('wood', 'Teak');
        UnansweredIdea::record('idea', 'glass top');
        $customer = $this->makeUser();
        $this->request($customer, ['status' => 'approved']);
        $this->request($customer, ['status' => 'approved', 'pipeline_status' => 'submitted']);

        $side = $this->sidebar($this->page($this->admin(), route('admin.dashboard'))->getContent());

        $this->assertMatchesRegularExpression('#Ideas to Review</span>\s*<span class="mnu-pill admin-nav-badge">2</span>#', $side);
        $this->assertMatchesRegularExpression('#Customize Requests</span>\s*<span class="mnu-pill admin-nav-badge">2</span>#', $side);
        $this->assertMatchesRegularExpression('#Custom Orders</span>\s*<span class="mnu-pill admin-nav-badge">1</span>#', $side);
        $this->assertStringContainsString('class="mnu-pill mnu-g-pill" data-sum="2"', $side, 'Luma & Customize Studio sums to 2');
        $this->assertStringContainsString('class="mnu-pill mnu-g-pill" data-sum="3"', $side, 'Orders & Payments sums to 3');
    }

    public function test_every_admin_sees_the_same_menu_and_a_customer_gets_none(): void
    {
        $a = $this->sidebar($this->page($this->admin(), route('admin.dashboard'))->getContent());
        $b = $this->sidebar($this->page($this->admin(), route('admin.dashboard'))->getContent());
        $this->assertSame(preg_replace('/\s+/', '', $a), preg_replace('/\s+/', '', $b));
    }

    public function test_a_customer_gets_no_admin_menu(): void
    {
        $res = $this->actingAs($this->makeUser(), 'web')->get(route('admin.dashboard'));
        $this->assertTrue($res->isRedirect() || !str_contains($res->getContent(), 'id="admin-sidebar"'));
    }

    public function test_the_layout_loads_the_menu_assets_and_the_burger_has_its_aria(): void
    {
        $html = $this->page($this->admin(), route('admin.dashboard'))->getContent();
        $this->assertStringContainsString('assets/css/admin-menu.css', $html);
        $this->assertStringContainsString('id="admin-burger" aria-label="Open menu" aria-controls="admin-sidebar" aria-expanded="false"', $html);
        $this->assertStringContainsString('id="admin-sidebar-close" aria-label="Close menu"', $html);
        $this->assertStringContainsString('id="admin-overlay"', $html);
    }

    /* ───────────────────────── Ideas to Review ───────────────────────── */

    public function test_view_data_says_where_an_idea_came_from_and_lists_the_real_requests(): void
    {
        $maria = $this->makeUser(); $maria->forceFill(['first_name' => 'Maria', 'last_name' => 'Santos'])->save();
        $jose = $this->makeUser(); $jose->forceFill(['first_name' => 'Jose', 'last_name' => 'Reyes'])->save();

        $bed = $this->request($maria, ['furniture_type' => 'Bed', 'preferred_material' => 'Special request', 'wood_special_details' => 'Puno ng  Saging']);
        $dining = $this->request($jose, ['furniture_type' => 'Table', 'preferred_material' => 'Special request', 'wood_special_details' => 'puno ng saging']);
        $pergola = $this->request($jose, ['furniture_type' => 'Other', 'furniture_type_other' => 'Pergola']);
        $glass = $this->request($maria, ['furniture_type' => 'Table', 'description' => 'I want a table with a glass top and thick legs']);
        $other = $this->request($maria, ['furniture_type' => 'Chair', 'description' => 'A plain chair']);

        $wood = UnansweredIdea::record('wood', 'Puno ng Saging'); UnansweredIdea::record('wood', 'Puno ng Saging');
        $type = UnansweredIdea::record('furniture_type', 'Pergola');
        $idea = UnansweredIdea::record('idea', 'glass top');
        $lonely = UnansweredIdea::record('wood', 'Never Typed On A Request');

        $data = $this->pageData($this->page($this->admin(), route('admin.ideas.index', ['status' => 'all']))->getContent());
        $rows = collect($data['rows'])->keyBy('id');

        $w = $rows[$wood->id];
        $this->assertSame(2, $w['requestTotal']);
        $this->assertEqualsCanonicalizing([$bed->id, $dining->id], array_column($w['requests'], 'id'));
        $this->assertSame('Bed and Table', $w['askedFor'] === 'Table and Bed' ? 'Bed and Table' : $w['askedFor']);
        $first = collect($w['requests'])->firstWhere('id', $bed->id);
        $this->assertSame('Maria S.', $first['who']);
        $this->assertSame('Bed', $first['item']);
        $this->assertSame(route('admin.design-requests.show', $bed->id), $first['url'], 'View Request goes to the real Customize Request page');

        $this->assertSame([$pergola->id], array_column($rows[$type->id]['requests'], 'id'));
        $this->assertSame('Pergola', $rows[$type->id]['furniture'][0] ?? null, 'a "Something else" request is named by what was typed');
        $this->assertSame([$glass->id], array_column($rows[$idea->id]['requests'], 'id'), 'a phrase is found inside the description');
        $this->assertSame(0, $rows[$lonely->id]['requestTotal']);
        $this->assertSame('', $rows[$lonely->id]['askedFor']);
        $this->assertTrue($rows[$type->id]['canOption']);
        $this->assertFalse($rows[$wood->id]['canOption']);
    }

    public function test_origins_use_one_query_per_kind_not_one_per_idea(): void
    {
        $u = $this->makeUser();
        foreach (range(1, 12) as $i) {
            $this->request($u, ['description' => "idea number $i and more"]);
            UnansweredIdea::record('idea', "idea number $i");
            UnansweredIdea::record('wood', "wood $i");
            UnansweredIdea::record('furniture_type', "type $i");
        }
        $ideas = UnansweredIdea::all();
        DB::enableQueryLog();
        $out = IdeaOrigins::for($ideas);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(36, $out);
        $this->assertLessThanOrEqual(8, $queries, 'a handful of queries however many ideas');
    }

    public function test_the_help_modal_and_the_page_furniture_are_on_the_page(): void
    {
        UnansweredIdea::record('wood', 'Teak');
        $html = $this->page($this->admin(), route('admin.ideas.index'))->assertOk()->getContent();

        foreach (['Need Help? See An Example', 'Puno ng Saging', 'Which Button Should I Use?', 'A Customer Types Something New', 'You Review It', 'You Choose What To Do',
                  'Needs Review', 'Answered', 'Added as Option', 'Dismissed', 'All Types', 'Most Typed First', 'Most Recent First',
                  '1. Can We Make This?', '2. Your Reply', 'What The Customer Will See', 'Name Customers Will See'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        foreach (['Idea', 'Type', 'Times Typed', 'Last Typed', 'Status', 'Actions'] as $col) {
            $this->assertStringContainsString('<th>' . $col . '</th>', $html);
        }
        $this->assertStringNotContainsString('>Apply<', $html, 'the filters are live, no Apply button');
        $js = file_get_contents(public_path('assets/js/admin-ideas-review.js'));
        foreach (['Strength And Care', 'Price Note', 'Build Time', 'Other Furniture', 'Suggest A Similar Choice', 'Replace the [brackets]'] as $chip) {
            $this->assertStringContainsString($chip, $js);
        }
        $this->assertSame(1, substr_count($html, 'class="ir2-topic"'));
        $this->assertStringContainsString('lp-modal lp-modal--wide', $html);
    }

    public function test_send_reply_saves_the_answer_and_the_next_customer_sees_it(): void
    {
        $admin = $this->admin();
        $row = UnansweredIdea::record('wood', 'Teak');

        $this->actingAs($admin, 'admin')->post(route('admin.ideas.answer', $row->id), ['answer' => 'Yes, we can make it in Teak. We will confirm the price first.', '_modal' => 'reply', '_idea' => $row->id])
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('answered', $row->status);
        $this->assertStringContainsString('Teak', $row->answer);
        $this->assertNotNull(UnansweredIdea::knownFor('wood', 'teak'));
    }

    public function test_a_reply_with_leftover_brackets_is_refused_and_the_page_reopens_the_window_with_the_text(): void
    {
        $admin = $this->admin();
        $row = UnansweredIdea::record('wood', 'Teak');
        $text = 'Building in Teak may take [number] more days.';

        $res = $this->actingAs($admin, 'admin')->from(route('admin.ideas.index'))
            ->post(route('admin.ideas.answer', $row->id), ['answer' => $text, '_modal' => 'reply', '_idea' => $row->id]);
        $res->assertRedirect(route('admin.ideas.index'))->assertSessionHasErrors('answer');
        $this->assertSame('new', $row->fresh()->status, 'nothing was saved');

        $page = $this->actingAs($admin, 'admin')->withSession(['_old_input' => ['answer' => $text, '_modal' => 'reply', '_idea' => (string) $row->id], 'errors' => (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['answer' => ['Replace the [brackets] with your own words before saving.']]))])
            ->get(route('admin.ideas.index'));
        $data = $this->pageData($page->getContent());
        $this->assertSame('reply', $data['reopen']['modal']);
        $this->assertSame($row->id, $data['reopen']['id']);
        $this->assertSame($text, $data['reopen']['old']['answer']);
        $this->assertStringContainsString('Replace the [brackets]', $data['reopen']['errors']['answer'][0]);
    }

    public function test_an_empty_or_too_short_reply_is_still_refused_as_before(): void
    {
        $row = UnansweredIdea::record('wood', 'Teak');
        foreach (['', 'ok', str_repeat('a', 501), 'See https://example.com'] as $bad) {
            $this->actingAs($this->admin(), 'admin')->post(route('admin.ideas.answer', $row->id), ['answer' => $bad])->assertSessionHasErrors('answer');
        }
        $this->assertSame('new', $row->fresh()->status);
    }

    public function test_add_as_option_adds_the_quick_pick_and_the_quick_idea(): void
    {
        $admin = $this->admin();
        $type = UnansweredIdea::record('furniture_type', 'Pergola');
        $idea = UnansweredIdea::record('idea', 'glass top');

        $this->actingAs($admin, 'admin')->post(route('admin.ideas.option', $type->id), ['wording' => 'Garden Pergola'])->assertSessionHasNoErrors();
        $this->assertTrue(ExtraFurnitureType::where('name', 'Garden Pergola')->exists());
        $this->assertSame('added_as_option', $type->fresh()->status);

        $this->actingAs($admin, 'admin')->post(route('admin.ideas.option', $idea->id), ['scope' => 'type', 'key' => 'Table', 'wording' => 'glass top'])->assertSessionHasNoErrors();
        $this->assertTrue(DesignIdea::where('idea_key', 'Table')->where('text', 'glass top')->exists());
        $this->assertSame('added_as_option', $idea->fresh()->status);

        // the preview data the Add as Option window draws from
        $data = $this->pageData($this->page($admin, route('admin.ideas.index'))->getContent());
        $this->assertContains('Garden Pergola', $data['extraTypes']);
        $this->assertContains('glass top', $data['existingIdeas']['type:Table']);
    }

    public function test_a_refused_add_as_option_comes_back_with_the_window_open_and_the_reason(): void
    {
        $admin = $this->admin();
        $wood = UnansweredIdea::record('wood', 'Teak');
        $this->actingAs($admin, 'admin')->from(route('admin.ideas.index'))->post(route('admin.ideas.option', $wood->id), ['wording' => 'Teak'])
            ->assertRedirect(route('admin.ideas.index'))->assertSessionHas('error')->assertSessionHasErrors('wording');
        $this->assertSame('new', $wood->fresh()->status);

        $type = UnansweredIdea::record('furniture_type', 'Pergola');
        $before = ExtraFurnitureType::count();
        $this->actingAs($admin, 'admin')->post(route('admin.ideas.option', $type->id), ['wording' => ''])->assertSessionHasErrors('wording');
        $this->assertSame($before, ExtraFurnitureType::count(), 'name is required');
    }

    public function test_dismiss_restore_and_delete_still_work_and_old_write_answer_urls_stay_valid(): void
    {
        $admin = $this->admin();
        $row = UnansweredIdea::record('idea', 'glass top');

        $this->actingAs($admin, 'admin')->post(route('admin.ideas.dismiss', $row->id))->assertSessionHas('undo_idea', $row->id);
        $this->assertSame('dismissed', $row->fresh()->status);
        $this->actingAs($admin, 'admin')->post(route('admin.ideas.undo', $row->id));
        $this->assertSame('new', $row->fresh()->status);

        // the old status / kind / sort links open the right starting view
        $this->assertSame('dismissed', $this->pageData($this->page($admin, route('admin.ideas.index', ['status' => 'dismissed']))->getContent())['initial']['tab']);
        $this->assertSame('idea', $this->pageData($this->page($admin, route('admin.ideas.index', ['kind' => 'idea']))->getContent())['initial']['kind']);
        $this->assertSame('new', $this->pageData($this->page($admin, route('admin.ideas.index', ['status' => 'bogus']))->getContent())['initial']['tab']);

        $this->actingAs($admin, 'admin')->post(route('admin.ideas.delete', $row->id))->assertSessionHasNoErrors();
        $this->assertNull(UnansweredIdea::find($row->id));
    }

    public function test_only_an_admin_can_post_to_the_ideas_routes(): void
    {
        $row = UnansweredIdea::record('idea', 'glass top');
        $this->actingAs($this->makeUser(), 'web');
        foreach (['answer', 'option', 'dismiss', 'undo', 'delete'] as $route) {
            $this->post(route('admin.ideas.' . $route, $row->id), ['answer' => 'hello there', 'wording' => 'x'])->assertRedirect();
        }
        $this->assertSame('new', $row->fresh()->status);
        $this->assertNotNull(UnansweredIdea::find($row->id));
    }

    /* ───────────────────────── Reports and Analytics ───────────────────────── */

    /** October 2026 (today = Oct 20) against September, built from rows with known numbers. */
    private function seedMonths(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
        $buyer = $this->makeUser();
        $buyer->forceFill(['created_at' => '2026-10-02 09:00:00'])->save();                // 1 new customer this month, 0 last month

        $mk = function (string $when, float $total, float $paid, string $status = 'confirmed', array $over = []) use ($buyer) {
            $o = $this->makeOrder($buyer, array_merge(['total_amount' => $total, 'order_status' => $status], $over));
            $o->forceFill(['amount_paid' => $paid, 'created_at' => $when])->save();

            return $o;
        };
        // October: revenue 4,000 (2 orders), 3,400 collected, 1 cancelled of 3 placed = 33%
        $mk('2026-10-03 10:00:00', 1000, 400);
        $mk('2026-10-10 10:00:00', 3000, 3000, 'completed');
        $mk('2026-10-12 10:00:00', 500, 0, 'cancelled');
        // September: revenue 2,000 (1 order), 1,000 collected, 2 cancelled of 3 placed = 67%
        $mk('2026-09-05 10:00:00', 2000, 1000);
        $mk('2026-09-06 10:00:00', 500, 0, 'cancelled');
        $mk('2026-09-07 10:00:00', 500, 0, 'cancelled');

        // Luma: October 4 messages, 1 not answered (75%); September 2 messages, 1 not answered (50%)
        foreach ([['2026-10-04', 'intent:1'], ['2026-10-05', 'intent:2'], ['2026-10-06', 'unmatched'], ['2026-10-07', 'intent:1'], ['2026-09-04', 'intent:1'], ['2026-09-05', 'unmatched']] as [$d, $intent]) {
            DB::table('chatbot_logs')->insert(['user_id' => null, 'session_id' => 's', 'message' => 'Do you deliver?', 'response' => 'r', 'intent_detected' => $intent, 'created_at' => $d . ' 10:00:00']);
        }
    }

    public function test_the_six_number_cards_use_the_real_definitions_and_colors(): void
    {
        $this->seedMonths();
        $cards = collect(AdminReports::build('m')['kpis'])->keyBy('label');

        $this->assertSame('₱4,000', $cards['Revenue']['value']);
        $this->assertSame('₱2,000', $cards['Revenue']['was']);
        $this->assertSame('100%', $cards['Revenue']['chip']);
        $this->assertSame(['good', 'up'], [$cards['Revenue']['tone'], $cards['Revenue']['dir']]);

        $this->assertSame(['2', '1', '100%'], [$cards['Orders']['value'], $cards['Orders']['was'], $cards['Orders']['chip']], 'cancelled orders are not counted');
        $this->assertSame(['₱3,400', '₱1,000', '240%'], [$cards['Money Collected']['value'], $cards['Money Collected']['was'], $cards['Money Collected']['chip']]);
        $this->assertSame(['1', '0', '+1', 'good'], [$cards['New Customers']['value'], $cards['New Customers']['was'], $cards['New Customers']['chip'], $cards['New Customers']['tone']], 'a count shows plus or minus');

        // lower is better: the cancelled share fell from 67% to 33%, so the drop is green
        $this->assertSame(['33%', '67%', '33 pts', 'good', 'dn'], [$cards['Cancelled Orders']['value'], $cards['Cancelled Orders']['was'], $cards['Cancelled Orders']['chip'], $cards['Cancelled Orders']['tone'], $cards['Cancelled Orders']['dir']]);
        $this->assertSame(['75%', '50%', '25 pts', 'good'], [$cards['Luma Answered']['value'], $cards['Luma Answered']['was'], $cards['Luma Answered']['chip'], $cards['Luma Answered']['tone']]);
    }

    public function test_a_rising_cancel_rate_is_red_and_a_zero_previous_value_reads_new(): void
    {
        $this->assertSame(['New', 'good', 'up'], array_values(array_intersect_key(AdminReports::card('Revenue', 'money', 5000, 0, 'pct'), ['chip' => 1, 'tone' => 1, 'dir' => 1])));
        $c = AdminReports::card('Cancelled Orders', 'rate', 40, 10, 'pts', true);
        $this->assertSame(['30 pts', 'bad', 'up'], [$c['chip'], $c['tone'], $c['dir']]);
        $c = AdminReports::card('Not Answered', 'num', 3, 0, 'pct', true);
        $this->assertSame(['New', 'bad'], [$c['chip'], $c['tone']], 'a new problem is red');
        $c = AdminReports::card('Orders', 'num', 3, 3, 'pct');
        $this->assertSame(['No change', 'flat', 'none'], [$c['chip'], $c['tone'], $c['dir']]);
        $c = AdminReports::card('Revenue', 'money', 100, 200, 'pct');
        $this->assertSame(['50%', 'bad', 'dn'], [$c['chip'], $c['tone'], $c['dir']]);
    }

    public function test_the_period_switch_changes_the_ranges(): void
    {
        $this->seedMonths();
        $q = collect(AdminReports::build('q')['kpis'])->keyBy('label');
        $this->assertSame('₱6,000', $q['Revenue']['value'], 'Aug to Oct: 4,000 + 2,000');
        $this->assertSame('₱0', $q['Revenue']['was'], 'the three months before that had no orders');
        $this->assertSame('New', $q['Revenue']['chip']);

        $html = $this->page($this->admin(), route('admin.reports', ['period' => 'q']))->assertOk()->getContent();
        $this->assertStringContainsString('This Month vs Last Month', $html);
        $this->assertStringContainsString('Last 3 Months vs Before', $html);
        $this->assertMatchesRegularExpression('#class="is-on"\s+aria-current="true"\s*>Last 3 Months vs Before#', $html);
    }

    public function test_the_page_shows_the_five_tabs_the_real_figures_and_the_new_title(): void
    {
        $this->seedMonths();
        $html = $this->page($this->admin(), route('admin.reports'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Reports and Analytics — Admin</title>', $html);
        $this->assertStringContainsString('<div class="admin-topbar-title">Reports and Analytics</div>', $html);
        foreach (['Sales', 'Products', 'Services &amp; AI', 'Luma', 'Customers'] as $tab) {
            $this->assertStringContainsString('>' . $tab . '</button>', $html);
        }
        foreach (['Revenue', 'Orders', 'Money Collected', 'New Customers', 'Cancelled Orders', 'Luma Answered'] as $l) {
            $this->assertStringContainsString('rp-kpi__l">' . $l . '<', $html);
        }
        $this->assertStringContainsString('Was ₱2,000', $html);
        $this->assertStringContainsString('rp-kpi rp-top-good', $html);
        $this->assertStringNotContainsString('Sample numbers', $html);
        $this->assertStringNotContainsString('Maria S.', $html, 'no prototype names');
    }

    public function test_the_sales_tab_numbers(): void
    {
        $this->seedMonths();
        $s = AdminReports::build('m')['sales'];

        $this->assertCount(6, $s['months']);
        $this->assertSame('Oct', end($s['months'])['label']);
        $this->assertSame(4000.0, end($s['months'])['value']);
        $this->assertSame(['text' => '+100%', 'tone' => 'good'], end($s['months'])['chip']);
        $sep = $s['months'][4];
        $this->assertSame(2000.0, $sep['value']);
        $this->assertSame(['text' => 'First', 'tone' => 'good'], $sep['chip'], 'the first month with sales');
        $this->assertSame(['text' => 'None', 'tone' => 'flat'], $s['months'][0]['chip']);

        $counts = collect($s['statuses'])->pluck('count', 'key');
        $this->assertSame(2, $counts['confirmed']);
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(3, $counts['cancelled']);
        $this->assertSame(6, $s['ordersTotal']);

        $this->assertSame(['paid' => 4400.0, 'total' => 6000.0, 'left' => 1600.0, 'pct' => 73], $s['collected']);
        $catalog = collect($s['split'])->firstWhere('label', 'Catalog');
        $this->assertSame([3, 6000.0, 4400.0], [$catalog['orders'], $catalog['total'], $catalog['paid']]);
        $this->assertSame(0, collect($s['split'])->firstWhere('label', 'Customized')['orders']);
    }

    public function test_the_products_tab_wood_is_derived_from_products_and_custom_requests(): void
    {
        $buyer = $this->makeUser();
        $narra = Product::create(['product_name' => 'Narra Table', 'price' => 100, 'stock_quantity' => 3, 'product_status' => 'available', 'material' => 'Narra', 'category' => 'Table']);
        $chair = Product::create(['product_name' => 'Chair', 'price' => 50, 'stock_quantity' => 40, 'product_status' => 'available', 'material' => 'Narra', 'category' => 'Chair']);
        foreach ([[$narra, 3], [$chair, 1]] as [$p, $q]) {
            $o = $this->makeOrder($buyer, ['total_amount' => 100]);
            OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'product_name' => $p->product_name, 'quantity' => $q, 'unit_price' => 100, 'subtotal' => 100 * $q]);
        }
        $req = $this->request($buyer, ['preferred_material' => 'Mahogany']);
        $this->makeOrder($buyer, ['design_request_id' => $req->id]);
        $cancelled = $this->makeOrder($buyer, ['order_status' => 'cancelled']);
        OrderItem::create(['order_id' => $cancelled->id, 'product_id' => $chair->id, 'product_name' => 'Chair', 'quantity' => 1, 'unit_price' => 50, 'subtotal' => 50]);

        $p = AdminReports::build('m')['products'];
        $wood = collect($p['wood'])->keyBy('name');
        $this->assertSame(2, $wood['Narra']['n'], 'one count per order, cancelled orders left out');
        $this->assertSame(1, $wood['Mahogany']['n']);
        $this->assertSame(67, $wood['Narra']['pct']);

        $this->assertSame('Narra Table', $p['best'][0]->product_name);
        $this->assertSame(3, (int) $p['best'][0]->sold);
        $this->assertSame(['Narra Table'], $p['low']->pluck('product_name')->all(), 'low stock is 5 or fewer');
    }

    public function test_the_services_and_ai_tab_funnel_and_service_demand(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00'));
        $u = $this->makeUser();
        $make = function (string $when, bool $sent, bool $withPicture = true) use ($u) {
            $r = $this->request($u, $sent ? ['pipeline_status' => 'submitted', 'status' => 'approved'] : []);
            $r->forceFill(['created_at' => $when])->save();
            if ($withPicture) {
                DB::table('ai_design_suggestions')->insert(['design_id' => $r->id, 'image_path' => 'x.jpg', 'ai_model_used' => 'm', 'prompt_used' => 'p', 'generated_at' => $when]);
            }

            return $r;
        };
        $a = $make('2026-10-03 10:00:00', true);
        $make('2026-10-04 10:00:00', false);
        $make('2026-10-05 10:00:00', false);
        $make('2026-10-06 10:00:00', false);
        $make('2026-10-07 10:00:00', false, false);            // no picture: not a design
        $make('2026-09-03 10:00:00', true);
        $paid = $this->makeOrder($u, ['design_request_id' => $a->id, 'total_amount' => 10000]);
        $paid->forceFill(['amount_paid' => 4000])->save();

        $v = AdminReports::build('m')['services'];
        $cards = collect($v['cards'])->keyBy('label');
        $this->assertSame(['4', '1', '300%'], [$cards['Designs Made']['value'], $cards['Designs Made']['was'], $cards['Designs Made']['chip']]);
        $this->assertSame(['1', '1'], [$cards['Became Requests']['value'], $cards['Became Requests']['was']]);
        $this->assertSame('1', $cards['Became Paid Orders']['value']);
        $this->assertSame('₱4,000', $cards['Money From Designs']['value']);
        $this->assertSame('New', $cards['Money From Designs']['chip'], 'the zero-previous case');

        $f = $v['funnel'];
        $this->assertSame([4, 1, 1, 25, 100, 4000.0, 25.0], [$f['made'], $f['requests'], $f['paid'], $f['requestsPct'], $f['paidPct'], $f['average'], $f['salePct']]);
        $this->assertSame(4.0, end($v['months'])['value']);

        // Other Services demand
        foreach ([['repair', '2026-10-03', 'closed'], ['repair', '2026-10-04', 'new'], ['repair', '2026-09-04', 'new']] as $i => [$type, $d, $status]) {
            DB::table('service_inquiries')->insert(['user_id' => $u->id, 'reference_number' => 'SI-2026-000' . $i, 'service_type' => $type, 'concern' => 'x', 'name' => 'n', 'phone' => '09171234567', 'email' => 'a@b.c', 'status' => $status, 'created_at' => $d . ' 10:00:00', 'updated_at' => $d . ' 10:00:00']);
        }
        $row = collect(AdminReports::build('m')['services']['demand'])->firstWhere('label', 'Repair or restoration');
        $this->assertSame([2, 1, 1], [$row['requests'], $row['done'], $row['change']]);
    }

    public function test_the_luma_tab_counts_asked_answered_and_unanswered_from_the_logs(): void
    {
        $this->seedMonths();
        DB::table('luma_unanswered_questions')->insert(['question_key' => 'do you make boats', 'question' => 'do you make boats', 'times_asked' => 6, 'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('luma_unanswered_questions')->insert(['question_key' => 'taught one', 'question' => 'taught one', 'times_asked' => 9, 'taught_at' => now(), 'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $l = AdminReports::build('m')['luma'];
        $cards = collect($l['cards'])->keyBy('label');
        $this->assertSame(['4', '2', '100%'], [$cards['Questions Asked']['value'], $cards['Questions Asked']['was'], $cards['Questions Asked']['chip']]);
        $this->assertSame('3', $cards['Answered']['value']);
        $this->assertSame(['1', 'No change', 'flat'], [$cards['Not Answered']['value'], $cards['Not Answered']['chip'], $cards['Not Answered']['tone']]);
        $this->assertSame(['75%', '25 pts'], [$cards['Answer Rate']['value'], $cards['Answer Rate']['chip']]);

        $this->assertSame('do you deliver?', $l['top'][0]['q']);
        $this->assertSame(100, $l['top'][0]['pct']);
        $this->assertSame(['do you make boats'], $l['couldNot']->pluck('question')->all(), 'answered (taught) questions are not listed');
        $this->assertSame(75.0, end($l['months'])['value']);
    }

    public function test_the_customers_tab(): void
    {
        $this->seedMonths();
        $buyer = User::where('role', 'customer')->first();
        $waiting = $this->makeOrder($buyer, ['total_amount' => 7500, 'order_number' => 'LP-WAIT-1']);
        $waiting->forceFill(['created_at' => now()->subDays(3)])->save();

        $c = AdminReports::build('m')['customers'];
        $cards = collect($c['cards'])->keyBy('label');
        $this->assertSame('1', $cards['New This Month']['value']);
        $this->assertSame('+1', $cards['New This Month']['chip']);
        $this->assertSame('1', $cards['Verified']['value']);
        $this->assertSame('0', $cards['Not Verified']['value']);

        $this->assertCount(1, $c['follow']);
        $this->assertSame(['LP-WAIT-1', 7500.0, 3], [$c['follow'][0]['order'], $c['follow'][0]['amount'], $c['follow'][0]['days']]);

        $this->assertSame([4, 4400.0], [$c['top'][0]['orders'], $c['top'][0]['spent']], 'orders that are not cancelled, and the money actually received on them');
    }

    public function test_cancellation_reasons_group_by_what_was_stored(): void
    {
        $this->seedMonths();
        $cancelled = Order::where('order_status', 'cancelled')->get();
        $add = fn ($o, $admin, $note) => OrderStatusEvent::create(['order_id' => $o->id, 'admin_user_id' => $admin, 'type' => 'cancel', 'title' => 'x', 'from_status' => 'pending', 'to_status' => 'cancelled', 'note' => $note, 'customer_notified' => true]);
        $add($cancelled[0], null, 'Cancelled by the customer. Reason: Changed My Mind');
        $add($cancelled[0], null, 'Cancelled by the customer. Reason: Changed My Mind');
        $add($cancelled[1], null, 'Cancelled by the customer. Reason: Other - found it cheaper elsewhere');
        $add($cancelled[2], $this->admin()->id, 'Out of the wood');

        $why = collect(AdminReports::build('m')['customers']['why'])->keyBy('name');
        $this->assertSame(2, $why['Changed My Mind']['n']);
        $this->assertSame(1, $why['Other']['n'], 'the free text after "Other" is not a group of its own');
        $this->assertSame(1, $why['Cancelled By The Shop']['n']);
        $this->assertSame(50, $why['Changed My Mind']['pct']);
    }

    public function test_an_empty_database_gives_empty_states_not_errors(): void
    {
        $html = $this->page($this->admin(), route('admin.reports'))->assertOk()->getContent();
        foreach (['No orders yet.', 'No sales yet.', 'No unpaid orders are waiting.', 'No orders were cancelled in this period.', 'Luma has answered everything so far.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->page($this->admin(), route('admin.reports', ['period' => 'q', 'tab' => 'luma']))->assertOk();
        $this->page($this->admin(), route('admin.reports', ['period' => 'nonsense', 'tab' => 'nonsense']))->assertOk();
    }

    public function test_no_send_reminder_button_exists_because_there_is_no_reminder_feature(): void
    {
        $this->seedMonths();
        $html = $this->page($this->admin(), route('admin.reports', ['tab' => 'cust']))->getContent();
        $this->assertStringNotContainsString('Send Reminder', $html);
    }

    public function test_reports_is_for_admins_only(): void
    {
        $this->actingAs($this->makeUser(), 'web')->get(route('admin.reports'))->assertRedirect();
    }

    /* ───────────────────────── customer side untouched ───────────────────────── */

    public function test_customer_pages_do_not_load_any_of_the_new_admin_assets(): void
    {
        $customer = $this->makeUser();
        foreach ([route('home'), route('catalog')] as $url) {
            $html = $this->actingAs($customer)->get($url)->getContent();
            foreach (['admin-menu.css', 'admin-ideas-review', 'admin-reports', 'mnu-', 'admin-sidebar'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "$needle on $url");
            }
        }
    }
}
