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
            // Lightweight "has admin followed up" marker for the new
            // admin design-requests queue — null until Mark as Contacted.
            $table->timestamp('admin_contacted_at')->nullable()->after('admin_notes');
        });

        // 'rejected' already means "AI generation failed" (set by
        // GenerateAiDesign::handle() when zero variations succeed,
        // and shown to the customer as "We Couldn't Generate Your
        // Design" with a Retry action). Admin declining a request
        // the customer already selected is a different situation —
        // reusing 'rejected' would show that same "temporary issue,
        // try again" message for a deliberate human decision, which
        // is wrong. 'declined' is a distinct value for that case.
        Schema::table('custom_design_requests', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'ai_generated',
                'under_review',
                'approved',
                'rejected',
                'declined',
                'converted_to_order',
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_design_requests', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'ai_generated',
                'under_review',
                'approved',
                'rejected',
                'converted_to_order',
            ])->default('pending')->change();
        });

        Schema::table('custom_design_requests', function (Blueprint $table) {
            $table->dropColumn('admin_contacted_at');
        });
    }
};
