<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/** Admin sign-in page: "Keep me signed in on this device" (Laravel remember token) and its cancellation. */
class AdminLoginRememberTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private function admin(): User
    {
        $user = new User();
        $user->forceFill([
            'first_name' => 'Admin', 'last_name' => 'Tester',
            'email' => 'admin' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => self::PASSWORD, 'role' => 'admin',
            'is_validated' => true, 'account_status' => 'active',
        ])->save();

        return $user;
    }

    private function recallerName(): string
    {
        return Auth::guard('admin')->getRecallerName();
    }

    private function recallerCookie($response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $this->recallerName()) {
                return $cookie;
            }
        }

        return null;
    }

    /** A later request that carries only the remember cookie, as a browser with an expired session would. */
    private function visitWithCookie(string $method, string $url, Cookie $cookie)
    {
        // Guards are cached on the app between requests in one test process; start clean like a real new request.
        $this->app['auth']->forgetGuards();
        $this->flushSession(); // the test app keeps one session store alive; a real new browser has none

        return $this->call($method, $url, [], [$cookie->getName() => $cookie->getValue()]);
    }

    public function test_page_has_remember_checkbox_help_text_and_the_shared_toggle(): void
    {
        $html = $this->get(route('admin.login'))->assertOk()->getContent();

        $this->assertStringContainsString('name="remember"', $html);
        $this->assertStringContainsString('Keep me signed in on this device', $html);
        $this->assertStringContainsString("Don't use this on a shared computer", str_replace('&#039;', "'", $html));
        $this->assertStringContainsString('data-password-toggle="admin-password"', $html);
        $this->assertStringContainsString('assets/css/admin-login.css', $html);
    }

    public function test_login_without_remember_sets_no_long_lived_cookie(): void
    {
        $admin = $this->admin();

        $response = $this->post(route('admin.login.post'), ['email' => $admin->email, 'password' => self::PASSWORD]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertNull($this->recallerCookie($response));
        $this->assertNull($admin->fresh()->remember_token);
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_login_with_remember_sets_a_30_day_httponly_cookie_that_logs_in_a_fresh_browser(): void
    {
        $admin = $this->admin();

        $response = $this->post(route('admin.login.post'), [
            'email' => $admin->email, 'password' => self::PASSWORD, 'remember' => '1',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $cookie = $this->recallerCookie($response);
        $this->assertNotNull($cookie, 'remember cookie missing');
        $this->assertTrue($cookie->isHttpOnly());
        $days = ($cookie->getExpiresTime() - time()) / 86400;
        $this->assertGreaterThan(29.9, $days);
        $this->assertLessThan(30.1, $days);
        $this->assertNotNull($admin->fresh()->remember_token);

        // The password never travels in the cookie, in any form.
        $this->assertStringNotContainsString(self::PASSWORD, $cookie->getValue());
        $this->assertStringNotContainsString(self::PASSWORD, decrypt($cookie->getValue(), false));

        // No session at all, only the cookie: still signed in.
        $this->visitWithCookie('GET', route('admin.dashboard'), $cookie)->assertOk();
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_logout_cancels_the_remember_cookie(): void
    {
        $admin = $this->admin();
        $login = $this->post(route('admin.login.post'), [
            'email' => $admin->email, 'password' => self::PASSWORD, 'remember' => '1',
        ]);
        $cookie = $this->recallerCookie($login);
        $oldToken = $admin->fresh()->remember_token;

        $logout = $this->visitWithCookie('POST', route('admin.logout'), $cookie);

        $logout->assertRedirect(route('admin.login'));
        $expired = $this->recallerCookie($logout);
        $this->assertNotNull($expired);
        $this->assertLessThan(time(), $expired->getExpiresTime(), 'cookie must be expired on logout');
        $this->assertNotSame($oldToken, $admin->fresh()->remember_token);

        // Replaying the old cookie no longer signs anyone in.
        $this->visitWithCookie('GET', route('admin.dashboard'), $cookie)->assertRedirect();
        $this->assertGuest('admin');
    }

    public function test_password_change_cancels_the_remember_cookie(): void
    {
        $admin = $this->admin();
        $login = $this->post(route('admin.login.post'), [
            'email' => $admin->email, 'password' => self::PASSWORD, 'remember' => '1',
        ]);
        $cookie = $this->recallerCookie($login);

        $admin->fresh()->update(['password' => 'a-brand-new-password-1']);

        $this->visitWithCookie('GET', route('admin.dashboard'), $cookie)->assertRedirect();
        $this->assertGuest('admin');
    }

    public function test_wrong_password_shows_the_error_and_sets_no_cookie_even_with_remember_ticked(): void
    {
        $admin = $this->admin();

        $response = $this->from(route('admin.login'))->post(route('admin.login.post'), [
            'email' => $admin->email, 'password' => 'not-the-password', 'remember' => '1',
        ]);

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHasErrors('email');
        $this->assertNull($this->recallerCookie($response));
        $this->assertGuest('admin');
        $this->assertNull($admin->fresh()->remember_token);

        // The same message is rendered inside the card as a problem notice, and the email is kept.
        $page = $this->withSession(['errors' => $response->getSession()->get('errors')])
            ->get(route('admin.login'))->assertOk()->getContent();
        $this->assertStringContainsString('lp-notice--problem', $page);
        $this->assertStringContainsString('Incorrect email or password.', $page);
    }
}
