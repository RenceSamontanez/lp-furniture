<?php

namespace App\Support\Places;

use Illuminate\Support\Facades\DB;

/**
 * Loads provinces, cities/municipalities and barangays from a file (never typed by hand).
 *
 *   tree : docs/data/psgc-2019.json style  { province: { city: [barangay, ...] } }  (names only, no PSGC codes)
 *   psa  : the official PSA PSGC publication saved as CSV (columns: 10-digit code, name, ..., geographic level)
 *
 * Re-running is safe: rows are matched by name inside their parent and updated, nothing is deleted
 * (a customer's saved address points at these ids).
 */
final class PlaceImporter
{
    public const METRO_MANILA = 'Metro Manila';

    /** @return array{provinces:int,cities:int,barangays:int,skipped:int} */
    public function importTree(array $tree, array $codes = []): array
    {
        $stats = ['provinces' => 0, 'cities' => 0, 'barangays' => 0, 'skipped' => 0];

        DB::transaction(function () use ($tree, $codes, &$stats) {
            $now = now();

            // provinces
            $rows = [];
            foreach (array_keys($tree) as $name) {
                $rows[] = ['name' => $name, 'psgc_code' => $codes['prov'][$name] ?? null, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('provinces')->upsert($chunk, ['name'], ['psgc_code', 'updated_at']);
            }
            $provIds = DB::table('provinces')->pluck('id', 'name')->all();

            // cities
            $rows = [];
            foreach ($tree as $prov => $cities) {
                foreach (array_keys($cities) as $city) {
                    $rows[] = [
                        'province_id' => $provIds[$prov], 'name' => $city,
                        'psgc_code' => $codes['city'][$prov . '|' . $city] ?? null, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('cities_municipalities')->upsert($chunk, ['province_id', 'name'], ['psgc_code', 'updated_at']);
            }
            $cityIds = [];
            foreach (DB::table('cities_municipalities')->select('id', 'province_id', 'name')->get() as $c) {
                $cityIds[$c->province_id . '|' . $c->name] = $c->id;
            }

            // barangays
            $batch = [];
            $flush = function () use (&$batch) {
                if ($batch) {
                    DB::table('barangays')->upsert($batch, ['city_municipality_id', 'name'], ['psgc_code', 'updated_at']);
                    $batch = [];
                }
            };
            foreach ($tree as $prov => $cities) {
                foreach ($cities as $city => $barangays) {
                    $cityId = $cityIds[$provIds[$prov] . '|' . $city];
                    $seen = [];
                    foreach ($barangays as $b) {
                        if (isset($seen[$b])) {
                            $stats['skipped']++; // same name twice in one city: the dropdown could not tell them apart
                            continue;
                        }
                        $seen[$b] = true;
                        $batch[] = [
                            'city_municipality_id' => $cityId, 'name' => $b,
                            'psgc_code' => $codes['brgy'][$prov . '|' . $city . '|' . $b] ?? null, 'created_at' => $now, 'updated_at' => $now,
                        ];
                        if (count($batch) >= 1000) {
                            $flush();
                        }
                    }
                }
            }
            $flush();

            $stats['provinces'] = DB::table('provinces')->count();
            $stats['cities']    = DB::table('cities_municipalities')->count();
            $stats['barangays'] = DB::table('barangays')->count();
        });

        PlaceLookup::flush();

        return $stats;
    }

    /** Official PSA publication saved as CSV. Returns [tree, codes]. */
    public function parsePsaCsv(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot read $path");
        }

        $levels = ['reg', 'prov', 'dist', 'mun', 'city', 'submun', 'bgy'];
        $tree = [];
        $codes = ['prov' => [], 'city' => [], 'brgy' => []];
        $prov = $city = null;

        while (($row = fgetcsv($fh)) !== false) {
            $code = preg_replace('/\D/', '', (string) ($row[0] ?? ''));
            $name = trim((string) ($row[1] ?? ''));
            if (strlen($code) !== 10 || $name === '') {
                continue; // header and blank rows
            }
            $level = '';
            foreach ($row as $cell) {
                if (in_array(strtolower(trim((string) $cell)), $levels, true)) {
                    $level = strtolower(trim((string) $cell));
                }
            }

            switch ($level) {
                case 'reg':
                    $city = null;
                    // the NCR has no provinces: it is one choice, "Metro Manila"
                    $prov = str_starts_with($code, '13') ? self::METRO_MANILA : null;
                    if ($prov) {
                        $tree[$prov] ??= [];
                        $codes['prov'][$prov] = $code;
                    }
                    break;
                case 'prov':
                    $prov = $name;
                    $city = null;
                    $tree[$prov] ??= [];
                    $codes['prov'][$prov] = $code;
                    break;
                case 'mun':
                case 'city':
                    $city = null;
                    if ($prov !== null) {
                        $city = $name;
                        $tree[$prov][$city] ??= [];
                        $codes['city'][$prov . '|' . $city] = $code;
                    }
                    break;
                case 'bgy':
                    // sub-municipalities (the districts of Manila) are skipped: the barangays attach to the city itself
                    if ($prov !== null && $city !== null) {
                        $tree[$prov][$city][] = $name;
                        $codes['brgy'][$prov . '|' . $city . '|' . $name] = $code;
                    }
                    break;
            }
        }
        fclose($fh);

        // a city with no barangays cannot be chosen in the form
        foreach ($tree as $p => $cities) {
            foreach ($cities as $c => $b) {
                if (!$b) {
                    unset($tree[$p][$c]);
                }
            }
        }

        return [$tree, $codes];
    }
}
