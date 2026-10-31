<?php

namespace App\Support\Installments;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo driver. Endpoints used (all from docs.paymongo.com):
 *   POST /v1/customers                       create the customer a card is vaulted to
 *   POST /v1/payment_intents                 with setup_future_usage {session_type:on_session, customer_id}
 *   POST /v1/payment_intents/{id}/attach     attach a payment method, returns next_action for 3DS / GCash
 *   GET  /v1/payment_intents/{id}            verify
 *   GET  /v1/customers/{id}/payment_methods  the vaulted card
 *
 * Card vaulting is documented as ON-SESSION only (the CVC is collected again for a saved card), and truly unattended
 * recurring card charges are a Subscriptions feature PayMongo enables per account. chargeSavedCard() therefore tries
 * the documented attach and reports a decline/need-for-action as a failed charge, which sends the customer down the
 * Pay Now path. Nothing here ever throws into the caller: an unreachable PayMongo is a failed result.
 */
class PayMongoInstallmentGateway implements InstallmentGateway
{
    private const BASE = 'https://api.paymongo.com/v1';

    public function isTestMode(): bool
    {
        return PayMongoMode::isTestKey();
    }

    private function http(): PendingRequest
    {
        return Http::withBasicAuth(PayMongoMode::secretKey(), '')->acceptJson()->timeout(20);
    }

    public function isSimulated(): bool
    {
        return false;
    }

    public function ensureCustomer(User $user): string
    {
        if ($user->paymongo_customer_id) {
            return (string) $user->paymongo_customer_id;
        }

        $r = $this->http()->post(self::BASE . '/customers', ['data' => ['attributes' => array_filter([
            'first_name' => $user->first_name ?: 'Customer',
            'last_name'  => $user->last_name ?: (string) $user->id,
            'email'      => $user->email,
            'phone'      => $user->phone_e164 ?: null, // PayMongo wants +639XXXXXXXXX, it rejects 09XXXXXXXXX
            'default_device' => 'email',
        ])]]);

        $id = $r->successful() ? $r->json('data.id') : null;

        // "A customer with this email already exists": use that customer instead of failing.
        if (!$id && $r->status() === 422 && $user->email) {
            $found = $this->http()->get(self::BASE . '/customers', ['email' => $user->email]);
            $id = $found->successful() ? $found->json('data.0.id') : null;
        }

        if (!$id) {
            Log::error('PayMongo customer could not be created: ' . $r->body());
            throw new \RuntimeException('PayMongo customer could not be created.');
        }

        if ($user->exists) {
            $user->forceFill(['paymongo_customer_id' => (string) $id])->saveQuietly();
        }

        return (string) $id;
    }

