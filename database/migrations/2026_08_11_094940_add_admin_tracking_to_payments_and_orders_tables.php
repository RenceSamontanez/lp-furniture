<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Matches the existing user_validations.reviewed_by /
        // reviewed_at pattern exactly — nullable FK, SET NULL on
        // delete, paired timestamp for when the action happened.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('verified_by')->nullable()->after('payment_status')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('status_updated_by')->nullable()->after('order_status')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable()->after('status_updated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verified_by', 'verified_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['status_updated_by']);
            $table->dropColumn(['status_updated_by', 'status_updated_at']);
        });
    }
};
