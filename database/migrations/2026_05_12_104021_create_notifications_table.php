<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->string('title', 255);
            $table->text('message');
            $table->enum('type', [
                'order_update',
                'payment_confirmed',
                'payment_rejected',
                'delivery_update',
                'validation_approved',
                'validation_rejected',
                'design_ready',
                'installment_due',
                'general'
            ])->default('general');
            $table->boolean('is_read')->default(false);
            $table->string('url', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('notifications');
    }
};