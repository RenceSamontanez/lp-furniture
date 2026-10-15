<?php

namespace App\Console\Commands;

use App\Support\CustomOrderPipeline;
use Illuminate\Console\Command;

class ExpireCustomOrders extends Command
{
    protected $signature = 'custom-orders:expire';

    protected $description = 'Expire sent custom-order quotes past their expires_at (and move the request from quoted to expired), and accepted agreements with no order past payment_due_at. Same rule the admin and customer pages apply lazily on load; like design:fail-stale this exists so cleanup still happens when nobody opens a page. Not scheduled by default.';

    public function handle(CustomOrderPipeline $pipeline)
    {
        $count = $pipeline->expireStaleQuotes();
        $agreements = $pipeline->expireUnpaidAgreements();

        $this->info("Expired {$count} custom order quote(s).");
        $this->info("Expired {$agreements} accepted agreement(s) nobody started paying for.");

        return self::SUCCESS;
    }
}
