<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->string('material_name', 255);
            $table->decimal('material_quantity', 10, 2);
            $table->string('unit', 50);
            $table->decimal('low_stock_threshold', 10, 2)->default(10);
            $table->enum('inventory_status', [
                'in_stock',
                'low_stock',
                'out_of_stock'
            ])->default('in_stock');
            $table->timestamp('last_updated')
                  ->useCurrent()
                  ->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};