<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M7 — the payment ledger (every peso received, by whom, how), plus
 * the two payments-table changes the custom-order flow needs:
 * a 'partial' payment status and a link to the agreement.
 *
 * UNIQUE(method, gateway_reference) stops one gateway capture from
 * being recorded twice. gateway_reference is NULL for cash, and MySQL
 * allows many NULLs in a unique index.
 */
return new class extends Migration {
    private const PAYMENTS_FK = 'payments_design_agreement_id_foreign';

    public function up(): void
    {
        if (!Schema::hasTable('payment_records')) {
            Schema::create('payment_records', function (Blueprint $table) {
                $table->id();

                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
                $table->foreignId('installment_schedule_id')
                      ->nullable()
                      ->constrained('installment_schedules')
                      ->nullOnDelete();

                $table->decimal('amount', 10, 2);
                $table->enum('method', ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo']);
                $table->string('gateway_reference', 191)->nullable();
                $table->date('paid_on');

                // NULL = recorded by the system (gateway confirmation or backfill)
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['method', 'gateway_reference']);
                $table->index('order_id');
            });
        }

        if (Schema::hasTable('payments')) {
            if (!SchemaGuard::enumHasValue('payments', 'payment_status', 'partial')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded', 'partial'])
                          ->default('pending')
                          ->change();
                });
            }

            if (!Schema::hasColumn('payments', 'design_agreement_id')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->unsignedBigInteger('design_agreement_id')->nullable();
                });
            }

            if (!SchemaGuard::foreignKeyExists('payments', self::PAYMENTS_FK)) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->foreign('design_agreement_id', self::PAYMENTS_FK)
                          ->references('id')->on('design_agreements')
                          ->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            if (SchemaGuard::foreignKeyExists('payments', self::PAYMENTS_FK)) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropForeign(self::PAYMENTS_FK);
                });
            }

            if (Schema::hasColumn('payments', 'design_agreement_id')) {
                Schema::table('payments', function (Blueprint $table) {
                    $table->dropColumn('design_agreement_id');
                });
            }

            if (SchemaGuard::enumHasValue('payments', 'payment_status', 'partial')) {
                // Map the new value back first so the shrink can't fail or truncate.
                DB::table('payments')->where('payment_status', 'partial')->update(['payment_status' => 'pending']);

                Schema::table('payments', function (Blueprint $table) {
                    $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])
                          ->default('pending')
                          ->change();
                });
            }
        }

        Schema::dropIfExists('payment_records');
    }
};
