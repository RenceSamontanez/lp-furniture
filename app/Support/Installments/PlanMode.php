<?php

namespace App\Support\Installments;

use App\Models\Payment;

/** Card plans are charged automatically; every other plan gets a Pay Now notification on each due date. */
final class PlanMode
{
    /** True while the bound driver is the sandbox: the one switch for every "Sandbox: simulated charge" label. */
    public static function sandboxActive(): bool
    {
        return app(InstallmentGateway::class)->isSimulated();
    }

    public static function autoCharges(Payment $plan): bool
    {
        if ($plan->payment_method !== 'paymongo_card' || !$plan->installment_card_method_id) {
            return false;
        }
        // A simulated card is only ever "charged" by the sandbox; under the real driver the plan falls back to Pay Now.
        if ($plan->card_simulated) {
            return self::sandboxActive();
        }

        return (bool) $plan->paymongo_customer_id;
    }

    /** The small "Sandbox: simulated charge" label shows for a simulated card while the sandbox is the driver. */
    public static function showSandboxLabel(?Payment $plan): bool
    {
        return $plan && $plan->payment_method === 'paymongo_card' && $plan->card_simulated && self::sandboxActive();
    }

    /** Ledger method for a card charge taken by the bound driver. */
    public static function cardLedgerMethod(): string
    {
        return self::sandboxActive() ? 'card_sandbox' : 'card_paymongo';
    }

    /** "Visa ending 4345", or "Card" when we do not know it. */
    public static function cardLabel(Payment $plan): string
    {
        return trim(($plan->card_brand ?: 'Card') . ($plan->card_last4 ? ' ending ' . $plan->card_last4 : ''));
    }
}
