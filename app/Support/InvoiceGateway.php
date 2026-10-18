<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;

/**
 * The only place the invoice payment code talks to Stripe and PayMongo. Kept as a small class so tests can
 * swap it for a fake and never reach a real gateway. It builds nothing for custom orders and does not touch
 * their controllers: it simply makes the same documented API calls.
 */
class InvoiceGateway
{
    /** @return object a Stripe Checkout Session (id, url, payment_intent...) */
    public function createStripeSession(array $params): object
    {
        return (new StripeClient(config('services.stripe.secret')))->checkout->sessions->create($params);
    }

    /** @return object a Stripe Checkout Session (status, payment_status, amount_total, metadata...) */
    public function retrieveStripeSession(string $sessionId): object
    {
        return (new StripeClient(config('services.stripe.secret')))->checkout->sessions->retrieve($sessionId);
    }

    /** Expire an open Checkout Session (best effort, e.g. when cash was taken instead). */
    public function expireStripeSession(string $sessionId): void
    {
        (new StripeClient(config('services.stripe.secret')))->checkout->sessions->expire($sessionId);
    }

    /**
     * The three PayMongo calls: payment intent, GCash payment method, attach (which returns the redirect).
     *
     * @return array{intent_id: string, url: string}
     */
    public function createPaymongoGcash(int $amountCents, string $description, array $metadata, array $billing, string $returnUrl): array
    {
        $secret = config('services.paymongo.secret_key');

        $intent = Http::withBasicAuth($secret, '')->post('https://api.paymongo.com/v1/payment_intents', ['data' => ['attributes' => [
            'amount' => $amountCents, 'currency' => 'PHP', 'payment_method_allowed' => ['gcash'],
            'description' => $description, 'metadata' => $metadata,
        ]]]);
        if (!$intent->successful()) {
            throw new \RuntimeException('PayMongo intent failed: ' . $intent->status());
        }
        $intentId  = $intent->json('data.id');
        $clientKey = $intent->json('data.attributes.client_key');

        $method = Http::withBasicAuth($secret, '')->post('https://api.paymongo.com/v1/payment_methods', ['data' => ['attributes' => [
            'type' => 'gcash', 'billing' => array_filter($billing),
        ]]]);
        if (!$method->successful()) {
            throw new \RuntimeException('PayMongo payment method failed: ' . $method->status());
        }

        $attach = Http::withBasicAuth($secret, '')->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", ['data' => ['attributes' => [
            'payment_method' => $method->json('data.id'), 'client_key' => $clientKey, 'return_url' => $returnUrl,
        ]]]);
        $url = $attach->successful() ? $attach->json('data.attributes.next_action.redirect.url') : null;
        if (!$url) {
            throw new \RuntimeException('PayMongo attach failed: ' . $attach->status());
        }

        return ['intent_id' => (string) $intentId, 'url' => (string) $url];
    }

    /** @return array{status: ?string, amount_cents: int} */
    public function retrievePaymongoIntent(string $intentId): array
    {
        $res = Http::withBasicAuth(config('services.paymongo.secret_key'), '')->get("https://api.paymongo.com/v1/payment_intents/{$intentId}");
        if (!$res->successful()) {
            throw new \RuntimeException('PayMongo intent lookup failed: ' . $res->status());
        }

        return ['status' => $res->json('data.attributes.status'), 'amount_cents' => (int) $res->json('data.attributes.amount')];
    }
}
