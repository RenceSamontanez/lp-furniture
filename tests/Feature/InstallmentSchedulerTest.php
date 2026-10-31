<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Support\InstallmentScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * InstallmentScheduler was extracted from OrderController. These tests
 * pin the extraction: catalog schedules must be identical to what the
 * old private method produced, for all six duration/frequency pairs.
 */
class InstallmentSchedulerTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const COMBINATIONS = [
        // [months, every N days, expected number of payments]
        [1, 15, 2], [1, 30, 1],
        [2, 15, 4], [2, 30, 2],
        [3, 15, 6], [3, 30, 3],
    ];

    private const TOTALS = [1000.00, 1000.01, 999.99, 12345.67, 33.33, 77777.77, 250.05, 5.00];

    /**
     * The ORIGINAL algorithm, copied verbatim from
     * OrderController::createInstallmentSchedule() at commit 20ee512
     * (and the old `round($total * 0.5, 2)` downpayment), returning
     * the rows it would have inserted instead of inserting them.
     * Do not "tidy" this — it is the reference being compared against.
     */
    private function legacySchedule(float $total, int $durationMonths, int $frequencyDays, Carbon $orderCreatedAt): array
    {
        $downpayment = round($total * 0.5, 2);
        $remaining   = $total - $downpayment;

        $numPayments = (int) round(($durationMonths * 30) / $frequencyDays);

        $baseAmount = floor(($remaining / $numPayments) * 100) / 100;
        $runningTotal = 0.0;
        $rows = [];

        for ($i = 1; $i <= $numPayments; $i++) {
            $isLast = $i === $numPayments;
            $amountDue = $isLast ? round($remaining - $runningTotal, 2) : $baseAmount;
            $runningTotal += $amountDue;

            $rows[] = [
                'installment_no' => $i,
                'amount_due'     => number_format($amountDue, 2, '.', ''),
                'due_date'       => $orderCreatedAt->copy()->addDays($frequencyDays * $i)->toDateString(),
            ];
        }

        return ['downpayment' => number_format($downpayment, 2, '.', ''), 'rows' => $rows];
    }

    private function storedSchedule(int $orderId): array
    {
        return DB::table('installment_schedules')
            ->where('order_id', $orderId)
            ->orderBy('installment_no')
            ->get(['installment_no', 'amount_due', 'due_date'])
            ->map(fn ($r) => [
                'installment_no' => (int) $r->installment_no,
                'amount_due'     => number_format((float) $r->amount_due, 2, '.', ''),
                'due_date'       => (string) $r->due_date,
            ])
            ->all();
    }

    public function test_catalog_downpayment_is_exactly_fifty_percent_as_before(): void
    {
        foreach (self::TOTALS as $total) {
            $this->assertSame(round($total * 0.5, 2), InstallmentScheduler::catalogDownpayment($total), "total {$total}");
        }
    }

    public function test_payment_counts_for_all_six_combinations(): void
    {
        foreach (self::COMBINATIONS as [$months, $freq, $expected]) {
            $this->assertSame($expected, InstallmentScheduler::paymentCount($months, $freq), "{$months} months / {$freq} days");
        }
    }

    public function test_scheduler_matches_the_original_algorithm_for_every_combination_and_total(): void
    {
        $customer = $this->makeUser();
        $compared = 0;

        foreach (self::COMBINATIONS as [$months, $freq, $expectedCount]) {
            foreach (self::TOTALS as $total) {
                $order   = $this->makeOrder($customer, ['total_amount' => $total]);
                $payment = $this->makePayment($order, [
                    'amount'                      => InstallmentScheduler::catalogDownpayment($total),
                    'payment_type'                => 'installment',
                    'installment_duration_months' => $months,
                    'installment_frequency_days'  => $freq,
                ]);

                // Exactly how OrderController::placeOrder() now calls it.
                $downpayment = InstallmentScheduler::catalogDownpayment($total);
                InstallmentScheduler::createSchedule($payment, $order, $total - $downpayment, $months, $freq);

                $legacy = $this->legacySchedule($total, $months, $freq, $order->created_at);
                $stored = $this->storedSchedule($order->id);

                $this->assertSame($legacy['rows'], $stored, "schedule differs for total {$total}, {$months}mo/{$freq}d");
                $this->assertCount($expectedCount, $stored);
                $this->assertSame($legacy['downpayment'], number_format($downpayment, 2, '.', ''));

                // downpayment + schedule always reproduces the total exactly
                $sum = round((float) $downpayment + array_sum(array_map(fn ($r) => (float) $r['amount_due'], $stored)), 2);
                $this->assertSame(round($total, 2), $sum, "does not sum to total for {$total}, {$months}mo/{$freq}d");

                $compared++;
            }
        }

        $this->assertSame(6 * count(self::TOTALS), $compared);
    }

    public function test_checkout_route_still_builds_the_same_schedule_for_all_six_combinations(): void
    {
        $price = 3333.33;

        foreach (self::COMBINATIONS as [$months, $freq, $expectedCount]) {
            $customer = $this->makeUser();
            $product  = Product::create([
                'product_name' => 'Chair', 'price' => $price, 'stock_quantity' => 5, 'product_status' => 'available',
            ]);

            DB::table('cart_items')->insert([
                'user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1,
                'added_at' => now(), 'updated_at' => now(),
            ]);

            $this->actingAs($customer)->post(route('order.place'), [
                'delivery_option'       => 'pickup',
                'payment_type'          => 'installment',
                'payment_method'        => 'gcash',
                'accept_installment_terms' => 1,
                'installment_duration'  => $months,
                'installment_frequency' => $freq,
            ])->assertRedirect();

            $order = Order::where('user_id', $customer->id)->latest('id')->firstOrFail();

            $legacy = $this->legacySchedule($price, $months, $freq, $order->created_at);

            $this->assertSame($legacy['rows'], $this->storedSchedule($order->id), "{$months}mo/{$freq}d via checkout");
            $this->assertCount($expectedCount, $legacy['rows']);
            $this->assertSame($legacy['downpayment'], number_format((float) $order->payment->amount, 2, '.', ''));
        }
    }

    /* ───────────── deposits (custom orders) ───────────── */

    public function test_minimum_deposit_is_one_named_constant_at_fifty_percent(): void
    {
        $this->assertSame(50, InstallmentScheduler::MIN_DEPOSIT_PERCENT);
        $this->assertSame(5000.00, InstallmentScheduler::minimumDeposit(10000.00));
        $this->assertSame(\App\Models\CustomQuote::class, (new \ReflectionMethod(\App\Models\CustomQuote::class, 'minimumDeposit'))->class);
    }

    public function test_deposit_of_fifty_percent_or_more_is_scheduled_correctly(): void
    {
        $customer = $this->makeUser();

        foreach ([5000.00, 5000.01, 6000.00, 9999.99] as $deposit) {
            foreach (self::COMBINATIONS as [$months, $freq, $expectedCount]) {
                $order   = $this->makeOrder($customer, ['total_amount' => 10000.00]);
                $payment = $this->makePayment($order, ['amount' => $deposit, 'payment_type' => 'installment']);

                InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, $deposit, $months, $freq);

                $rows = $this->storedSchedule($order->id);
                $this->assertCount($expectedCount, $rows, "deposit {$deposit}, {$months}mo/{$freq}d");

                $sum = round($deposit + array_sum(array_map(fn ($r) => (float) $r['amount_due'], $rows)), 2);
                $this->assertSame(10000.00, $sum, "deposit {$deposit} + schedule must equal the total");
            }
        }
    }

    public function test_full_payment_deposit_creates_no_schedule(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 10000.00]);
        $payment  = $this->makePayment($order, ['amount' => 10000.00]);

        InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, 10000.00, 2, 30);

        $this->assertSame([], $this->storedSchedule($order->id));
    }

    public function test_deposit_below_minimum_or_above_total_is_rejected(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 10000.00]);
        $payment  = $this->makePayment($order, ['amount' => 4999.99]);

        foreach ([4999.99, 0.0, 1.00] as $tooLow) {
            try {
                InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, $tooLow, 2, 30);
                $this->fail("deposit {$tooLow} should have been rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('at least 50%', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, 10000.01, 2, 30);
    }

    public function test_deposit_schedule_rejects_unsupported_duration_or_frequency(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 10000.00]);
        $payment  = $this->makePayment($order, ['amount' => 5000.00]);

        $this->expectException(InvalidArgumentException::class);
        InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, 5000.00, 4, 10);
    }

    public function test_rejected_deposit_leaves_no_partial_schedule_rows(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 10000.00]);
        $payment  = $this->makePayment($order, ['amount' => 100.00]);

        try {
            InstallmentScheduler::createScheduleForDeposit($payment, $order, 10000.00, 100.00, 3, 15);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([], $this->storedSchedule($order->id));
    }
}
