<?php

namespace Tests\Feature;

use App\Models\AiDesignSuggestion;
use App\Models\User;
use App\Models\UserNameChange;
use App\Models\UserValidation;
use App\Support\AdminCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Admin Customers list and Customer profile (docs/admin/admin-menu-v1.html). Sorting and filtering are computed on the
 * server from one grouped query; "Total Spent" is the money received on orders that are NOT cancelled (same as Reports).
 */
class AdminCustomersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeUser('admin');
    }

    /** @param array<string,mixed> $o */
    private function customer(string $first, string $last, array $o = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first . '.' . $last) . '@example.test',
            'password' => 'not-a-real-password', 'role' => 'customer', 'is_validated' => false, 'account_status' => 'active',
            'phone_number' => null,
        ], $o))->save();

        return $u;
    }

    private function paid(User $u, float $total, float $paid, string $status = 'confirmed'): void
    {
        $this->makeOrder($u, ['total_amount' => $total, 'order_status' => $status])->forceFill(['amount_paid' => $paid])->save();
    }

    /**
     * Ana (verified, 2 orders, 5,000 received), Ben (unverified, 0 orders), Cara (verified, 11 orders, 100 each),
     * Dino (unverified, 1 order only cancelled: 9,000 received but cancelled), Eli (old account, 1 order 30,000).
     *
     * @return array<string,User>
     */
    private function seedSet(): array
    {
        $ana = $this->customer('Ana', 'Zamora', ['is_validated' => true, 'created_at' => now()->subDays(3), 'phone_number' => '09171110001']);
        $ben = $this->customer('Ben', 'Yap', ['created_at' => now()->subDays(20), 'phone_number' => '09172220002']);
        $cara = $this->customer('Cara', 'Xavier', ['is_validated' => true, 'created_at' => now()->subDays(100), 'phone_number' => '09173330003']);
        $dino = $this->customer('Dino', 'Walker', ['created_at' => now()->subDays(1), 'phone_number' => '09174440004']);
        $eli = $this->customer('Eli', 'Vega', ['created_at' => now()->subYears(2), 'is_validated' => true]);

        $this->paid($ana, 3000, 3000, 'completed');
        $this->paid($ana, 4000, 2000);
        for ($i = 0; $i < 11; $i++) {
            $this->paid($cara, 100, 100);
        }
        $this->paid($dino, 9000, 9000, 'cancelled');
        $this->paid($eli, 30000, 30000, 'completed');

        return compact('ana', 'ben', 'cara', 'dino', 'eli');
    }

    /** @return list<string> first names, in the order the list shows them */
    private function names(array $q = []): array
    {
        $res = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', $q))->assertOk();
        preg_match_all('/<tr class="cu-row">\s*<td class="cu-name"><strong>(\S+) /', $res->getContent(), $m);

        return $m[1];
    }

    // ───────────── access ─────────────

    public function test_only_an_admin_reaches_the_pages_and_only_customers_have_profiles(): void
    {
        $c = $this->customer('Solo', 'Customer');
        $staff = $this->makeUser('staff');

        $this->get(route('admin.customers.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.customers.show', $c->id))->assertRedirect(route('admin.login'));
        $this->actingAs($c)->get(route('admin.customers.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($c, 'admin')->get(route('admin.customers.show', $c->id))->assertRedirect(route('admin.login'));

        $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $staff->id))->assertNotFound();
        $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', 999999))->assertNotFound();
        $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $c->id))->assertOk();
    }

    public function test_the_pages_have_no_write_actions(): void
    {
        $c = $this->customer('Solo', 'Customer');
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $c->id))->getContent();
        $list = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index'))->getContent();
        // the page body (after the shared top bar and menu) has no form that changes anything
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*method="POST"/i', substr($html, (int) strpos($html, 'class="admin-content"')));
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*method="POST"/i', substr($list, (int) strpos($list, 'class="admin-content"')));
        $this->actingAs($this->admin, 'admin')->post('/admin/customers')->assertStatus(405);
        $this->actingAs($this->admin, 'admin')->delete(route('admin.customers.show', $c->id))->assertStatus(405);
    }

    // ───────────── list: markup, stats, search ─────────────

    public function test_the_list_has_the_prototype_controls_and_nothing_old(): void
    {
        $this->seedSet();
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('admin-customers.css', $html);
        $this->assertStringContainsString('class="cu-stat', $html);
        $this->assertStringContainsString('Search name, email or phone', $html);
        $this->assertStringContainsString('data-lp-modal-open="cu-filters"', $html);
        $this->assertStringContainsString('id="cu-filters"', $html);
        $this->assertStringContainsString('Showing 5 of 5 customers', $html);
        foreach (AdminCustomers::SORTS as $label) {
            $this->assertStringContainsString('>' . $label . '</option>', $html);
        }
        $this->assertStringNotContainsString('admin-filter-tab', $html);
        $this->assertStringNotContainsString('Search</button>', preg_replace('~<noscript>.*?</noscript>~s', '', $html));
    }

    public function test_stat_cards_count_all_customers_and_link_to_the_status_filter(): void
    {
        $this->seedSet();
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index'))->getContent();
        $this->assertMatchesRegularExpression('~<b>5</b><span>Total Customers~', $html);
        $this->assertMatchesRegularExpression('~<b>3</b><span>Verified~', $html);
        $this->assertMatchesRegularExpression('~<b>2</b><span>Unverified~', $html);
        $this->assertStringContainsString('status=verified', $html);
        $this->assertStringContainsString('status=unverified', $html);

        // the cards keep counting everyone while a filter is on, and the active one is marked
        $on = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['status' => 'verified']))->getContent();
        $this->assertMatchesRegularExpression('~<b>5</b><span>Total Customers~', $on);
        $this->assertMatchesRegularExpression('~cu-stat--v is-on"\s+aria-current~', $on);
    }

    public function test_search_finds_name_email_and_phone(): void
    {
        $this->seedSet();
        $this->assertSame(['Ana'], $this->names(['search' => 'Zamora']));
        $this->assertSame(['Ana'], $this->names(['search' => 'ana zam']));        // full name
        $this->assertSame(['Cara'], $this->names(['search' => 'cara.xavier@']));  // email
        $this->assertSame(['Ben'], $this->names(['search' => '0917 222 0002']));  // phone typed with spaces
        $this->assertSame(['Ben'], $this->names(['search' => '09172220002']));
        $this->assertSame([], $this->names(['search' => 'nobody-by-this-name']));
        $this->assertSame([], $this->names(['search' => '%']), 'a % typed in the box is text, not a wildcard');
        $this->assertSame([], $this->names(['search' => '_']));
    }

    // ───────────── list: filters ─────────────

    public function test_status_orders_and_joined_filters(): void
    {
        $this->seedSet();
        $this->assertEqualsCanonicalizing(['Ana', 'Cara', 'Eli'], $this->names(['status' => 'verified']));
        $this->assertEqualsCanonicalizing(['Ben', 'Dino'], $this->names(['status' => 'unverified']));
        $this->assertEqualsCanonicalizing(['Ana', 'Cara', 'Eli'], $this->names(['filter' => 'verified']), 'the old ?filter= link still works');

        $this->assertSame(['Ben'], $this->names(['orders' => 'none']));
        $this->assertEqualsCanonicalizing(['Ana', 'Cara', 'Dino', 'Eli'], $this->names(['orders' => '1']));
        $this->assertSame(['Cara'], $this->names(['orders' => '10']));

        $this->assertEqualsCanonicalizing(['Ana', 'Dino'], $this->names(['joined' => '7']));
        $this->assertEqualsCanonicalizing(['Ana', 'Ben', 'Dino'], $this->names(['joined' => '30']));
        $this->assertEqualsCanonicalizing(['Ana', 'Ben', 'Cara', 'Dino'], $this->names(['joined' => 'year']));
    }

    public function test_filters_combine_and_unknown_values_fall_back_to_the_defaults(): void
    {
        $this->seedSet();
        $this->assertSame(['Cara'], $this->names(['status' => 'verified', 'orders' => '10']));
        $this->assertSame(['Ana'], $this->names(['status' => 'verified', 'joined' => '7', 'orders' => '1']));
        $this->assertCount(5, $this->names(['status' => 'hacked', 'orders' => 'x', 'joined' => '9999', 'sort' => 'password-asc']));
    }

    public function test_cancelled_orders_do_not_count_toward_total_spent(): void
    {
        ['dino' => $dino] = $this->seedSet();
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['search' => 'Dino']))->getContent();
        $this->assertStringContainsString('₱0.00', $html);
        $this->assertStringNotContainsString('9,000.00', $html);
        $this->assertStringContainsString('data-label="Orders">1<', $html, 'the cancelled order still counts as an order');

        // and spent is money received, not order value: Ana's second order is 4,000 with 2,000 received
        $ana = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['search' => 'Zamora']))->getContent();
        $this->assertStringContainsString('₱5,000.00', $ana);

        // the spent filter agrees with what the column shows
        $this->assertNotContains('Dino', $this->names(['min' => 1]));
        $this->assertContains('Dino', $this->names(['max' => 0]));
        $this->assertSame(0.0, (float) AdminCustomers::listQuery(AdminCustomers::filtersFrom(['search' => 'Dino']))->first()->total_spent);
        $this->assertSame($dino->id, AdminCustomers::listQuery(AdminCustomers::filtersFrom(['search' => 'Dino']))->first()->id);
    }

    public function test_total_spent_range(): void
    {
        $this->seedSet();
        $this->assertEqualsCanonicalizing(['Ana', 'Eli'], $this->names(['min' => '5000']));
        $this->assertSame(['Eli'], $this->names(['min' => '10,000']));
        $this->assertEqualsCanonicalizing(['Ana', 'Ben', 'Cara', 'Dino'], $this->names(['max' => '5000']));
        $this->assertEqualsCanonicalizing(['Ana', 'Cara'], $this->names(['min' => '1000', 'max' => '5000']));
        $this->assertSame(['Ana'], $this->names(['min' => '5000', 'max' => '5000']), 'equal bounds are allowed');
    }

    public function test_a_minimum_above_the_maximum_applies_nothing_and_shows_the_message(): void
    {
        $this->seedSet();
        $res = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['min' => '9000', 'max' => '100']))->assertOk();
        $html = $res->getContent();
        $this->assertCount(5, $this->names(['min' => '9000', 'max' => '100']));
        $this->assertStringContainsString('data-range-error="1"', $html);
        $this->assertMatchesRegularExpression('~<p class="cu-ferr" id="cu-ferr" role="alert"\s*>~', $html);
        $this->assertStringContainsString('The minimum can&#039;t be higher than the maximum.', str_replace("can't", 'can&#039;t', $html));
        $this->assertStringNotContainsString('class="cu-chip"', $html, 'no spent chip while the range is rejected');

        $fine = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['min' => '100', 'max' => '9000']))->getContent();
        $this->assertStringNotContainsString('data-range-error="1"', $fine);
        $this->assertMatchesRegularExpression('~<p class="cu-ferr" id="cu-ferr" role="alert"\s+hidden\s*>~', $fine);
    }

    public function test_chips_badge_count_and_clear_all(): void
    {
        $this->seedSet();
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['status' => 'verified', 'orders' => '1', 'min' => '1000', 'max' => '40000']))->getContent();
        $this->assertSame(4 - 1, substr_count($html, 'class="cu-chip"') - 0, 'status, orders, spent');
        $this->assertStringContainsString('Verified<a href=', $html);
        $this->assertStringContainsString('1 or More Orders', $html);
        $this->assertStringContainsString('Spent ₱1,000 to ₱40,000', $html);
        $this->assertMatchesRegularExpression('~<span class="cu-badge" id="cu-badge" >3</span>~', $html);
        $this->assertStringContainsString('Clear All', $html);
        $this->assertStringContainsString('Showing 3 of 5 customers', $html);

        $one = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['status' => 'verified']))->getContent();
        $this->assertStringNotContainsString('class="cu-clear"', $one, 'Clear All appears with two or more chips');
    }

    // ───────────── list: sort ─────────────

    public function test_all_eight_sorts_and_the_default(): void
    {
        $this->seedSet();
        $this->assertSame(['Dino', 'Ana', 'Ben', 'Cara', 'Eli'], $this->names(), 'default is newest joined');
        $this->assertSame(['Dino', 'Ana', 'Ben', 'Cara', 'Eli'], $this->names(['sort' => 'joined-desc']));
        $this->assertSame(['Eli', 'Cara', 'Ben', 'Ana', 'Dino'], $this->names(['sort' => 'joined-asc']));
        $this->assertSame(['Ana', 'Ben', 'Cara', 'Dino', 'Eli'], $this->names(['sort' => 'name-asc']));
        $this->assertSame(['Eli', 'Dino', 'Cara', 'Ben', 'Ana'], $this->names(['sort' => 'name-desc']));
        $this->assertSame('Cara', $this->names(['sort' => 'orders-desc'])[0]);
        $this->assertSame('Ben', $this->names(['sort' => 'orders-asc'])[0]);
        $this->assertSame(['Eli', 'Ana', 'Cara'], array_slice($this->names(['sort' => 'spent-desc']), 0, 3));
        $this->assertSame('Eli', array_slice($this->names(['sort' => 'spent-asc']), -1)[0]);
    }

    public function test_column_headers_sort_and_flip_direction(): void
    {
        $this->assertSame('name-asc', AdminCustomers::headerSort('joined-desc', 'name'));
        $this->assertSame('name-desc', AdminCustomers::headerSort('name-asc', 'name'));
        $this->assertSame('name-asc', AdminCustomers::headerSort('name-desc', 'name'));
        $this->assertSame('orders-desc', AdminCustomers::headerSort('name-asc', 'orders'));
        $this->assertSame('spent-asc', AdminCustomers::headerSort('spent-desc', 'spent'));
        $this->assertSame('joined-asc', AdminCustomers::headerSort('joined-desc', 'joined'));

        $this->seedSet();
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['sort' => 'spent-desc']))->getContent();
        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringContainsString('sort=spent-asc', $html);
    }

    // ───────────── list: URL state, pagination, empty ─────────────

    public function test_pagination_and_links_keep_the_view(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->customer('Pat' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Page', ['is_validated' => true]);
        }
        $q = ['status' => 'verified', 'sort' => 'name-asc'];
        $p1 = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', $q))->getContent();
        $this->assertStringContainsString('Showing 1 to 15 of 20 matching customers (20 in total)', $p1);
        $this->assertStringContainsString('page=2', $p1);
        $this->assertMatchesRegularExpression('~page=2[^"]*"~', $p1);
        $this->assertStringContainsString('sort=name-asc', $p1);

        $p2 = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', $q + ['page' => 2]))->getContent();
        preg_match_all('~<td class="cu-name"><strong>(\S+) ~', $p2, $m);
        $this->assertSame(['Pat16', 'Pat17', 'Pat18', 'Pat19', 'Pat20'], $m[1]);
        // View carries the list state (and the page) so Back can restore it
        $this->assertMatchesRegularExpression('~href="[^"]*customers/\d+\?[^"]*status=verified[^"]*"~', $p2);
        $this->assertMatchesRegularExpression('~href="[^"]*customers/\d+\?[^"]*page=2~', $p2);
    }

    public function test_empty_states(): void
    {
        $none = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index'))->getContent();
        $this->assertStringContainsString('No customers yet', $none);
        $this->assertStringContainsString('Showing 0 of 0 customers', $none);

        $this->seedSet();
        $miss = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.index', ['search' => 'zzzz-no-one']))->getContent();
        $this->assertStringContainsString('No customers match', $miss);
        $this->assertStringContainsString('Clear Filters', $miss);
        $this->assertStringContainsString('Showing 0 of 5 customers', $miss);
    }

    public function test_the_list_runs_a_fixed_number_of_queries(): void
    {
        $this->seedSet();
        for ($i = 0; $i < 10; $i++) {
            $this->customer('Extra' . $i, 'Row');
        }
        $this->actingAs($this->admin, 'admin');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.customers.index', ['sort' => 'spent-desc']))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        // a handful for the session, the admin, the page, its total and the stat cards: none per customer row
        $this->assertLessThan(12, $count, $count . ' queries for 15 customers');
    }

    // ───────────── profile ─────────────

    public function test_profile_header_tiles_and_back_link_keep_the_list_state(): void
    {
        ['ana' => $ana] = $this->seedSet();
        $url = route('admin.customers.show', ['id' => $ana->id, 'status' => 'verified', 'sort' => 'name-asc', 'search' => 'an', 'page' => 2, 'junk' => 'x', 'min' => '9', 'max' => '1']);
        $html = $this->actingAs($this->admin, 'admin')->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Back to Customers', $html);
        $back = html_entity_decode(preg_replace('~^.*?<a href="([^"]+)"[^>]*>Back to Customers.*$~s', '$1', $html));
        $this->assertStringStartsWith(route('admin.customers.index'), $back);
        parse_str((string) parse_url($back, PHP_URL_QUERY), $q);
        $this->assertSame(['search' => 'an', 'status' => 'verified', 'sort' => 'name-asc', 'page' => '2'], $q, 'known keys only; a rejected range is dropped');

        $this->assertMatchesRegularExpression('~<b>2</b><span>Total Orders~', $html);
        $this->assertMatchesRegularExpression('~<b>₱5,000\.00</b><span>Total Spent~', $html);
        $this->assertMatchesRegularExpression('~<b>0</b><span>Designs Made~', $html);
        $this->assertMatchesRegularExpression('~<b>₱2,500\.00</b><span>Average Order~', $html);
        $this->assertStringContainsString('cp-av', $html);
        $this->assertStringContainsString('>AZ<', $html);
        $this->assertStringContainsString('Last activity', $html);
    }

    public function test_profile_orders_tab_links_to_the_real_order_pages(): void
    {
        ['ana' => $ana, 'dino' => $dino] = $this->seedSet();
        $order = \App\Models\Order::where('user_id', $ana->id)->orderBy('id')->first();
        \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'product_name' => 'Narra Dining Table', 'quantity' => 1, 'unit_price' => 3000, 'subtotal' => 3000]);

        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $ana->id))->getContent();
        $this->assertStringContainsString('href="' . route('admin.orders.show', $order->id) . '"', $html);
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Narra Dining Table', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('₱3,000.00', $html);
        $this->assertStringContainsString('cp-pst is-paid">Paid', $html);
        $this->assertStringContainsString('cp-pst is-partial">Partial', $html);
        $this->assertStringContainsString('₱2,000.00 received', $html);
        $this->assertStringContainsString('Showing the latest 2 of 2 orders.', $html);

        $cancelled = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $dino->id))->getContent();
        $this->assertStringContainsString('cp-pst is-cancelled">Cancelled', $cancelled);
        $this->assertMatchesRegularExpression('~<b>₱0\.00</b><span>Total Spent~', $cancelled);
        $this->assertMatchesRegularExpression('~<b>₱0\.00</b><span>Average Order~', $cancelled);
    }

    public function test_profile_designs_tab(): void
    {
        ['ana' => $ana] = $this->seedSet();
        $legacy = $this->makeRequest($ana);
        $legacy->forceFill(['status' => 'approved', 'title' => 'Legacy Bench'])->save();
        $piped = $this->makeRequest($ana);
        $piped->forceFill(['title' => 'Piped Cabinet', 'status' => 'approved', 'pipeline_status' => 'quoted'])->save();
        $draft = $this->makeRequest($ana);
        $draft->forceFill(['title' => 'Draft Shelf', 'status' => 'pending'])->save();
        foreach ([$piped, $piped] as $r) {
            AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => 'images/ai-designs/missing.jpg', 'ai_model_used' => 't', 'prompt_used' => 't', 'generated_at' => now()]);
        }

        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', ['id' => $ana->id, 'tab' => 'designs']))->getContent();
        $this->assertMatchesRegularExpression('~<b>3</b><span>Designs Made~', $html);
        $this->assertStringContainsString('href="' . route('admin.custom-orders.show', $piped->id) . '"', $html);
        $this->assertStringContainsString('href="' . route('admin.design-requests.show', $legacy->id) . '"', $html);
        $this->assertStringNotContainsString(route('admin.design-requests.show', $draft->id), $html, 'a request with no admin page is not linked');
        $this->assertStringContainsString('Quoted', $html);
        $this->assertStringContainsString('2 pictures', $html);
        $this->assertMatchesRegularExpression('~id="cp-pane-designs"[^>]*role="tabpanel"[^>]*aria-labelledby="cp-tab-designs"\s*>~', $html, 'the asked-for tab is the open one');
        $this->assertMatchesRegularExpression('~id="cp-pane-orders"[^>]*hidden~', $html);
    }

    public function test_profile_activity_tab_comes_from_existing_records_and_never_shows_chat_words(): void
    {
        ['ana' => $ana] = $this->seedSet();
        DB::table('chatbot_logs')->insert([
            ['user_id' => $ana->id, 'session_id' => 's', 'message' => 'my secret sofa question', 'response' => 'ok', 'intent_detected' => 'x', 'created_at' => now()->subHour()],
            ['user_id' => $ana->id, 'session_id' => 's', 'message' => 'another private one', 'response' => 'ok', 'intent_detected' => 'x', 'created_at' => now()->subMinutes(50)],
        ]);
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', ['id' => $ana->id, 'tab' => 'activity']))->getContent();

        $this->assertStringContainsString('Created an account', $html);
        $this->assertStringContainsString('Placed order', $html);
        $this->assertStringContainsString('Asked Luma 2 questions', $html);
        $this->assertStringNotContainsString('secret sofa', $html);
        $this->assertStringNotContainsString('private one', $html);
        $this->assertStringContainsString('Sign-ins are not recorded', $html);
        $this->assertStringNotContainsString('Logged in', $html);
    }

    public function test_profile_name_changes_tab(): void
    {
        $c = $this->customer('Neo', 'New', ['is_validated' => true]);
        UserNameChange::create(['user_id' => $c->id, 'old_first_name' => 'Old', 'old_last_name' => 'Name', 'new_first_name' => 'Neo', 'new_last_name' => 'New', 'was_verified' => true]);
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $c->id))->getContent();
        $this->assertStringContainsString('Old Name', $html);
        $this->assertStringContainsString('Neo New', $html);
        $this->assertStringContainsString('Changing a name does not reset identity verification', $html);
        $this->assertStringContainsString('Changed name to Neo New', $html, 'also on the activity timeline');
        $this->assertMatchesRegularExpression('~data-tab="names"[^>]*>Name Changes<i>1</i>~', $html);
    }

    public function test_profile_empty_states_for_a_customer_with_nothing(): void
    {
        $c = $this->customer('Fresh', 'Face');
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $c->id))->assertOk()->getContent();
        $this->assertStringContainsString('No orders yet', $html);
        $this->assertStringContainsString('No designs yet', $html);
        $this->assertStringContainsString('No name changes', $html);
        $this->assertStringContainsString('Created an account', $html, 'the activity tab always has the account line');
        $this->assertStringContainsString('No phone on file', $html);
        $this->assertStringContainsString('Not provided yet', $html);
        $this->assertStringContainsString('No address saved', $html);
        $this->assertStringContainsString('Not Submitted', $html);
        $this->assertMatchesRegularExpression('~<b>₱0\.00</b><span>Average Order~', $html);
        $this->assertStringNotContainsString('Review Identity Validation', $html);
    }

    public function test_contact_card_location_comes_from_the_saved_address_city(): void
    {
        $c = $this->makeUser('customer');   // has a complete saved address in Lipa City, Batangas
        $html = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $c->id))->getContent();
        $this->assertStringContainsString('Lipa City, Batangas', $html);
        $this->assertStringContainsString('Blk 5 Lot 12, Sampaguita St.', $html);
        $this->assertStringContainsString('4217', $html);

        $legacy = $this->customer('Old', 'Address', ['address' => '12 Rizal Ave, Batangas City']);
        $h2 = $this->actingAs($this->admin, 'admin')->get(route('admin.customers.show', $legacy->id))->getContent();
        $this->assertStringContainsString('12 Rizal Ave, Batangas City', $h2);
        $this->assertStringContainsString('Not provided yet', $h2, 'no city, no location: nothing is guessed');
    }

    public function test_review_identity_button_only_for_a_pending_identity(): void
    {
        $this->actingAs($this->admin, 'admin');
        $none = $this->customer('No', 'Submission');
        $pending = $this->customer('Wait', 'Ing');
        $rejected = $this->customer('Re', 'Jected');
        $verified = $this->customer('Ver', 'Ified', ['is_validated' => true]);
        $mk = fn (User $u, string $status, array $o = []) => UserValidation::create(array_merge([
            'user_id' => $u->id, 'attempt_number' => 1, 'id_photo_path' => 'x', 'selfie_photo_path' => 'y', 'status' => $status, 'submitted_at' => now()->subDay(),
        ], $o));
        $v = $mk($pending, 'pending');
        $mk($rejected, 'rejected', ['reviewed_at' => now()->subHours(3), 'rejection_reason' => 'blurry']);
        $mk($verified, 'approved', ['reviewed_at' => now()->subHours(2)]);

        $page = fn (User $u) => $this->get(route('admin.customers.show', $u->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Review Identity Validation', $page($none));
        $this->assertStringNotContainsString('Review Identity Validation', $page($rejected));
        $this->assertStringNotContainsString('Review Identity Validation', $page($verified));

        $h = $page($pending);
        $this->assertStringContainsString('Review Identity Validation', $h);
        $this->assertStringContainsString('href="' . route('admin.validations.show', $v->id) . '"', $h);
        $this->assertStringContainsString('Pending Review', $h);
        $this->assertStringContainsString('Rejected', $page($rejected));
        $this->assertStringContainsString('Verified', $page($verified));
    }

    // ───────────── guardrails ─────────────

    public function test_customer_pages_do_not_load_the_customer_admin_assets(): void
    {
        $c = $this->makeUser('customer');
        foreach (['/', '/catalog'] as $url) {
            $html = $this->actingAs($c)->get($url)->getContent();
            $this->assertStringNotContainsString('admin-customers', $html, $url);
            $this->assertStringNotContainsString('admin-type.css', $html, $url);
        }
    }

    public function test_the_new_views_use_shared_buttons_without_arrows_or_inline_styles(): void
    {
        foreach (['index', 'show'] as $v) {
            $src = file_get_contents(base_path('resources/views/admin/customers/' . $v . '.blade.php'));
            $this->assertDoesNotMatchRegularExpression('/(←|→|«|»|&larr;|&rarr;)/u', $src, $v);
            $this->assertStringNotContainsString('admin-btn', $src, $v);
            $this->assertStringNotContainsString('style="', $src, $v);
        }
    }
}
