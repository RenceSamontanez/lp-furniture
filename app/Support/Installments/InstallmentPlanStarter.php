<?php

namespace App\Support\Installments;

use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\Log;

/**
 * The down payment was confirmed: the plan starts TODAY. Due dates are counted from
 * this moment (not from when the order was created), the saved card is picked up and
 * the customer gets "Installment Plan Started". Safe to call twice.
 */
class InstallmentPlanStarter
{
    public function __construct(private InstallmentGateway $gateway, private InstallmentNotifier $notify)
    {
    }

    public function start(Order $order): bool
    {
        $plan = Payment::where('order_id', $order->id)->lockForUpdate()->first();
        // Catalog orders only: a customized order's installments follow its own agreement.
        if (!$plan || $order->design_agreement_id !== null || $plan->payment_type !== 'installment' || $plan->installments_started_at) {
            return false;
        }

        $rows = InstallmentSchedule::where('payment_id', $plan->id)->orderBy('installment_no')->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return false;
        }

        $this->pickUpCard($plan);

        $start = now(InstallmentPayments::TZ)->startOfDay();
        $freq = (int) $plan->installment_frequency_days;
        foreach ($rows as $row) {
            $row->forceFill(['due_date' => $start->copy()->addDays($freq * $row->installment_no)->toDateString()])->save();
        }
        $plan->forceFill(['installments_started_at' => now()])->save();

        $rows = $rows->fresh();
        try {
            $this->notify->planStarted($plan, $rows->count(), (float) $rows->first()->amount_due, (float) $plan->amount, $freq, $rows->first()->due_date->format('M d, Y'));
            ActivityLog::record('installment_plan_created', 'Installment plan started for order ' . $order->order_number, 'orders', $order, 'Order ' . $order->order_number, null);
        } catch (\Throwable $e) {
            Log::warning('Installment plan start follow-ups failed: ' . $e->getMessage());
        }

        return true;
    }

    /** Remember the vaulted card (its id came back with the down payment). Best effort: no card means a Pay Now plan. */
    private function pickUpCard(Payment $plan): void
    {
        if ($plan->payment_method !== 'paymongo_card') {
            return;
        }
        if ($this->gateway->isSimulated()) {
            $this->recordSimulatedCard($plan);

            return;
        }
        if (!$plan->paymongo_customer_id) {
            return;
        }
        try {
            $method = $plan->installment_card_method_id ?: $plan->paymongo_payment_method_id;
            $cards = collect($this->gateway->savedCards($plan->paymongo_customer_id));
            $card = $cards->firstWhere('id', $method) ?: ($method ? null : $cards->last());
            if ($card || $method) {
                $plan->forceFill([
                    'installment_card_method_id' => $card['id'] ?? $method,
                    'card_brand' => isset($card['brand']) ? ucfirst((string) $card['brand']) : $plan->card_brand,
                    'card_last4' => $card['last4'] ?? $plan->card_last4,
                ])->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Saved card could not be read after the down payment: ' . $e->getMessage());
        }
    }

    /**
     * Sandbox: PayMongo cannot vault a card on a test account, so the plan gets a "simulated saved card" (flagged). Its
     * brand and last 4 are the ones PayMongo reported for the down payment, else a plain "Test Card" label.
     */
    private function recordSimulatedCard(Payment $plan): void
    {
        $card = null;
        try {
            $info = $plan->paymongo_payment_intent_id ? $this->gateway->lookupIntent($plan->paymongo_payment_intent_id) : null;
            $card = $info['card'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('Card details of the down payment could not be read: ' . $e->getMessage());
        }

        $plan->forceFill([
            'card_simulated'             => true,
            'installment_card_method_id' => 'sim_card_' . $plan->id,
            'card_brand'                 => !empty($card['brand']) ? ucfirst((string) $card['brand']) : 'Test Card',
            'card_last4'                 => $card['last4'] ?? null,
        ])->save();
    }
}
