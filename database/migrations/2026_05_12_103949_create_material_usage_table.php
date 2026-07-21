<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('material_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');
            $table->foreignId('inventory_id')
                  ->constrained('inventory');
            $table->decimal('quantity_used', 10, 2);
            $table->text('notes')->nullable();
            $table->timestamp('used_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('material_usage');
    }
};