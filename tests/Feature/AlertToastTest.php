<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 15 part B: session messages are shown once, as toasts, by the shared
 * partial in both layouts; nothing prints them inline as well.
 */
class AlertToastTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function toastCount(string $html): int
    {
        return substr_count($html, 'data-lp-toast ');
    }

    public function test_each_type_renders_with_its_class_role_and_close_button(): void
    {
        $html = view('partials.alerts', [])->render();
        $this->assertSame('', trim($html), 'no messages, no markup');

        $this->withSession(['success' => 'Saved it', 'error' => 'It broke', 'warning' => 'Careful now', 'status' => 'Link sent', 'info' => 'FYI only'])
            ->get(route('catalog'))->assertOk()
            ->assertSee('lp-toast--success', false)->assertSee('lp-toast--error', false)
            ->assertSee('lp-toast--warning', false)->assertSee('lp-toast--info', false)
            ->assertSee('Saved it')->assertSee('It broke')->assertSee('Careful now')->assertSee('Link sent')->assertSee('FYI only')
            ->assertSee('role="alert"', false)->assertSee('data-lp-toast-close', false)
            ->assertSee('data-lp-toast-timeout="6000"', false)
            ->assertSee('assets/js/alerts.js', false)->assertSee('assets/css/alerts.css', false);
    }

    public function test_messages_are_escaped(): void
    {
        $this->withSession(['error' => '<b>bold</b>'])->get(route('catalog'))->assertOk()
            ->assertDontSee('<b>bold</b>', false)->assertSee('&lt;b&gt;bold&lt;/b&gt;', false);
    }

    public function test_the_old_session_toast_markup_is_gone(): void
    {
        $html = $this->withSession(['success' => 'Hello there'])->get(route('catalog'))->assertOk()->getContent();

        $this->assertStringNotContainsString('toast-session', $html);
        $this->assertSame(1, $this->toastCount($html));
        $this->assertSame(1, substr_count($html, 'Hello there'));
    }

    public function test_no_duplicates_on_key_customer_pages(): void
    {
        $user = $this->makeUser();
        $pages = [
            'catalog'       => route('catalog'),
            'cart'          => route('cart.index'),
            'orders'        => route('orders.index'),
            'profile'       => route('profile'),
            'design'        => route('design.index'),
            'notifications' => route('notifications.index'),
            'reviews'       => route('feedback.index'),
        ];

        foreach ($pages as $name => $url) {
            foreach (['success', 'error'] as $key) {
                $this->flushSession();
                $text = "Unique {$key} message for {$name}";
                $html = $this->actingAs($user)->withSession([$key => $text])->get($url)->assertOk()->getContent();

                $this->assertSame(1, substr_count($html, $text), "{$key} shown once on {$name}");
                $this->assertSame(1, $this->toastCount($html), "one toast on {$name}");
            }
        }
    }

    public function test_no_duplicates_on_admin_pages_and_the_admin_login(): void
    {
        $admin = $this->makeUser('admin');
        $pages = [
            'dashboard' => route('admin.dashboard'),
            'products'  => route('admin.products.index'),
            'employees' => route('admin.employees.index'),
            'inventory' => route('admin.inventory.index'),
            'chatbot'   => route('admin.chatbot.index'),
            'orders'    => route('admin.orders.index'),
            'custom'    => route('admin.custom-orders.index'),
            'designs'   => route('admin.design-requests.index'),
        ];

        foreach ($pages as $name => $url) {
            $this->flushSession();
            $text = "Admin success on {$name}";
            $html = $this->actingAs($admin, 'admin')->withSession(['success' => $text])->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, $text), "shown once on admin {$name}");
            $this->assertSame(1, $this->toastCount($html), "one toast on admin {$name}");
            $this->assertStringNotContainsString('admin-toast-success', $html);
        }

        Auth::guard('admin')->logout();
        $html = $this->withSession(['success' => 'Signed out fine'])->get(route('admin.login'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Signed out fine'));
        $this->assertSame(1, $this->toastCount($html));
    }

    public function test_validation_errors_are_toasted_only_when_a_layout_asks_for_it(): void
    {
        $bag = (new ViewErrorBag())->put('default', new MessageBag(['email' => ['The email is bad.']]));

        $this->assertStringNotContainsString('The email is bad.', view('partials.alerts', ['errors' => $bag])->render());

        $html = view('partials.alerts', ['errors' => $bag, 'toastValidation' => true])->render();
        $this->assertSame(1, substr_count($html, 'The email is bad.'));
        $this->assertStringContainsString('lp-toast--error', $html);
    }

    public function test_autodismiss_markup_and_script_behaviour(): void
    {
        $js = file_get_contents(public_path('assets/js/alerts.js'));
        foreach (['mouseenter', 'mouseleave', 'focusin', 'focusout', 'data-lp-toast-close', '|| 6000'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }

        $css = file_get_contents(public_path('assets/css/alerts.css'));
        $this->assertStringContainsString('@media (max-width: 640px)', $css);
        $this->assertStringContainsString('top: 16px;', $css);
        $this->assertStringContainsString('right: 16px;', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function test_the_script_runs_correctly_with_a_fake_clock(): void
    {
        exec('node --version 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('node is not available');
        }

        $output = [];
        exec('node ' . escapeshellarg(base_path('tests/js/alerts.test.cjs')) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('alerts.js ok', implode("\n", $output));
    }
}
