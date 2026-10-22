<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Luma gets a fresh CSRF token after a 419 and retries once. The browser walk proves the retry; this covers the endpoint and the widget's rules. */
class LumaSessionRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refresh_route_returns_the_live_session_token_and_is_never_cached(): void
    {
        $res = $this->getJson(route('csrf.refresh'))->assertOk();

        $this->assertSame(session()->token(), $res->json('token'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_a_visitor_without_a_session_still_gets_a_token(): void
    {
        $token = $this->getJson(route('csrf.refresh'))->assertOk()->json('token');

        $this->assertSame(40, strlen($token));
    }

    public function test_the_page_hands_the_widget_the_refresh_url(): void
    {
        $this->get('/')->assertOk()->assertSee('csrfUrl: "/csrf-token"', false);
    }

    public function test_the_widget_retries_only_a_419_and_only_once(): void
    {
        $js = file_get_contents(public_path('assets/main.js'));

        $this->assertSame(1, substr_count($js, 'if (res.status === 419) {'));
        $this->assertSame(2, substr_count($js, 'await send();'), 'one send plus one retry, no loop');
        $this->assertStringContainsString('lumaFetchJson(APP.chatbotUrl', $js);
        $this->assertStringContainsString('lumaAddRetry(msg)', $js);
        $this->assertStringNotContainsString('console.log(data.token', $js);
    }
}
