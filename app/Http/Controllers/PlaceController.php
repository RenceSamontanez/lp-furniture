<?php

namespace App\Http\Controllers;

use App\Models\CityMunicipality;
use App\Models\Province;
use App\Support\Places\PlaceLookup;
use Illuminate\Http\JsonResponse;

/**
 * Dropdown data for the delivery address, from our own tables (no outside service). Lists are cached on the server and
 * for an hour in the browser; cities load after a province is chosen and barangays after a city, never all at once.
 */
class PlaceController extends Controller
{
    public function provinces(): JsonResponse
    {
        return $this->json(PlaceLookup::provinces());
    }

    public function cities(int $province): JsonResponse
    {
        abort_unless(Province::whereKey($province)->exists(), 404);

        return $this->json(PlaceLookup::cities($province));
    }

    public function barangays(int $city): JsonResponse
    {
        abort_unless(CityMunicipality::whereKey($city)->exists(), 404);

        return $this->json(PlaceLookup::barangays($city));
    }

    private function json(array $rows): JsonResponse
    {
        return response()->json($rows)->header('Cache-Control', 'private, max-age=3600');
    }
}
