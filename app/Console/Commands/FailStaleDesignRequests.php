<?php

namespace App\Console\Commands;

use App\Models\CustomDesignRequest;
use Illuminate\Console\Command;

class FailStaleDesignRequests extends Command
{
    protected $signature = 'design:fail-stale';

    protected $description = 'Mark design requests still pending past CustomDesignRequest::STALE_AFTER_MINUTES as failed (generation timed out). Same rule CustomDesignController applies lazily on page load — this exists so cleanup still happens without a scheduler wired up.';

    public function handle()
    {
        $count = CustomDesignRequest::failStale();

        $this->info("Marked {$count} stale design request(s) as failed.");
    }
}
