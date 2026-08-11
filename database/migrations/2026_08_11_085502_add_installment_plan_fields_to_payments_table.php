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
        Schema::table('payments', function (Blueprint $table) {
            // The chosen installment plan, so it's directly displayable
            // without inferring it from installment_schedules rows.
            // Null for payment_type = 'full'.
            $table->unsignedTinyInteger('installment_duration_months')->nullable()->after('payment_type');
            $table->unsignedTinyInteger('installment_frequency_days')->nullable()->after('installment_duration_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['installment_duration_months', 'installment_frequency_days']);
        });
    }
};
