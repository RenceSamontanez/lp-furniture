<?php

namespace App\Support\Installments;

use App\Models\InstallmentAttempt;
use App\Models\InstallmentSchedule;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo payment.paid / payment.failed for installments. A later installment is told
 * apart from the order's first payment (and from the other installments of the same order)
 * through the payment intent we created for it, never through payments.intent_id:
 *   1. an installment_attempts row that holds this intent id (every Pay Now / pay-in-full we started),
 *   2. installment_schedules.gateway_reference (already recorded: makes a repeat a no-op),
 *   3. the installment_schedule_id we put in the intent metadata.
 * Idempotent: the ledger's UNIQUE(method, gateway_reference) and the paid-status guard mean a
 * repeated event changes nothing.
 */
class InstallmentWebhook
{
    public function __construct(private InstallmentPayments $payments)
    {
    }

    /** @return bool true when the event belonged to an installment (handled or deliberately ignored) */
    public function handle(string $eventType, array $resource): bool
    {
        $attrs = $resource['attributes'] ?? [];
        $intent = $attrs['payment_intent_id'] ?? null;
        $meta = (array) ($attrs['metadata'] ?? []);

        $attempt = $intent ? InstallmentAttempt::where('gateway_reference', $intent)->whereIn('kind', ['pay_now', 'pay_full', 'charge', 'retry'])->latest('id')->first() : null;
        $schedule = $attempt?->installment
            ?: ($intent ? InstallmentSchedule::where('gateway_reference', $intent)->first() : null)
            ?: (isset($meta['installment_schedule_id']) && ($meta['kind'] ?? '') === 'installment' ? InstallmentSchedule::find((int) $meta['installment_schedule_id']) : null);

        if (!$schedule) {
            return false;
        }

        $amount = (int) ($attrs['amount'] ?? 0);
        $method = (($attrs['source']['type'] ?? 'card') === 'gcash') ? 'gcash_paymongo' : 'card_paymongo';

        if ($eventType === 'payment.failed') {
            InstallmentPayments::attempt($schedule, 'webhook', 'failed', 'PayMongo reported the payment failed', $intent);

            return true;
        }

        if ($schedule->status === 'paid' && $schedule->gateway_reference === $intent) {
            return true; // repeat of an event we already applied
        }

        $isFull = $attempt && $attempt->kind === 'pay_full';

        // A second payment for an installment that is already paid (two Pay Now links opened): never lost, never applied twice.
        if (!$isFull && $schedule->status === 'paid') {
            $schedule->loadMissing('order');
            \App\Support\PaymentLedger::flagForReview($schedule->order, "a second payment ({$intent}) arrived for installment {$schedule->installment_no}, which was already paid, check for a refund");
            InstallmentPayments::attempt($schedule, 'webhook', 'failed', 'A second payment arrived for an installment that was already paid', $intent);

            return true;
        }
        $ids = $isFull
            ? InstallmentSchedule::where('payment_id', $schedule->payment_id)->whereIn('status', InstallmentSchedule::OPEN)->pluck('id')->all()
            : [$schedule->id];

        $expected = (int) round(InstallmentSchedule::whereIn('id', $ids)->sum('amount_due') * 100);
        if ($ids === [] || $amount !== $expected) {
            // Already settled (repeat), or the amounts do not agree: apply nothing.
            if ($ids !== []) {
                Log::warning("PayMongo installment payment {$intent}: amount {$amount} does not match the {$expected} due; not applied.");
            }

            return true;
        }

        $via = $isFull ? 'pay_full' : (in_array($attempt?->kind, ['charge', 'retry'], true) ? 'auto' : 'pay_now');
        $this->payments->recordGateway($ids, $method, (string) $intent, $via);

        return true;
    }
}
