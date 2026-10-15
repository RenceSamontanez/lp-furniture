<?php

namespace App\Support;

use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use InvalidArgumentException;

/**
 * Builds the installment_schedules rows for the balance left after the
 * upfront payment (downpayment / deposit).
 *
 * This is the former OrderController::createInstallmentSchedule(),
 * moved here unchanged in behaviour so the same code serves catalog
 * orders (always exactly 50% down) and custom orders (a deposit of at
 * least MIN_DEPOSIT_PERCENT, chosen on the agreement).
 *
 * Duration (months) and frequency (days between payments) combine to
 * the number of payments — always a whole number across the six
 * allowed combinations (1/2/3 months × 15/30 days), using 30-day
 * months, e.g. 3 months / every 15 days = 6 payments. Amounts are
 * split evenly with the LAST payment absorbing any rounding remainder,
 * so the schedule always sums to exactly the remaining balance.
 */
final class InstallmentScheduler
{
    /** The one definition of the minimum deposit for customized orders. */
    public const MIN_DEPOSIT_PERCENT = 50;

    /** Catalog orders pay exactly this much up front — not configurable per order. */
    public const CATALOG_DOWNPAYMENT_PERCENT = 50;

    public const ALLOWED_DURATION_MONTHS = [1, 2, 3];
    public const ALLOWED_FREQUENCY_DAYS  = [15, 30];

    /** Catalog downpayment: exactly 50% of the total, rounded to centavos. */
    public static function catalogDownpayment(float $total): float
    {
        return round($total * (self::CATALOG_DOWNPAYMENT_PERCENT / 100), 2);
    }

    /** Smallest deposit allowed on a custom order of this total. */
    public static function minimumDeposit(float $total): float
    {
        return round($total * (self::MIN_DEPOSIT_PERCENT / 100), 2);
    }

    /** Number of installment payments for a duration/frequency pair. */
    public static function paymentCount(int $durationMonths, int $frequencyDays): int
    {
        return (int) round(($durationMonths * 30) / $frequencyDays);
    }

    /**
     * Schedule for a catalog-style call: $remaining is the balance
     * after the upfront payment (identical contract and behaviour to
     * the old OrderController method).
     */
    public static function createSchedule(
        Payment $payment,
        Order $order,
        float $remaining,
        int $durationMonths,
        int $frequencyDays
    ): void {
        foreach (self::splitAmounts($remaining, $durationMonths, $frequencyDays) as $row) {
            $i = $row['no'];
            $amountDue = $row['amount'];

            InstallmentSchedule::create([
                'payment_id'      => $payment->id,
                'order_id'        => $order->id,
                'user_id'         => $order->user_id,
                'installment_no'  => $i,
                'amount_due'      => $amountDue,
                'due_date'        => $order->created_at->copy()->addDays($frequencyDays * $i),
                'status'          => 'pending',
            ]);
        }
    }

    /**
     * The payment amounts and day offsets (from the upfront payment) for
     * a remaining balance. Pure: no models, no dates. createSchedule()
     * and every preview use this one implementation, so what an admin
     * is shown is what gets created.
     *
     * @return array<int, array{no:int, amount:float, day_offset:int}>
     */
    public static function splitAmounts(float $remaining, int $durationMonths, int $frequencyDays): array
    {
        $numPayments = self::paymentCount($durationMonths, $frequencyDays);

        $baseAmount = floor(($remaining / $numPayments) * 100) / 100;
        $runningTotal = 0.0;
        $rows = [];

        for ($i = 1; $i <= $numPayments; $i++) {
            $isLast = $i === $numPayments;
            $amountDue = $isLast ? round($remaining - $runningTotal, 2) : $baseAmount;
            $runningTotal += $amountDue;

            $rows[] = ['no' => $i, 'amount' => $amountDue, 'day_offset' => $frequencyDays * $i];
        }

        return $rows;
    }

    /**
     * Schedule for a custom order: validates that the deposit is at
     * least MIN_DEPOSIT_PERCENT of the total (and not more than it),
     * then schedules the balance. A deposit equal to the total leaves
     * no balance and creates no rows.
     *
     * @throws InvalidArgumentException
     */
    public static function createScheduleForDeposit(
        Payment $payment,
        Order $order,
        float $total,
        float $deposit,
        int $durationMonths,
        int $frequencyDays
    ): void {
        self::assertValidDeposit($total, $deposit);

        if (!in_array($durationMonths, self::ALLOWED_DURATION_MONTHS, true)
            || !in_array($frequencyDays, self::ALLOWED_FREQUENCY_DAYS, true)) {
            throw new InvalidArgumentException('Installment duration must be 1, 2 or 3 months and frequency 15 or 30 days.');
        }

        $remaining = round($total - $deposit, 2);

        if ($remaining <= 0) {
            return; // paid in full up front — nothing to schedule
        }

        self::createSchedule($payment, $order, $remaining, $durationMonths, $frequencyDays);
    }

    /** @throws InvalidArgumentException */
    public static function assertValidDeposit(float $total, float $deposit): void
    {
        if ($deposit > $total) {
            throw new InvalidArgumentException('The deposit cannot be more than the total.');
        }

        if ($deposit < self::minimumDeposit($total)) {
            throw new InvalidArgumentException(
                'The deposit must be at least ' . self::MIN_DEPOSIT_PERCENT . '% of the total.'
            );
        }
    }
}
