<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Customer navbar (My Orders, icons), the notification dropdown feed, the has-details rule and the full page filters.
 * The look and the dropdown behaviour are checked in the browser walk (tests/UiLinks/browser-notif-nav.cjs).
 */
class NotificationsNavTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function note(User $u, array $over = []): Notification
    {
        $created = $over['created_at'] ?? null;
        unset($over['created_at']);
        $n = Notification::create($over + [
            'user_id' => $u->id, 'title' => 'Order LP-1: In Production', 'message' => 'Started.',
            'type' => 'order_update', 'url' => route('orders.show', 1), 'is_read' => false,
        ]);
        if ($created) {
            $n->forceFill(['created_at' => $created])->save();
        }

        return $n->fresh();
    }

    // ── the has-details rule: one place, every notification type ──

    public static function kinds(): array
    {
        return [
            'order stage'                 => ['order_update', '/orders/12', 'order', true],
            'payment confirmed'           => ['payment_confirmed', '/orders/12', 'order', true],
            'payment rejected'            => ['payment_rejected', '/orders/12', 'order', true],
            'delivery update'             => ['delivery_update', '/orders/12', 'order', true],
            'installment due'             => ['installment_due', '/orders/12', 'order', true],
            'custom order on its order'   => ['custom_order', '/orders/12', 'order', true],
            'custom order agreement'      => ['custom_order', '/agreements/3', 'service', true],
            'custom order checkout'       => ['custom_order', '/custom-orders/3/checkout', 'service', true],
            'design ready'                => ['design_ready', '/design/9', 'service', true],
            'design reviewed'             => ['general', '/design/9', 'service', true],
            'design failed'               => ['general', '/design', 'service', true],
            'invoice'                     => ['general', '/services/invoices/4', 'service', true],
            'refund'                      => ['general', '/services/refunds/REF-2026-0001', 'service', true],
            'service ticket'              => ['general', '/services/request', 'service', true],
            'identity verified'           => ['validation_approved', '/', 'account', false],
            'identity verified (any url)' => ['validation_approved', '/orders/12', 'account', false],
            'identity rejected'           => ['validation_rejected', '/validate-account', 'account', false],
            'new ID photos requested'     => ['validation_rejected', '/validate-account', 'account', false],
            'password changed'            => ['general', '/profile', 'account', false],
            'contact details changed'     => ['general', '/profile', 'account', false],
            'welcome, no link'            => ['general', null, 'account', false],
            'welcome, home link'          => ['general', '/', 'account', false],
            'admin link never details'    => ['general', '/admin/orders', 'account', false],
        ];
    }

    /** @dataProvider kinds */
    public function test_has_details_rule(string $type, ?string $url, string $kind, bool $details): void
    {
        $n = new Notification(['type' => $type, 'url' => $url === null ? null : 'http://localhost' . $url, 'title' => 'x', 'message' => 'y']);
        $this->assertSame($kind, NotificationKind::for($n));
        $this->assertSame($details, NotificationKind::hasDetails($n));
        $this->assertSame($details, $n->hasDetails());
        $this->assertSame($details, NotificationKind::detailsUrl($n) !== null);
    }

    public function test_details_url_is_same_site_and_relative(): void
    {
        $n = new Notification(['type' => 'order_update', 'url' => 'https://evil.example/orders/5?x=1', 'title' => 'x', 'message' => 'y']);
        $this->assertSame('/orders/5?x=1', NotificationKind::detailsUrl($n));
    }

    // ── navbar ──

    public function test_my_orders_link_is_in_the_nav_after_assistant_before_cart(): void
    {
        $u = $this->makeUser();
        $html = $this->actingAs($u)->get(route('catalog'))->assertOk()->getContent();
        $nav = substr($html, strpos($html, 'id="sn-links"'));
        $nav = substr($nav, 0, strpos($nav, '</nav>'));

        $this->assertSame(1, substr_count($nav, 'href="' . route('orders.index') . '"'), 'one My Orders link serves desktop and the phone menu');
        $this->assertStringContainsString('My Orders', $nav);
        $assistant = strpos($nav, 'Assistant');
        $orders    = strpos($nav, 'My Orders');
        $cart      = strpos($nav, 'Cart');
        $this->assertTrue($assistant < $orders && $orders < $cart, 'order: Assistant, My Orders, Cart');
        $this->assertStringContainsString('id="sn-bell"', $nav);
        $this->assertStringContainsString('aria-controls="notif-dd"', $nav);
        $this->assertStringContainsString('aria-expanded="false"', $nav);
    }

    public function test_bell_dot_follows_unread(): void
    {
        $u = $this->makeUser();
        $html = $this->actingAs($u)->get(route('catalog'))->getContent();
        $this->assertMatchesRegularExpression('/id="sn-bell-dot"[^>]*\shidden\s*>/', $html);

        $this->note($u);
        $html = $this->actingAs($u)->get(route('catalog'))->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="sn-bell-dot"[^>]*\shidden\s*>/', $html);
    }

    public function test_guests_have_no_dropdown_and_no_my_orders(): void
    {
        $html = $this->get(route('catalog'))->assertOk()->getContent();
        $this->assertStringNotContainsString('notif-dd', $html);
        $this->assertStringNotContainsString('My Orders', $html);
    }

    // ── feed ──

    public function test_feed_requires_login(): void
    {
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
        $this->get(route('notifications.feed'))->assertRedirect();
    }

    public function test_feed_returns_only_own_notifications_newest_first_and_caps_at_twenty(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->note($b, ['title' => 'Not yours']);
        foreach (range(1, 22) as $i) {
            $this->note($a, ['title' => "Mine $i", 'created_at' => now()->subMinutes(30 - $i)]);
        }
        $json = $this->actingAs($a)->getJson(route('notifications.feed'))->assertOk()->json();

        $this->assertCount(20, $json['items']);
        $this->assertSame('Mine 22', $json['items'][0]['title']);
        $this->assertSame(22, $json['unread']);
        $this->assertNotContains('Not yours', array_column($json['items'], 'title'));
        $this->assertSame(['id', 'title', 'message', 'time', 'iso', 'read', 'kind', 'details_url'], array_keys($json['items'][0]));
    }

    public function test_feed_filters_unread_and_dates(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => 'Old read', 'is_read' => true, 'created_at' => '2026-01-10 10:00:00']);
        $this->note($u, ['title' => 'Old unread', 'created_at' => '2026-01-20 10:00:00']);
        $this->note($u, ['title' => 'Feb unread', 'created_at' => '2026-02-28 23:30:00']);
        $this->note($u, ['title' => 'Mar read', 'is_read' => true, 'created_at' => '2026-03-02 08:00:00']);
        $titles = fn (string $q) => array_column($this->actingAs($u)->getJson(route('notifications.feed') . $q)->assertOk()->json('items'), 'title');

        $this->assertEqualsCanonicalizing(['Old unread', 'Feb unread'], $titles('?unread=1'));
        $this->assertEqualsCanonicalizing(['Old unread', 'Feb unread'], $titles('?from=2026-01-15&to=2026-02-28'));
        $this->assertSame(['Old read'], $titles('?to=2026-01-10'));
        $this->assertEqualsCanonicalizing(['Mar read'], $titles('?from=2026-03-01'));
        $this->assertSame(['Old unread'], $titles('?unread=1&from=2026-01-15&to=2026-01-31'));
    }

    public function test_feed_ignores_invalid_dates(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => 'One']);
        foreach (['?from=nonsense', '?to=2026-13-45', '?from[]=1', '?from=2026-02-30', '?from=01/02/2026'] as $q) {
            $this->actingAs($u)->getJson(route('notifications.feed') . $q)->assertOk()->assertJsonCount(1, 'items');
        }
    }

    public function test_feed_text_is_data_not_markup(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => '<script>alert(1)</script>', 'message' => '<img src=x onerror=alert(2)> & "q"']);
        $res = $this->actingAs($u)->getJson(route('notifications.feed'))->assertOk();
        $raw = $res->getContent();

        $this->assertStringNotContainsString('<script>', $raw);
        $this->assertStringNotContainsString('<img', $raw);
        $this->assertSame('<script>alert(1)</script>', $res->json('items.0.title'), 'decoded text is untouched; the page inserts it with textContent');
        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_feed_kind_and_details_url_follow_the_rule(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => 'Identity Verified ✓', 'type' => 'validation_approved', 'url' => route('home')]);
        $this->note($u, ['title' => 'Order stage', 'url' => route('orders.show', 7)]);
        $items = collect($this->actingAs($u)->getJson(route('notifications.feed'))->json('items'))->keyBy('title');

        $this->assertSame('account', $items['Identity Verified ✓']['kind']);
        $this->assertNull($items['Identity Verified ✓']['details_url']);
        $this->assertSame('order', $items['Order stage']['kind']);
        $this->assertSame('/orders/7', $items['Order stage']['details_url']);
    }

    // ── mark read ──

    public function test_mark_read_is_owner_only_and_json_aware(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $mine = $this->note($a);
        $theirs = $this->note($b);

        $this->actingAs($a)->patchJson(route('notifications.read', $theirs->id))->assertNotFound();
        $this->assertFalse($theirs->fresh()->is_read);

        $this->actingAs($a)->patchJson(route('notifications.read', $mine->id))->assertOk()->assertExactJson(['ok' => true, 'unread' => 0]);
        $this->assertTrue($mine->fresh()->is_read);

        $plain = $this->note($a);
        $this->actingAs($a)->from(route('notifications.index'))->patch(route('notifications.read', $plain->id))->assertRedirect(route('notifications.index'));
        $this->assertTrue($plain->fresh()->is_read);
    }

    public function test_mark_all_read_touches_only_own_rows(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $this->note($a);
        $this->note($a);
        $theirs = $this->note($b);

        $this->actingAs($a)->patchJson(route('notifications.readAll'))->assertOk()->assertExactJson(['ok' => true, 'unread' => 0]);
        $this->assertSame(0, Notification::where('user_id', $a->id)->where('is_read', false)->count());
        $this->assertFalse($theirs->fresh()->is_read);
    }

    public function test_marking_read_needs_login(): void
    {
        $u = $this->makeUser();
        $n = $this->note($u);
        $this->patchJson(route('notifications.readAll'))->assertUnauthorized();
        $this->patchJson(route('notifications.read', $n->id))->assertUnauthorized();
        $this->assertFalse($n->fresh()->is_read);
    }

    // ── full page ──

    public function test_page_has_no_order_history_button_and_keeps_pagination(): void
    {
        $u = $this->makeUser();
        foreach (range(1, 25) as $i) {
            $this->note($u, ['title' => "Row $i", 'created_at' => now()->subMinutes(60 - $i)]);
        }
        $html = $this->actingAs($u)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Order History', $html);
        $this->assertStringContainsString('Mark all as read', $html);
        $this->assertStringContainsString('Only show unread', $html);
        $this->assertStringContainsString('>Dates<', $html);
        $this->assertStringNotContainsString('notif-action-arrow', $html);
        $this->assertSame(20, substr_count($html, 'class="nf-item '));
        $this->assertStringContainsString('page=2', $html);

        $this->actingAs($u)->get(route('notifications.index', ['page' => 2]))->assertOk()->assertSee('Row 1', false);
    }

    public function test_page_filters_by_query_string_and_keeps_them_in_the_pager(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => 'Jan read', 'is_read' => true, 'created_at' => '2026-01-10 10:00:00']);
        $this->note($u, ['title' => 'Feb unread', 'created_at' => '2026-02-10 10:00:00']);
        $this->note($u, ['title' => 'Mar unread', 'created_at' => '2026-03-10 10:00:00']);

        $html = $this->actingAs($u)->get('/notifications?unread=1&from=2026-01-01&to=2026-02-28')->assertOk()->getContent();
        $this->assertStringContainsString('Feb unread', $html);
        $this->assertStringNotContainsString('Jan read', $html);
        $this->assertStringNotContainsString('Mar unread', $html);
        $this->assertMatchesRegularExpression('/name="unread"[^>]*checked/', $html);
        $this->assertStringContainsString('value="2026-01-01"', $html);

        $this->actingAs($u)->get('/notifications?from=junk&to=2026-13-01')->assertOk()->assertSee('Mar unread', false);
    }

    public function test_page_empty_state_when_filters_match_nothing(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['created_at' => '2026-01-10 10:00:00']);
        $html = $this->actingAs($u)->get('/notifications?from=2030-01-01')->assertOk()->getContent();
        $this->assertStringContainsString('No notifications match.', $html);
        $this->assertStringContainsString('Clear filters', $html);
        $this->assertStringContainsString('id="nf-filter"', $html, 'controls stay so the filter can be cleared');
    }

    public function test_page_shows_view_details_only_for_order_and_service_and_escapes_text(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['title' => 'Identity Verified ✓', 'type' => 'validation_approved', 'url' => route('home'), 'message' => 'Verified.']);
        $this->note($u, ['title' => 'Order <b>LP-1</b>', 'url' => route('orders.show', 3), 'message' => '<script>x</script>']);

        $html = $this->actingAs($u)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'View details'));
        $this->assertStringContainsString('href="/orders/3"', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('Order &lt;b&gt;LP-1&lt;/b&gt;', $html);

        // the Identity Verified row has Mark read and no View details
        preg_match('/<article[^>]*>(?:(?!<\/article>).)*Identity Verified(?:(?!<\/article>).)*<\/article>/s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('View details', $m[0]);
        $this->assertStringContainsString('Mark read', $m[0]);
    }

    public function test_read_notification_has_no_mark_read_and_page_needs_login(): void
    {
        $u = $this->makeUser();
        $this->note($u, ['is_read' => true, 'url' => null, 'type' => 'general']);
        $html = $this->actingAs($u)->get(route('notifications.index'))->getContent();
        $this->assertStringNotContainsString('Mark read', $html);
        $this->assertStringNotContainsString('View details', $html);

        auth()->logout();
        $this->get(route('notifications.index'))->assertRedirect();
    }
}
