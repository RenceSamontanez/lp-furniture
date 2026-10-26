<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The password rules for the Security tab. A password never expires. The only limit: once it has been changed from the
 * Security tab it cannot be changed there again for 7 days. Forgot-password by email is a different path: it always
 * works and neither counts toward this limit nor is blocked by it.
 */
final class AccountPassword
{
    public const WAIT_DAYS = 7;

    /** Signed in with Google and no password of our own: nothing to change here. */
    public static function isGoogleOnly(User $user): bool
    {
        return $user->google_id !== null && $user->password === null;
    }

    /** When the next change is allowed, or null when it is allowed now. */
    public static function availableAt(User $user, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();
        if (!$user->password_changed_at) {
            return null;
        }
        $at = $user->password_changed_at->copy()->addDays(self::WAIT_DAYS);

        return $at->greaterThan($now) ? $at : null;
    }

    public static function isLocked(User $user, ?Carbon $now = null): bool
    {
        return self::availableAt($user, $now) !== null;
    }

    /** Whole days since the last change (0 = today), or null if never changed here. */
    public static function daysAgo(User $user, ?Carbon $now = null): ?int
    {
        return $user->password_changed_at
            ? (int) $user->password_changed_at->copy()->startOfDay()->diffInDays(($now ?? now())->copy()->startOfDay())
            : null;
    }

    public static function lockedMessage(User $user): string
    {
        $n = self::daysAgo($user);
        $when = $n === 0 ? 'today' : ($n === 1 ? '1 day ago' : $n . ' days ago');

        return 'Password changed ' . $when . '. You can change it again on ' . self::availableAt($user)->format('M j, Y') . '.';
    }
}
