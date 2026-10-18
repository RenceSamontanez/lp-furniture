<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 21e: online payments for "Other Services" invoices. Additive only: three columns on
 * service_invoices and two new tables. Nothing existing is renamed or dropped, and none of this
 * touches the custom-order payment ledger. Every step skips what already exists.
 * Rolling back drops only what this migration added.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('service_invoices', 'payment_plan')) {
                $table->string('payment_plan', 10)->default('full');               // full, half_half
            }
            if (!Schema::hasColumn('service_invoices', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('service_invoices', 'service_done_at')) {
                $table->dateTime('service_done_at')->nullable();                   // dateTime: no implicit ON UPDATE on MariaDB
            }
        });

        if (!Schema::hasTable('service_invoice_payments')) {
            Schema::create('service_invoice_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
                $table->unsignedTinyInteger('installment');                        // 1 or 2
                $table->decimal('amount', 10, 2);
                $table->string('method', 10);                                      // stripe, paymongo, cash
                $table->string('status', 10)->default('pending');                  // pending, paid, failed, expired
                $table->string('provider_reference', 100)->nullable();             // Stripe session id / PayMongo intent id
                $table->text('checkout_url')->nullable();                          // lets a double click reuse the open checkout
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->string('note', 500)->nullable();                           // why a payment was flagged (amount mismatch, late after cancel...)
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();   // the admin, for cash
                // "<invoice id>-<installment>" while status = paid, otherwise NULL. The UNIQUE index below
                // therefore lets an installment have only one PAID record (MySQL has no partial indexes).
                $table->string('paid_slot', 40)->nullable();
                $table->timestamps();

                $table->unique(['method', 'provider_reference'], 'sip_provider_ref_unique');
                $table->unique('paid_slot', 'sip_one_paid_per_installment');
                $table->index(['service_invoice_id', 'installment', 'status'], 'sip_invoice_installment_status');
            });
        }

        if (!Schema::hasTable('service_invoice_refund_notes')) {
            Schema::create('service_invoice_refund_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_invoice_id')->constrained('service_invoices')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('method', 20);                                      // stripe, gcash, cash, other
                $table->string('note', 1000);
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('service_invoice_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_refund_notes');
        Schema::dropIfExists('service_invoice_payments');

        Schema::table('service_invoices', function (Blueprint $table) {
            foreach (['payment_plan', 'amount_paid', 'service_done_at'] as $col) {
                if (Schema::hasColumn('service_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
