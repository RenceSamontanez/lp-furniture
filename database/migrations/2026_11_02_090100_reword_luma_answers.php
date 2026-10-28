<?php

use App\Support\Luma\DefaultIntents;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Luma answer format v2: the built-in answers and the 20 hand-seeded ones get the new, plainer wording, and contact
 * details come from Contact Settings through [[contact]] / {{contact}} instead of typed text.
 *
 * Only a row whose stored answer is still exactly the OLD text is changed (so an admin's edits are safe). Rows an admin
 * edited are left alone and listed in the log. Not reversible on purpose: the old text is in git and in
 * database/data/luma-previous-defaults.json.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_intents') || !Schema::hasColumn('chatbot_intents', 'answer_tl')) {
            return;
        }

        $changed = DefaultIntents::refreshUntouched();
        Log::info('Luma answers reworded: ' . count($changed) . ' fields', $changed);
    }

    public function down(): void
    {
        // intentionally empty
    }
};
