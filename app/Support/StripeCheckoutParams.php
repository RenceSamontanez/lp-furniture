<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Builds the parameters for a Stripe Checkout Session and knows the
 * limits Stripe enforces, so a request that would be rejected is caught
 * (or shaped correctly) before it is sent.
 *
 * Limits (see docs/stripe-audit-stage14.md for which were verified live):
 *  - expires_at: from 30 minutes to LESS than 24 hours after creation.
 *    We use at least 31 minutes and at most 23 hours from now, leaving
 *    an hour of room for clock skew between this server and Stripe.
 *  - unit_amount: integer centavos; at least 50 US cents once converted
 *    (we require PHP 40.00), at most 99,999,999 centavos (documented limit).
 *  - product_data.name: at most 250 characters.
 *  - metadata: at most 50 keys, key at most 40 and value at most 500 characters.
 *  - success_url / cancel_url: absolute URLs.
 */
final class StripeCheckoutParams
{
    // Stripe requires the charge to convert to at least 50 US cents. Live test:
    // PHP 31.00 rejected, PHP 32.00 accepted (rate dependent), so PHP 40.00 leaves margin.
    public const MIN_AMOUNT_CENTAVOS = 4000;
    public const MAX_AMOUNT_CENTAVOS = 99999999;
    public const NAME_MAX            = 250;
    public const EXPIRES_MIN_MINUTES = 31;
    public const EXPIRES_CAP_HOURS   = 23;

    /** Why this amount cannot be charged through Stripe, or null. */
    public static function amountProblem(int $centavos): ?string
    {
        if ($centavos < self::MIN_AMOUNT_CENTAVOS) {
            return 'Card payments must be at least ₱' . number_format(self::MIN_AMOUNT_CENTAVOS / 100, 2) . '. Please choose GCash, or contact us.';
        }
        if ($centavos > self::MAX_AMOUNT_CENTAVOS) {
            return 'This amount is above the card payment limit of ₱' . number_format(self::MAX_AMOUNT_CENTAVOS / 100, 2) . '. Please contact us.';
        }

        return null;
    }

    /**
     * expires_at for a payment with a deadline: the earlier of the
     * deadline and 23 hours from now, never sooner than 31 minutes from
     * now. When the deadline is closer than 31 minutes away, no
     * expires_at is sent at all (Stripe's default applies). Null when
     * there is no deadline (catalog orders).
     */
    public static function expiresAt(?Carbon $dueAt, ?Carbon $now = null): ?int
    {
        if ($dueAt === null) {
            return null;
        }

        $now ??= now();
        $min = $now->copy()->addMinutes(self::EXPIRES_MIN_MINUTES);
        $cap = $now->copy()->addHours(self::EXPIRES_CAP_HOURS);

        if ($dueAt->lessThan($min)) {
            return null;
        }

        return ($dueAt->lessThan($cap) ? $dueAt : $cap)->timestamp;
    }

    public static function amountCentavos(Order $order): int
    {
        return (int) round((float) $order->payment->amount * 100);
    }

    public static function build(Order $order, ?int $userId): array
    {
        $name = 'LP Furniture Order ' . $order->order_number
            . ($order->payment->payment_type === 'installment' ? ' (Downpayment)' : '');

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'php',
                    'product_data' => [
                        'name' => mb_substr($name, 0, self::NAME_MAX),
                    ],
                    'unit_amount' => self::amountCentavos($order),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('payment.stripe.success', $order->id) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('payment.stripe', $order->id),
            'metadata' => [
                'order_id' => (string) $order->id,
                'user_id'  => (string) ($userId ?? $order->user_id),
            ],
        ];

        if ($expires = self::expiresAt(app(AgreementCheckout::class)->orderDueAt($order))) {
            $params['expires_at'] = $expires;
        }

        return $params;
    }
}
