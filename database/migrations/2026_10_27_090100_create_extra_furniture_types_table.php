<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed quick-pick chips inside the customer's "Something else" panel. They are shortcuts for the free-text box:
 * picking one still stores furniture_type "Other" with that text, so nothing about validation or old requests changes.
 * New table only. Seeded with the six examples the page already showed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('extra_furniture_types')) {
            Schema::create('extra_furniture_types', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60);
                $table->string('name_key', 60)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('source_idea_id')->nullable();   // the unanswered_ideas row it was added from, if any
                $table->timestamps();
            });
        }

        if (DB::table('extra_furniture_types')->exists()) {
            return;
        }

        $now = now();
        foreach (['Staircase railing', 'Altar', 'TV wall panel', 'Headboard', 'Kitchen counter', 'Gazebo'] as $i => $name) {
            DB::table('extra_furniture_types')->insert([
                'name' => $name, 'name_key' => mb_strtolower($name), 'sort_order' => $i + 1,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_furniture_types');
    }
};
