<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\UserNameChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * My Account, Personal info: three cards (name, contact, delivery address), each saved on its own through
 * PUT /profile/section, and the name-change notice and history for verified accounts.
 */
class PersonalInfoCardsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function bare(bool $verified = false): User
    {
        $u = $this->makeUser();
        $u->forceFill([
            'is_validated' => $verified,
            'phone_number' => null, 'phone_e164' => null, 'phone_country' => null,
            'address_province_id' => null, 'address_city_id' => null, 'address_barangay_id' => null,
            'address_street' => null, 'address_postal_code' => null, 'address_landmark' => null,
        ])->save();

        return $u->fresh();
    }

    private function saveSection(User $u, array $data, bool $json = true)
    {
        $c = $this->actingAs($u)->from(route('profile'));

        return $json ? $c->putJson(route('profile.section'), $data) : $c->put(route('profile.section'), $data);
    }

    /* ───────────── the bug: a valid phone alone must save ───────────── */

    public function test_a_valid_phone_alone_saves_with_an_empty_address(): void
    {
        $u = $this->bare();
        $this->saveSection($u, ['section' => 'phone', 'phone_country' => 'PH', 'phone_number' => '09451031206'])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Phone number saved', 'values' => ['display' => '0945 103 1206']]);

        $u = $u->fresh();
        $this->assertSame('+639451031206', $u->phone_e164);
        $this->assertNull($u->address_street);                       // the address card was not touched
    }

    public function test_the_page_has_no_button_pointing_at_a_missing_form(): void
    {
        $html = $this->actingAs($this->bare())->get(route('profile'))->assertOk()->getContent();
        $this->assertStringNotContainsString('f-personal', $html);
        preg_match_all('/form="([^"]+)"/', $html, $m);
        foreach ($m[1] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "a button points at a form id that does not exist: {$id}");
        }
    }

    /* ───────────── the three cards ───────────── */

    public function test_the_page_shows_three_read_only_cards_and_the_past_orders_line(): void
    {
        $page = $this->actingAs($this->bare())->get(route('profile'))->assertOk();
        foreach (['data-section="name"', 'data-section="phone"', 'data-section="address"', 'Save name', 'Save number', 'Save address', 'Add address', 'No delivery address yet',
                  'Changes here apply to your next orders. Past orders keep the address they were placed with.'] as $s) {
            $page->assertSee($s, false);
        }
        $page->assertDontSee('Save changes')->assertDontSee('id="fDisc"', false);
    }

    public function test_each_card_saves_only_its_own_fields(): void
    {
        $u = $this->bare();
        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Ana', 'last_name' => 'Cruz'])->assertOk()->assertJson(['message' => 'Name saved']);
        $u = $u->fresh();
        $this->assertSame('Ana', $u->first_name);
        $this->assertNull($u->phone_e164);
        $this->assertNull($u->address_street);

        $this->saveSection($u, ['section' => 'address'] + $this->addressInput())->assertOk()->assertJson(['message' => 'Delivery address saved']);
        $u = $u->fresh();
        $this->assertSame('Blk 5 Lot 12, Sampaguita St.', $u->address_street);
        $this->assertSame('Ana', $u->first_name);
        $this->assertNull($u->phone_e164);
    }

    public function test_an_empty_or_half_filled_address_cannot_be_saved(): void
    {
        $u = $this->bare();
        $this->saveSection($u, ['section' => 'address'])->assertStatus(422)->assertJsonValidationErrors(['province_id', 'city_id', 'barangay_id', 'street', 'postal_code']);
        $this->saveSection($u, ['section' => 'address'] + $this->addressInput('', ['postal_code' => '']))->assertStatus(422)->assertJsonValidationErrors(['postal_code']);
        $this->assertNull($u->fresh()->address_street);
    }

    public function test_an_address_save_does_not_change_past_orders(): void
    {
        $u = $this->makeUser();
        $snap = ['recipient' => 'Old', 'address' => ['street' => 'Old street']];
        $order = Order::create([
            'user_id' => $u->id, 'order_number' => 'ORD-T-1', 'total_amount' => 1000, 'order_status' => 'pending',
            'delivery_snapshot' => $snap,
        ]);
        $other = $this->testPlaces('Cavite', 'Dasmarinas', 'Zone 1');
        $this->saveSection($u, ['section' => 'address'] + $this->addressInput('', ['street' => 'New street 9'], $other))->assertOk();

        $this->assertSame('New street 9', $u->fresh()->address_street);
        $this->assertSame($snap, $order->fresh()->delivery_snapshot);
    }

    public function test_an_invalid_phone_is_refused_with_a_plain_message(): void
    {
        $u = $this->bare();
        $this->saveSection($u, ['section' => 'phone', 'phone_country' => 'PH', 'phone_number' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone_number']);
        $this->assertNull($u->fresh()->phone_e164);
    }

    public function test_without_json_the_endpoint_redirects_back(): void
    {
        $u = $this->bare();
        $this->saveSection($u, ['section' => 'phone', 'phone_country' => 'PH', 'phone_number' => '09451031206'], false)
            ->assertRedirect(route('profile', ['tab' => 'personal']))->assertSessionHas('success', 'Phone number saved');
    }

    public function test_an_unknown_section_is_refused(): void
    {
        $this->saveSection($this->bare(), ['section' => 'email', 'email' => 'x@example.test'])->assertStatus(422);
    }

    /* ───────────── name change on a verified account ───────────── */

    public function test_a_verified_account_must_see_the_notice_before_a_name_change_is_saved(): void
    {
        $u = $this->bare(true);
        $this->actingAs($u)->get(route('profile'))->assertSee('Your name must match your ID.', false);

        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Maria', 'last_name' => 'Santos'])
            ->assertStatus(422)->assertJsonValidationErrors(['name_confirm']);
        $this->assertSame('Customer', $u->fresh()->first_name);
        $this->assertSame(0, UserNameChange::count());
    }

    public function test_save_anyway_changes_the_name_keeps_the_verification_and_records_the_history(): void
    {
        $u = $this->bare(true);
        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Maria', 'last_name' => 'Santos', 'confirm_name_change' => 1])->assertOk();

        $u = $u->fresh();
        $this->assertSame('Maria', $u->first_name);
        $this->assertTrue((bool) $u->is_validated, 'the verification is not reset automatically');
        $h = UserNameChange::where('user_id', $u->id)->sole();
        $this->assertSame(['Customer', 'Tester', 'Maria', 'Santos', true], [$h->old_first_name, $h->old_last_name, $h->new_first_name, $h->new_last_name, $h->was_verified]);
    }

    public function test_an_unverified_account_sees_no_notice_and_saves_straight_away(): void
    {
        $u = $this->bare(false);
        $this->actingAs($u)->get(route('profile'))->assertDontSee('Your name must match your ID.', false);

        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Maria', 'last_name' => 'Santos'])->assertOk();
        $this->assertSame('Maria', $u->fresh()->first_name);
        $this->assertFalse(UserNameChange::where('user_id', $u->id)->sole()->was_verified);
    }

    public function test_saving_the_same_name_records_nothing_and_needs_no_confirmation(): void
    {
        $u = $this->bare(true);
        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Customer', 'last_name' => 'Tester'])->assertOk();
        $this->assertSame(0, UserNameChange::count());
    }

    public function test_the_admin_sees_the_name_history_on_the_customer_page(): void
    {
        $u = $this->bare(true);
        $this->saveSection($u, ['section' => 'name', 'first_name' => 'Maria', 'last_name' => 'Santos', 'confirm_name_change' => 1])->assertOk();

        $admin = $this->makeUser('admin');
        $this->actingAs($admin, 'admin')->get(route('admin.customers.show', $u->id))
            ->assertOk()->assertSee('Name changes')->assertSee('Customer Tester')->assertSee('Maria Santos');
    }
}
