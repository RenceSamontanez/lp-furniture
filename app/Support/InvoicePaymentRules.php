<?php

namespace App\Support;

/**
 * The payment rules for "Other Services" invoices, agreed with the owner, in ONE place.
 * Change a default here and nothing else needs touching.
 *
 * An invoice has one plan, chosen when the admin sends it: "full" (always available) or
 * "half_half" (50% now, 50% when the admin taps "Service done"). No other splits.
 */
final class InvoicePaymentRules
{
    public const FULL      = 'full';
    public const HALF_HALF = 'half_half';

    public const PLANS = [
        self::FULL      => 'Pay in full',
        self::HALF_HALF => 'Half now, half when the service is done',
    ];

    public const FULL_ONLY    = 'full_only';
    public const FULL_OR_HALF = 'full_or_half';

    /**
     * Per service type (the ticket's service_type): may the customer split the invoice?
     * A service type that is "paid when we arrive" would be listed as full_only. Any service type
     * not listed here is treated as full_only.
     */
    public const SERVICE_TYPES = [
        'labor_only_own_wood' => self::FULL_OR_HALF,   // Labor only (customer brings the wood)
        'repair'              => self::FULL_OR_HALF,   // Repair or restoration
        'other'               => self::FULL_ONLY,      // Something else (scope unknown, so full payment)
    ];

    /** Half and half is only offered when the invoice total is at least this many pesos. */
    public const HALF_HALF_MIN_TOTAL = 3000.00;

    /** Online payments (Stripe card, GCash through PayMongo) must be at least this many pesos. Stripe needs 40. */
    public const MIN_ONLINE_PAYMENT = 40.00;

    public static function allowsHalfHalf(?string $serviceType, mixed $total): bool
    {
        return self::halfHalfProblem($serviceType, $total) === null;
    }

    /** Why half and half is not allowed here, or null when it is. */
    public static function halfHalfProblem(?string $serviceType, mixed $total): ?string
    {
        if ((self::SERVICE_TYPES[$serviceType] ?? self::FULL_ONLY) !== self::FULL_OR_HALF) {
            return 'This type of service must be paid in full, so half and half is not available.';
        }
        if (ServiceInvoiceDesk::cents($total) < ServiceInvoiceDesk::cents(self::HALF_HALF_MIN_TOTAL)) {
            return 'Half and half is only available when the invoice total is at least ' . ServiceInvoiceDesk::peso(self::HALF_HALF_MIN_TOTAL) . '.';
        }

        return null;
    }

    /**
     * The installments of a plan, in centavos: [1 => first, 2 => second]. Full is a single installment.
     * Half and half: the first half is rounded down, the second takes the odd centavo, so they always add up.
     *
     * @return array<int, int>
     */
    public static function installments(string $plan, int $totalCents): array
    {
        if ($plan !== self::HALF_HALF) {
            return [1 => $totalCents];
        }

        $first = intdiv($totalCents, 2);

        return [1 => $first, 2 => $totalCents - $first];
    }

    public static function minOnlineCents(): int
    {
        return ServiceInvoiceDesk::cents(self::MIN_ONLINE_PAYMENT);
    }
}
