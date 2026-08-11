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
            $table->string('paymongo_payment_intent_id', 255)->nullable()->after('gcash_reference_number');
            $table->string('paymongo_payment_method_id', 255)->nullable()->after('paymongo_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['paymongo_payment_intent_id', 'paymongo_payment_method_id']);
        });
    }
};
