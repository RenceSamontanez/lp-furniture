<?php

namespace App\Support\Installments;

/** What a gateway call came back with. */
final class ChargeResult
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const PENDING = 'pending';   // processing, or waiting for the customer (redirect)

    private function __construct(
        public readonly string $status,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $paymentMethodId = null,
    ) {
    }

    public static function succeeded(string $reference, ?string $paymentMethodId = null): self
    {
        return new self(self::SUCCEEDED, $reference, null, null, $paymentMethodId);
    }

    public static function failed(string $message, ?string $reference = null): self
    {
        return new self(self::FAILED, $reference, $message);
    }

    public static function pending(string $reference, ?string $redirectUrl = null, ?string $paymentMethodId = null): self
    {
        return new self(self::PENDING, $reference, null, $redirectUrl, $paymentMethodId);
    }

    public function ok(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    public function failedNow(): bool
    {
        return $this->status === self::FAILED;
    }
}
