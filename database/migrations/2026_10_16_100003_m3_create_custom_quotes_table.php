<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 — quotes (drafts and sent versions) for a custom order request.
 *
 * No generated column: "one open quote per request" is enforced in
 * code, by checking inside a transaction that holds lockForUpdate on
 * the custom_design_requests row. The only database-level uniqueness
 * is (request_id, version).
 *
 * amends_agreement_id has no foreign key here because design_agreements
 * is created later (M5); M5 adds that foreign key.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('custom_quotes')) {
            return;
        }

        Schema::create('custom_quotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')
                  ->constrained('custom_design_requests')
                  ->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->enum('status', ['draft', 'sent', 'superseded', 'accepted', 'declined', 'expired'])
                  ->default('draft');

            // ── Agreed terms (all nullable while the quote is still a draft) ──
            $table->text('final_description')->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('depth_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->string('material', 100)->nullable();
            $table->string('finish', 100)->nullable();
            $table->text('modifications')->nullable();
            $table->decimal('price', 10, 2)->nullable();          // app caps this at 999,999
            $table->enum('delivery_option', ['pickup', 'delivery'])->default('pickup');
            $table->decimal('delivery_fee', 10, 2)->default(0);   // app default for delivery is 500
            $table->text('delivery_address')->nullable();
            $table->unsignedSmallInteger('production_days')->nullable();

            // ── Payment terms ──
            $table->enum('payment_terms', ['full', 'deposit'])->default('full');
            $table->decimal('deposit_amount', 10, 2)->nullable();
            $table->unsignedTinyInteger('installment_duration_months')->nullable(); // 1/2/3
            $table->unsignedTinyInteger('installment_frequency_days')->nullable();  // 15/30

            // Internal only — never shown to the customer.
            $table->text('call_notes')->nullable();

            // ── Version history ──
            $table->decimal('previous_price', 10, 2)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_amendment')->default(false);
            $table->unsignedBigInteger('amends_agreement_id')->nullable();
            $table->text('customer_decline_reason')->nullable();

            $table->timestamps();

            $table->unique(['request_id', 'version']);
            $table->index(['request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_quotes');
    }
};
