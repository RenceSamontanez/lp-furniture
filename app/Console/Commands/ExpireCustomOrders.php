<?php

namespace App\Console\Commands;

use App\Support\CustomOrderPipeline;
use Illuminate\Console\Command;

class ExpireCustomOrders extends Command
{
    protected $signature = 'custom-orders:expire';

    protected $description = 'Expire sent custom-order quotes past their expires_at (and move the request from quoted to expired). Same rule the admin and customer pages apply lazily on load; like design:fail-stale this exists so cleanup still happens when nobody opens a page. Not scheduled by default.';

    public function handle(CustomOrderPipeline $pipeline)
    {
        $count = $pipeline->expireStaleQuotes();

        $this->info("Expired {$count} custom order quote(s).");

        return self::SUCCESS;
    }
}
