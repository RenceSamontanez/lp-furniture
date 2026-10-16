<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 21 follow-up: simple invoices for "Other Services" tickets. Additive only: two new tables,
 * nothing existing is touched. An invoice is NOT part of the payment ledger, Stripe, PayMongo or
 * the custom order pipeline. Money is decimal(10,2), like quotes. Skips a table that already exists.
 * Rolling back drops only these two tables.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('service_invoices')) {
            Schema::create('service_invoices', function (Blueprint $table) {
                $table->id();

                // RESTRICT: a ticket that has an invoice cannot be deleted away from under it.
                $table->foreignId('service_inquiry_id')->constrained('service_inquiries')->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                // INV-YYYY-NNNN, given when the invoice is issued (so drafts leave no gaps). NULL until then.
                $table->string('invoice_number', 20)->nullable()->unique();
                $table->string('status', 10)->default('draft');          // draft, issued, paid, void

                // Customer snapshot, copied from the ticket when the draft is created.
                $table->string('customer_name', 120);
                $table->string('customer_phone', 30);
                $table->string('customer_email', 150)->nullable();
                $table->text('customer_address')->nullable();

                $table->string('service_description', 200);
                $table->string('delivery_option', 20)->default('none');  // none, home_service, delivery_one_way, delivery_round_trip
                $table->text('notes')->nullable();

                $table->decimal('subtotal', 10, 2)->default(0);
                $table->decimal('total', 10, 2)->default(0);

                // dateTime, not timestamp: avoids MariaDB's implicit ON UPDATE CURRENT_TIMESTAMP.
                $table->dateTime('issued_at')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->string('paid_method', 20)->nullable();           // cash, gcash_direct, bank_transfer
                $table->string('paid_reference', 100)->nullable();
                $table->decimal('amount_received', 10, 2)->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->string('void_reason', 1000)->nullable();

                $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['service_inquiry_id', 'status']);
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('service_invoice_lines')) {
            Schema::create('service_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('service_invoices')->restrictOnDelete();
                $table->string('kind', 20);   // service_cost, material_fee, other_fee, delivery_fee, home_service_fee
                $table->string('description', 200);
                $table->decimal('amount', 10, 2);
                $table->timestamps();

                $table->index('invoice_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_invoice_lines');
        Schema::dropIfExists('service_invoices');
    }
};
