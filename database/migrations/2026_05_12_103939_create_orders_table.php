<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->unsignedBigInteger('design_request_id')->nullable();
            $table->string('order_number', 100)->unique();
            $table->decimal('total_amount', 10, 2);
            $table->enum('delivery_option', ['pickup','delivery'])
                  ->default('pickup');
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_fee', 10, 2)->default(0.00);
            $table->text('customization_note')->nullable();
            $table->enum('production_status', [
                'pending',
                'on_process',
                'done'
            ])->default('pending');
            $table->enum('order_status', [
                'pending',
                'confirmed',
                'in_production',
                'ready',
                'out_for_delivery',
                'completed',
                'cancelled'
            ])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};