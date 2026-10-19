<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 21i (Prompt 8f, part 6): two additive, nullable columns on service_inquiries.
 *   work_location     home | shop | unsure   ("Where should the work be done?")
 *   reference_photos  JSON list of private storage paths (up to 3 photos; a clear description OR a photo is enough)
 * Nothing existing is renamed, dropped or rewritten. Every step skips what already exists; rolling back drops only
 * these two columns. Run on the real database yourself: php artisan migrate
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_inquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('service_inquiries', 'work_location')) {
                $table->string('work_location', 10)->nullable();
            }
            if (!Schema::hasColumn('service_inquiries', 'reference_photos')) {
                $table->json('reference_photos')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_inquiries', function (Blueprint $table) {
            foreach (['work_location', 'reference_photos'] as $col) {
                if (Schema::hasColumn('service_inquiries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
