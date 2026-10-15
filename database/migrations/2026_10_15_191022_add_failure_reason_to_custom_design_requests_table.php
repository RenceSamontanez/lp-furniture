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
        Schema::table('custom_design_requests', function (Blueprint $table) {
            // Null for every other status (pending/ai_generated/
            // approved/declined/converted_to_order) and for a
            // 'rejected' row from before this column existed — only
            // ever set alongside a 'rejected' status going forward, so
            // the history row can say WHY instead of just THAT it failed.
            $table->string('failure_reason', 30)->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_design_requests', function (Blueprint $table) {
            $table->dropColumn('failure_reason');
        });
    }
};
