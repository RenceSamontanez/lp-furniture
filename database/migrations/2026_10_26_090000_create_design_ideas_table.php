<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick ideas on the Customize page, editable by the admins (the editor comes later).
 * scope 'type' = key is a furniture type value, 'style' = key is a style name, 'general' = no key.
 * New table only, nothing existing is touched. The seed is the approved prototype's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('design_ideas')) {
            Schema::create('design_ideas', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 10);
                $table->string('idea_key', 60)->default('');
                $table->string('text', 80);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['scope', 'idea_key', 'is_active']);
            });
        }

        if (DB::table('design_ideas')->exists()) {
            return;
        }

        $seed = [
            ['type', 'Sofa', ['soft rounded cushions', 'solid wood legs', 'seats three people']],
            ['type', 'Bed', ['tall headboard', 'under-bed storage', 'queen size']],
            ['type', 'Table', ['seats six', 'rounded edges', 'extendable top']],
            ['type', 'Chair', ['curved backrest', 'padded seat', 'armrests']],
            ['type', 'Cabinet', ['sliding doors', 'adjustable shelves', 'soft-close drawers']],
            ['type', 'Door', ['frosted glass panels', 'brushed-metal handle', 'vertical panels']],
            ['type', 'Shelf / Bookcase', ['open back', 'five shelves', 'floor to ceiling']],
            ['type', 'Desk', ['cable hole', 'two drawers', 'fits a laptop and monitor']],
            ['style', 'Modern', ['clean straight legs', 'matte natural finish', 'thin edges']],
            ['style', 'Rustic', ['visible wood grain', 'distressed finish', 'thick chunky legs']],
            ['style', 'Classic', ['carved details', 'glossy dark finish', 'turned legs']],
            ['style', 'Minimalist', ['no handles', 'plain light finish', 'simple shape']],
            ['style', 'Contemporary', ['soft curved edges', 'two-tone finish', 'mixed wood and metal']],
            ['general', '', ['rounded edges', 'natural finish', 'warm golden-brown tone', 'with storage', 'for a small space']],
        ];

        $now = now();
        $rows = [];
        foreach ($seed as [$scope, $key, $texts]) {
            foreach ($texts as $i => $text) {
                $rows[] = ['scope' => $scope, 'idea_key' => $key, 'text' => $text, 'sort_order' => $i + 1,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('design_ideas')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('design_ideas');
    }
};
