<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Admin typography, Title Case and "words only" buttons (docs/fonts/typography-specimen-v2.html, admin rows).
 * The admin has its own body class and stylesheet; customer pages never load it; admin buttons carry no arrows;
 * Title Case is display only (stored values and typed names are untouched).
 */
class AdminTypographyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    /** @return list<string> admin Blade views plus the admin layout */
    private function adminViews(): array
    {
        $files = [base_path('resources/views/layouts/admin.blade.php')];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('resources/views/admin')));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }

    private function withoutComments(string $s): string
    {
        $s = preg_replace('/\{\{--.*?--\}\}/s', '', $s);
        $s = preg_replace('/<!--.*?-->/s', '', $s);
        $s = preg_replace('~/\*.*?\*/~s', '', $s);

        return preg_replace('~(^|\s)//[^\n]*~', '$1', $s);
    }

    public function test_admin_pages_load_self_hosted_fonts_the_admin_sheet_and_the_body_class(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->get('/admin/orders')->assertOk()->getContent();

        $this->assertStringContainsString('assets/css/fonts.css', $html);
        $this->assertStringContainsString('assets/css/admin-type.css', $html);
        $this->assertMatchesRegularExpression('/<body[^>]*class="[^"]*\badmin-type\b/', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('customer-type.css', $html);
        // the admin sheet comes after the page stylesheets so it wins
        $this->assertGreaterThan(strpos($html, 'assets/css/admin.css'), strpos($html, 'assets/css/admin-type.css'));
    }

    public function test_admin_login_page_has_the_body_class_and_no_google_fonts(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<body[^>]*class="[^"]*\badmin-type\b/', $html);
        $this->assertStringContainsString('assets/css/admin-type.css', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_customer_pages_do_not_load_the_admin_stylesheet(): void
    {
        foreach (['/', '/terms', '/login', '/catalog'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('admin-type.css', $html, $url);
            $this->assertDoesNotMatchRegularExpression('/<body[^>]*\badmin-type\b/', $html, $url);
        }
    }

    public function test_admin_stylesheet_is_scoped_to_the_admin_body(): void
    {
        $css = $this->withoutComments(file_get_contents(public_path('assets/css/admin-type.css')));
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $selectorList) {
            $plain = preg_replace('/:is\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/', '', $selectorList);
            $plain = preg_replace('/:not\([^()]*\)/', '', $plain);
            foreach (explode(',', $plain) as $sel) {
                $sel = trim($sel);
                if ($sel === '') {
                    continue;
                }
                $this->assertStringStartsWith('body.admin-type', $sel, 'unscoped selector: ' . $sel);
            }
        }
        // the two approved faces only; Cormorant only for the page title
        $this->assertStringContainsString("'Montserrat'", $css);
        $this->assertSame(1, preg_match_all('/var\(--font-title\)/', $css), 'Cormorant is used by one rule only: the page titles');
        $this->assertStringNotContainsString('Playfair', $css);
        $this->assertStringNotContainsString('DM Sans', $css);
    }

    public function test_admin_views_and_scripts_have_no_arrows_on_buttons(): void
    {
        $arrows = '/(←|→|«|»|›|‹|➜|➔|&larr;|&rarr;|&laquo;|&raquo;|&rsaquo;|&lsaquo;|&#8592;|&#8594;|&#x2190;|&#x2192;)/u';
        $files = array_merge($this->adminViews(), [
            public_path('assets/js/admin.js'), public_path('assets/js/order-tracking.js'), public_path('assets/js/identity-review.js'),
        ]);
        foreach ($files as $file) {
            $src = $this->withoutComments(file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression($arrows, $src, 'arrow left in ' . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
        }
    }

    public function test_admin_views_have_no_arrow_or_left_right_chevron_icons(): void
    {
        // icon arrows drawn as SVG paths (right/left arrows and left/right chevrons); up/down chevrons that open a section are allowed
        $shapes = '/d="(M5 ?12h14|M19 ?12H5|M9 ?18l6-6-6-6|M15 ?18l-6-6 6-6|M10 ?6l6 6-6 6|M14 ?6l-6 6 6 6)|points="(9 18 15 12 9 6|15 18 9 12 15 6)"/';
        foreach ($this->adminViews() as $file) {
            $this->assertDoesNotMatchRegularExpression($shapes, $this->withoutComments(file_get_contents($file)), 'arrow icon left in ' . basename($file));
        }
        $login = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('Sign In', $login);
        $this->assertStringContainsString('Back to main site', $login);
    }

    public function test_admin_pages_render_without_arrows_on_buttons_and_links(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer);
        foreach (['/admin/dashboard', '/admin/orders', '/admin/orders/' . $order->id, '/admin/customers/' . $customer->id, '/admin/products/create', '/admin/reports'] as $url) {
            $html = $this->actingAs($admin, 'admin')->get($url)->assertOk()->getContent();
            preg_match_all('~<(button|a)\b[^>]*>(.*?)</\1>~s', $html, $m);
            foreach ($m[2] as $inner) {
                $this->assertDoesNotMatchRegularExpression('/[←→»«›‹]/u', strip_tags($inner), $url);
            }
        }
    }

    public function test_title_case_is_display_only_and_typed_names_are_left_alone(): void
    {
        $admin = $this->makeUser('admin');
        $customer = $this->makeUser();
        $order = $this->makeOrder($customer, ['order_status' => 'in_production']);
        Product::create([
            'product_name' => 'oak dining table by juan', 'category' => 'Tables', 'material' => 'Narra', 'finish' => 'Natural',
            'price' => 5000, 'stock_quantity' => 2, 'product_status' => 'available',
        ]);

        $orders = $this->actingAs($admin, 'admin')->get('/admin/orders?status=all')->assertOk()->getContent();
        $this->assertStringContainsString('In Production', $orders);       // tab and badge
        $this->assertStringContainsString('All Orders', $orders);
        $this->assertSame('in_production', $order->fresh()->order_status); // stored value untouched
        $this->assertStringContainsString('value=', $orders);

        $products = $this->actingAs($admin, 'admin')->get('/admin/products')->assertOk()->getContent();
        $this->assertStringContainsString('oak dining table by juan', $products);        // typed text is shown exactly as saved
        $this->assertStringNotContainsString('Oak Dining Table By Juan', $products);
        $this->assertSame('oak dining table by juan', Product::first()->product_name);

        $form = $this->actingAs($admin, 'admin')->get('/admin/products/create')->assertOk()->getContent();
        $this->assertStringContainsString('Sale Price (₱)', $form);
        $this->assertStringContainsString('(Optional)', $form);
        $this->assertStringNotContainsString('(optional)</span>', $form);
    }

    public function test_page_titles_and_sidebar_use_title_case(): void
    {
        $admin = $this->makeUser('admin');
        $html = $this->actingAs($admin, 'admin')->get('/admin/system-health')->assertOk()->getContent();

        $this->assertStringContainsString('class="admin-topbar-title">System Health<', $html);
        $this->assertStringContainsString('Quick Ideas', $html);
        $this->assertStringContainsString('Ideas to Review', $html);
        $this->assertStringNotContainsString('Quick ideas', $html);
    }

    public function test_table_headers_and_labels_are_not_forced_to_capitals(): void
    {
        $css = file_get_contents(public_path('assets/css/admin-type.css'));
        $this->assertMatchesRegularExpression('/\.admin-table th[^{]*\{[^}]*text-transform:\s*none/s', $css);
    }
}
