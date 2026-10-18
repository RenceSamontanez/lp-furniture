<?php

namespace Tests\Feature;

use App\Support\InvoiceGateway;

/**
 * Replaces Stripe and PayMongo in the Stage 21f tests: records calls, returns canned data. It has no refund method
 * on purpose, so a test run can never reach a gateway and nothing can start a refund.
 */
class CancelBugGateway extends InvoiceGateway
{
    public int $calls = 0;
    public array $expired = [];

    public function createStripeSession(array $params): object
    {
        $this->calls++;

        return (object) ['id' => 'cs_fake' . $this->calls, 'url' => 'https://checkout.stripe.test/' . $this->calls, 'payment_intent' => 'pi_x'];
    }

    public function retrieveStripeSession(string $sessionId): object
    {
        throw new \RuntimeException('no session');
    }

    public function expireStripeSession(string $sessionId): void
    {
        $this->expired[] = $sessionId;
    }

    public function createPaymongoGcash(int $amountCents, string $description, array $metadata, array $billing, string $returnUrl): array
    {
        $this->calls++;

        return ['intent_id' => 'pi_pm_fake' . $this->calls, 'url' => 'https://gcash.test/' . $this->calls];
    }

    public function retrievePaymongoIntent(string $intentId): array
    {
        return ['status' => 'pending', 'amount_cents' => 0];
    }
}
