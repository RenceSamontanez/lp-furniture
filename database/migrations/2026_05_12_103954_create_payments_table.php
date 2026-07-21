<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_method', ['stripe','gcash','onsite'])
                  ->default('stripe');
            $table->enum('payment_type', ['full','installment'])
                  ->default('full');
            $table->string('stripe_payment_intent_id', 255)->nullable();
            $table->string('stripe_client_secret', 255)->nullable();
            $table->string('stripe_charge_id', 255)->nullable();
            $table->string('stripe_customer_id', 255)->nullable();
            $table->string('gcash_reference_number', 100)->nullable();
            $table->string('receipt_image_path', 255)->nullable();
            $table->enum('payment_status', [
                'pending',
                'paid',
                'failed',
                'refunded'
            ])->default('pending');
            $table->string('qr_hash', 255)->nullable();
            $table->boolean('qr_is_active')->default(true);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('payments');
    }
};