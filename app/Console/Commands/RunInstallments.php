<?php

namespace App\Console\Commands;

use App\Support\Installments\InstallmentCharger;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunInstallments extends Command
{
    protected $signature = 'installments:run {--date= : Pretend it is this date (Y-m-d H:i), for checking only}';

    protected $description = 'Daily installment job: reminders, charges, retries, Pay Now links and Overdue';

    public function handle(InstallmentCharger $charger): int
    {
        $now = $this->option('date') ? Carbon::parse($this->option('date'), config('installments.timezone')) : null;
        $stats = $charger->runDaily($now);

        $this->info('Installments: ' . collect($stats)->map(fn ($v, $k) => "{$k} {$v}")->implode(', '));

        return self::SUCCESS;
    }
}
