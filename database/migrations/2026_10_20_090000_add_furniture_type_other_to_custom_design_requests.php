<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: the short text for furniture type "Other". The existing
 * furniture_type column and every existing row are left exactly as they are.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('custom_design_requests', 'furniture_type_other')) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->string('furniture_type_other', 100)->nullable()->after('furniture_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('custom_design_requests', 'furniture_type_other')) {
            Schema::table('custom_design_requests', function (Blueprint $table) {
                $table->dropColumn('furniture_type_other');
            });
        }
    }
};
