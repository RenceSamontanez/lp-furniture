<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M0 — bring a database built from migrations in line with the live
 * furniture_db, which has columns the application already depends on
 * but that no earlier migration ever created:
 *
 *   order_items.product_name, order_items.product_image
 *       (the order-item snapshot, commit fb7c2fd — without these a
 *        migrate:fresh database cannot place an order at all)
 *   custom_design_requests.furniture_type, .style_preference
 *   ai_design_suggestions.is_favorited
 *   ai_design_suggestions.image_path   varchar(255) -> text
 *
 * Plus the missing index + foreign key on orders.design_request_id.
 *
 * On the live database every column already exists, so up() adds
 * nothing there (all guarded by hasColumn / column-type checks).
 *
 * down(): the reconciled columns are NOT dropped. They exist on the
 * live database independently of this migration and the running app
 * reads and writes them, so removing them on rollback would destroy
 * data and break order items. Only the foreign key this migration
 * itself adds is removed.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('custom_design_requests')) {
            $needType  = !Schema::hasColumn('custom_design_requests', 'furniture_type');
            $needStyle = !Schema::hasColumn('custom_design_requests', 'style_preference');

            if ($needType || $needStyle) {
                Schema::table('custom_design_requests', function (Blueprint $table) use ($needType, $needStyle) {
                    if ($needType) {
                        $table->string('furniture_type', 100)->nullable()->after('title');
                    }
                    if ($needStyle) {
                        $table->string('style_preference', 150)->nullable()->after('preferred_finish');
                    }
                });
            }
        }

        if (Schema::hasTable('ai_design_suggestions')) {
            if (!Schema::hasColumn('ai_design_suggestions', 'is_favorited')) {
                Schema::table('ai_design_suggestions', function (Blueprint $table) {
                    $table->boolean('is_favorited')->default(false)->after('is_selected');
                });
            }

            // varchar(255) is too short for some provider URLs / paths;
            // live is already text. Widening only — never narrows.
            if (SchemaGuard::columnType('ai_design_suggestions', 'image_path') !== 'text'
                && Schema::hasColumn('ai_design_suggestions', 'image_path')) {
                Schema::table('ai_design_suggestions', function (Blueprint $table) {
                    $table->text('image_path')->nullable()->change();
                });
            }
        }

        if (Schema::hasTable('order_items')) {
            $needName  = !Schema::hasColumn('order_items', 'product_name');
            $needImage = !Schema::hasColumn('order_items', 'product_image');

            if ($needName || $needImage) {
                Schema::table('order_items', function (Blueprint $table) use ($needName, $needImage) {
                    if ($needName) {
                        $table->string('product_name', 255)->nullable()->after('product_id');
                    }
                    if ($needImage) {
                        $table->string('product_image', 255)->nullable()->after('product_name');
                    }
                });
            }
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'design_request_id')
            && !SchemaGuard::foreignKeyExists('orders', 'orders_design_request_id_foreign')) {
            // A foreign key can't be added over dangling values.
            DB::table('orders')
                ->whereNotNull('design_request_id')
                ->whereNotIn('design_request_id', DB::table('custom_design_requests')->select('id'))
                ->update(['design_request_id' => null]);

            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('design_request_id')
                      ->references('id')->on('custom_design_requests')
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && SchemaGuard::foreignKeyExists('orders', 'orders_design_request_id_foreign')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign('orders_design_request_id_foreign');
            });
        }

        // MySQL/MariaDB keep the index the foreign key created after the
        // constraint itself is dropped; live had no such index before up().
        if (Schema::hasTable('orders') && SchemaGuard::indexExists('orders', 'orders_design_request_id_foreign')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_design_request_id_foreign');
            });
        }

        // Reconciled columns intentionally left in place — see class docblock.
    }
};