    public function startCardPayment(?string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult
    {
        $vault = $customerId && config('installments.vault_cards')
            ? ['setup_future_usage' => ['session_type' => 'on_session', 'customer_id' => $customerId]]
            : [];

        return $this->intentAndAttach($centavos, $description, $metadata, $paymentMethodId, $returnUrl, $vault, ['card']);
    }

    public function chargeSavedCard(string $customerId, string $paymentMethodId, int $centavos, string $description, array $metadata): ChargeResult
    {
        $r = $this->intentAndAttach($centavos, $description, $metadata, $paymentMethodId, url('/'), [], ['card']);

        // Nobody is here to complete 3-D Secure, so a redirect on an unattended charge is a failed charge (Pay Now path).
        if ($r->redirectUrl !== null) {
            return ChargeResult::failed('The card needs the customer to confirm the payment.', $r->reference);
        }

        return $r;
    }

    public function startGcash(User $user, int $centavos, string $description, array $metadata, string $returnUrl): ChargeResult
    {
        try {
            $pm = $this->http()->post(self::BASE . '/payment_methods', ['data' => ['attributes' => [
                'type' => 'gcash',
                'billing' => array_filter(['name' => trim($user->first_name . ' ' . $user->last_name), 'email' => $user->email, 'phone' => $user->phone_number]),
            ]]]);
            if (!$pm->successful()) {
                Log::error('PayMongo GCash payment method failed: ' . $pm->body());

                return ChargeResult::failed('GCash could not be started.');
            }

            return $this->intentAndAttach($centavos, $description, $metadata, (string) $pm->json('data.id'), $returnUrl, [], ['gcash']);
        } catch (\Throwable $e) {
            Log::error('PayMongo GCash start failed: ' . $e->getMessage());

            return ChargeResult::failed('GCash could not be started.');
        }
    }

    public function lookupIntent(string $intentId): ?array
    {
        try {
            $r = $this->http()->get(self::BASE . '/payment_intents/' . rawurlencode($intentId));
            if (!$r->successful()) {
                return null;
            }

            $out = [
                'status'   => (string) $r->json('data.attributes.status'),
                'amount'   => (int) $r->json('data.attributes.amount'),
                'metadata' => (array) ($r->json('data.attributes.metadata') ?? []),
            ];
            $src = $r->json('data.attributes.payments.0.attributes.source');
            if (is_array($src) && ($src['type'] ?? null) === 'card') {
                $out['card'] = ['brand' => $src['brand'] ?? null, 'last4' => $src['last4'] ?? null];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('PayMongo intent lookup failed: ' . $e->getMessage());

            return null;
        }
    }

    public function savedCards(string $customerId): array
    {
        try {
            $r = $this->http()->get(self::BASE . '/customers/' . rawurlencode($customerId) . '/payment_methods');
            if (!$r->successful()) {
                return [];
            }

            return collect($r->json('data') ?? [])->map(fn ($m) => [
                'id'    => (string) ($m['id'] ?? ''),
                'brand' => $m['attributes']['details']['card_brand'] ?? $m['attributes']['details']['brand'] ?? null,
                'last4' => $m['attributes']['details']['last4'] ?? null,
            ])->filter(fn ($m) => $m['id'] !== '')->values()->all();
        } catch (\Throwable $e) {
            Log::warning('PayMongo saved cards lookup failed: ' . $e->getMessage());

            return [];
        }
    }

    /** Create the intent, attach the method, translate the answer. */
    private function intentAndAttach(int $centavos, string $description, array $metadata, string $paymentMethodId, string $returnUrl, array $extraAttributes, array $allowed): ChargeResult
    {
        try {
            $attrs = [
                'amount' => $centavos,
                'currency' => 'PHP',
                'payment_method_allowed' => $allowed,
                'description' => mb_substr($description, 0, 200),
                'metadata' => array_map('strval', $metadata),
            ] + $extraAttributes;
            if (in_array('card', $allowed, true)) {
                $attrs['payment_method_options'] = ['card' => ['request_three_d_secure' => 'any']];
            }

            $intent = $this->http()->post(self::BASE . '/payment_intents', ['data' => ['attributes' => $attrs]]);

            // An account that cannot vault answers 400 "...not yet supported": take the payment without it.
            if (!$intent->successful() && $extraAttributes !== [] && $intent->status() === 400 && str_contains((string) $intent->json('errors.0.detail'), 'not yet supported')) {
                Log::warning('PayMongo cannot vault cards on this account; taking the payment without it.');
                unset($attrs['setup_future_usage']);
                $intent = $this->http()->post(self::BASE . '/payment_intents', ['data' => ['attributes' => $attrs]]);
            }
            if (!$intent->successful()) {
                Log::error('PayMongo payment intent failed: ' . $intent->body());

                return ChargeResult::failed('The payment could not be started.');
            }
            $intentId = (string) $intent->json('data.id');

            $attach = $this->http()->post(self::BASE . "/payment_intents/{$intentId}/attach", ['data' => ['attributes' => [
                'payment_method' => $paymentMethodId,
                'client_key'     => $intent->json('data.attributes.client_key'),
                'return_url'     => $returnUrl,
            ]]]);
            if (!$attach->successful()) {
                $detail = (string) ($attach->json('errors.0.detail') ?? 'The payment was declined.');
                Log::warning('PayMongo attach failed: ' . $attach->body());

                return ChargeResult::failed($detail, $intentId);
            }

            $status = (string) $attach->json('data.attributes.status');
            $redirect = $attach->json('data.attributes.next_action.redirect.url');

            return match (true) {
                $status === 'succeeded' => ChargeResult::succeeded($intentId, $paymentMethodId),
                $redirect !== null      => ChargeResult::pending($intentId, $redirect, $paymentMethodId),
                $status === 'processing' => ChargeResult::pending($intentId, null, $paymentMethodId),
                default => ChargeResult::failed((string) ($attach->json('data.attributes.last_payment_error.failed_message') ?? 'The payment was declined.'), $intentId),
            };
        } catch (\Throwable $e) {
            Log::error('PayMongo call failed: ' . $e->getMessage());

            return ChargeResult::failed('The payment service could not be reached.');
        }
    }
}
