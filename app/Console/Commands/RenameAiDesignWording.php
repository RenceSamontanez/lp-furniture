<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The shop calls the feature "Customize"; some chatbot rows saved earlier still say "AI Design".
 * Dry-run is the default and only prints. --apply writes. Run it by hand: nothing (migration, seeder, test) calls it.
 */
class RenameAiDesignWording extends Command
{
    protected $signature = 'luma:rename-ai-design {--apply : Write the changes. Without it nothing is changed}';

    protected $description = 'Replace the exact phrase "AI Design" with "Customize" in the visible text of chatbot intents (dry-run unless --apply).';

    /** Visible text only. "keywords" is matching data (customers may still type "ai design"), so it is left alone. */
    private const FIELDS = ['response', 'answer_tl', 'suggestions'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $db = DB::connection()->getDatabaseName();
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . " on database: {$db}");

        $changes = [];
        $columns = array_values(array_filter(self::FIELDS, fn ($c) => \Schema::hasColumn('chatbot_intents', $c)));

        foreach (DB::table('chatbot_intents')->orderBy('id')->get() as $row) {
            $update = [];
            foreach ($columns as $col) {
                $before = $row->{$col};
                if (!is_string($before) || $before === '') {
                    continue;
                }
                $after = self::replace($before);
                if ($after !== $before) {
                    $update[$col] = $after;
                    $changes[] = [$row->id, $col, $before, $after];
                }
            }
            if ($apply && $update) {
                DB::table('chatbot_intents')->where('id', $row->id)->update($update);
            }
        }

        foreach ($changes as [$id, $col, $before, $after]) {
            $this->line("row {$id}, {$col}");
            $this->line("  before: {$before}");
            $this->line("  after:  {$after}");
        }

        $n = count($changes);
        if ($n === 0) {
            $this->info('Nothing to change.');
        } elseif ($apply) {
            $this->info("{$n} field(s) updated.");
        } else {
            $this->info("{$n} field(s) would change. Run again with --apply to write them.");
        }

        return self::SUCCESS;
    }

    /** "AI Design" / "AI design" -> "Customize", "ai design" -> "customize", "AI DESIGN" -> "CUSTOMIZE". Nothing else changes. */
    public static function replace(string $text): string
    {
        return preg_replace_callback('/\bAI design\b/i', function (array $m) {
            if ($m[0] === strtoupper($m[0])) {
                return 'CUSTOMIZE';
            }

            return $m[0] === 'ai design' ? 'customize' : 'Customize';
        }, $text) ?? $text;
    }
}
