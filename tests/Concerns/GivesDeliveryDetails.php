<?php

namespace Tests\Concerns;

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Province;
use App\Models\User;

/**
 * Real-looking (but test-only) place rows and a customer who already has a phone and a complete saved delivery address,
 * so tests of the order paths start from an account that is allowed to order. Everything lives inside the test's
 * rolled-back transaction.
 */
trait GivesDeliveryDetails
{
    /** @return array{province:Province,city:CityMunicipality,barangay:Barangay} */
    protected function testPlaces(string $province = 'Batangas', string $city = 'Lipa City', string $barangay = 'Marauoy'): array
    {
        $p = Province::firstOrCreate(['name' => $province]);
        $c = CityMunicipality::firstOrCreate(['province_id' => $p->id, 'name' => $city]);
        $b = Barangay::firstOrCreate(['city_municipality_id' => $c->id, 'name' => $barangay]);

        return ['province' => $p, 'city' => $c, 'barangay' => $b];
    }

    /** The address array the order and profile forms post (with an optional field prefix). */
    protected function addressInput(string $prefix = '', array $over = [], ?array $places = null): array
    {
        $pl = $places ?? $this->testPlaces();
        $base = [
            'province_id' => $pl['province']->id, 'city_id' => $pl['city']->id, 'barangay_id' => $pl['barangay']->id,
            'street' => 'Blk 5 Lot 12, Sampaguita St.', 'postal_code' => '4217', 'landmark' => 'Near the chapel',
        ];
        $out = [];
        foreach (array_merge($base, $over) as $k => $v) {
            $out[$prefix . $k] = $v;
        }

        return $out;
    }

    protected function giveDeliveryDetails(User $user, string $phone = '09171234567'): User
    {
        $a = $this->addressInput();
        $user->forceFill([
            'phone_number' => $phone, 'phone_e164' => '+63' . substr($phone, 1), 'phone_country' => 'PH',
            'address_province_id' => $a['province_id'], 'address_city_id' => $a['city_id'], 'address_barangay_id' => $a['barangay_id'],
            'address_street' => $a['street'], 'address_postal_code' => $a['postal_code'], 'address_landmark' => $a['landmark'],
        ])->save();

        return $user;
    }
}
