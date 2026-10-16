<?php

namespace App\Support;

/**
 * A log-safe description of a gateway exception: its class, HTTP status
 * and message with anything that looks like a key or token removed.
 * (Stripe's "Invalid API Key" message includes part of the key.)
 */
final class StripeErrors
{
    public static function describe(\Throwable $e): string
    {
        $status = method_exists($e, 'getHttpStatus') ? $e->getHttpStatus() : null;
        $message = preg_replace('/\b(?:sk|pk|rk|whsec)_[A-Za-z0-9_*]+/', '[redacted]', $e->getMessage());

        return get_class($e) . ($status ? " (HTTP {$status})" : '') . ': ' . mb_substr((string) $message, 0, 300);
    }
}
