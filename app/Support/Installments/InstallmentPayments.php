<?php

namespace App\Support\Installments;

use App\Models\InstallmentAttempt;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Support\ActivityLog;
use App\Support\OrderTracking;
use App\Support\PaymentLedger;
use App\Support\RecordPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where an installment payment that arrived through a gateway reaches the ledger.
 * Modeled on RecordPayment::record, but for the system: one payment_records row per
 * installment (installment_schedule_id set, UNIQUE method + reference), under the order
 * lock. PaymentController::recordPaymentArrived is for the FIRST payment only and is
 * never used for later installments.
 */
class InstallmentPayments
{
    public const TZ = 'Asia/Manila';

    public function __construct(private InstallmentStanding $standing, private InstallmentNotifier $notify)
    {
    }

    public static function attempt(InstallmentSchedule|int $i, string $kind, string $result, string $message, ?string $ref = null, bool $simulated = false): void
    {
        try {
            InstallmentAttempt::create([
                'installment_schedule_id' => $i instanceof InstallmentSchedule ? $i->id : $i,
                'kind' => $kind, 'result' => $result, 'message' => mb_substr($message, 0, 255),
                'gateway_reference' => $ref ? mb_substr($ref, 0, 120) : null, 'simulated' => $simulated,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Installment attempt could not be logged: ' . $e->getMessage());
        }
    }

    /**
     * Record gateway money against one or several installments of ONE order.
     * Idempotent: a repeat of the same method + reference changes nothing.
     *
     * @param list<int> $scheduleIds
     * @param string    $method  card_paymongo | card_sandbox (reference starts SIM-) | gcash_paymongo
     * @param string    $via     auto | pay_now | pay_full
     * @return int number of installments newly marked paid
     */
    public function recordGateway(array $scheduleIds, string $method, string $reference, string $via, bool $simulated = false): int
    {
        $paidNow = collect();
        $orderId = InstallmentSchedule::whereIn('id', $scheduleIds)->value('order_id');
        if (!$orderId) {
            return 0;
        }

        $today = now(self::TZ)->toDateString();

        $result = DB::transaction(function () use ($scheduleIds, $method, $reference, $via, $simulated, $orderId, $today, &$paidNow) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $payment = Payment::where('order_id', $order->id)->lockForUpdate()->first();
            $rows = InstallmentSchedule::whereIn('id', $scheduleIds)->where('order_id', $order->id)->orderBy('installment_no')->lockForUpdate()->get()
                ->filter(fn ($r) => $r->status !== 'paid');

            if (!$payment || $rows->isEmpty()) {
                return 0;
            }

            $multi = count($scheduleIds) > 1;
            foreach ($rows as $row) {
                $ref = $multi ? $reference . '#i' . $row->id : $reference;
                $was = $row->status;
                $hadFailure = (bool) $row->charge_failed;

                $ledger = PaymentLedger::insert($order, $payment, [
                    'installment_schedule_id' => $row->id,
                    'amount' => $row->amount_due, 'method' => $method, 'gateway_reference' => substr($ref, 0, 191),
                    'paid_on' => $today, 'recorded_by' => null,
                    'note' => "Installment {$row->installment_no} paid ({$via})",
                ]);

                $row->forceFill([
                    'status' => 'paid', 'paid_at' => now(), 'paid_via' => $via, 'next_attempt_at' => null,
                    'gateway_reference' => $multi ? null : substr($ref, 0, 120),
                ])->save();

                self::attempt($row, $via === 'auto' ? 'charge' : $via, 'ok', ($via === 'auto' ? 'Charged ' : 'Paid ') . '₱' . number_format((float) $row->amount_due, 2) . (str_starts_with($method, 'card_') ? ' by card' : ' with GCash'), $ref, $simulated);

                if ($ledger) {
                    OrderTracking::recordPaymentEvent($order, (float) $row->amount_due, RecordPayment::METHOD_LABELS[$method] ?? $method);
                }

                $paidNow->push([$row, $was, $hadFailure]);
            }

            $paid = PaymentLedger::refreshAmountPaid($order);
            if ($payment->payment_status !== 'refunded' && $paid + 0.001 >= (float) $order->total_amount) {
                $payment->forceFill(['payment_status' => 'paid', 'paid_at' => $payment->paid_at ?: now()])->save();
            }
            if ($order->order_status === 'cancelled') {
                PaymentLedger::flagForReview($order, 'an installment was paid on a cancelled order, check for refund');
            }

            return $rows->count();
        });

        if ($result === 0) {
            return 0;
        }

        // Standing, receipt, activity log: after the money is safely written; none of them may undo it.
        try {
            $order = Order::find($orderId);
            $balance = max(0, round((float) $order->total_amount - (float) $order->amount_paid, 2));
            foreach ($paidNow as [$row, $was, $hadFailure]) {
                $row->refresh();
                $this->standing->onPaid($row, $was, $hadFailure, $via, $today);
                if ($paidNow->count() === 1) {
                    $this->notify->receipt($row, $balance);
                }
                ActivityLog::record('installment_charge_succeeded', "Installment {$row->installment_no} paid for order {$order->order_number}", 'orders', $order, 'Order ' . $order->order_number, null);
            }
            if ($paidNow->count() > 1) {
                $this->notify->receipt($paidNow->first()[0], $balance, (float) $paidNow->sum(fn ($p) => (float) $p[0]->amount_due));
            }
            $this->standing->onPlanProgress((int) $paidNow->first()[0]->payment_id);
        } catch (\Throwable $e) {
            Log::warning('Installment follow-ups failed after a recorded payment: ' . $e->getMessage());
        }

        return $result;
    }

    /** After RecordPayment closes an installment by hand (admin Mark Paid / cash). */
    public function afterManualPayment(InstallmentSchedule $i): void
    {
        try {
            $i->forceFill(['paid_via' => 'admin', 'next_attempt_at' => null])->save();
            self::attempt($i, 'marked_paid', 'ok', 'Marked paid by admin');
            $this->standing->onPlanProgress((int) $i->payment_id);
        } catch (\Throwable $e) {
            Log::warning('Installment manual follow-up failed: ' . $e->getMessage());
        }
    }
}
