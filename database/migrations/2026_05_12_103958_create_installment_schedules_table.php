<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')
                  ->constrained('payments')
                  ->onDelete('cascade');
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->integer('installment_no');
            $table->decimal('amount_due', 10, 2);
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->enum('status', ['pending','paid','overdue'])
                  ->default('pending');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('installment_schedules');
    }
};