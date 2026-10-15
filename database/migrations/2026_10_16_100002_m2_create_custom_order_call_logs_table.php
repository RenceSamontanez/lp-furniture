<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 — one row per phone-call attempt an admin makes about a custom
 * order request. Append-only by convention: there are no update or
 * delete routes for it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('custom_order_call_logs')) {
            return;
        }

        Schema::create('custom_order_call_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')
                  ->constrained('custom_design_requests')
                  ->cascadeOnDelete();

            // The admin who made the call. Kept (as NULL) if that account is later removed.
            $table->foreignId('admin_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->unsignedSmallInteger('attempt_no');
            // dateTime, not timestamp: on MariaDB the first NOT NULL TIMESTAMP column
            // silently gets DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('called_at');
            $table->boolean('answered')->default(false);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['request_id', 'attempt_no']);
            $table->index(['request_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_call_logs');
    }
};
