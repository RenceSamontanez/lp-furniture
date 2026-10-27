<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order history behind the admin order page and the customer's Track My Order page: one row per thing that
 * happened to an order (placed, payment, stage change, progress update, correction, cancel).
 *
 * Existing orders get one "Order placed" row so no order has an empty history. The statuses themselves are not
 * remapped: orders.order_status already holds exactly the stage keys the new code uses
 * (pending, confirmed, in_production, ready, out_for_delivery, completed, cancelled).
 * Safe to run twice: the table is only created when missing and the backfill only touches orders with no row yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_status_events')) {
            Schema::create('order_status_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('admin_user_id')->nullable();
                $table->string('type', 20);                      // placed, payment, stage, update, correction, cancel
                $table->string('title', 160)->nullable();
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20)->nullable();
                $table->text('note')->nullable();                // the admin's message, or the reason for a correction / cancel
                $table->string('photo_path', 255)->nullable();   // relative to the public disk: order-update-photos/<random>.jpg|png
                $table->boolean('customer_notified')->default(false);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['order_id', 'id']);
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('admin_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }

        DB::statement(
            "INSERT INTO order_status_events (order_id, type, title, to_status, customer_notified, created_at)
             SELECT o.id, 'placed', 'Order placed', 'pending', 1, COALESCE(o.created_at, NOW())
             FROM orders o
             WHERE NOT EXISTS (SELECT 1 FROM order_status_events e WHERE e.order_id = o.id)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
    }
};
