<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 6 — when the customer must START paying after accepting an
 * agreement: the later of the quote's expiry and acceptance + 24h (D2).
 * Set once, inside the acceptance transaction, and also written into
 * the agreement's terms_snapshot. Additive and guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('custom_design_requests')
            && !Schema::hasColumn('custom_design_requests', 'payment_due_at')) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->dateTime('payment_due_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('custom_design_requests')
            && Schema::hasColumn('custom_design_requests', 'payment_due_at')) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->dropColumn('payment_due_at');
            });
        }
    }
};
