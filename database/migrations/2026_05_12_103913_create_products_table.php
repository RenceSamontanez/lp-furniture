<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name', 255);
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock_quantity')->default(0);
            $table->string('product_image', 255)->nullable();
            $table->string('material', 100)->nullable();
            $table->string('finish', 100)->nullable();
            $table->string('dimensions', 150)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_customizable')->default(true);
            $table->enum('product_status', ['available','unavailable'])
                  ->default('available');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};