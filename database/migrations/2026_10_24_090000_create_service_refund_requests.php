<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 21f: cancellations and refunds for "Other Services", and the "work started" step.
 * Additive only: three new tables and two columns on service_inquiries. Nothing existing is renamed or
 * dropped (the Stage 21e service_invoice_refund_notes table and its data are untouched). Every step skips
 * what already exists. Rolling back drops only what this migration added.
 * Refunds are recorded here, never paid by the system: no gateway refund API is ever called.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_inquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('service_inquiries', 'service_started_at')) {
                $table->dateTime('service_started_at')->nullable();   // dateTime: no implicit ON UPDATE on MariaDB
            }
            if (!Schema::hasColumn('service_inquiries', 'terms_accepted_at')) {
                $table->dateTime('terms_accepted_at')->nullable();
            }
        });

        if (!Schema::hasTable('service_refund_requests')) {
            Schema::create('service_refund_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->unique();                           // REF-YYYY-NNNN
                $table->foreignId('service_inquiry_id')->constrained('service_inquiries')->restrictOnDelete();
                $table->foreignId('service_invoice_id')->nullable()->constrained('service_invoices')->restrictOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source', 10);                                        // form, luma, admin
                $table->string('reason_code', 30);                                   // change_of_mind, taking_too_long, wrong_details, found_other_provider, other
                $table->text('reason_text')->nullable();
                $table->string('contact_phone', 30);
                $table->string('contact_email', 150);
                $table->string('refund_method', 10)->nullable();                     // gcash, cash; null when nothing was paid
                $table->text('gcash_number')->nullable();                            // encrypted by the model cast
                $table->string('gcash_account_name', 120)->nullable();
                $table->decimal('amount_paid_snapshot', 10, 2)->default(0);
                $table->string('work_state_snapshot', 12);                           // not_started, started, done
                $table->string('status', 15)->default('new');                       // new, needs_info, approved, refunded, refund_problem, declined, closed
                // = the invoice id (or "t<ticket id>") while the request is open, otherwise NULL. The UNIQUE index
                // lets a ticket or invoice have only one open request even on a double click (MySQL has no partial indexes).
                $table->string('open_slot', 30)->nullable()->unique('srr_one_open_per_invoice');
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('decided_at')->nullable();
                $table->text('decision_note')->nullable();                           // the explanation the customer reads when declined
                $table->decimal('approved_amount', 10, 2)->nullable();
                $table->boolean('approved_as_exception')->default(false);
                $table->text('exception_reason')->nullable();
                $table->dateTime('refund_due_at')->nullable();                       // approval + 7 days
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('user_id');
            });
        }

        if (!Schema::hasTable('service_refund_attempts')) {
            Schema::create('service_refund_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_refund_request_id')->constrained('service_refund_requests')->restrictOnDelete();
                $table->decimal('amount', 10, 2);
                $table->string('method', 10);                                        // gcash, cash
                $table->string('transaction_number', 60)->nullable();                // the GCash reference or cash receipt number (required when sent)
                $table->date('sent_on')->nullable();
                $table->string('outcome', 25);                                       // sent, failed_wrong_details, failed_other
                $table->string('note', 1000)->nullable();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('service_refund_messages')) {
            Schema::create('service_refund_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_refund_request_id')->constrained('service_refund_requests')->restrictOnDelete();
                $table->string('sender', 10);                                        // customer, admin, system
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_refund_messages');
        Schema::dropIfExists('service_refund_attempts');
        Schema::dropIfExists('service_refund_requests');

        Schema::table('service_inquiries', function (Blueprint $table) {
            foreach (['service_started_at', 'terms_accepted_at'] as $col) {
                if (Schema::hasColumn('service_inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
