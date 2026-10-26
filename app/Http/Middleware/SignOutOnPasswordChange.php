<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a browser out when the account's password was changed somewhere else (My Account > Security, or a password
 * reset). Every customer session remembers a stamp of the password it signed in with; the session that changes the
 * password re-stamps itself (see stamp()), every other session no longer matches and is ended on its next request.
 *
 * The stamp belongs to one user id: a session that now holds a different user (a new sign-in) just takes a new stamp.
 */
class SignOutOnPasswordChange
{
    public const KEY_USER  = 'pw_stamp_user';
    public const KEY_STAMP = 'pw_stamp';

    public static function stampFor($user): string
    {
        return hash_hmac('sha256', (string) $user->getAuthPassword(), (string) config('app.key'));
    }

    /** Make this session the one that matches the account's current password. */
    public static function stamp(Request $request, $user): void
    {
        $request->session()->put(self::KEY_USER, $user->getAuthIdentifier());
        $request->session()->put(self::KEY_STAMP, self::stampFor($user));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if ($session->get(self::KEY_USER) !== $user->getAuthIdentifier() || !$session->has(self::KEY_STAMP)) {
            self::stamp($request, $user);

            return $next($request);
        }

        if (!hash_equals((string) $session->get(self::KEY_STAMP), self::stampFor($user))) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')->with('error', 'Your password was changed on another device, so you were signed out. Please sign in again.');
        }

        return $next($request);
    }
}
