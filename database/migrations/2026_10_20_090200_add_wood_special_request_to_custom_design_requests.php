<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: what a "Special request" wood is, and who supplies it. The existing
 * preferred_material column (which now holds Gmelina, Mahogany, Acacia or Special request
 * for new requests) and every existing row are left exactly as they are.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('custom_design_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('custom_design_requests', 'wood_special_details')) {
                $table->text('wood_special_details')->nullable()->after('preferred_material');
            }
            if (!Schema::hasColumn('custom_design_requests', 'wood_supply')) {
                $table->string('wood_supply', 20)->nullable()->after('wood_special_details');
            }
        });
    }

    public function down(): void
    {
        foreach (['wood_supply', 'wood_special_details'] as $column) {
            if (Schema::hasColumn('custom_design_requests', $column)) {
                Schema::table('custom_design_requests', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
