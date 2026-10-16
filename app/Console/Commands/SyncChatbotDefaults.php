<?php

namespace App\Console\Commands;

use App\Support\Luma\DefaultIntents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncChatbotDefaults extends Command
{
    protected $signature = 'chatbot:sync-defaults';

    protected $description = "Add Luma's built-in English and Tagalog answers that are missing. Never changes an intent that already exists.";

    public function handle(): int
    {
        if (!Schema::hasColumn('chatbot_intents', 'answer_tl')) {
            $this->error('The answer_tl column is missing. Run "php artisan migrate" first.');

            return self::FAILURE;
        }

        $result = DefaultIntents::sync();

        foreach ($result['added'] as $name) {
            $this->line("  added   {$name}");
        }
        foreach ($result['skipped'] as $name) {
            $this->line("  exists  {$name} (left as it is)");
        }

        $this->info(count($result['added']) . ' added, ' . count($result['skipped']) . ' already there.');

        return self::SUCCESS;
    }
}
