<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shop address, nearby landmarks and map coordinates for the footer "Visit our shop" block. All optional. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('shop_address', 255)->nullable()->after('tiktok_url');
            $table->string('shop_landmarks', 500)->nullable()->after('shop_address');
            $table->decimal('map_lat', 10, 7)->nullable()->after('shop_landmarks');
            $table->decimal('map_lng', 10, 7)->nullable()->after('map_lat');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn(['shop_address', 'shop_landmarks', 'map_lat', 'map_lng']);
        });
    }
};
