<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ideas to review (admin): remembers what a row was before it was dismissed (so Undo can restore it) and who last handled it.
 * Additive only: three nullable columns on a table created in the previous migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('unanswered_ideas')) {
            return;
        }

        Schema::table('unanswered_ideas', function (Blueprint $table) {
            if (!Schema::hasColumn('unanswered_ideas', 'previous_status')) {
                $table->string('previous_status', 20)->nullable()->after('status');
            }
            if (!Schema::hasColumn('unanswered_ideas', 'handled_by')) {
                $table->unsignedBigInteger('handled_by')->nullable()->after('answered_at');
            }
            if (!Schema::hasColumn('unanswered_ideas', 'handled_at')) {
                $table->timestamp('handled_at')->nullable()->after('handled_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('unanswered_ideas', function (Blueprint $table) {
            foreach (['previous_status', 'handled_by', 'handled_at'] as $col) {
                if (Schema::hasColumn('unanswered_ideas', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
