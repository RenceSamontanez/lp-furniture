<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Stage 21d part B: branded 404 / 403 / 419 pages and a database-free 500. Status codes do not change.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]); // the real pages, not the developer error screen

        Route::middleware('web')->group(function () {
            Route::get('/_t/forbidden', fn () => abort(403));
            Route::get('/_t/expired', fn () => abort(419));
            Route::get('/_t/boom', function () {
                throw new \RuntimeException('secret detail C:\\xampp\\should-not-appear.php');
            });
        });
    }

    private function assertClean(string $html): void
    {
        foreach (['Stack trace', '#0 ', '.php', 'vendor\\', 'vendor/', 'RuntimeException', 'Whoops', 'xampp', 'Illuminate\\'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "page leaks: {$leak}");
        }
    }

    public function test_404_is_branded_and_leaks_nothing(): void
    {
        $res = $this->get('/no-such-page-at-all');
        $res->assertNotFound()
            ->assertSee('We could not find that page')
            ->assertSee('The link may be old or the page may have moved.')
            ->assertSee('Go to Home')->assertSee('Browse Catalog')
            ->assertSee('Prefer to talk first?');
        $this->assertStringContainsString(route('catalog'), $res->getContent());
        $this->assertStringContainsString('<meta name="robots" content="noindex"', $res->getContent());
        $this->assertClean($res->getContent());
    }

    public function test_403_is_branded_and_leaks_nothing(): void
    {
        $res = $this->get('/_t/forbidden');
        $res->assertForbidden()->assertSee('You do not have access to this page')->assertSee('Go to Home');
        $this->assertClean($res->getContent());
    }

    public function test_419_page_renders_branded_and_leaks_nothing(): void
    {
        // bootstrap/app.php deliberately turns a real 419 into a redirect to the login page with a message,
        // so the branded view is the fallback that is used if that handler is ever removed. Render it directly.
        $html = view('errors.419', ['exception' => new \Symfony\Component\HttpKernel\Exception\HttpException(419)])->render();
        $this->assertStringContainsString('Your session expired, please refresh and try again', $html);
        $this->assertStringContainsString('Go to Home', $html);
        $this->assertClean($html);
    }

    public function test_the_existing_419_redirect_to_login_is_unchanged(): void
    {
        $res = $this->get('/_t/expired');
        $res->assertRedirect(route('login'));
        $this->assertStringContainsString('Your session expired for your security', session('errors')->first('email'));
    }

    public function test_500_is_static_and_never_touches_the_database(): void
    {
        $res = $this->get('/_t/boom');
        $res->assertStatus(500)->assertSee('Something went wrong on our side')->assertSee('Go to Home');
        $this->assertClean($res->getContent());

        $source = file_get_contents(resource_path('views/errors/500.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/@extends|@include|@auth|Auth::|DB::|auth\(\)|session\(|csrf_token|route\(|\{\{[^-]|@php/', $source);

        // rendering it runs no query at all
        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        view('errors.500')->render();
        $this->assertSame(0, $queries);
    }

    public function test_error_status_codes_for_protected_routes_are_unchanged(): void
    {
        $this->get('/admin/dashboard')->assertRedirect();            // guest is still redirected, not shown a page
        $this->get('/services/invoices/1')->assertRedirect(route('login'));
        $this->get('/product/999999999')->assertNotFound();
    }
}
