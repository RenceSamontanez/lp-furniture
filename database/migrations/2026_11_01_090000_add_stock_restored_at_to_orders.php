<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'stock_restored_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            // Set the moment a cancelled order's items went back into stock, so it can never happen twice.
            $table->timestamp('stock_restored_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'stock_restored_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('stock_restored_at');
            });
        }
    }
};
