<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional TikTok link for the footer "Follow us" row. Blank means the button is not shown. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->string('tiktok_url', 255)->nullable()->after('facebook_url');
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table) {
            $table->dropColumn('tiktok_url');
        });
    }
};
