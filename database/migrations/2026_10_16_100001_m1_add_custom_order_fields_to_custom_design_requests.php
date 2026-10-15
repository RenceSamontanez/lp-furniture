<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1 — custom-order fields on custom_design_requests.
 *
 * `pipeline_status` is the new admin pipeline; the existing `status`
 * keeps its current meaning ('approved' = sent to the team). It stays
 * NULL for every request that has not been submitted as an order.
 */
return new class extends Migration {
    private const FK = 'custom_design_requests_selected_suggestion_id_foreign';
    private const INDEX = 'custom_design_requests_pipeline_status_index';

    public function up(): void
    {
        if (!Schema::hasTable('custom_design_requests')) {
            return;
        }

        Schema::table('custom_design_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_design_requests', 'pipeline_status')) {
                $table->enum('pipeline_status', [
                    'submitted', 'contacting', 'unreachable', 'quoted', 'accepted',
                    'ordered', 'expired', 'declined_by_customer', 'declined_by_admin', 'cancelled',
                ])->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'request_mode')) {
                $table->enum('request_mode', ['exact', 'modified'])->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'modification_notes')) {
                $table->text('modification_notes')->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'selected_suggestion_id')) {
                $table->unsignedBigInteger('selected_suggestion_id')->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'contact_phone')) {
                $table->string('contact_phone', 30)->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'contact_email')) {
                $table->string('contact_email', 150)->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'preferred_call_window')) {
                $table->enum('preferred_call_window', ['morning_8_12', 'afternoon_12_5', 'anytime'])->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('custom_design_requests', 'decline_reason')) {
                $table->text('decline_reason')->nullable();
            }
            // Bumped on every admin edit so a stale form save can be rejected.
            if (!Schema::hasColumn('custom_design_requests', 'lock_version')) {
                $table->unsignedInteger('lock_version')->default(0);
            }
        });

        if (!SchemaGuard::foreignKeyExists('custom_design_requests', self::FK)
            && Schema::hasTable('ai_design_suggestions')) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->foreign('selected_suggestion_id', self::FK)
                      ->references('id')->on('ai_design_suggestions')
                      ->nullOnDelete();
            });
        }

        if (!SchemaGuard::indexExists('custom_design_requests', self::INDEX)) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->index('pipeline_status', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('custom_design_requests')) {
            return;
        }

        if (SchemaGuard::foreignKeyExists('custom_design_requests', self::FK)) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->dropForeign(self::FK);
            });
        }

        if (SchemaGuard::indexExists('custom_design_requests', self::INDEX)) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }

        $columns = array_values(array_filter([
            'pipeline_status', 'request_mode', 'modification_notes', 'selected_suggestion_id',
            'contact_phone', 'contact_email', 'preferred_call_window', 'submitted_at',
            'decline_reason', 'lock_version',
        ], fn ($c) => Schema::hasColumn('custom_design_requests', $c)));

        if ($columns) {
            Schema::table('custom_design_requests', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
