<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 — versioned refund/cancellation policy text shown on the
 * agreement. Rows are immutable (model guard in AgreementPolicy);
 * a policy change is a new version. v1 is a placeholder until the
 * real text is supplied.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('agreement_policies')) {
            Schema::create('agreement_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('version')->unique();
                $table->string('title', 255);
                $table->text('body');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (!DB::table('agreement_policies')->where('version', 1)->exists()) {
            DB::table('agreement_policies')->insert([
                'version'    => 1,
                'title'      => 'Refund and Cancellation Policy',
                'body'       => '[Refund and cancellation policy — text to be supplied]',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_policies');
    }
};
