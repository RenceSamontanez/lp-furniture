<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M8 — append-only event log that feeds the customer and admin
 * timelines (request → quote → agreement → payment → production →
 * delivery). Append-only by convention: no update or delete routes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('custom_order_events')) {
            return;
        }

        Schema::create('custom_order_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_id')->constrained('custom_design_requests')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            $table->string('type', 60);
            $table->enum('actor_type', ['customer', 'admin', 'system']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['request_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_order_events');
    }
};
