<?php

namespace Tests\Feature;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/** Admin footer carries no customer links; admin notifications list uses an "Open notification" button. */
class AdminNotificationButtonTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function note($admin, string $title, bool $read, ?string $url): Notification
    {
        return Notification::create([
            'user_id' => $admin->id, 'title' => $title, 'message' => 'Message for ' . $title,
            'type' => 'general', 'url' => $url, 'is_read' => $read,
        ]);
    }

    public function test_admin_pages_do_not_link_to_guide_or_terms_but_customer_pages_do(): void
    {
        $admin = $this->makeUser('admin');
        foreach ([route('admin.notifications.index'), route('admin.dashboard')] as $url) {
            $html = $this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('&copy; ' . now()->year . ' LP Furniture', str_replace('©', '&copy;', $html));
            $this->assertStringNotContainsString('href="' . route('guide') . '"', $html, $url);
            $this->assertStringNotContainsString('href="' . route('terms') . '"', $html, $url);
        }

        $customer = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . route('guide') . '"', $customer);
        $this->assertStringContainsString('href="' . route('terms') . '"', $customer);
    }

    public function test_list_shows_open_button_on_every_row_and_mark_read_only_on_unread(): void
    {
        $admin = $this->makeUser('admin');
        $u1 = url('/admin/service-inquiries/5');
        $u2 = url('/admin/custom-orders/9');
        $this->note($admin, 'New Service Request', false, $u1);
        $this->note($admin, 'Custom Order Update', true, $u2);
        $this->note($admin, 'No Destination', false, null);

        $html = $this->actingAs($admin, 'admin')->get(route('admin.notifications.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Open custom order', $html);
        $this->assertStringContainsString('href="' . $u1 . '" class="lp-btn lp-btn--primary" aria-label="Open notification: New Service Request"', $html);
        $this->assertStringContainsString('href="' . $u2 . '" class="lp-btn lp-btn--primary" aria-label="Open notification: Custom Order Update"', $html);
        $this->assertStringNotContainsString('Open notification: No Destination', $html);

        $this->assertStringContainsString('Mark read: New Service Request', $html);
        $this->assertStringContainsString('Mark read: No Destination', $html);
        $this->assertStringNotContainsString('Mark read: Custom Order Update', $html);
        $this->assertStringContainsString('Mark all as read', $html);
    }

    public function test_customer_notifications_page_is_unchanged(): void
    {
        $customer = $this->makeUser('customer');
        Notification::create([
            'user_id' => $customer->id, 'title' => 'Hello', 'message' => 'Hi there',
            'type' => 'general', 'url' => null, 'is_read' => false,
        ]);
        $html = $this->actingAs($customer)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Open notification', $html);
    }
}
