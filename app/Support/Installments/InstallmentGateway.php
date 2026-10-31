<?php

namespace App\Support\Installments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/**
 * Everything installments need from a payment provider. PayMongo is the only
 * real driver (PayMongoInstallmentGateway); tests bind a fake. Going live means
 * a key change in .env, not a code change.
 */
interface InstallmentGateway
{
    /** True only for a PayMongo secret key that starts with sk_test_. Simulate buttons depend on it. */
    public function isTestMode(): bool;

    /** True for the sandbox driver: later card charges are simulated, no money and no PayMongo call. */
    public function isSimulated(): bool;

    /** The provider's customer id for this user: the stored one, else found by email, else created (and stored). */
    public function ensureCustomer(User $user): string;

    /**
     * First (down) payment or an Update Card payment taken with a card the browser tokenised. An ordinary card
     * payment; the card is only asked to be vaulted when config('installments.vault_cards') is on.
     *
     * @param array<string,string> $metadata
     */
    public function startCardPayment(?string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult;

    /**
     * Charge a card that is already saved. Returns failed when the provider cannot do it unattended.
     *
     * @param array<string,string> $metadata
     */
    public function chargeSavedCard(string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata): ChargeResult;

    /**
     * A GCash payment the customer approves in GCash (redirect).
     *
     * @param array<string,string> $metadata
     */
    public function startGcash(User $user, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult;

    /** @return array{status:string, amount:int, metadata:array<string,mixed>, card?:array{brand:?string,last4:?string}}|null  null when the provider cannot be reached; card = what paid it, when the provider says */
    public function lookupIntent(string $intentId): ?array;

    /** @return list<array{id:string, brand:?string, last4:?string}> */
    public function savedCards(string $customerId): array;
}
