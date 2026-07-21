<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('delivery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');
            $table->text('delivery_address')->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->enum('status', [
                'waiting_for_courier',
                'on_progress',
                'shipped',
                'delivered'
            ])->default('waiting_for_courier');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('delivery');
    }
};