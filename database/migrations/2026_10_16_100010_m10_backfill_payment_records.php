<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10 — give the new ledger a starting balance from existing data, so
 * orders.amount_paid is correct from day one.
 *
 *   • online payments (Stripe / GCash-PayMongo) already 'paid'
 *   • installment rows already marked 'paid' by an admin
 *
 * Each gets one system entry (recorded_by NULL) whose note starts with
 * "Backfilled:". Cash ('onsite') orders get NONE: nothing in the
 * database says they were ever paid, so they show as unrecorded rather
 * than being guessed at.
 *
 * Installment rows don't record how they were paid; the parent
 * payment's method is used (onsite → cash, stripe → stripe,
 * gcash → gcash_paymongo) and the note says so.
 *
 * Idempotent: re-running adds nothing already backfilled.
 */
return new class extends Migration {
    private const NOTE_PREFIX = 'Backfilled:';

    private const ONLINE_METHOD = ['stripe' => 'stripe', 'gcash' => 'gcash_paymongo'];
    private const INSTALLMENT_METHOD = ['onsite' => 'cash', 'stripe' => 'stripe', 'gcash' => 'gcash_paymongo'];

    public function up(): void
    {
        if (!Schema::hasTable('payment_records') || !Schema::hasTable('payments')) {
            return;
        }

        // ── 1. Online payments already marked paid ──
        DB::table('payments')
            ->where('payment_status', 'paid')
            ->whereIn('payment_method', array_keys(self::ONLINE_METHOD))
            ->orderBy('id')
            ->chunkById(200, function ($payments) {
                foreach ($payments as $payment) {
                    $alreadyDone = DB::table('payment_records')
                        ->where('payment_id', $payment->id)
                        ->whereNull('installment_schedule_id')
                        ->where('note', 'like', self::NOTE_PREFIX . '%')
                        ->exists();

                    if ($alreadyDone) {
                        continue;
                    }

                    $reference = $payment->payment_method === 'stripe'
                        ? ($payment->stripe_charge_id ?: $payment->stripe_payment_intent_id)
                        : $payment->paymongo_payment_intent_id;

                    DB::table('payment_records')->insertOrIgnore([
                        'order_id'          => $payment->order_id,
                        'payment_id'        => $payment->id,
                        'amount'            => $payment->amount,
                        'method'            => self::ONLINE_METHOD[$payment->payment_method],
                        'gateway_reference' => $reference ? substr($reference, 0, 191) : null,
                        'paid_on'           => substr((string) ($payment->paid_at ?: $payment->updated_at ?: $payment->created_at ?: now()), 0, 10),
                        'recorded_by'       => null,
                        'note'              => self::NOTE_PREFIX . ' existing paid ' . $payment->payment_method . ' payment',
                        'created_at'        => now(),
                    ]);
                }
            });

        // ── 2. Installment rows already marked paid ──
        if (Schema::hasTable('installment_schedules')) {
            DB::table('installment_schedules')
                ->where('status', 'paid')
                ->orderBy('id')
                ->chunkById(200, function ($installments) {
                    $methods = DB::table('payments')
                        ->whereIn('id', $installments->pluck('payment_id')->unique())
                        ->pluck('payment_method', 'id');

                    foreach ($installments as $installment) {
                        $alreadyDone = DB::table('payment_records')
                            ->where('installment_schedule_id', $installment->id)
                            ->exists();

                        if ($alreadyDone) {
                            continue;
                        }

                        $parentMethod = $methods[$installment->payment_id] ?? 'onsite';

                        DB::table('payment_records')->insert([
                            'order_id'                => $installment->order_id,
                            'payment_id'              => $installment->payment_id,
                            'installment_schedule_id' => $installment->id,
                            'amount'                  => $installment->amount_due,
                            'method'                  => self::INSTALLMENT_METHOD[$parentMethod] ?? 'cash',
                            'gateway_reference'       => null,
                            'paid_on'                 => substr((string) ($installment->paid_at ?: $installment->created_at ?: now()), 0, 10),
                            'recorded_by'             => null,
                            'note'                    => self::NOTE_PREFIX . " installment #{$installment->installment_no} marked paid by an admin (method assumed from the order's payment method; not recorded at the time)",
                            'created_at'              => now(),
                        ]);
                    }
                });
        }

        // ── 3. orders.amount_paid = sum of the ledger ──
        if (Schema::hasColumn('orders', 'amount_paid')) {
            DB::statement(
                'UPDATE orders o SET o.amount_paid = COALESCE('
                . '(SELECT SUM(pr.amount) FROM payment_records pr WHERE pr.order_id = o.id), 0)'
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_records')) {
            return;
        }

        DB::table('payment_records')->where('note', 'like', self::NOTE_PREFIX . '%')->delete();

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'amount_paid')) {
            DB::statement(
                'UPDATE orders o SET o.amount_paid = COALESCE('
                . '(SELECT SUM(pr.amount) FROM payment_records pr WHERE pr.order_id = o.id), 0)'
            );
        }
    }
};
