<?php

namespace App\Console\Commands;

use App\Support\ActivityLog;
use Illuminate\Console\Command;

class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune';

    protected $description = 'Deletes Activity Log records older than 180 days.';

    public function handle(): int
    {
        $n = ActivityLog::prune();
        $this->info("Removed {$n} activity log record(s) older than " . ActivityLog::RETENTION_DAYS . ' days.');

        return self::SUCCESS;
    }
}
