<?php

namespace App\Console\Commands;

use Database\Seeders\MaterialEstimateSeeder;
use Illuminate\Console\Command;

class SeedMaterialEstimates extends Command
{
    protected $signature = 'materials:seed-estimates';

    protected $description = 'Create or refresh the furniture-type material library and give every product that has none a starter estimate. Safe to run again: edited rows and saved product estimates are kept.';

    public function handle(): int
    {
        (new MaterialEstimateSeeder())->run();

        $this->info('Material library: ' . \App\Models\MaterialEstimate::count() . ' furniture types. Products with an estimate: '
            . \App\Models\ProductMaterialEstimate::count() . ' of ' . \App\Models\Product::count() . '.');

        return self::SUCCESS;
    }
}
