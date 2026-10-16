<?php

namespace App\Support;

use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place an admin-entered payment (cash, GCash direct, bank
 * transfer, or an installment marked paid) reaches the ledger.
 *
 * Inside one transaction: lockForUpdate the order, compute the
 * remaining balance from the ledger (a posted balance is never used),
 * validate, insert the payment_records row, recompute
 * orders.amount_paid, update payments.payment_status, close the linked
 * installment if the money covers it, and move the order pending ->
 * confirmed once the first required amount has been received. Nothing
 * else ever changes the order status.
 */
class RecordPayment
{
    public const ADMIN_METHODS = ['cash', 'gcash_direct', 'bank_transfer'];

    public const METHOD_LABELS = [
        'cash'           => 'Cash',
        'gcash_direct'   => 'GCash (direct)',
        'bank_transfer'  => 'Bank transfer',
        'stripe'         => 'Stripe',
        'gcash_paymongo' => 'GCash (PayMongo)',
    ];

    /** Same payment from the same admin for the same amount inside this window is a double submit. */
    public const DUPLICATE_WINDOW_SECONDS = 10;

    public function record(
        int $orderId,
        User $admin,
        mixed $amount,
        string $method,
        ?string $paidOn,
        ?string $note = null,
        ?int $installmentId = null,
    ): PaymentRecord {
        return DB::transaction(function () use ($orderId, $admin, $amount, $method, $paidOn, $note, $installmentId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $payment = Payment::where('order_id', $order->id)->lockForUpdate()->first();

            if (!$payment) {
                throw new PaymentRecordException('This order has no payment record to apply money to.');
            }
            if ($order->order_status === 'cancelled') {
                throw new PaymentRecordException('This order is cancelled. Payments are not recorded against a cancelled order; write any money handling in the order note.');
            }

            $cents = $this->amountToCents($amount);
            if ($cents <= 0) {
                throw new PaymentRecordException('Enter an amount greater than zero, with at most two decimals.');
            }

            // Derived from the ledger under the lock: the form's idea of the balance is never used.
            $remaining = round((float) $order->total_amount - (float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
            if ($remaining <= 0) {
                throw new PaymentRecordException('This order has no balance left to pay.');
            }
            if ($cents > (int) round($remaining * 100)) {
                throw new PaymentRecordException('The amount is more than the remaining balance of ₱' . number_format($remaining, 2) . '.');
            }

            if (!in_array($method, self::ADMIN_METHODS, true)) {
                throw new PaymentRecordException('Choose how the money was received: cash, GCash (direct) or bank transfer.');
            }

            $date = $this->parseDate($paidOn);

            $installment = null;
            if ($installmentId !== null) {
                $installment = InstallmentSchedule::where('order_id', $order->id)->lockForUpdate()->find($installmentId);
                if (!$installment) {
                    throw new PaymentRecordException('That installment does not belong to this order.');
                }
                if ($installment->status === 'paid') {
                    throw new PaymentRecordException('That installment is already paid.');
                }
            }

            $amountStr = number_format($cents / 100, 2, '.', '');

            $recent = PaymentRecord::where('order_id', $order->id)
                ->where('recorded_by', $admin->id)
                ->where('amount', $amountStr)
                ->where('installment_schedule_id', $installment?->id)
                ->where('method', $method)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
                ->exists();
            if ($recent) {
                throw new PaymentRecordException('This looks like a double submit: the same payment was just recorded. Reload the page to see it.');
            }

            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            if ($note !== null && mb_strlen($note) > 1000) {
                throw new PaymentRecordException('The note is too long (1000 characters at most).');
            }

            $record = PaymentRecord::create([
                'order_id'                => $order->id,
                'payment_id'              => $payment->id,
                'installment_schedule_id' => $installment?->id,
                'amount'                  => $amountStr,
                'method'                  => $method,
                'gateway_reference'       => null,
                'paid_on'                 => $date->toDateString(),
                'recorded_by'             => $admin->id,
                'note'                    => $note,
            ]);

            $paid = PaymentLedger::refreshAmountPaid($order);
            $total = round((float) $order->total_amount, 2);

            // payments.payment_status follows the ledger (refunded is left alone).
            if ($payment->payment_status !== 'refunded') {
                $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : $payment->payment_status);
                $payment->forceFill(array_filter([
                    'payment_status' => $status,
                    'paid_at'        => $status === 'paid' && !$payment->paid_at ? now() : null,
                ], fn ($v) => $v !== null))->save();
            }

            if ($installment) {
                $covered = round((float) PaymentRecord::where('installment_schedule_id', $installment->id)->sum('amount'), 2);
                if ($covered + 0.001 >= (float) $installment->amount_due) {
                    $installment->update(['status' => 'paid', 'paid_at' => now()]);
                }
            }

            // pending -> confirmed once the first required amount is in:
            // the downpayment / deposit / full price, i.e. payments.amount.
            $confirmed = false;
            if ($order->order_status === 'pending' && $paid + 0.001 >= (float) $payment->amount) {
                $order->forceFill([
                    'order_status'      => 'confirmed',
                    'status_updated_by' => $admin->id,
                    'status_updated_at' => now(),
                ])->save();
                $confirmed = true;

                if ($order->design_request_id) {
                    \App\Models\CustomOrderEvent::create([
                        'request_id'  => $order->design_request_id,
                        'order_id'    => $order->id,
                        'type'        => 'order_confirmed',
                        'actor_type'  => \App\Models\CustomOrderEvent::ACTOR_ADMIN,
                        'actor_id'    => $admin->id,
                        'data'        => ['via' => $method, 'amount' => $amountStr],
                        'occurred_at' => now(),
                    ]);
                }

                if ($ready = app(AgreementCheckout::class)->expectedReadyDate($order, $date)) {
                    $order->forceFill(['expected_ready_date' => $ready->toDateString()])->save();
                }
            }

            $this->notifyCustomer($order, $record, $total - $paid, $confirmed);

            return $record;
        });
    }

    /**
     * Mark one installment paid for the Installments page button:
     * the same ledger path, for the installment's own amount.
     * Returns null when the ledger already shows the order fully paid
     * (the row is then only closed, no money is invented).
     */
    public function recordInstallment(InstallmentSchedule $installment, User $admin, string $method = 'cash', ?string $note = null): ?PaymentRecord
    {
        return DB::transaction(function () use ($installment, $admin, $method, $note) {
            $order = Order::lockForUpdate()->findOrFail($installment->order_id);
            $row = InstallmentSchedule::lockForUpdate()->findOrFail($installment->id);

            if ($row->status === 'paid') {
                throw new PaymentRecordException('That installment is already paid.');
            }

            $remaining = round((float) $order->total_amount - (float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
            $due = min((float) $row->amount_due, $remaining);

            if ($due <= 0) {
                $row->update(['status' => 'paid', 'paid_at' => now()]);

                return null;
            }

            return $this->record(
                $order->id,
                $admin,
                number_format($due, 2, '.', ''),
                $method,
                now(AgreementTerms::TZ)->toDateString(),
                $note ?? "Installment #{$row->installment_no} marked paid from the Installments page",
                $row->id
            );
        });
    }

    /** Clear an order's needs_review flag, recording who and when. */
    public function markReviewed(int $orderId, User $admin): void
    {
        DB::transaction(function () use ($orderId, $admin) {
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if (!$order->needs_review) {
                throw new PaymentRecordException('This order is not flagged for review.');
            }

            $order->forceFill([
                'needs_review' => false,
                'reviewed_by'  => $admin->id,
                'reviewed_at'  => now(),
            ])->save();
        });
    }

    /* ───────────── internals ───────────── */

    private function amountToCents(mixed $amount): int
    {
        if (is_int($amount) || is_float($amount)) {
            $amount = number_format((float) $amount, 2, '.', '');
        }
        $amount = is_string($amount) ? trim($amount) : '';

        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $amount)) {
            return 0;
        }

        [$whole, $frac] = array_pad(explode('.', $amount), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
    }

    private function parseDate(?string $paidOn): Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $paidOn, AgreementTerms::TZ)->startOfDay();
        } catch (\Throwable) {
            throw new PaymentRecordException('Enter the date the payment was received.');
        }

        if ($date->format('Y-m-d') !== trim((string) $paidOn)) {
            throw new PaymentRecordException('Enter the date the payment was received.');
        }
        if ($date->greaterThan(now(AgreementTerms::TZ)->startOfDay())) {
            throw new PaymentRecordException('The payment date cannot be in the future.');
        }

        return $date;
    }

    private function notifyCustomer(Order $order, PaymentRecord $record, float $balanceLeft, bool $confirmed): void
    {
        $amount = number_format((float) $record->amount, 2);
        $custom = $order->design_agreement_id !== null;

        $t = NotificationTemplates::render('payment_received', [
            'amount'       => (float) $record->amount,
            'order_number' => $order->order_number,
            'balance'      => $balanceLeft,
            'confirmed'    => $confirmed,
            'ready_date'   => $custom && $order->expected_ready_date ? Carbon::parse($order->expected_ready_date)->format('M d, Y') : null,
        ]);

        Notification::create([
            'user_id' => $order->user_id,
            'title'   => $t['title'],
            'message' => $t['message'],
            'type'    => $custom ? 'custom_order' : 'payment_confirmed',
            'url'     => route('orders.show', $order->id),
            'is_read' => false,
        ]);
    }
}
