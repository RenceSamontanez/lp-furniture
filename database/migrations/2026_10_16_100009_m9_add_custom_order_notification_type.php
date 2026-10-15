<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M9 — one new notifications.type value, 'custom_order', for every
 * custom-order notification (kept to a single value so the ENUM only
 * changes once).
 */
return new class extends Migration {
    private const BASE_TYPES = [
        'order_update', 'payment_confirmed', 'payment_rejected', 'delivery_update',
        'validation_approved', 'validation_rejected', 'design_ready', 'installment_due', 'general',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('notifications') || SchemaGuard::enumHasValue('notifications', 'type', 'custom_order')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', array_merge(self::BASE_TYPES, ['custom_order']))->default('general')->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('notifications') || !SchemaGuard::enumHasValue('notifications', 'type', 'custom_order')) {
            return;
        }

        // Map the new value back first so the shrink can't fail or truncate.
        DB::table('notifications')->where('type', 'custom_order')->update(['type' => 'general']);

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', self::BASE_TYPES)->default('general')->change();
        });
    }
};
