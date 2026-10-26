<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $t) {
            $t->id();
            $t->string('psgc_code', 10)->nullable()->unique();
            $t->string('name', 120)->unique();
            $t->timestamps();
        });

        Schema::create('cities_municipalities', function (Blueprint $t) {
            $t->id();
            $t->string('psgc_code', 10)->nullable()->unique();
            $t->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();
            $t->string('name', 160);
            $t->timestamps();
            $t->unique(['province_id', 'name']);
        });

        Schema::create('barangays', function (Blueprint $t) {
            $t->id();
            $t->string('psgc_code', 10)->nullable()->unique();
            $t->foreignId('city_municipality_id')->constrained('cities_municipalities')->cascadeOnDelete();
            $t->string('name', 200);
            $t->timestamps();
            $t->unique(['city_municipality_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
        Schema::dropIfExists('cities_municipalities');
        Schema::dropIfExists('provinces');
    }
};
