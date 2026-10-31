<?php

use Database\Seeders\MaterialEstimateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materials tracking: a movement history for the inventory, the furniture-type estimate library, one estimate per product,
 * and the columns that make deducting an order's materials idempotent and reversible. Seeds the starter library (safe to re-run).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->nullable()->constrained('inventory')->nullOnDelete();
            $table->string('material_name', 255);                       // snapshot, so history survives a removed material
            $table->enum('type', ['consumed', 'restored', 'restocked', 'corrected', 'created']);
            $table->decimal('quantity_change', 10, 2);                  // signed
            $table->decimal('quantity_after', 10, 2);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index(['inventory_id', 'created_at']);
        });

        Schema::create('material_estimates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            foreach (['wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue'] as $k) {
                $table->decimal($k, 10, 2)->default(0);
            }
            foreach (['wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue'] as $k) {
                $table->decimal('starter_' . $k, 10, 2)->nullable();    // null = a type the admin added (no starter to reset to)
            }
            $table->boolean('is_edited')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('ref_length_cm', 8, 2)->nullable();          // reference size, backend only: scales custom orders
            $table->decimal('ref_width_cm', 8, 2)->nullable();
            $table->decimal('ref_height_cm', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('product_material_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('type_name', 120);                            // the starter it came from
            foreach (['wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue'] as $k) {
                $table->decimal($k, 10, 2)->default(0);
            }
            $table->boolean('is_edited')->default(false);
            $table->timestamps();
        });

        Schema::table('material_usage', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('used_at');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('materials_deducted_at')->nullable();      // set once, when the order's materials were taken off the inventory
            $table->string('materials_note', 255)->nullable();           // e.g. "unmapped_wood:Pine" or "customer_wood"
        });

        (new MaterialEstimateSeeder())->seedLibrary();
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['materials_deducted_at', 'materials_note']);
        });
        Schema::table('material_usage', function (Blueprint $table) {
            $table->dropColumn('reversed_at');
        });
        Schema::dropIfExists('product_material_estimates');
        Schema::dropIfExists('material_estimates');
        Schema::dropIfExists('inventory_movements');
    }
};
