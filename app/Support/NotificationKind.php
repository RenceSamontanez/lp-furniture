<?php

namespace App\Support;

use App\Models\Notification;

/**
 * The one place that decides what kind of customer notification this is and therefore whether it gets a "View details" button.
 *   order   -> has a related order (order stages, payments) .......... View details + Mark read
 *   service -> custom design, agreements, Other Services pages ....... View details + Mark read
 *   account -> identity, password, contact details, anything whose
 *              link is not an order or service page ................... Mark read only
 * Used by the navbar dropdown feed, the full Notifications page and anything else that lists a notification.
 */
final class NotificationKind
{
    public const ORDER   = 'order';
    public const SERVICE = 'service';
    public const ACCOUNT = 'account';

    /** Types that are always account notices, whatever link they carry (Identity Verified used to link to the home page). */
    private const ACCOUNT_TYPES = ['validation_approved', 'validation_rejected'];

    private const ORDER_PATHS   = ['/orders'];
    private const SERVICE_PATHS = ['/design', '/agreements', '/custom-orders', '/services'];

    public static function for(Notification $n): string
    {
        if (in_array($n->type, self::ACCOUNT_TYPES, true)) {
            return self::ACCOUNT;
        }
        $path = self::path($n->url);
        if ($path === null) {
            return self::ACCOUNT;
        }
        if (self::under($path, self::ORDER_PATHS)) {
            return self::ORDER;
        }
        if (self::under($path, self::SERVICE_PATHS)) {
            return self::SERVICE;
        }

        return self::ACCOUNT;
    }

    public static function hasDetails(Notification $n): bool
    {
        return self::for($n) !== self::ACCOUNT;
    }

    /** Same-site relative link for the View details button (null for account notices). */
    public static function detailsUrl(Notification $n): ?string
    {
        if (!self::hasDetails($n)) {
            return null;
        }
        if (ServiceNotificationDetails::isServiceNotification($n)) {
            return route('notifications.show', $n->id, false);
        }
        $query = (string) parse_url((string) $n->url, PHP_URL_QUERY);

        return self::path($n->url) . ($query !== '' ? '?' . $query : '');
    }

    /** Path of a stored link without the app's own sub-folder, or null when there is no usable link. */
    private static function path(?string $url): ?string
    {
        $path = parse_url(trim((string) $url), PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }
        $base = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        return $path;
    }

    private static function under(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if ($path === $p || str_starts_with($path, $p . '/')) {
                return true;
            }
        }

        return false;
    }
}
