<?php

namespace Tests\Feature;

use App\Models\PaymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * M10: the ledger backfill gives existing online payments and paid
 * installments a system entry, and leaves cash orders alone.
 */
class PaymentLedgerBackfillTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_10_16_100010_m10_backfill_payment_records.php');
        $migration->up();
    }

    private function insertInstallment(int $paymentId, int $orderId, int $userId, int $no, string $status, ?string $paidAt = null): int
    {
        return DB::table('installment_schedules')->insertGetId([
            'payment_id' => $paymentId, 'order_id' => $orderId, 'user_id' => $userId,
            'installment_no' => $no, 'amount_due' => 500, 'due_date' => now()->addDays(15 * $no)->toDateString(),
            'status' => $status, 'paid_at' => $paidAt,
        ]);
    }

    public function test_backfill_records_online_and_paid_installments_but_never_cash(): void
    {
        $customer = $this->makeUser();

        // Paid Stripe order (full)
        $stripeOrder = $this->makeOrder($customer, ['total_amount' => 1000]);
        $this->makePayment($stripeOrder, [
            'amount' => 1000, 'payment_method' => 'stripe', 'payment_status' => 'paid',
            'stripe_charge_id' => 'pi_backfill_1', 'paid_at' => now(),
        ]);

        // Paid GCash installment order: 500 down (paid online) + one paid, one pending installment
        $gcashOrder   = $this->makeOrder($customer, ['total_amount' => 1500]);
        $gcashPayment = $this->makePayment($gcashOrder, [
            'amount' => 750, 'payment_method' => 'gcash', 'payment_status' => 'paid',
            'payment_type' => 'installment', 'paymongo_payment_intent_id' => 'pm_backfill_1', 'paid_at' => now(),
        ]);
        $this->insertInstallment($gcashPayment->id, $gcashOrder->id, $customer->id, 1, 'paid', now()->toDateTimeString());
        $this->insertInstallment($gcashPayment->id, $gcashOrder->id, $customer->id, 2, 'pending');

        // Cash order whose installment an admin marked paid by hand
        $cashOrder   = $this->makeOrder($customer, ['total_amount' => 1000]);
        $cashPayment = $this->makePayment($cashOrder, [
            'amount' => 500, 'payment_method' => 'onsite', 'payment_status' => 'pending', 'payment_type' => 'installment',
        ]);
        $this->insertInstallment($cashPayment->id, $cashOrder->id, $customer->id, 1, 'paid', now()->toDateTimeString());

        // Cash order with nothing recorded at all — must stay unrecorded
        $untouched = $this->makeOrder($customer, ['total_amount' => 800]);
        $this->makePayment($untouched, ['amount' => 800, 'payment_method' => 'onsite', 'payment_status' => 'pending']);

        // Unpaid Stripe order — must get nothing
        $unpaid = $this->makeOrder($customer, ['total_amount' => 900]);
        $this->makePayment($unpaid, ['amount' => 900, 'payment_method' => 'stripe', 'payment_status' => 'pending']);

        $this->runBackfill();

        $stripeEntry = PaymentRecord::where('order_id', $stripeOrder->id)->sole();
        $this->assertSame('stripe', $stripeEntry->method);
        $this->assertSame('pi_backfill_1', $stripeEntry->gateway_reference);
        $this->assertNull($stripeEntry->recorded_by, 'system entry');
        $this->assertStringStartsWith('Backfilled:', $stripeEntry->note);

        $gcashEntries = PaymentRecord::where('order_id', $gcashOrder->id)->get();
        $this->assertCount(2, $gcashEntries, 'down payment + the one paid installment');
        $this->assertSame(['gcash_paymongo'], $gcashEntries->pluck('method')->unique()->values()->all());

        $cashEntry = PaymentRecord::where('order_id', $cashOrder->id)->sole();
        $this->assertSame('cash', $cashEntry->method);
        $this->assertNotNull($cashEntry->installment_schedule_id);

        $this->assertSame(0, PaymentRecord::where('order_id', $untouched->id)->count(), 'cash order with no record gets nothing');
        $this->assertSame(0, PaymentRecord::where('order_id', $unpaid->id)->count(), 'unpaid order gets nothing');

        // orders.amount_paid reflects the ledger
        $this->assertSame('1000.00', (string) DB::table('orders')->where('id', $stripeOrder->id)->value('amount_paid'));
        $this->assertSame('1250.00', (string) DB::table('orders')->where('id', $gcashOrder->id)->value('amount_paid'));
        $this->assertSame('500.00', (string) DB::table('orders')->where('id', $cashOrder->id)->value('amount_paid'));
        $this->assertSame('0.00', (string) DB::table('orders')->where('id', $untouched->id)->value('amount_paid'));
    }

    public function test_backfill_is_idempotent(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 1000]);
        $payment  = $this->makePayment($order, [
            'amount' => 1000, 'payment_method' => 'stripe', 'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_idem', 'paid_at' => now(),
        ]);
        $this->insertInstallment($payment->id, $order->id, $customer->id, 1, 'paid', now()->toDateTimeString());

        $this->runBackfill();
        $first = PaymentRecord::where('order_id', $order->id)->count();
        $this->runBackfill();

        $this->assertSame(2, $first);
        $this->assertSame($first, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('1500.00', (string) DB::table('orders')->where('id', $order->id)->value('amount_paid'));
    }

    public function test_backfill_rollback_removes_only_its_own_entries(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer, ['total_amount' => 1000]);
        $payment  = $this->makePayment($order, [
            'amount' => 1000, 'payment_method' => 'stripe', 'payment_status' => 'paid',
            'stripe_charge_id' => 'pi_rb', 'paid_at' => now(),
        ]);

        $this->runBackfill();

        // A real, human-recorded entry that must survive the rollback
        PaymentRecord::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 50,
            'method' => 'cash', 'paid_on' => now()->toDateString(),
            'recorded_by' => $customer->id, 'note' => 'Balance received in person',
        ]);

        $migration = require base_path('database/migrations/2026_10_16_100010_m10_backfill_payment_records.php');
        $migration->down();

        $this->assertSame(1, PaymentRecord::where('order_id', $order->id)->count());
        $this->assertSame('Balance received in person', PaymentRecord::where('order_id', $order->id)->value('note'));
        $this->assertSame('50.00', (string) DB::table('orders')->where('id', $order->id)->value('amount_paid'));
    }
}
