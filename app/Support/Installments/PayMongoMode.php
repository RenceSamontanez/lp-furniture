<?php

namespace App\Support\Installments;

/** Which PayMongo key mode is configured. The webhook signature and the Simulate buttons both follow it. */
final class PayMongoMode
{
    public static function secretKey(): string
    {
        return (string) config('services.paymongo.secret_key');
    }

    public static function isLive(): bool
    {
        return str_starts_with(self::secretKey(), 'sk_live_');
    }

    /** Strictly an sk_test_ key: an empty or unknown key is NOT test mode. */
    public static function isTestKey(): bool
    {
        return str_starts_with(self::secretKey(), 'sk_test_');
    }

    /** Which signature in "Paymongo-Signature: t=..,te=..,li=.." applies to this key. */
    public static function signatureField(): string
    {
        return self::isLive() ? 'li' : 'te';
    }
}
