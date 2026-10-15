<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Small helpers around the payment ledger (payment_records).
 *
 * Every method that writes money expects the caller to be inside a
 * transaction that already holds lockForUpdate() on the order row.
 * orders.amount_paid is always recomputed as SUM(ledger) rather than
 * incremented, so it can never drift from the ledger.
 */
final class PaymentLedger
{
    /**
     * Insert a ledger row. Returns false (and writes nothing) when this
     * method + gateway_reference was already recorded: the UNIQUE index
     * makes a repeat capture a no-op instead of an error.
     */
    public static function insert(Order $order, Payment $payment, array $attrs): ?PaymentRecord
    {
        try {
            // A nested transaction is a savepoint, so a duplicate-key error
            // doesn't poison the caller's transaction.
            return DB::transaction(fn () => PaymentRecord::create($attrs + [
                'order_id'   => $order->id,
                'payment_id' => $payment->id,
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000' && str_contains($e->getMessage(), 'payment_records_method_gateway_reference_unique')) {
                return null;
            }
            throw $e;
        }
    }

    /** orders.amount_paid := SUM(payment_records.amount). Returns the new total. */
    public static function refreshAmountPaid(Order $order): float
    {
        $sum = round((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
        $order->forceFill(['amount_paid' => $sum])->save();

        return $sum;
    }

    /** What the customer still owes on the order, never below zero. */
    public static function remaining(Order $order): float
    {
        return max(0.0, round((float) $order->total_amount - (float) $order->amount_paid, 2));
    }

    /**
     * Flag an order for a person to look at and alert every admin.
     * The order status is never touched here.
     */
    public static function flagForReview(Order $order, string $reason): void
    {
        $order->forceFill([
            'needs_review'  => true,
            'review_reason' => mb_substr($order->review_reason ? $order->review_reason . '; ' . $reason : $reason, 0, 255),
            'reviewed_by'   => null,
            'reviewed_at'   => null,
        ])->save();

        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            Notification::create([
                'user_id' => $adminId,
                'title'   => 'Payment Needs Review',
                'message' => "Order {$order->order_number}: {$reason}",
                'type'    => 'general',
                'url'     => route('admin.orders.show', $order->id),
                'is_read' => false,
            ]);
        }
    }
}
