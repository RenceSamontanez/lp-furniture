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

    /**
     * Post the Personal info cards the way the page does, one section at a time (phone, address, name). Returns the first
     * response that carried errors, otherwise the last one. $input holds the same keys the old single form used.
     */
    protected function putProfileSections($user, array $input)
    {
        $sections = [
            'phone'   => ['phone_country', 'phone_number'],
            'address' => ['province_id', 'city_id', 'barangay_id', 'street', 'postal_code', 'landmark'],
            'name'    => ['first_name', 'last_name'],
        ];
        $last = null;
        foreach ($sections as $name => $keys) {
            if (!array_intersect($keys, array_keys($input))) {
                continue;
            }
            $last = $this->actingAs($user)->from(route('profile'))->put(route('profile.section'), ['section' => $name, 'confirm_name_change' => 1] + array_intersect_key($input, array_flip($keys)));
            if ($last->getSession()->has('errors')) {
                return $last;
            }
        }

        return $last;
    }
}
