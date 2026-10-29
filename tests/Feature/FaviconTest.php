<?php

namespace Tests\Feature;

use Tests\TestCase;

class FaviconTest extends TestCase
{
    private const PAGES_WITH_OWN_HEAD = [
        'layouts/app', 'layouts/admin', 'layouts/focus',
        'agreements/print', 'design/agreement-blocked', 'service-invoices/print', 'welcome',
    ];

    public function test_icon_files_exist(): void
    {
        foreach (['favicon.ico', 'site.webmanifest', 'icons/icon-16.png', 'icons/icon-32.png', 'icons/icon-48.png',
                  'icons/icon-180.png', 'icons/icon-192.png', 'icons/icon-512.png'] as $file) {
            $this->assertFileExists(public_path($file));
            $this->assertGreaterThan(0, filesize(public_path($file)), $file);
        }
    }

    public function test_manifest_is_valid(): void
    {
        $m = json_decode(file_get_contents(public_path('site.webmanifest')), true);
        $this->assertSame('LP Furniture', $m['name']);
        $this->assertSame('#2a1611', $m['theme_color']);
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
    }

    public function test_every_head_includes_the_favicon_partial(): void
    {
        foreach (self::PAGES_WITH_OWN_HEAD as $view) {
            $this->assertStringContainsString("@include('partials.favicon')",
                file_get_contents(resource_path("views/$view.blade.php")), $view);
        }
    }

    public function test_static_500_page_has_plain_icon_links(): void
    {
        $src = file_get_contents(resource_path('views/errors/500.blade.php'));
        foreach (['/favicon.ico?v=1', '/icons/icon-32.png?v=1', '/icons/icon-16.png?v=1', '/icons/icon-180.png?v=1', '/site.webmanifest?v=1'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $src);
        }
    }

    public function test_no_other_view_has_its_own_head_without_the_icons(): void
    {
        $skip = array_map(fn ($v) => str_replace('/', DIRECTORY_SEPARATOR, $v), array_merge(self::PAGES_WITH_OWN_HEAD, ['errors/500']));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if (! str_ends_with($file, '.blade.php') || str_contains($file, DIRECTORY_SEPARATOR . 'emails' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (preg_match('/<head[\s>]/', file_get_contents($file))) {
                $rel = str_replace([resource_path('views') . DIRECTORY_SEPARATOR, '.blade.php'], '', (string) $file);
                $this->assertContains($rel, $skip, "$rel has its own <head> without the favicon links");
            }
        }
    }

    public function test_rendered_pages_carry_the_links(): void
    {
        foreach (['/login', '/this-page-does-not-exist'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringContainsString('rel="icon" href="', $html, $url);
            $this->assertStringContainsString('favicon.ico?v=1', $html, $url);
            $this->assertStringContainsString('icons/icon-32.png?v=1', $html, $url);
            $this->assertStringContainsString('icons/icon-16.png?v=1', $html, $url);
            $this->assertStringContainsString('rel="apple-touch-icon" sizes="180x180"', $html, $url);
            $this->assertStringContainsString('site.webmanifest?v=1', $html, $url);
        }
    }
}
