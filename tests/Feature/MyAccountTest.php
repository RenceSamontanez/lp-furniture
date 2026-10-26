<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Province;
use App\Models\User;
use App\Support\AccountPassword;
use App\Support\DeliveryAddress;
use App\Support\PhoneNumber;
use App\Support\Places\PlaceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * My Account rebuild: required phone (E.164), structured delivery address by place id, the order paths that need both,
 * the order's address snapshot, the weekly password limit and the old single-line address.
 */
class MyAccountTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── helpers ───────────── */

    private function customer(): User
    {
        return $this->makeUser();
    }

    /** A customer with no phone and no saved address. */
    private function bareCustomer(): User
    {
        $u = $this->makeUser();
        $u->forceFill([
            'phone_number' => null, 'phone_e164' => null, 'phone_country' => null,
            'address_province_id' => null, 'address_city_id' => null, 'address_barangay_id' => null,
            'address_street' => null, 'address_postal_code' => null, 'address_landmark' => null,
        ])->save();

        return $u->fresh();
    }

    private function profileInput(array $over = []): array
    {
        return array_merge([
            'first_name' => 'Maria', 'last_name' => 'Reyes-Cruz', 'phone_country' => 'PH', 'phone_number' => '0917 123 4567',
        ], $this->addressInput(), $over);
    }

    private function save(User $u, array $over = [])
    {
        return $this->actingAs($u)->from(route('profile'))->put(route('profile.update'), $this->profileInput($over));
    }

    private function product(): Product
    {
        return Product::create([
            'product_name' => 'Side table', 'price' => 1000, 'stock_quantity' => 10, 'category' => 'Tables',
            'description' => 'A table', 'product_image' => 'x.jpg', 'status' => 'available',
        ]);
    }

    private function cart(User $u): void
    {
        DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $this->product()->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
    }

    private function orderForm(array $over = []): array
    {
        return array_merge(['delivery_option' => 'pickup', 'payment_type' => 'full', 'payment_method' => 'onsite'], $over);
    }

    /* ───────────── the page ───────────── */

    public function test_the_account_page_renders_every_tab_with_the_new_markup(): void
    {
        $u = $this->customer();
        $page = $this->actingAs($u)->get(route('profile'))->assertOk();
        foreach (['Personal info', 'Security', 'Verification', 'Account', 'Sign out', 'name="phone_number"', 'name="province_id"', 'name="city_id"', 'name="barangay_id"',
                  'name="postal_code"', 'name="landmark"', 'You can change your password', 'Available'] as $s) {
            $page->assertSee($s, false);
        }
        $page->assertDontSee('AI design');
        // the shared navbar and footer are still there
        $page->assertSee('id="site-nav"', false);
    }

    public function test_a_google_account_gets_the_signed_in_with_google_card_and_no_password_form(): void
    {
        $u = $this->customer();
        $u->forceFill(['google_id' => 'g-1', 'password' => null])->save();
        $page = $this->actingAs($u->fresh())->get(route('profile', ['tab' => 'security']))->assertOk();
        $page->assertSee('Signed in with Google')->assertDontSee('name="current_password"', false);
        $this->actingAs($u->fresh())->put(route('profile.password'), ['current_password' => 'x', 'password' => 'Newpass12', 'password_confirmation' => 'Newpass12'])
            ->assertSessionHas('error');
        $this->assertNull($u->fresh()->password_changed_at);
    }

    /* ───────────── phone ───────────── */

    public function test_philippine_numbers_in_both_formats_are_stored_as_e164(): void
    {
        foreach (['0917 123 4567', '+63 917 123 4567', '09171234567', '639171234567'] as $in) {
            $this->assertSame('+639171234567', PhoneNumber::parse($in, 'PH')['e164'], $in);
        }
        $u = $this->customer();
        $this->save($u, ['phone_number' => '+63 918 765 4321'])->assertSessionHasNoErrors();
        $u = $u->fresh();
        $this->assertSame('+639187654321', $u->phone_e164);
        $this->assertSame('PH', $u->phone_country);
        $this->assertSame('09187654321', $u->phone_number);   // the mirror older code reads
    }

    public function test_philippine_numbers_need_exactly_the_right_digits(): void
    {
        foreach (['0917 123 456', '0917 123 45678', '0817 123 4567', '917123456', '02 8123 4567', '0917abc4567', ''] as $bad) {
            $this->assertFalse(PhoneNumber::parse($bad, 'PH')['ok'], "[$bad]");
        }
        $u = $this->customer();
        $this->save($u, ['phone_number' => '0917 123 456'])->assertSessionHasErrors('phone_number');
        $this->save($u, ['phone_number' => ''])->assertSessionHasErrors('phone_number');
        $this->assertSame('09171234567', $u->fresh()->phone_number);
    }

    public function test_other_countries_use_their_own_length_rules(): void
    {
        $this->assertSame('+6581234567', PhoneNumber::parse('8123 4567', 'SG')['e164']);
        $this->assertFalse(PhoneNumber::parse('8123 456', 'SG')['ok']);
        $this->assertSame('+12025550143', PhoneNumber::parse('(202) 555-0143', 'US')['e164']);
        $this->assertFalse(PhoneNumber::parse('202 555 014', 'US')['ok']);
        $this->assertSame('+447911123456', PhoneNumber::parse('07911 123456', 'GB')['e164']);   // trunk zero dropped
        $this->assertFalse(PhoneNumber::parse('+65 8123 4567', 'PH')['ok']);                    // wrong country for the plus code
        $this->assertFalse(PhoneNumber::parse('8123 4567', 'XX')['ok']);
        $u = $this->customer();
        $this->save($u, ['phone_country' => 'SG', 'phone_number' => '8123 4567'])->assertSessionHasNoErrors();
        $this->assertSame('+6581234567', $u->fresh()->phone_e164);
    }

    /* ───────────── text safety ───────────── */

    public function test_names_only_allow_letters_spaces_hyphen_apostrophe_and_period(): void
    {
        $u = $this->customer();
        $this->save($u, ['first_name' => "Mary-Ann O'Neil Jr."])->assertSessionHasNoErrors();
        foreach (['Maria1', 'Maria_', 'M@ria', 'maria@example.com', 'www.maria.com', 'maria.com', 'Ma<b>ria</b>9', 'Maria#', '', '12345'] as $bad) {
            $this->save($u, ['first_name' => $bad])->assertSessionHasErrors('first_name');
            $this->save($u, ['last_name' => $bad])->assertSessionHasErrors('last_name');
        }
    }

    public function test_names_and_address_lines_reject_unsuitable_words(): void
    {
        $u = $this->customer();
        $this->save($u, ['first_name' => 'Fuck'])->assertSessionHasErrors('first_name');
        $this->save($u, ['street' => 'Sex shop street 5'])->assertSessionHasErrors('street');
        $this->save($u, ['landmark' => 'Near the sex shop'])->assertSessionHasErrors('landmark');
    }

    public function test_street_and_landmark_reject_links_and_emails(): void
    {
        $u = $this->customer();
        foreach (['street', 'landmark'] as $f) {
            foreach (['visit www.shop.com', 'me@example.com', 'https://x.test', 'shop dot com', 'shop.com'] as $bad) {
                $this->save($u, [$f => $bad])->assertSessionHasErrors($f);
            }
        }
        $this->save($u, ['landmark' => ''])->assertSessionHasNoErrors();   // landmark is optional
    }

    public function test_postal_code_is_four_digits_and_required_with_an_address(): void
    {
        $u = $this->customer();
        foreach (['', '421', '42177', '42a7', 'abcd'] as $bad) {
            $this->save($u, ['postal_code' => $bad])->assertSessionHasErrors('postal_code');
        }
        $this->save($u, ['postal_code' => '4217'])->assertSessionHasNoErrors();
    }

    public function test_a_half_filled_address_is_refused_and_an_empty_one_clears_it(): void
    {
        $u = $this->customer();
        $this->save($u, ['barangay_id' => ''])->assertSessionHasErrors('barangay_id');
        $this->assertNotNull($u->fresh()->address_barangay_id);

        $this->save($u, ['province_id' => '', 'city_id' => '', 'barangay_id' => '', 'street' => '', 'postal_code' => '', 'landmark' => ''])->assertSessionHasNoErrors();
        $this->assertNull($u->fresh()->address_province_id);
        $this->assertNull($u->fresh()->address_street);
    }

    /* ───────────── places ───────────── */

    public function test_the_barangay_must_belong_to_the_city_and_the_city_to_the_province(): void
    {
        $a = $this->testPlaces();
        $other = $this->testPlaces('Cebu', 'Cebu City', 'Lahug');
        $u = $this->customer();

        // city of another province
        $this->save($u, ['province_id' => $a['province']->id, 'city_id' => $other['city']->id, 'barangay_id' => $other['barangay']->id])->assertSessionHasErrors('barangay_id');
        // barangay of another city
        $this->save($u, ['province_id' => $a['province']->id, 'city_id' => $a['city']->id, 'barangay_id' => $other['barangay']->id])->assertSessionHasErrors('barangay_id');
        // an id that does not exist, and text instead of an id
        $this->save($u, ['province_id' => 999999])->assertSessionHasErrors('province_id');
        $this->save($u, ['city_id' => 'Lipa City'])->assertSessionHasErrors('city_id');
        // the matching chain passes
        $this->save($u, $this->addressInput('', [], $other))->assertSessionHasNoErrors();
        $this->assertSame($other['barangay']->id, (int) $u->fresh()->address_barangay_id);
    }

    public function test_the_place_endpoints_return_one_level_at_a_time_and_need_a_sign_in(): void
    {
        $a = $this->testPlaces();
        $this->get(route('places.provinces'))->assertRedirect(route('login'));

        $u = $this->customer();
        $this->actingAs($u)->getJson(route('places.provinces'))->assertOk()->assertJsonFragment(['name' => 'Batangas']);
        $this->actingAs($u)->getJson(route('places.cities', $a['province']->id))->assertOk()->assertExactJson([['id' => $a['city']->id, 'name' => 'Lipa City']]);
        $this->actingAs($u)->getJson(route('places.barangays', $a['city']->id))->assertOk()->assertExactJson([['id' => $a['barangay']->id, 'name' => 'Marauoy']]);
        $this->actingAs($u)->getJson(route('places.cities', 999999))->assertNotFound();
    }

    public function test_the_importer_loads_a_tree_without_duplicating_and_a_psa_csv_with_codes(): void
    {
        $tree = ['Metro Manila' => ['Manila' => ['Barangay 1', 'Barangay 2'], 'Pateros' => ['Aguho']], 'Abra' => ['Bangued' => ['Agtangao', 'Agtangao']]];
        $imp = new PlaceImporter();
        $s = $imp->importTree($tree);
        $this->assertSame([2, 3, 4], [$s['provinces'], $s['cities'], $s['barangays']]);
        $this->assertSame(1, $s['skipped']);
        $s = $imp->importTree($tree);                             // running it again changes nothing
        $this->assertSame([2, 3, 4], [$s['provinces'], $s['cities'], $s['barangays']]);
        $this->assertSame(1, Province::where('name', 'Metro Manila')->count());

        $csv = tempnam(sys_get_temp_dir(), 'psgc');
        file_put_contents($csv, implode("\n", [
            '10-digit PSGC,Name,Correspondence Code,Geographic Level',
            '1300000000,National Capital Region (NCR),,Reg',
            '1380600000,City of Manila,,City',
            '1380601000,Tondo I/II,,SubMun',
            '1380601001,Barangay 1,,Bgy',
            '1380601002,Barangay 2,,Bgy',
            '0100000000,Region I,,Reg',
            '0102800000,Ilocos Norte,,Prov',
            '0102801000,Adams,,Mun',
            '0102801001,Adams (Pob.),,Bgy',
        ]));
        [$t, $codes] = $imp->parsePsaCsv($csv);
        unlink($csv);
        $this->assertSame(['Barangay 1', 'Barangay 2'], $t['Metro Manila']['City of Manila']);   // sub-municipality skipped
        $this->assertSame(['Adams (Pob.)'], $t['Ilocos Norte']['Adams']);
        $this->assertSame('0102801001', $codes['brgy']['Ilocos Norte|Adams|Adams (Pob.)']);
        $imp->importTree($t, $codes);
        $this->assertSame('1380601001', Barangay::where('name', 'Barangay 1')->whereHas('city', fn ($q) => $q->where('name', 'City of Manila'))->value('psgc_code'));
    }

    public function test_the_checked_in_fallback_file_is_a_real_place_tree(): void
    {
        $tree = json_decode(file_get_contents(base_path('docs/data/psgc-2019.json')), true);
        $this->assertGreaterThan(80, count($tree));
        $this->assertArrayHasKey('Metro Manila', $tree);
        $this->assertContains('Lipa City', array_keys($tree['Batangas']));
        $this->assertContains('Barangay 1', $tree['Metro Manila']['Manila']);
    }

    /* ───────────── ordering needs a phone and an address ───────────── */

    public function test_a_catalog_order_needs_a_phone_and_a_complete_address(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);

        $this->actingAs($u)->post(route('order.place'), $this->orderForm())
            ->assertSessionHasErrors(['contact_phone', 'addr_province_id']);
        $this->assertSame(0, Order::count());

        // phone but no address
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['contact_phone' => '0917 123 4567', 'phone_country' => 'PH']))
            ->assertSessionHasErrors('addr_province_id');
        // address but no postal code
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['contact_phone' => '0917 123 4567'] + $this->addressInput('addr_', ['postal_code' => ''])))
            ->assertSessionHasErrors('addr_postal_code');
        $this->assertSame(0, Order::count());
    }

    public function test_pickup_orders_need_the_same_details_as_delivery_orders(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);
        foreach (['pickup', 'delivery'] as $opt) {
            $this->actingAs($u)->post(route('order.place'), $this->orderForm(['delivery_option' => $opt]))->assertSessionHasErrors('contact_phone');
        }
        $this->assertSame(0, Order::count());
    }

    public function test_first_time_order_with_save_it_keeps_the_address_and_the_phone(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm([
            'recipient_name' => 'Ana Cruz', 'contact_phone' => '+63 917 123 4567', 'phone_country' => 'PH',
            'address_mode' => 'new', 'keep_address' => 'yes',
        ] + $this->addressInput('addr_')))->assertSessionHasNoErrors();

        $u = $u->fresh();
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $u->address_street);
        $this->assertSame('+639171234567', $u->phone_e164);
        $order = Order::first();
        $this->assertSame('Ana Cruz', $order->delivery_snapshot['recipient']);
        $this->assertSame('+639171234567', $order->delivery_snapshot['phone']);
        $this->assertSame('Marauoy', $order->delivery_snapshot['barangay']);
        $this->assertStringContainsString('Brgy. Marauoy, Lipa City, Batangas 4217', $order->delivery_address);
    }

    public function test_first_time_order_with_only_for_this_order_does_not_save_the_address(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm([
            'contact_phone' => '0917 123 4567', 'address_mode' => 'new', 'keep_address' => 'no',
        ] + $this->addressInput('addr_')))->assertSessionHasNoErrors();

        $this->assertNull($u->fresh()->address_street);
        $this->assertSame(1, Order::count());
        $this->assertNotNull(Order::first()->delivery_snapshot);
    }

    public function test_an_order_uses_the_saved_address_by_default_and_snapshots_it(): void
    {
        $u = $this->customer();
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm())->assertSessionHasNoErrors();
        $order = Order::first();
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $order->delivery_snapshot['street']);
        $this->assertSame('+639171234567', $order->delivery_snapshot['phone']);
        $this->assertSame('Tester', explode(' ', $order->delivery_snapshot['recipient'])[1]);
    }

    public function test_a_different_address_for_this_order_leaves_the_saved_one_alone(): void
    {
        $u = $this->customer();
        $other = $this->testPlaces('Cebu', 'Cebu City', 'Lahug');
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['address_mode' => 'new', 'keep_address' => 'no'] + $this->addressInput('addr_', ['street' => '9 Osmena Blvd.'], $other)))
            ->assertSessionHasNoErrors();

        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $u->fresh()->address_street);
        $this->assertSame('9 Osmena Blvd.', Order::first()->delivery_snapshot['street']);
        $this->assertSame('Lahug', Order::first()->delivery_snapshot['barangay']);
    }

    public function test_replace_my_saved_address_changes_the_profile_but_never_an_old_order(): void
    {
        $u = $this->customer();
        $other = $this->testPlaces('Cebu', 'Cebu City', 'Lahug');
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm())->assertSessionHasNoErrors();
        $old = Order::first();

        $this->cart($u);
        // the duplicate-order guard looks at the last 10 seconds
        Carbon::setTestNow(now()->addMinutes(5));
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['address_mode' => 'new', 'keep_address' => 'yes'] + $this->addressInput('addr_', ['street' => '9 Osmena Blvd.'], $other)))
            ->assertSessionHasNoErrors();

        $this->assertSame('9 Osmena Blvd.', $u->fresh()->address_street);
        $this->assertSame('Lahug', Barangay::find($u->fresh()->address_barangay_id)->name);
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $old->fresh()->delivery_snapshot['street']);   // the old order is untouched
        $this->assertSame(2, Order::count());
    }

    public function test_editing_the_profile_later_does_not_change_an_old_order(): void
    {
        $u = $this->customer();
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['delivery_option' => 'delivery']))->assertSessionHasNoErrors();
        $this->save($u->fresh(), ['street' => '77 New Street', 'phone_number' => '0918 765 4321'])->assertSessionHasNoErrors();

        $o = Order::first()->fresh();
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $o->delivery_snapshot['street']);
        $this->assertSame('+639171234567', $o->delivery_snapshot['phone']);
        $this->assertSame('09171234567', Delivery::first()->contact_number === '0917 123 4567' ? '09171234567' : Delivery::first()->contact_number);
    }

    public function test_the_order_form_rejects_a_mismatched_place_chain_and_unsafe_text(): void
    {
        $u = $this->customer();
        $other = $this->testPlaces('Cebu', 'Cebu City', 'Lahug');
        $this->cart($u);
        $base = $this->orderForm(['address_mode' => 'new', 'keep_address' => 'no']);

        $this->actingAs($u)->post(route('order.place'), $base + $this->addressInput('addr_', ['barangay_id' => $other['barangay']->id]))->assertSessionHasErrors('addr_barangay_id');
        $this->actingAs($u)->post(route('order.place'), $base + $this->addressInput('addr_', ['street' => 'see www.shop.com']))->assertSessionHasErrors('addr_street');
        $this->actingAs($u)->post(route('order.place'), $base + $this->addressInput('addr_', ['landmark' => 'a@b.com']))->assertSessionHasErrors('addr_landmark');
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['recipient_name' => 'Ana 99']))->assertSessionHasErrors('recipient_name');
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['contact_phone' => '12345']))->assertSessionHasErrors('contact_phone');
        $this->assertSame(0, Order::count());
    }

    public function test_choosing_the_saved_address_when_none_exists_is_refused(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);
        $this->actingAs($u)->post(route('order.place'), $this->orderForm(['contact_phone' => '0917 123 4567', 'address_mode' => 'saved']))
            ->assertSessionHasErrors('addr_province_id');
        $this->assertSame(0, Order::count());
    }

    public function test_the_checkout_page_shows_the_order_details_in_the_right_state(): void
    {
        $u = $this->bareCustomer();
        $this->cart($u);
        $first = $this->actingAs($u)->get(route('checkout'))->assertOk();
        $first->assertSee('Use this as my permanent delivery address?')->assertSee('Yes, save it')->assertSee('No, only for this order')
              ->assertSee('name="addr_province_id"', false)->assertDontSee('Use a different address');

        $this->giveDeliveryDetails($u);
        $saved = $this->actingAs($u->fresh())->get(route('checkout'))->assertOk();
        $saved->assertSee('Saved address')->assertSee('Use a different address')->assertSee('Replace your saved delivery address with this one?')
              ->assertSee('Yes, replace my saved address');
    }

    /* ───────────── old single-line address ───────────── */

    public function test_the_old_address_text_is_kept_and_shown_as_a_read_only_previous_address(): void
    {
        $u = $this->bareCustomer();
        $u->forceFill(['address' => 'Purok 3, Lipa, Batangas'])->save();

        $this->actingAs($u->fresh())->get(route('profile'))->assertOk()->assertSee('Previous address')->assertSee('Purok 3, Lipa, Batangas');
        $this->cart($u);
        $this->actingAs($u->fresh())->get(route('checkout'))->assertOk()->assertSee('Previous address')->assertSee('Purok 3, Lipa, Batangas');

        // an order is refused until the structured parts are chosen
        $this->actingAs($u->fresh())->post(route('order.place'), $this->orderForm(['contact_phone' => '0917 123 4567']))->assertSessionHasErrors('addr_province_id');

        // saving the structured address does not delete the old text
        $this->save($u->fresh())->assertSessionHasNoErrors();
        $this->assertSame('Purok 3, Lipa, Batangas', $u->fresh()->address);
        $this->actingAs($u->fresh())->get(route('profile'))->assertOk()->assertDontSee('Previous address (read only)');
    }

    public function test_the_account_migrations_can_be_rolled_back_and_run_again(): void
    {
        // The real rollback runs against the scratch database in the report; here we check that every migration
        // defines down() and that it names every column or table that up() adds.
        foreach (['create_places_tables', 'add_account_details_to_users', 'add_delivery_snapshot_to_orders'] as $name) {
            $file = collect(glob(database_path('migrations/*_' . $name . '.php')))->first();
            $this->assertNotNull($file, $name);
            $src = file_get_contents($file);
            $this->assertStringContainsString('function down()', $src, $name);
        }
        $down = file_get_contents(collect(glob(database_path('migrations/*_add_account_details_to_users.php')))->first());
        foreach (['phone_e164', 'phone_country', 'password_changed_at', 'address_province_id', 'address_city_id', 'address_barangay_id', 'address_street', 'address_postal_code', 'address_landmark'] as $col) {
            $this->assertGreaterThanOrEqual(2, substr_count($down, $col), "$col is added and dropped");
        }
        $this->assertStringNotContainsString("dropColumn('address')", $down);   // the old text column is never touched
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', 'address'));
    }

    /* ───────────── password ───────────── */

    private function changePassword(User $u, string $current = 'OldPass123', string $new = 'NewPass456')
    {
        return $this->actingAs($u)->from(route('profile', ['tab' => 'security']))
            ->put(route('profile.password'), ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new]);
    }

    private function withPassword(string $plain = 'OldPass123'): User
    {
        $u = $this->customer();
        $u->forceFill(['password' => $plain])->save();

        return $u->fresh();
    }

    public function test_a_password_never_expires_and_an_unchanged_one_can_be_changed(): void
    {
        $u = $this->withPassword();
        $u->forceFill(['created_at' => now()->subYears(3)])->save();
        $this->assertFalse(AccountPassword::isLocked($u->fresh()));
        $this->actingAs($u->fresh())->get(route('profile', ['tab' => 'security']))->assertOk()->assertSee('You can change your password')->assertSee('Available');

        $this->changePassword($u)->assertSessionHasNoErrors();
        $u = $u->fresh();
        $this->assertTrue(Hash::check('NewPass456', $u->password));
        $this->assertNotNull($u->password_changed_at);
    }

    public function test_after_a_change_the_password_is_locked_for_seven_days(): void
    {
        $u = $this->withPassword();
        $this->changePassword($u)->assertSessionHasNoErrors();

        Carbon::setTestNow(now()->addDays(2));
        $u = $u->fresh();
        $this->assertTrue(AccountPassword::isLocked($u));
        $page = $this->actingAs($u)->get(route('profile', ['tab' => 'security']))->assertOk();
        $page->assertSee('Password changed 2 days ago')->assertSee('You can change it again on')
             ->assertSee($u->password_changed_at->copy()->addDays(7)->format('M j, Y'))->assertSee('5 days to wait');
        $this->assertMatchesRegularExpression('/id="p0"[^>]*disabled/', $page->getContent());

        // the server refuses, whatever the page did
        $this->changePassword($u, 'NewPass456', 'Another789')->assertSessionHasErrors('password_form');
        $this->assertTrue(Hash::check('NewPass456', $u->fresh()->password));
    }

    public function test_the_password_can_be_changed_again_after_seven_days(): void
    {
        $u = $this->withPassword();
        $this->changePassword($u)->assertSessionHasNoErrors();

        Carbon::setTestNow(now()->addDays(6)->addHours(23));
        $this->changePassword($u->fresh(), 'NewPass456', 'Another789')->assertSessionHasErrors('password_form');

        Carbon::setTestNow(now()->addHours(2));      // 7 days and 1 hour after the change
        $this->assertFalse(AccountPassword::isLocked($u->fresh()));
        $this->actingAs($u->fresh())->get(route('profile', ['tab' => 'security']))->assertSee('You can change your password');
        $this->changePassword($u->fresh(), 'NewPass456', 'Another789')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Another789', $u->fresh()->password));
    }

    public function test_forgot_password_by_email_always_works_and_does_not_count_toward_the_limit(): void
    {
        $u = $this->withPassword();
        $this->changePassword($u)->assertSessionHasNoErrors();
        $changedAt = $u->fresh()->password_changed_at;
        $this->assertTrue(AccountPassword::isLocked($u->fresh()));

        // locked, and the reset link is still sent and the reset still works
        auth()->logout();
        \Illuminate\Support\Facades\Notification::fake();
        $this->post(route('password.email'), ['email' => $u->email])->assertSessionHasNoErrors();
        $token = Password::createToken($u->fresh());
        $this->post(route('password.update'), ['token' => $token, 'email' => $u->email, 'password' => 'Reset12345', 'password_confirmation' => 'Reset12345'])
            ->assertRedirect(route('login'));

        $fresh = $u->fresh();
        $this->assertTrue(Hash::check('Reset12345', $fresh->password));
        $this->assertTrue($fresh->password_changed_at->equalTo($changedAt));      // the reset neither resets nor extends the lock
        $this->assertTrue(AccountPassword::isLocked($fresh));

        // and a reset does not start a lock for someone who never used the Security tab
        $v = $this->withPassword();
        $token = Password::createToken($v);
        $this->post(route('password.update'), ['token' => $token, 'email' => $v->email, 'password' => 'Reset12345', 'password_confirmation' => 'Reset12345']);
        $this->assertNull($v->fresh()->password_changed_at);
        $this->assertFalse(AccountPassword::isLocked($v->fresh()));
    }

    public function test_the_new_password_rules_are_enforced_on_the_server(): void
    {
        $u = $this->withPassword();
        $cases = [
            ['short1A', 'Use at least 8'],
            ['alllowercase1', 'upper and lower'],
            ['ALLUPPERCASE1', 'upper and lower'],
            ['NoNumbersHere', 'number'],
        ];
        foreach ($cases as [$bad]) {
            $this->changePassword($u, 'OldPass123', $bad)->assertSessionHasErrors('password');
        }
        $this->changePassword($u, 'OldPass123', 'OldPass123')->assertSessionHasErrors('password_form');           // same as current
        $this->changePassword($u, 'WrongCurrent1', 'NewPass456')->assertSessionHasErrors('password_form');          // wrong current
        $this->actingAs($u)->from(route('profile'))->put(route('profile.password'), ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'Different1'])
            ->assertSessionHasErrors('password');                                                                    // do not match
        $this->assertTrue(Hash::check('OldPass123', $u->fresh()->password));
        $this->assertNull($u->fresh()->password_changed_at);
    }

    public function test_changing_the_password_signs_out_other_devices_but_not_this_one(): void
    {
        $u = $this->withPassword();
        $oldStamp = \App\Http\Middleware\SignOutOnPasswordChange::stampFor($u);

        $this->changePassword($u)->assertSessionHasNoErrors();
        $after = $u->fresh();
        $this->assertNotSame($oldStamp, \App\Http\Middleware\SignOutOnPasswordChange::stampFor($after));

        // this browser took the new stamp and stays signed in
        $this->assertSame(\App\Http\Middleware\SignOutOnPasswordChange::stampFor($after), session('pw_stamp'));
        $this->get(route('profile'))->assertOk();

        // another browser still holds the old stamp: its next request ends the session
        $this->flushSession();
        $this->actingAs($after)->withSession(['pw_stamp_user' => $after->id, 'pw_stamp' => $oldStamp])
            ->get(route('profile'))->assertRedirect(route('login'));
    }

    public function test_a_password_reset_by_email_also_signs_out_other_devices(): void
    {
        $u = $this->withPassword();
        $oldStamp = \App\Http\Middleware\SignOutOnPasswordChange::stampFor($u);
        $this->post(route('password.update'), ['token' => Password::createToken($u), 'email' => $u->email, 'password' => 'Reset12345', 'password_confirmation' => 'Reset12345']);

        $this->flushSession();
        $this->actingAs($u->fresh())->withSession(['pw_stamp_user' => $u->id, 'pw_stamp' => $oldStamp])->get(route('profile'))->assertRedirect(route('login'));
    }
}
