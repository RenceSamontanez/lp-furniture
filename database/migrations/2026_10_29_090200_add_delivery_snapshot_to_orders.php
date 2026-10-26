<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // Who receives it, their phone and the structured address as it was at order time (never re-read from the profile).
            $t->json('delivery_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('delivery_snapshot');
        });
    }
};
