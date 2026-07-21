<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->enum('role', ['construction','barnis']);
            $table->enum('type', ['oncall','daily']);
            $table->string('phone', 20)->nullable();
            $table->enum('status', [
                'available',
                'assigned',
                'oncall',
                'off'
            ])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};