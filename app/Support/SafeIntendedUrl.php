<?php

namespace App\Support;

use Illuminate\Http\Request;

// Shared by AuthController (customer + Google login) and
// Admin\AdminController (admin login) — both need to honor the "intended
// URL" a guest was redirected from (set by the 'auth'/'admin' middleware
// or the 419 handler in bootstrap/app.php) without it becoming an open
// redirect or a way to cross between the customer and admin areas. Pulled
// out here so both logins enforce the exact same two rules; letting them
// drift would turn this into exactly the kind of guard-boundary bug this
// whole thing exists to prevent.
class SafeIntendedUrl
{
    // $adminArea = false (customer login): refuse to land on an
    // /admin/* URL — that URL belongs to a completely different guard,
    // and a customer session reaching it would still 404/redirect at
    // the 'admin' middleware, but sending them there at all is the
    // wrong UX and worth refusing outright.
    // $adminArea = true (admin login): the inverse — only ever honor
    // an intended URL that's actually under /admin/*.
    public static function resolve(Request $request, string $default, bool $adminArea = false): string
    {
        $intended = $request->session()->pull('url.intended');

        if (!$intended) {
            return $default;
        }

        $host = parse_url($intended, PHP_URL_HOST);

        if ($host !== null && strcasecmp($host, $request->getHost()) !== 0) {
            return $default; // different host — open redirect, refuse
        }

        $path = parse_url($intended, PHP_URL_PATH) ?? '/';
        $isAdminPath = $path === '/admin' || str_starts_with($path, '/admin/');

        if ($adminArea !== $isAdminPath) {
            return $default;
        }

        return $intended;
    }
}
