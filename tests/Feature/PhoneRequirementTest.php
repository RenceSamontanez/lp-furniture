<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Rules\PhilippineMobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 2: PhilippineMobile rule, profile phone validation and the
 * "no valid phone" prompt. Catalog checkout must not need a phone.
 */
class PhoneRequirementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    /* ───────────── the rule ───────────── */

    public function test_accepted_formats_normalize_to_09(): void
    {
        foreach (['09171234567', '+639171234567', '639171234567', '0917 123 4567', '+63-917-123-4567'] as $input) {
            $this->assertSame('09171234567', PhilippineMobile::normalize($input), $input);
            $this->assertTrue(Validator::make(['p' => $input], ['p' => [new PhilippineMobile()]])->passes(), $input);
        }
    }

    public function test_invalid_formats_are_rejected(): void
    {
        $bad = ['', '0917123456', '091712345678', '9171234567', '+63917123456', '08171234567',
                '+6309171234567', '0063917123456', '(02) 8123 4567', '0917abc4567', '+1 917 123 4567'];

        foreach ($bad as $input) {
            $this->assertNull(PhilippineMobile::normalize($input), $input);
            $this->assertFalse(Validator::make(['p' => $input], ['p' => ['required', new PhilippineMobile()]])->passes(), $input);
        }

        $this->assertNull(PhilippineMobile::normalize(null));
        $this->assertNull(PhilippineMobile::normalize(9171234567));
    }

    /* ───────────── profile save ───────────── */

    private function saveProfile($user, array $overrides = [])
    {
        return $this->actingAs($user)->from(route('profile'))->put(route('profile.update'), array_merge([
            'first_name'   => 'Ana',
            'last_name'    => 'Cruz',
            'phone_number' => '09171234567',
            'address'      => 'Batangas City',
        ], $overrides));
    }

    public function test_form_without_address_field_keeps_stored_address(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '09171234567', 'address' => 'Lipa City'])->save();

        // what the Security tab's password form sends: no address field
        $this->actingAs($user)->from(route('profile'))->put(route('profile.update'), [
            'first_name'            => 'Ana',
            'last_name'             => 'Cruz',
            'phone_number'          => '09171234567',
            'password'              => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Lipa City', $user->fresh()->address);
    }

    public function test_form_with_address_still_updates_it(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '09171234567', 'address' => 'Lipa City'])->save();

        $this->saveProfile($user, ['address' => 'Batangas City'])->assertSessionHasNoErrors();
        $this->assertSame('Batangas City', $user->fresh()->address);

        $this->saveProfile($user, ['address' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->address);
    }

    public function test_profile_save_normalizes_a_new_number(): void
    {
        $user = $this->makeUser();

        $this->saveProfile($user, ['phone_number' => '+639181234567'])
            ->assertRedirect(route('profile'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('09181234567', $user->phone_number);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Batangas City', $user->address);
    }

    public function test_profile_save_rejects_an_invalid_new_number(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '09171234567'])->save();

        $this->saveProfile($user, ['phone_number' => '12345', 'first_name' => 'Changed'])
            ->assertSessionHasErrors('phone_number');

        $user->refresh();
        $this->assertSame('09171234567', $user->phone_number);
        $this->assertNotSame('Changed', $user->first_name);
    }

    public function test_existing_legacy_number_is_kept_when_not_edited(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '(043) 723-1234'])->save();

        $this->saveProfile($user, ['phone_number' => '(043) 723-1234', 'first_name' => 'Renamed'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('(043) 723-1234', $user->phone_number);
        $this->assertSame('Renamed', $user->first_name);
    }

    public function test_editing_a_legacy_number_is_validated(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '(043) 723-1234'])->save();

        $this->saveProfile($user, ['phone_number' => '(043) 723-9999'])->assertSessionHasErrors('phone_number');
        $this->assertSame('(043) 723-1234', $user->fresh()->phone_number);
    }

    public function test_user_with_a_number_cannot_blank_it(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '09171234567'])->save();

        $this->saveProfile($user, ['phone_number' => ''])->assertSessionHasErrors('phone_number');
        $this->assertSame('09171234567', $user->fresh()->phone_number);
    }

    public function test_google_user_without_phone_can_save_without_adding_one(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->phone_number);

        $this->saveProfile($user, ['phone_number' => '', 'first_name' => 'Google'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->phone_number);
        $this->assertSame('Google', $user->first_name);
    }

    /* ───────────── profile prompt ───────────── */

    public function test_profile_shows_not_set_prompt_without_phone(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('profile'))
            ->assertOk()
            ->assertSee('Add a mobile number.')
            ->assertSee('Not set');
    }

    public function test_profile_shows_unverified_for_a_legacy_number(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '(043) 723-1234'])->save();

        $this->actingAs($user)->get(route('profile'))
            ->assertOk()
            ->assertSee('Please check your mobile number.')
            ->assertSee('Unverified');
    }

    public function test_profile_has_no_prompt_with_a_valid_number(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '09171234567'])->save();

        $this->actingAs($user)->get(route('profile'))
            ->assertOk()
            ->assertDontSee('profile-phone-prompt', false)
            ->assertDontSee('profile-phone-tag', false);
    }

    /* ───────────── catalog checkout unaffected ───────────── */

    public function test_catalog_checkout_works_without_a_phone(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->phone_number);

        $product = Product::create([
            'product_name' => 'Narra Chair', 'category' => 'Chairs', 'price' => 2500,
            'stock_quantity' => 5, 'product_status' => 'available',
        ]);
        DB::table('cart_items')->insert([
            'user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->get(route('checkout'))->assertOk();
        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup',
            'payment_type'    => 'full',
            'payment_method'  => 'onsite',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $order->order_status);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }
}
