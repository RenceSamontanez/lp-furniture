<?php

namespace App\Console\Commands;

use App\Support\Places\PlaceImporter;
use Illuminate\Console\Command;

class ImportPlaces extends Command
{
    protected $signature = 'places:import {--csv= : Official PSA PSGC publication saved as CSV} {--json= : Name tree JSON (default: docs/data/psgc-2019.json)}';

    protected $description = 'Import real Philippine provinces, cities/municipalities and barangays (safe to re-run; deletes nothing)';

    public function handle(PlaceImporter $importer): int
    {
        if ($csv = $this->option('csv')) {
            if (!is_file($csv)) {
                $this->error("File not found: $csv");
                return self::FAILURE;
            }
            [$tree, $codes] = $importer->parsePsaCsv($csv);
            $source = 'PSA PSGC publication (CSV): ' . basename($csv);
        } else {
            $json = $this->option('json') ?: base_path('docs/data/psgc-2019.json');
            $tree = is_file($json) ? json_decode(file_get_contents($json), true) : null;
            if (!is_array($tree) || !$tree) {
                $this->error("Cannot read a place tree from $json");
                return self::FAILURE;
            }
            $codes = [];
            $source = 'Fallback file ' . basename($json) . ' (2019 edition, names only: no PSGC codes, no newer changes such as the Maguindanao split)';
        }

        $stats = $importer->importTree($tree, $codes);

        $this->info("Source: $source");
        $this->info("Now in the database: {$stats['provinces']} provinces, {$stats['cities']} cities/municipalities, {$stats['barangays']} barangays"
            . ($stats['skipped'] ? " ({$stats['skipped']} same-name duplicates inside a city skipped)" : ''));

        return self::SUCCESS;
    }
}
