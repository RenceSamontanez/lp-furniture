<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity validation redesign. Additive only: four nullable/defaulted columns and one unique key.
 * No row is moved, rewritten or deleted. Every existing submission becomes attempt 1 through the column default
 * (a customer has exactly one row today, so (user_id, attempt_number) is already unique).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_validations', function (Blueprint $table) {
            $table->unsignedInteger('attempt_number')->default(1)->after('user_id');
            $table->json('checks_confirmed')->nullable()->after('rejection_reason');
            $table->timestamp('follow_up_requested_at')->nullable()->after('reviewed_at');
            $table->unsignedBigInteger('follow_up_requested_by')->nullable()->after('follow_up_requested_at');

            $table->foreign('follow_up_requested_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['user_id', 'attempt_number'], 'user_validations_user_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_validations', function (Blueprint $table) {
            $table->dropForeign(['follow_up_requested_by']);
            $table->dropUnique('user_validations_user_attempt_unique');
            $table->dropColumn(['attempt_number', 'checks_confirmed', 'follow_up_requested_at', 'follow_up_requested_by']);
        });
    }
};
