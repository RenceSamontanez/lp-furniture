<?php

namespace App\Support;

use App\Models\ActivityLog as Row;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records who signed in and what the team changed. It stores people and actions only: no IP address, no
 * user agent, no request payloads, no emails or passwords. record() never throws and never blocks the
 * action it logs (a failure is logged as a warning). Rows are kept for 180 days (`activity-log:prune`).
 */
final class ActivityLog
{
    public const RETENTION_DAYS = 180;
    public const ALERT_GAP_MINUTES = 30;
    public const ALERT_TITLE = 'New Sign-In To Your Account';

    public const CATEGORIES = [
        'signin'   => 'Sign-Ins',
        'orders'   => 'Orders & Payments',
        'catalog'  => 'Catalog & Stock',
        'people'   => 'People',
        'settings' => 'Luma & Settings',
    ];

    /**
     * @param Model|string|null $subject a model (type and id are taken from it) or a type string
     * @param User|null         $user    defaults to whoever is signed in (admin guard first, then web)
     */
    public static function record(
        string $event,
        string $description,
        string $category,
        Model|string|null $subject = null,
        ?string $subjectLabel = null,
        ?User $user = null,
        string $kind = 'action',
    ): ?Row {
        try {
            $user ??= Auth::guard('admin')->user() ?? Auth::user();
            [$type, $id] = self::subjectParts($subject);

            return Row::create([
                'user_id'       => $user?->id,
                'actor_name'    => self::name($user),
                'role'          => $user ? ($user->role === 'admin' ? 'admin' : 'customer') : 'system',
                'event'         => mb_substr($event, 0, 60),
                'kind'          => $kind,
                'category'      => $category,
                'description'   => mb_substr($description, 0, 255),
                'subject_type'  => $type,
                'subject_id'    => $id,
                'subject_label' => $subjectLabel !== null ? mb_substr($subjectLabel, 0, 160) : null,
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            try {
                Log::warning('Activity log entry could not be saved: ' . $e->getMessage());
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /** A successful sign-in: the record, users.last_login_at and (customers only) the throttled alert. */
    public static function signedIn(User $user): void
    {
        try {
            DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
            $user->last_login_at = now();
        } catch (\Throwable $e) {
            Log::warning('last_login_at could not be saved: ' . $e->getMessage());
        }

        self::record('login', 'Signed in', 'signin', null, null, $user, 'signin');

        if ($user->role !== 'admin') {
            self::customerAlert($user);
        }
    }

    public static function signedOut(?User $user): void
    {
        if ($user) {
            self::record('logout', 'Signed out', 'signin', null, null, $user, 'signout');
        }
    }

    /**
     * One "New Sign-In To Your Account" notification, at most one every 30 minutes, decided by the last
     * alert's own time (not last_login_at). No IP, no device, no email.
     */
    public static function customerAlert(User $user): void
    {
        try {
            $last = Notification::where('user_id', $user->id)->where('title', self::ALERT_TITLE)->max('created_at');
            if ($last && Carbon::parse($last)->gt(now()->subMinutes(self::ALERT_GAP_MINUTES))) {
                return;
            }
            $alert = new Notification([
                'user_id' => $user->id,
                'title'   => self::ALERT_TITLE,
                'message' => self::alertMessage(now()),
                'type'    => 'general',
                'is_read' => false,
                'url'     => null,
            ]);
            $alert->created_at = now();   // set here (not the column default) so the 30-minute gap and the message agree
            $alert->save();
        } catch (\Throwable $e) {
            Log::warning('Sign-in alert could not be created: ' . $e->getMessage());
        }
    }

    public static function alertMessage(\DateTimeInterface $at): string
    {
        $t = Carbon::instance($at);

        return 'You signed in on ' . $t->format('M j, Y') . ' at ' . $t->format('g:i A') . '. If this was not you, change your password right away.';
    }

    /** A short, single-line piece of customer-typed text for a description (no new lines, clipped). */
    public static function clip(?string $text, int $max = 40): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    public static function prune(): int
    {
        return Row::where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }

    private static function name(?User $user): string
    {
        if (!$user) {
            return 'System';
        }
        $n = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return mb_substr($n !== '' ? $n : 'Unknown', 0, 120);
    }

    /** @return array{0: ?string, 1: ?int} */
    private static function subjectParts(Model|string|null $subject): array
    {
        if ($subject instanceof Model) {
            return [strtolower(class_basename($subject)), (int) $subject->getKey()];
        }

        return [$subject, null];
    }
}
