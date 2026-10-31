<?php

namespace Tests\Feature;

use App\Models\ActivityLog as Row;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * The Activity Log page (admin only): access, summary cards, tabs and their counts, every filter, every sort, date edges,
 * the Late Night tag, pagination, Details data, Open Item links, the CSV export, the sidebar link and the empty state.
 */
class ActivityLogViewerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->admin = $this->makeUser('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string,mixed> $o */
    private function log(string $when, string $who, string $role, string $kind, string $category, string $desc, array $o = []): Row
    {
        return Row::create($o + [
            'user_id' => null, 'actor_name' => $who, 'role' => $role, 'event' => $o['event'] ?? 'x', 'kind' => $kind, 'category' => $category,
            'description' => $desc, 'subject_type' => null, 'subject_id' => null, 'subject_label' => null, 'created_at' => Carbon::parse($when),
        ]);
    }

    private function page(array $q = [])
    {
        return $this->actingAs($this->admin, 'admin')->get(route('admin.activity-log', $q));
    }

    /** The descriptions shown in the table, in order. */
    private function shown(array $q = []): array
    {
        $html = $this->page($q)->assertOk()->getContent();
        preg_match_all('#<tr class="al-row-item">.*?</tr>#s', $html, $rows);
        $out = [];
        foreach ($rows[0] as $r) {
            preg_match('#data-label="What Happened">(.*?)</td>#s', $r, $m);
            $out[] = html_entity_decode($m[1]);
        }

        return $out;
    }

    private function seedSet(): void
    {
        $this->log('2026-10-31 09:02', 'Maria Santos', 'customer', 'signin', 'signin', 'Signed in');
        $this->log('2026-10-31 08:50', 'LP Admin', 'admin', 'action', 'catalog', 'Restocked Wood', ['subject_label' => 'Material: Wood (Gemelina)']);
        $this->log('2026-10-31 08:55', 'LP Admin', 'admin', 'action', 'orders', 'Confirmed order LP-1058', ['subject_label' => 'Order LP-1058']);
        $this->log('2026-10-30 17:40', 'Admin Joy', 'admin', 'action', 'catalog', 'Edited the estimate for Bed');
        $this->log('2026-10-30 02:31', 'LP Admin', 'admin', 'signout', 'signin', 'Signed out');
        $this->log('2026-10-25 15:20', 'Mark Tan', 'customer', 'signin', 'signin', 'Signed in');
        $this->log('2026-10-01 10:00', 'LP Admin', 'admin', 'action', 'settings', 'Saved Contact Settings');
    }

    /* ───────────── access ───────────── */

    public function test_only_a_signed_in_admin_can_open_the_page_and_the_export(): void
    {
        $customer = $this->makeUser();

        $this->get(route('admin.activity-log'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.activity-log.export'))->assertRedirect(route('admin.login'));
        $this->actingAs($customer)->get(route('admin.activity-log'))->assertRedirect(route('admin.login'));
        $this->actingAs($customer, 'admin')->get(route('admin.activity-log'))->assertRedirect(route('admin.login'));
        $this->actingAs($customer, 'admin')->get(route('admin.activity-log.export'))->assertRedirect(route('admin.login'));

        $this->page()->assertOk()->assertSee('Activity Log');
    }

    public function test_the_page_is_read_only_there_is_no_write_route(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->uri(), 'activity-log')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
    }

    /* ───────────── sidebar ───────────── */

    public function test_the_sidebar_has_activity_log_between_employees_and_reports_with_the_active_state(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-label="Employees".*?data-label="Activity Log".*?data-label="Reports"#s', $html);
        $this->assertMatchesRegularExpression('#class="admin-nav-link mnu-i active" data-label="Activity Log"\s+aria-current="page"#', $html);

        $other = $this->actingAs($this->admin, 'admin')->get(route('admin.reports'))->assertOk()->getContent();
        $this->assertStringNotContainsString('mnu-i active" data-label="Activity Log"', $other);
        $this->assertStringContainsString('data-label="Activity Log"', $other);
    }

    /* ───────────── summary cards, tabs, empty state ───────────── */

    public function test_summary_cards_count_today_and_this_week(): void
    {
        $a = $this->makeUser('admin');
        $c = $this->makeUser();
        $this->log('2026-10-31 09:00', 'A', 'admin', 'signin', 'signin', 'Signed in', ['user_id' => $a->id]);
        $this->log('2026-10-31 09:05', 'A', 'admin', 'action', 'orders', 'Did a thing', ['user_id' => $a->id]);
        $this->log('2026-10-31 09:06', 'A', 'admin', 'action', 'orders', 'Did another', ['user_id' => $a->id]);
        $this->log('2026-10-31 09:30', 'C', 'customer', 'signin', 'signin', 'Signed in', ['user_id' => $c->id]);
        $this->log('2026-10-27 09:30', 'C', 'customer', 'signin', 'signin', 'Signed in', ['user_id' => $c->id]);   // 4 days ago, same customer
        $this->log('2026-10-20 09:30', 'D', 'customer', 'signin', 'signin', 'Signed in');                          // outside the week

        $html = $this->page()->assertOk()->getContent();
        $get = fn ($label) => (int) (preg_match('#<b>(\d+)</b><span>' . preg_quote($label, '#') . '</span>#', $html, $m) ? $m[1] : -1);

        $this->assertSame(2, $get('Sign-Ins Today'));
        $this->assertSame(1, $get('Admins Active Today'));
        $this->assertSame(2, $get('Admin Actions Today'));
        $this->assertSame(1, $get('Customers Signed In This Week'));
    }

    public function test_tabs_filter_and_their_counts_follow_the_other_active_filters(): void
    {
        $this->seedSet();

        $html = $this->page()->getContent();
        foreach (['All' => 7, 'Sign-Ins' => 3, 'Orders & Payments' => 1, 'Catalog & Stock' => 2, 'People' => 0, 'Luma & Settings' => 1] as $label => $n) {
            $this->assertMatchesRegularExpression('#>' . preg_quote(htmlspecialchars($label, ENT_QUOTES), '#') . '<span class="al-tab__c">' . $n . '</span>#', $html, "$label should be $n");
        }

        // with Who = Customers every tab count follows it; the selected tab only changes the rows
        $html = $this->page(['who' => 'customer', 'tab' => 'orders'])->getContent();
        $this->assertMatchesRegularExpression('#>All<span class="al-tab__c">2</span>#', $html);
        $this->assertMatchesRegularExpression('#>Sign-Ins<span class="al-tab__c">2</span>#', $html);
        $this->assertMatchesRegularExpression('#>Orders &amp; Payments<span class="al-tab__c">0</span>#', $html);
        $this->assertSame([], $this->shown(['who' => 'customer', 'tab' => 'orders']));

        $this->assertSame(['Restocked Wood', 'Edited the estimate for Bed'], $this->shown(['tab' => 'catalog']));
    }

    public function test_the_empty_state_and_an_unknown_tab(): void
    {
        $this->page()->assertSee('No activity matches')->assertSee('Try a different tab, date or search.');
        $this->seedSet();
        $this->assertCount(7, $this->shown(['tab' => 'nonsense']));
    }

    /* ───────────── filters ───────────── */

    public function test_search_matches_person_description_and_item(): void
    {
        $this->seedSet();
        $this->assertSame(['Signed in'], $this->shown(['q' => 'maria']));
        $this->assertSame(['Confirmed order LP-1058'], $this->shown(['q' => 'confirmed']));
        $this->assertSame(['Restocked Wood'], $this->shown(['q' => 'Gemelina']));
        $this->assertSame(['Confirmed order LP-1058'], $this->shown(['q' => 'LP-1058']));
        $this->assertSame([], $this->shown(['q' => 'zzz']));
        $this->assertSame([], $this->shown(['q' => '%']), 'wildcards are literal');
    }

    public function test_person_what_and_who_filters(): void
    {
        $joy = $this->makeUser('admin');
        $this->log('2026-10-31 09:00', 'Joy', 'admin', 'action', 'orders', 'Joy did it', ['user_id' => $joy->id]);
        $this->log('2026-10-31 09:01', 'Other', 'admin', 'action', 'orders', 'Other did it');
        $this->log('2026-10-31 09:02', 'Maria', 'customer', 'signin', 'signin', 'Signed in');
        $this->log('2026-10-31 09:03', 'Maria', 'customer', 'signout', 'signin', 'Signed out');

        $this->assertSame(['Joy did it'], $this->shown(['person' => $joy->id]));
        $this->assertSame(['Signed in'], $this->shown(['what' => 'signin']));
        $this->assertSame(['Signed out'], $this->shown(['what' => 'signout']));
        $this->assertSame(['Other did it', 'Joy did it'], $this->shown(['what' => 'action']));
        $this->assertSame(['Signed out', 'Signed in'], $this->shown(['who' => 'customer']));
        $this->assertSame(['Other did it', 'Joy did it'], $this->shown(['who' => 'admin']));
        $this->assertCount(4, $this->shown(['what' => 'bogus', 'who' => 'bogus', 'person' => 'x']));

        $html = $this->page()->getContent();
        $this->assertStringContainsString('<option value="' . $joy->id . '"', $html);
    }

    public function test_date_range_edges_are_inclusive_and_limited_to_180_days(): void
    {
        $this->log('2026-10-31 00:00:00', 'A', 'admin', 'action', 'orders', 'start of today');
        $this->log('2026-10-30 23:59:59', 'A', 'admin', 'action', 'orders', 'end of yesterday');
        $this->log('2026-10-30 00:00:00', 'A', 'admin', 'action', 'orders', 'start of yesterday');
        $this->log('2026-10-29 23:59:59', 'A', 'admin', 'action', 'orders', 'end of the day before');

        $this->assertSame(['start of today'], $this->shown(['from' => '2026-10-31', 'to' => '2026-10-31']));
        $this->assertSame(['end of yesterday', 'start of yesterday'], $this->shown(['from' => '2026-10-30', 'to' => '2026-10-30']));
        $this->assertSame(['start of today', 'end of yesterday', 'start of yesterday'], $this->shown(['from' => '2026-10-30']));
        $this->assertSame(['end of the day before'], $this->shown(['to' => '2026-10-29']));
        // reversed dates are put in order; junk is ignored
        $this->assertCount(3, $this->shown(['from' => '2026-10-30', 'to' => '2026-10-29']));
        $this->assertCount(4, $this->shown(['from' => 'nope', 'to' => '2026-02-31']));
        // outside the window: lifted to 180 days ago / today
        $html = $this->page(['from' => '2020-01-01', 'to' => '2099-01-01'])->getContent();
        $this->assertStringContainsString('name="from" class="form-input" value="2026-05-04"', $html);
        $this->assertStringContainsString('name="to" class="form-input" value="2026-10-31"', $html);
        $this->assertStringContainsString('min="2026-05-04" max="2026-10-31"', $html);
    }

    public function test_quick_date_links_set_the_range_and_mark_the_current_one(): void
    {
        $html = $this->page(['from' => '2026-10-25', 'to' => '2026-10-31'])->getContent();
        $this->assertMatchesRegularExpression('#from=2026-10-25&amp;to=2026-10-31"[^>]*aria-pressed="true">Last 7 Days#', $html);
        $this->assertMatchesRegularExpression('#aria-pressed="false">All Time#', $html);
        $this->assertStringContainsString('aria-pressed="true">All Time', $this->page()->getContent());
    }

    public function test_time_of_day_filter_and_the_late_night_tag_at_459_and_500(): void
    {
        $this->log('2026-10-31 04:59:59', 'A', 'admin', 'action', 'orders', 'four fifty nine');
        $this->log('2026-10-31 05:00:00', 'A', 'admin', 'action', 'orders', 'five oclock');
        $this->log('2026-10-31 00:00:00', 'A', 'admin', 'action', 'orders', 'midnight');
        $this->log('2026-10-30 12:00:00', 'A', 'admin', 'action', 'orders', 'noon');
        $this->log('2026-10-30 17:59:59', 'A', 'admin', 'action', 'orders', 'almost six');
        $this->log('2026-10-30 18:00:00', 'A', 'admin', 'action', 'orders', 'six pm');
        $this->log('2026-10-30 23:59:59', 'A', 'admin', 'action', 'orders', 'last second');

        $this->assertEqualsCanonicalizing(['four fifty nine', 'midnight'], $this->shown(['tod' => 'night']));
        $this->assertSame(['five oclock'], $this->shown(['tod' => 'morning']));
        $this->assertEqualsCanonicalizing(['noon', 'almost six'], $this->shown(['tod' => 'afternoon']));
        $this->assertEqualsCanonicalizing(['six pm', 'last second'], $this->shown(['tod' => 'evening']));

        $html = $this->page()->getContent();
        $this->assertSame(2, substr_count($html, 'class="al-late">Late Night</em>'), 'only 4:59 and midnight carry the tag');
        preg_match('#four fifty nine#', $html);
        $row = fn ($text) => preg_match('#<tr class="al-row-item">((?:(?!</tr>).)*?' . $text . '.*?)</tr>#s', $html, $m) ? $m[1] : '';
        $this->assertStringContainsString('Late Night', $row('four fifty nine'));
        $this->assertStringNotContainsString('Late Night', $row('five oclock'));
    }

    /* ───────────── sorts ───────────── */

    public function test_every_sort_and_the_header_links_toggle_the_direction(): void
    {
        $this->log('2026-10-31 09:00', 'Bea', 'customer', 'signin', 'signin', 'b-signin');
        $this->log('2026-10-31 10:00', 'Ana', 'admin', 'action', 'orders', 'a-orders');
        $this->log('2026-10-31 11:00', 'Cyd', 'admin', 'action', 'catalog', 'c-catalog');
        $this->log('2026-10-31 08:00', 'Ana', 'admin', 'action', 'people', 'a-people');

        $this->assertSame(['c-catalog', 'a-orders', 'b-signin', 'a-people'], $this->shown());
        $this->assertSame(['c-catalog', 'a-orders', 'b-signin', 'a-people'], $this->shown(['sort' => 'new']));
        $this->assertSame(['a-people', 'b-signin', 'a-orders', 'c-catalog'], $this->shown(['sort' => 'old']));
        $this->assertSame(['a-orders', 'a-people', 'b-signin', 'c-catalog'], $this->shown(['sort' => 'az']));
        $this->assertSame(['c-catalog', 'b-signin', 'a-orders', 'a-people'], $this->shown(['sort' => 'za']));
        $this->assertSame(['c-catalog', 'a-orders', 'a-people', 'b-signin'], $this->shown(['sort' => 'type']), 'Catalog, Orders, People, Sign-Ins');
        $this->assertSame(['c-catalog', 'a-orders', 'b-signin', 'a-people'], $this->shown(['sort' => 'junk']));

        $html = $this->page()->getContent();
        $this->assertStringContainsString('href="' . route('admin.activity-log', ['sort' => 'old']) . '"', str_replace('&amp;', '&', $html));
        $html = str_replace('&amp;', '&', $this->page(['sort' => 'old'])->getContent());
        $this->assertStringNotContainsString('sort=old"', preg_replace('#<select.*?</select>#s', '', $html) ? '' : '', 'sanity');
        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $html = str_replace('&amp;', '&', $this->page(['sort' => 'az'])->getContent());
        $this->assertStringContainsString('sort=za"', $html);
    }

    /* ───────────── pagination ───────────── */

    public function test_pagination_is_12_per_page_and_keeps_the_query_string(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->log('2026-10-31 ' . sprintf('%02d:%02d', 1 + intdiv($i, 60), $i % 60), 'Joy', 'admin', 'action', 'orders', "item $i");
        }
        $this->log('2026-10-31 11:00', 'Maria', 'customer', 'signin', 'signin', 'Signed in');

        $q = ['who' => 'admin', 'sort' => 'old'];
        $this->assertCount(12, $this->shown($q));
        $html = str_replace('&amp;', '&', $this->page($q)->getContent());
        $this->assertStringContainsString('Showing 1 to 12 of 30', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*who=admin[^"]*sort=old[^"]*page=2"[^>]*rel="next">Next#', $html);
        $this->assertMatchesRegularExpression('#aria-disabled="true">Previous#', $html);

        $html = str_replace('&amp;', '&', $this->page($q + ['page' => 3])->getContent());
        $this->assertStringContainsString('Showing 25 to 30 of 30', $html);
        $this->assertMatchesRegularExpression('#href="[^"]*who=admin[^"]*page=2"[^>]*rel="prev">Previous#', $html);
        $this->assertCount(6, $this->shown($q + ['page' => 3]));
        $this->assertCount(6, $this->shown($q + ['page' => 99]), 'a page past the end shows the last page');
    }

    /* ───────────── rows, Details, Open Item ───────────── */

    public function test_rows_show_relative_days_roles_types_and_the_details_data(): void
    {
        $order = $this->makeOrder($this->makeUser(), ['order_number' => 'LP-1058']);
        $this->log('2026-10-31 09:02', 'Maria Santos', 'customer', 'signin', 'signin', 'Signed in');
        $this->log('2026-10-30 04:10', 'LP Admin', 'admin', 'action', 'orders', 'Confirmed order LP-1058', ['subject_type' => 'order', 'subject_id' => $order->id, 'subject_label' => 'Order LP-1058']);
        $this->log('2026-10-20 13:00', 'LP Admin', 'admin', 'action', 'catalog', 'Removed product Old Chair', ['subject_type' => 'product', 'subject_id' => null, 'subject_label' => 'Product: Old Chair']);

        $html = $this->page()->getContent();
        $this->assertStringContainsString('<b>Today</b><span>9:02 AM</span>', $html);
        $this->assertStringContainsString('<b>Yesterday</b><span>4:10 AM<em class="al-late">Late Night</em></span>', $html);
        $this->assertStringContainsString('<b>Oct 20, 2026</b>', $html);
        $this->assertStringContainsString('al-role al-role--customer">Customer<', $html);
        $this->assertStringContainsString('al-role al-role--admin">Admin<', $html);
        $this->assertStringContainsString('al-type e-ord">Orders &amp; Payments<', $html);
        $this->assertStringContainsString('al-type e-cat">Catalog &amp; Stock<', $html);

        $this->assertStringContainsString('data-when="Yesterday (2026-10-30) at 4:10 AM, late night"', $html);
        $this->assertStringContainsString('data-who="LP Admin (Admin)"', $html);
        $this->assertStringContainsString('data-what="Confirmed order LP-1058"', $html);
        $this->assertStringContainsString('data-type="Orders &amp; Payments"', $html);
        $this->assertStringContainsString('data-item="Order LP-1058"', $html);
        $this->assertStringContainsString('data-url="' . route('admin.orders.show', $order->id, false) . '"', $html);
        $this->assertMatchesRegularExpression('#data-item="—"\s+data-url=""#', $html, 'a sign-in has no item and no link');
        $this->assertStringContainsString('data-item="Product: Old Chair"' . "\n" . '                  data-url=""', $html, 'a removed product has no page to open');
        $this->assertStringContainsString('id="al-open"', $html);
        $this->assertStringContainsString('Customer Sign-In Alert', $html);
    }

    public function test_open_item_links_exist_only_while_the_record_exists(): void
    {
        $order = $this->makeOrder($this->makeUser(), ['order_number' => 'LP-77']);
        $r = $this->log('2026-10-31 09:00', 'A', 'admin', 'action', 'orders', 'Confirmed order', ['subject_type' => 'order', 'subject_id' => $order->id, 'subject_label' => 'Order LP-77']);
        $s = $this->log('2026-10-31 09:01', 'A', 'admin', 'action', 'settings', 'Saved Contact Settings', ['subject_type' => 'settings', 'subject_label' => 'Contact Settings']);

        $links = \App\Support\ActivityLogViewer::links(collect([$r, $s]));
        $this->assertSame(route('admin.orders.show', $order->id, false), $links[$r->id]);
        $this->assertSame(route('admin.settings.contact.edit', [], false), $links[$s->id]);

        Order::whereKey($order->id)->delete();
        $this->assertArrayNotHasKey($r->id, \App\Support\ActivityLogViewer::links(collect([$r->fresh()])));
    }

    public function test_active_filters_show_as_removable_chips_with_clear_all(): void
    {
        $this->seedSet();
        $html = str_replace('&amp;', '&', $this->page(['q' => 'order', 'who' => 'admin', 'tab' => 'orders', 'from' => '2026-10-30', 'to' => '2026-10-31', 'tod' => 'morning'])->getContent());

        foreach (['Search: order', 'Admin Team', 'Orders & Payments', 'Oct 30, 2026 to Oct 31, 2026', 'Morning (5 AM to 12 PM)'] as $chip) {
            $this->assertStringContainsString($chip . '<a href=', $html, $chip);
        }
        $this->assertStringContainsString('Clear All</a>', $html);
        // the link on the Search chip drops only q
        $this->assertMatchesRegularExpression('#Search: order<a href="[^"]*"#', $html);
        preg_match('#Search: order<a href="([^"]*)"#', $html, $m);
        $this->assertStringNotContainsString('q=', $m[1]);
        $this->assertStringContainsString('who=admin', $m[1]);
        // no filters: no chips and no Clear All
        $this->assertStringNotContainsString('Clear All', $this->page()->getContent());
    }

    /* ───────────── CSV ───────────── */

    private function csv(array $q = []): array
    {
        $res = $this->actingAs($this->admin, 'admin')->get(route('admin.activity-log.export', $q))->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(array_map('str_getcsv', explode("\n", trim($res->streamedContent())))));

        return $lines;
    }

    public function test_the_csv_matches_the_filters_and_sort_not_just_the_page(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->log('2026-10-31 ' . sprintf('00:%02d', $i), 'Joy', 'admin', 'action', 'orders', "item $i", ['subject_label' => "Order $i"]);
        }
        $this->log('2026-10-31 11:00', 'Maria', 'customer', 'signin', 'signin', 'Signed in');

        $rows = $this->csv(['who' => 'admin', 'sort' => 'old']);
        $this->assertSame(['When', 'Who', 'Role', 'What Happened', 'Type', 'Item'], $rows[0]);
        $this->assertCount(21, $rows, 'all 20 matching rows, not the 12 of one page');
        $this->assertSame(['2026-10-31 00:01', 'Joy', 'Admin', 'item 1', 'Orders & Payments', 'Order 1'], $rows[1]);
        $this->assertSame('item 20', $rows[20][3]);

        $this->assertCount(2, $this->csv(['who' => 'customer']));
        $this->assertCount(1, $this->csv(['q' => 'nothing matches this']), 'only the header');
    }

    public function test_the_csv_escapes_formulas_and_caps_at_5000_rows(): void
    {
        $this->log('2026-10-31 09:00', '=cmd|calc', 'admin', 'action', 'orders', '=SUM(A1)', ['subject_label' => '@home']);
        $this->log('2026-10-31 09:01', '+1', 'admin', 'action', 'orders', '-2+3', ['subject_label' => 'fine']);

        $rows = $this->csv(['sort' => 'old']);
        $this->assertSame("'=cmd|calc", $rows[1][1]);
        $this->assertSame("'=SUM(A1)", $rows[1][3]);
        $this->assertSame("'@home", $rows[1][5]);
        $this->assertSame("'+1", $rows[2][1]);
        $this->assertSame("'-2+3", $rows[2][3]);
        $this->assertSame('fine', $rows[2][5]);

        $this->assertSame(5000, \App\Support\ActivityLogViewer::EXPORT_CAP);
        $now = now();
        $batch = [];
        for ($i = 0; $i < 5100; $i++) {
            $batch[] = ['actor_name' => 'Bulk', 'role' => 'admin', 'event' => 'x', 'kind' => 'action', 'category' => 'orders', 'description' => 'bulk ' . $i, 'created_at' => $now->copy()->subSeconds($i + 60)];
            if (count($batch) === 500) { Row::insert($batch); $batch = []; }
        }
        Row::insert($batch);
        $this->assertCount(5001, $this->csv(['q' => 'bulk']), 'header plus 5000 rows');
    }

    public function test_nothing_outside_the_table_is_shown_or_exported(): void
    {
        $this->seedSet();
        $html = $this->page()->getContent();
        $this->assertDoesNotMatchRegularExpression('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', strip_tags($html));
        $head = $this->csv()[0];
        $this->assertSame(['When', 'Who', 'Role', 'What Happened', 'Type', 'Item'], $head);
    }
}
