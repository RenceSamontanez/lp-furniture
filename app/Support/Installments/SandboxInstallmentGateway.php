<?php

namespace App\Support\Installments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Test-mode stand-in for the unattended card charge PayMongo cannot do on a test account yet (no vaulting, no
 * Subscriptions). Everything else is the real PayMongo driver: customers, the down payment and GCash really go to
 * PayMongo's test API. Only chargeSavedCard() is simulated, and it never calls PayMongo:
 *   - the result comes from a one-shot outcome the admin picked in Simulate Due Date (success | declined | insufficient),
 *     success when none was picked (the daily job in a demo);
 *   - a success carries a reference starting "SIM-", and the ledger records it as method card_sandbox.
 * HARD BLOCK: this driver does nothing unless the PayMongo secret key starts with sk_test_. With any other key it refuses
 * and logs, and AppServiceProvider will not even bind it.
 */
class SandboxInstallmentGateway extends PayMongoInstallmentGateway
{
    public const OUTCOMES = [
        'success'      => 'Charge Succeeds',
        'declined'     => 'Charge Fails (Card Declined)',
        'insufficient' => 'Insufficient Funds',
    ];

    public const PREFIX = 'SIM-';

    public function isSimulated(): bool
    {
        return true;
    }

    private static function key(int $scheduleId): string
    {
        return "installments.sandbox.outcome.{$scheduleId}";
    }

    /** The next simulated charge of this installment will end this way (used once). */
    public static function setOutcome(int $scheduleId, string $outcome): void
    {
        if (array_key_exists($outcome, self::OUTCOMES)) {
            Cache::put(self::key($scheduleId), $outcome, now()->addMinutes(10));
        }
    }

    /** Whether the sandbox may run at all: only with an sk_test_ key. */
    public static function allowed(): bool
    {
        return PayMongoMode::isTestKey();
    }

    public function chargeSavedCard(string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata): ChargeResult
    {
        if (!self::allowed()) {
            Log::critical('The installment sandbox gateway was asked to charge with a non-test PayMongo key. Refused.');

            return ChargeResult::failed('The sandbox gateway is only available with PayMongo test keys.');
        }

        $id = (int) ($metadata['installment_schedule_id'] ?? 0);
        $outcome = $id ? (string) Cache::pull(self::key($id), 'success') : 'success';

        return match ($outcome) {
            'declined'     => ChargeResult::failed('card declined', self::PREFIX . $id . '-' . Str::upper(Str::random(8))),
            'insufficient' => ChargeResult::failed('insufficient funds', self::PREFIX . $id . '-' . Str::upper(Str::random(8))),
            default        => ChargeResult::succeeded(self::PREFIX . $id . '-' . Str::upper(Str::random(8)), $paymentMethodId),
        };
    }

    /** The simulated card lives on the plan, not at PayMongo. */
    public function savedCards(string $customerId): array
    {
        return [];
    }
}
