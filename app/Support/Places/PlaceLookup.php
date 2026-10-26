<?php

namespace App\Support\Places;

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Province;
use Illuminate\Support\Facades\Cache;

/** Read side of the place tables: cached lists for the dropdowns and the parent checks the server relies on. */
final class PlaceLookup
{
    private const KEY = 'places.v1';

    public static function provinces(): array
    {
        return Cache::rememberForever(self::KEY . '.provinces', fn () => Province::orderBy('name')->get(['id', 'name'])->toArray());
    }

    public static function cities(int $provinceId): array
    {
        return Cache::rememberForever(self::KEY . ".cities.$provinceId",
            fn () => CityMunicipality::where('province_id', $provinceId)->orderBy('name')->get(['id', 'name'])->toArray());
    }

    public static function barangays(int $cityId): array
    {
        return Cache::rememberForever(self::KEY . ".brgys.$cityId",
            fn () => Barangay::where('city_municipality_id', $cityId)->orderBy('name')->get(['id', 'name'])->toArray());
    }

    /** True only when the barangay belongs to the city and the city belongs to the province. */
    public static function chainIsValid(int $provinceId, int $cityId, int $barangayId): bool
    {
        return CityMunicipality::where('id', $cityId)->where('province_id', $provinceId)->exists()
            && Barangay::where('id', $barangayId)->where('city_municipality_id', $cityId)->exists();
    }

    public static function flush(): void
    {
        Cache::forget(self::KEY . '.provinces');
        foreach (Province::pluck('id') as $pid) {
            foreach (CityMunicipality::where('province_id', $pid)->pluck('id') as $cid) {
                Cache::forget(self::KEY . ".brgys.$cid");
            }
            Cache::forget(self::KEY . ".cities.$pid");
        }
    }
}
