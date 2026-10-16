<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 20: Luma answers in the language of the question. `response` stays the English answer;
 * `answer_tl` is the optional Tagalog one (empty = Luma uses the English answer). Additive only:
 * skips if the column is already there, and rolling back drops only this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chatbot_intents', 'answer_tl')) {
            return;
        }

        Schema::table('chatbot_intents', function (Blueprint $table) {
            $table->text('answer_tl')->nullable()->after('response');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('chatbot_intents', 'answer_tl')) {
            return;
        }

        Schema::table('chatbot_intents', function (Blueprint $table) {
            $table->dropColumn('answer_tl');
        });
    }
};
