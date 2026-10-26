<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a customer changes their own name on My Account: old name, new name, when, and whether the account was
 * verified at that moment. Admins read it on the customer page, to decide whether to reset the identity verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_name_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('old_first_name', 60);
            $t->string('old_last_name', 60);
            $t->string('new_first_name', 60);
            $t->string('new_last_name', 60);
            $t->boolean('was_verified')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_name_changes');
    }
};
