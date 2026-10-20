<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Luma's widget must work from any page depth: every URL it uses is root-absolute (or http) and passed to the script through the
 * APP config object / attributes, never built relative to the current page. Guards the "pose missing and chat says trouble on
 * nested pages" report (front end only).
 */
class LumaUrlsTest extends TestCase
{
    use RefreshDatabase;

    private function pages(): array
    {
        $p = Product::create(['product_name' => 'Url Chair', 'category' => 'Chair', 'price' => 1000, 'stock_quantity' => 3, 'product_status' => 'available', 'description' => 'x']);

        return ['/', '/catalog', '/terms', '/services/terms', '/guide', '/product/' . $p->getKey()];
    }

    private function assertRootAbsolute(string $url, string $what): void
    {
        $this->assertMatchesRegularExpression('#^(/(?!/)|https?://)#', $url, "{$what} must start with / or http, got '{$url}'");
    }

    private function publicFileFor(string $url): string
    {
        return public_path(ltrim(parse_url($url, PHP_URL_PATH), '/'));
    }

    public function test_every_luma_url_is_absolute_on_nested_pages_and_resolves(): void
    {
        foreach ($this->pages() as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            foreach (['chatbotUrl', 'lumaPoseBase', 'homeUrl', 'loginUrl', 'cartUrl', 'ordersUrl'] as $key) {
                $this->assertSame(1, preg_match('/\b' . $key . ':\s*"([^"]*)"/', $html, $m), "{$page}: APP.{$key} missing");
                $this->assertRootAbsolute($m[1], "{$page} APP.{$key}");
            }

            preg_match('/chatbotUrl:\s*"([^"]*)"/', $html, $c);
            preg_match('/lumaPoseBase:\s*"([^"]*)"/', $html, $b);

            // every pose the script can ask for exists as a file under the pose base
            foreach (['greeting', 'waiting', 'asked', 'working', 'smiling', 'wondering'] as $pose) {
                $this->assertFileExists($this->publicFileFor($b[1] . "/luma-{$pose}.png"), "{$page}: pose {$pose}");
            }

            // images, stylesheet and script tags of the widget
            preg_match_all('/<img[^>]+src="([^"]+)"/', substr($html, strpos($html, 'chatbot-fab')), $imgs);
            foreach ($imgs[1] as $src) {
                if (str_starts_with($src, 'data:')) {
                    continue;
                }
                $this->assertRootAbsolute($src, "{$page} widget <img>");
                $this->assertFileExists($this->publicFileFor($src), "{$page}: {$src}");
            }
            foreach (['assets/css/luma.css', 'assets/main.js'] as $asset) {
                $this->assertSame(1, preg_match('#(?:href|src)="([^"]*' . preg_quote(basename($asset), '#') . '\?v=\d+)"#', $html, $a), "{$page}: {$asset} has a version query string");
                $this->assertRootAbsolute($a[1], "{$page} {$asset}");
                $this->assertFileExists($this->publicFileFor($a[1]));
            }

            // the chat endpoint answers a POST with JSON, wherever the page was
            $res = $this->postJson($c[1], ['message' => 'labor only'])->assertOk();
            $this->assertIsString($res->json('response'), "{$page}: chat reply");
            $this->get($c[1])->assertStatus(405);   // wrong method gets the right response, not a login page
        }
    }

    public function test_the_chat_request_asks_for_json_and_keeps_the_typed_text_on_failure(): void
    {
        // Laravel's CSRF check is skipped while running tests, so the 419 itself is covered by tests/Luma/browser-urls.cjs (stale).
        // Here: without "Accept: application/json" an expired session is redirected to the HTML login page and res.json() throws.
        $js = file_get_contents(public_path('assets/main.js'));
        $send = substr($js, strpos($js, 'async function sendChat'), 2600);
        $this->assertStringContainsString("'Accept': 'application/json'", $send);
        $this->assertStringContainsString('input.value = msg;', $send);
        $this->assertStringContainsString("I'm having trouble right now. Please try again!", $send);
    }

    public function test_the_widget_code_has_no_relative_urls(): void
    {
        $js = file_get_contents(public_path('assets/main.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $nav = file_get_contents(resource_path('views/partials/luma-nav-item.blade.php'));

        // fetch() must be given a variable, a root-absolute path or a URL, never 'foo/bar' or './foo'
        $this->assertSame(0, preg_match('#fetch\(\s*[\'"`](?!/|https?:)#', $js), 'relative fetch() URL in main.js');
        // pose images are assigned from APP.lumaPoseBase only
        $this->assertSame(0, preg_match('#\.src\s*=\s*[\'"](?!/|https?:|data:)#', $js), 'relative image src assigned in main.js');
        $this->assertDoesNotMatchRegularExpression('#luma-[a-z]+\.png#', preg_replace('#APP\.lumaPoseBase[^;]*;#', '', $js) ?: '', 'pose file name used outside APP.lumaPoseBase');

        // the layout's widget markup: no relative src/href, no hard-coded host
        foreach ([$layout, $nav] as $blade) {
            $this->assertSame(0, preg_match('#\b(?:src|href)="(?!/|https?:|data:|\#|\{\{|mailto:|tel:|javascript:)#', $blade), 'relative src/href in a Luma Blade view');
            $this->assertDoesNotMatchRegularExpression('#localhost|127\.0\.0\.1#', $blade);
        }
        $this->assertDoesNotMatchRegularExpression('#localhost|127\.0\.0\.1#', $js);
    }
}
