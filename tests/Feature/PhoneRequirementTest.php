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
 * Stage 2: PhilippineMobile rule, profile phone validation (now required, see the My Account rebuild) and the
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

    /* ───────────── profile save (My Account rebuild: the phone is required, E.164 is stored, the address is structured) ───────────── */

    private function saveProfile($user, array $overrides = [])
    {
        return $this->putProfileSections($user, array_merge([
            'first_name'    => 'Ana',
            'last_name'     => 'Cruz',
            'phone_country' => 'PH',
            'phone_number'  => '09171234567',
        ], $this->addressInput(), $overrides));
    }

    private function withoutPhone($user)
    {
        $user->forceFill(['phone_number' => null, 'phone_e164' => null, 'phone_country' => null])->save();

        return $user->fresh();
    }

    public function test_profile_save_normalizes_a_new_number(): void
    {
        $user = $this->makeUser();

        $this->saveProfile($user, ['phone_number' => '+639181234567'])
            ->assertRedirect(route('profile', ['tab' => 'personal']))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('09181234567', $user->phone_number);
        $this->assertSame('+639181234567', $user->phone_e164);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $user->address_street);
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

    public function test_an_old_non_mobile_number_must_be_replaced_before_the_profile_can_be_saved(): void
    {
        // the phone is required and must be a real mobile number (or another country's valid number)
        $user = $this->makeUser();
        $user->forceFill(['phone_number' => '(043) 723-1234', 'phone_e164' => null])->save();

        $this->saveProfile($user, ['phone_number' => '(043) 723-1234', 'first_name' => 'Renamed'])->assertSessionHasErrors('phone_number');
        $this->assertSame('(043) 723-1234', $user->fresh()->phone_number);      // nothing was deleted or rewritten
        $this->assertNotSame('Renamed', $user->fresh()->first_name);

        $this->saveProfile($user, ['phone_number' => '0917 123 4567', 'first_name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $user->fresh()->first_name);
    }

    public function test_user_with_a_number_cannot_blank_it(): void
    {
        $user = $this->makeUser();

        $this->saveProfile($user, ['phone_number' => ''])->assertSessionHasErrors('phone_number');
        $this->assertSame('09171234567', $user->fresh()->phone_number);
    }

    public function test_a_google_user_without_a_phone_must_add_one_to_save(): void
    {
        $user = $this->withoutPhone($this->makeUser());
        $this->assertNull($user->phone_number);

        $this->saveProfile($user, ['phone_number' => '', 'first_name' => 'Google'])->assertSessionHasErrors('phone_number');
        $this->assertNull($user->fresh()->phone_number);

        $this->saveProfile($user, ['phone_number' => '0917 123 4567', 'first_name' => 'Google'])->assertSessionHasNoErrors();
        $this->assertSame('+639171234567', $user->fresh()->phone_e164);
    }

    /* ───────────── the profile page shows the phone ───────────── */

    public function test_profile_shows_an_empty_required_phone_field_without_a_number(): void
    {
        $user = $this->withoutPhone($this->makeUser());

        $this->actingAs($user)->get(route('profile'))->assertOk()
            ->assertSee('name="phone_number"', false)->assertSee('Phone number');
        $this->assertFalse($user->hasValidPhone());
    }

    public function test_profile_shows_a_stored_number_in_the_national_form(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('value="09171234567"', false);
        $this->assertTrue($user->hasValidPhone());
    }

    /* ───────────── ordering needs a phone (since the My Account rebuild) ───────────── */

    private function cartOf($user): void
    {
        $product = Product::create([
            'product_name' => 'Narra Chair', 'category' => 'Chairs', 'price' => 2500,
            'stock_quantity' => 5, 'product_status' => 'available',
        ]);
        DB::table('cart_items')->insert([
            'user_id' => $user->id, 'product_id' => $product->id, 'quantity' => 1,
            'added_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_catalog_checkout_needs_a_phone(): void
    {
        $user = $this->withoutPhone($this->makeUser());
        $this->cartOf($user);

        $this->actingAs($user)->get(route('checkout'))->assertOk();
        $this->actingAs($user)->post(route('order.place'), ['delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'onsite'])
            ->assertSessionHasErrors('contact_phone');
        $this->assertSame(0, Order::where('user_id', $user->id)->count());
    }

    public function test_catalog_checkout_works_with_a_phone_and_a_saved_address(): void
    {
        $user = $this->makeUser();
        $this->cartOf($user);

        $this->actingAs($user)->post(route('order.place'), [
            'delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'onsite',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $order->order_status);
        $this->assertSame(4, Product::first()->stock_quantity);
    }
}
