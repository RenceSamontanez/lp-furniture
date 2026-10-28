<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The questions Luma could not answer, one row per distinct (normalised) question, with how often it was asked.
 * Filled by App\Support\Luma\UnansweredQuestions (after its safety filters); worked by Admin > Chatbot Training.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('luma_unanswered_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question_key', 200)->unique();   // lower case, no punctuation: repeats group on this
            $table->string('question', 200);                  // as first typed, with phone numbers, emails and addresses removed
            $table->unsignedInteger('times_asked')->default(1);
            $table->timestamp('first_asked_at')->nullable();
            $table->timestamp('last_asked_at')->nullable()->index();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('taught_at')->nullable();       // an intent with a matching keyword was added or edited
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luma_unanswered_questions');
    }
};
