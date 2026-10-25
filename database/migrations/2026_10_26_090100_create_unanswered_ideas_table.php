<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What customers typed on the Customize page that the system has no answer for yet
 * (a furniture type, a wood, a description phrase). Admins answer these later; an
 * answered entry is shown back to the next customer who types the same thing.
 * New table only. One row per (kind, normalised text), counted in times_asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unanswered_ideas')) {
            return;
        }

        Schema::create('unanswered_ideas', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);              // furniture_type | wood | idea
            $table->string('text', 120);             // as first typed
            $table->string('text_key', 120);         // lower case, single spaces
            $table->unsignedInteger('times_asked')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status', 20)->default('new');   // new | answered | added_as_option
            $table->text('answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['kind', 'text_key']);
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unanswered_ideas');
    }
};
