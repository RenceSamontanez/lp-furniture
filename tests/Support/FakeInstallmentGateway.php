<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Installments\ChargeResult;
use App\Support\Installments\InstallmentGateway;

/** A scripted stand-in for PayMongo. Nothing leaves the process. */
class FakeInstallmentGateway implements InstallmentGateway
{
    public bool $testMode = true;
    /** @var list<ChargeResult> results handed out, in order, to chargeSavedCard(); empty = succeed */
    public array $cardResults = [];
    public ?ChargeResult $gcash = null;
    public ?ChargeResult $cardPayment = null;
    /** @var array<string,array{status:string,amount:int,metadata:array}> */
    public array $intents = [];
    public array $saved = [];

    public array $charges = [];
    public int $n = 0;

    public bool $simulated = false;

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function isSimulated(): bool
    {
        return $this->simulated;
    }

    public function ensureCustomer(User $user): string
    {
        return 'cus_fake_' . $user->id;
    }

    public function startCardPayment(?string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult
    {
        $this->charges[] = ['card_payment', $centavos, $metadata];

        return $this->cardPayment ?? ChargeResult::succeeded('pi_card_' . ++$this->n, $paymentMethodId);
    }

    public function chargeSavedCard(string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata): ChargeResult
    {
        $this->charges[] = ['saved_card', $centavos, $metadata];

        return array_shift($this->cardResults) ?? ChargeResult::succeeded('pi_saved_' . ++$this->n, $paymentMethodId);
    }

    public function startGcash(User $user, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult
    {
        $this->charges[] = ['gcash', $centavos, $metadata];

        return $this->gcash ?? ChargeResult::pending('pi_gcash_' . ++$this->n, 'https://pm.test/gcash/' . $this->n);
    }

    public function lookupIntent(string $intentId): ?array
    {
        return $this->intents[$intentId] ?? null;
    }

    public function savedCards(string $customerId): array
    {
        return $this->saved ?: [['id' => 'pm_fake_card', 'brand' => 'visa', 'last4' => '4345']];
    }
}
