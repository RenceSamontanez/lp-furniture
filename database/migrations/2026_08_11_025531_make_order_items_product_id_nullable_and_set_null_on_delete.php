<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_product_id_foreign');
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign('order_items_product_id_foreign');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->foreign('product_id')
                  ->references('id')->on('products')
                  ->cascadeOnDelete();
        });
    }
};
