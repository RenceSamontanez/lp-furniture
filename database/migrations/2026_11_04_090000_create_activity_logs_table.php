<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity Log: who signed in and what the team changed. People and actions only: no IP address, no
 * user agent, no request payloads. Also users.last_login_at (feeds "Last Active" on the Customers pages).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name', 120);
                $table->string('role', 12);            // admin | customer | system
                $table->string('event', 60);           // login, logout, order_confirmed ...
                $table->string('kind', 10);            // signin | signout | action
                $table->string('category', 12);        // signin | orders | catalog | people | settings
                $table->string('description', 255);
                $table->string('subject_type', 40)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('subject_label', 160)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('created_at');
                $table->index(['user_id', 'created_at']);
                $table->index(['category', 'created_at']);
                $table->index(['kind', 'created_at']);
            });
        }

        if (!Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_at');
            });
        }
        Schema::dropIfExists('activity_logs');
    }
};
