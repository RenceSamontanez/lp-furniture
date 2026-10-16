<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 13: a saved contact phone and email per account, offered as a
 * convenience on the "Send to our team" form. Each request still stores
 * the contact it was submitted with. Additive and guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'saved_contact_phone')) {
                $table->string('saved_contact_phone', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'saved_contact_email')) {
                $table->string('saved_contact_email', 150)->nullable();
            }
            if (!Schema::hasColumn('users', 'contact_saved_at')) {
                $table->dateTime('contact_saved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        $columns = array_values(array_filter(
            ['saved_contact_phone', 'saved_contact_email', 'contact_saved_at'],
            fn ($c) => Schema::hasColumn('users', $c)
        ));

        if ($columns) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
