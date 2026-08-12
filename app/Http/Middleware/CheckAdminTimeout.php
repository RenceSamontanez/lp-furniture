<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class CheckAdminTimeout
{
    // Separate from CheckAdmin (which only confirms identity) so
    // each middleware has one job. Runs after CheckAdmin in the
    // admin route group, so Auth::guard('admin')->check() is
    // already known true in practice — still checked defensively
    // here in case this is ever applied standalone.
    //
    // Logs out only the admin guard, never the whole session —
    // web and admin guards share one physical session (see
    // config/auth.php, both use 'driver' => 'session'), so a full
    // session()->invalidate() here would also wipe out any
    // customer 'web' guard login sharing that same browser
    // session. Auth::guard('admin')->logout() clears only that
    // guard's own session key.
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('admin')->check()) {
            $lastActivity = session('admin_last_activity');
            $timeoutMinutes = (int) config('auth.admin_idle_timeout', 30);

            if ($lastActivity && Carbon::parse($lastActivity)->diffInMinutes(now()) > $timeoutMinutes) {
                Auth::guard('admin')->logout();
                session()->forget('admin_last_activity');

                return redirect()->route('admin.login')
                    ->withErrors(['email' => 'Your session expired after ' . $timeoutMinutes . ' minutes of inactivity. Please log in again.']);
            }

            session(['admin_last_activity' => now()]);
        }

        return $next($request);
    }
}
