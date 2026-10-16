<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: Length x Width x Height in centimetres, and the unit the
 * customer typed them in. The old `dimensions` text column and every existing
 * row are left as they are; nothing is dropped or rewritten.
 *
 * Old rows: "Width x Depth x Height" in cm is read as Length x Width x Height
 * (old Width = Length, old Depth = Width, Height unchanged). That is done when
 * the value is read (App\Support\Dimensions::legacy), so there is no backfill,
 * nothing to re-run, and no snapshot is touched. Old custom_quotes keep their
 * width_cm / depth_cm / height_cm columns with the same reading.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('custom_design_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_design_requests', 'dim_length_cm')) {
                $table->decimal('dim_length_cm', 8, 2)->nullable()->after('dimensions');
            }
            if (!Schema::hasColumn('custom_design_requests', 'dim_width_cm')) {
                $table->decimal('dim_width_cm', 8, 2)->nullable()->after('dim_length_cm');
            }
            if (!Schema::hasColumn('custom_design_requests', 'dim_height_cm')) {
                $table->decimal('dim_height_cm', 8, 2)->nullable()->after('dim_width_cm');
            }
            if (!Schema::hasColumn('custom_design_requests', 'dimension_unit')) {
                $table->string('dimension_unit', 4)->nullable()->after('dim_height_cm');
            }
        });
    }

    public function down(): void
    {
        foreach (['dimension_unit', 'dim_height_cm', 'dim_width_cm', 'dim_length_cm'] as $column) {
            if (Schema::hasColumn('custom_design_requests', $column)) {
                Schema::table('custom_design_requests', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
