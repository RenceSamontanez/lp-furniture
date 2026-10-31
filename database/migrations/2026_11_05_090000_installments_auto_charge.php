<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installments: scheduled charging, the saved card, terms acceptance and the
 * Installment Standing. Every step is guarded so it can be re-run safely.
 */
return new class extends Migration {
    private const OLD_STATUS = ['pending', 'paid', 'overdue'];
    private const NEW_STATUS = ['pending', 'paid', 'overdue', 'failed', 'link_sent', 'processing'];

    public function up(): void
    {
        // payments.payment_method: card installments go through PayMongo.
        if (!SchemaGuard::enumHasValue('payments', 'payment_method', 'paymongo_card')) {
            Schema::table('payments', function (Blueprint $t) {
                $t->enum('payment_method', ['stripe', 'gcash', 'onsite', 'paymongo_card'])->default('stripe')->change();
            });
        }

        Schema::table('payments', function (Blueprint $t) {
            foreach ([
                'paymongo_customer_id'       => fn () => $t->string('paymongo_customer_id', 80)->nullable(),
                'installment_card_method_id' => fn () => $t->string('installment_card_method_id', 80)->nullable(),
                'card_brand'                 => fn () => $t->string('card_brand', 30)->nullable(),
                'card_last4'                 => fn () => $t->string('card_last4', 4)->nullable(),
                'installments_started_at'    => fn () => $t->timestamp('installments_started_at')->nullable(),
                'terms_version'              => fn () => $t->string('terms_version', 30)->nullable(),
                'terms_accepted_at'          => fn () => $t->timestamp('terms_accepted_at')->nullable(),
                'auto_charge_consent_at'     => fn () => $t->timestamp('auto_charge_consent_at')->nullable(),
            ] as $col => $add) {
                if (!Schema::hasColumn('payments', $col)) {
                    $add();
                }
            }
        });

        // payment_records.method: card payments taken through PayMongo.
        if (!SchemaGuard::enumHasValue('payment_records', 'method', 'card_paymongo')) {
            Schema::table('payment_records', function (Blueprint $t) {
                $t->enum('method', ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo', 'card_paymongo'])->change();
            });
        }

        // installment_schedules: status values and charging state.
        if (!SchemaGuard::enumHasValue('installment_schedules', 'status', 'link_sent')) {
            Schema::table('installment_schedules', function (Blueprint $t) {
                $t->enum('status', self::NEW_STATUS)->default('pending')->change();
            });
        }

        Schema::table('installment_schedules', function (Blueprint $t) {
            foreach ([
                'attempts'         => fn () => $t->unsignedTinyInteger('attempts')->default(0),
                'next_attempt_at'  => fn () => $t->timestamp('next_attempt_at')->nullable(),
                'last_attempt_at'  => fn () => $t->timestamp('last_attempt_at')->nullable(),
                'reminder_sent_at' => fn () => $t->timestamp('reminder_sent_at')->nullable(),
                'pay_link_sent_at' => fn () => $t->timestamp('pay_link_sent_at')->nullable(),
                'grace_ends_at'    => fn () => $t->timestamp('grace_ends_at')->nullable(),
                'overdue_at'       => fn () => $t->timestamp('overdue_at')->nullable(),
                'gateway_reference'=> fn () => $t->string('gateway_reference', 120)->nullable(),
                'charge_failed'    => fn () => $t->boolean('charge_failed')->default(false),
                'paid_via'         => fn () => $t->string('paid_via', 20)->nullable(),
            ] as $col => $add) {
                if (!Schema::hasColumn('installment_schedules', $col)) {
                    $add();
                }
            }
        });
        if (!SchemaGuard::indexExists('installment_schedules', 'installment_schedules_status_due_idx')) {
            Schema::table('installment_schedules', fn (Blueprint $t) => $t->index(['status', 'due_date'], 'installment_schedules_status_due_idx'));
        }
        if (!SchemaGuard::indexExists('installment_schedules', 'installment_schedules_gateway_reference_unique')) {
            Schema::table('installment_schedules', fn (Blueprint $t) => $t->unique('gateway_reference', 'installment_schedules_gateway_reference_unique'));
        }

        if (!Schema::hasColumn('users', 'installment_standing_score')) {
            Schema::table('users', function (Blueprint $t) {
                $t->unsignedTinyInteger('installment_standing_score')->default((int) config('installments.start_score', 70));
            });
        }

        if (!Schema::hasTable('installment_standing_history')) {
            Schema::create('installment_standing_history', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->unsignedBigInteger('installment_schedule_id')->nullable();
                $t->unsignedBigInteger('payment_id')->nullable();
                $t->string('scoring_key', 80)->nullable()->unique();
                $t->smallInteger('points');
                $t->unsignedTinyInteger('score_after');
                $t->string('reason', 255);
                $t->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['user_id', 'id']);
            });
        }

        if (!Schema::hasTable('installment_attempts')) {
            Schema::create('installment_attempts', function (Blueprint $t) {
                $t->id();
                $t->foreignId('installment_schedule_id')->constrained('installment_schedules')->cascadeOnDelete();
                $t->string('kind', 24);       // reminder, charge, retry, link, overdue, pay_now, pay_full, marked_paid, webhook
                $t->string('result', 12);     // ok, failed, info
                $t->string('message', 255);
                $t->string('gateway_reference', 120)->nullable();
                $t->boolean('simulated')->default(false);
                $t->timestamp('created_at')->useCurrent();
                $t->index('installment_schedule_id');
            });
        }

        // Existing plans: the down payment was confirmed when the payment was paid.
        // (Catalog orders only: customized orders keep their own agreement schedule.)
        DB::table('payments')->where('payment_type', 'installment')->whereNull('installments_started_at')
            ->where('payment_status', 'paid')
            ->whereIn('order_id', DB::table('orders')->whereNull('design_agreement_id')->select('id'))
            ->update(['installments_started_at' => DB::raw('COALESCE(paid_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_attempts');
        Schema::dropIfExists('installment_standing_history');

        if (Schema::hasColumn('users', 'installment_standing_score')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('installment_standing_score'));
        }

        DB::table('installment_schedules')->whereIn('status', ['failed', 'link_sent', 'processing'])->update(['status' => 'pending']);
        Schema::table('installment_schedules', function (Blueprint $t) {
            $t->dropUnique('installment_schedules_gateway_reference_unique');
            $t->dropIndex('installment_schedules_status_due_idx');
            $t->dropColumn(['attempts', 'next_attempt_at', 'last_attempt_at', 'reminder_sent_at', 'pay_link_sent_at', 'grace_ends_at', 'overdue_at', 'gateway_reference', 'charge_failed', 'paid_via']);
        });
        Schema::table('installment_schedules', fn (Blueprint $t) => $t->enum('status', self::OLD_STATUS)->default('pending')->change());

        DB::table('payment_records')->where('method', 'card_paymongo')->update(['method' => 'gcash_paymongo']);
        Schema::table('payment_records', fn (Blueprint $t) => $t->enum('method', ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo'])->change());

        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn(['paymongo_customer_id', 'installment_card_method_id', 'card_brand', 'card_last4', 'installments_started_at', 'terms_version', 'terms_accepted_at', 'auto_charge_consent_at']);
        });
        DB::table('payments')->where('payment_method', 'paymongo_card')->update(['payment_method' => 'stripe']);
        Schema::table('payments', fn (Blueprint $t) => $t->enum('payment_method', ['stripe', 'gcash', 'onsite'])->default('stripe')->change());
    }
};
