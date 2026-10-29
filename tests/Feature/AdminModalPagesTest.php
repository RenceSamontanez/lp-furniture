<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Chatbot Training, Contact Settings and System Health as modal pages (docs/admin/admin-modals-v1.html). The forms still post to the
 * existing controllers, the server validates, and a rejected submit reopens the right modal with the typed values.
 */
class AdminModalPagesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private function admin()
    {
        return $this->makeUser('admin');
    }

    private function intent(array $over = []): ChatbotIntent
    {
        return ChatbotIntent::create($over + ['keywords' => 'sofa, couch', 'response' => 'We make sofas.', 'category' => 'Furniture', 'is_active' => true]);
    }

    private function settings(array $over = []): BusinessSetting
    {
        BusinessSetting::query()->delete();

        return BusinessSetting::create($over + [
            'phone' => '09171234567', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture',
        ]);
    }

    /* ───────────── Chatbot Training ───────────── */

    public function test_the_intents_list_renders_with_the_new_columns_tools_and_modals(): void
    {
        $this->intent();
        $this->intent(['keywords' => 'pay, gcash', 'response' => 'We take GCash.', 'category' => 'Payment', 'is_active' => false]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<th>Category</th><th>Keywords</th><th>Response Preview</th><th>Status</th><th>Actions</th>~', $html);
        foreach (['Add Intent', 'Search Intents', 'All Categories', 'Intents', 'Unanswered Questions (0)', 'Active', 'Inactive'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('<option value="Payment"', $html);
        // one wide modal to add, one per listed intent to edit, one small delete confirmation; all use the shared modal
        $this->assertStringContainsString('id="intent-add"', $html);
        $this->assertSame(ChatbotIntent::count(), preg_match_all('/id="intent-edit-\d+"/', $html));
        $this->assertStringContainsString('id="intent-delete"', $html);
        $this->assertStringContainsString('lp-modal lp-modal--wide', $html);
        $this->assertStringContainsString('Delete This Intent?', $html);
        $this->assertStringContainsString('{{phone}}', $html);                       // the Contact Settings hint is kept
        $this->assertStringContainsString('Suggestion Chips (Comma-Separated)', $html);
        $this->assertStringContainsString('Tagalog Response', $html);
        $this->assertStringContainsString('assets/css/admin-modals.css', $html);
        $this->assertStringNotContainsString('onsubmit="return confirm(', $html);   // no native confirm any more
    }

    public function test_search_and_category_filter_combine(): void
    {
        $this->intent();
        $this->intent(['keywords' => 'sofa sale', 'response' => 'Sale on sofas.', 'category' => 'Promo']);
        $this->intent(['keywords' => 'pay', 'response' => 'We take sofa-shaped cash.', 'category' => 'Payment']);

        $admin = $this->admin();
        $both = $this->actingAs($admin, 'admin')->get(route('admin.chatbot.index', ['search' => 'sofa', 'category' => 'Promo']))->assertOk()->getContent();
        $this->assertStringContainsString('Sale on sofas.', $both);
        $this->assertStringNotContainsString('We make sofas.', $both);               // right search, wrong category
        $this->assertStringNotContainsString('sofa-shaped cash', $both);

        $search = $this->get(route('admin.chatbot.index', ['search' => 'sofa']))->getContent();
        $this->assertSame(3, preg_match_all('/id="intent-edit-\d+"/', $search));
    }

    public function test_unanswered_questions_live_on_their_own_tab(): void
    {
        $this->intent();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.chatbot.index'))->assertOk()->assertDontSee('Questions Luma Could Not Answer');
        $this->get(route('admin.chatbot.index', ['tab' => 'unanswered']))->assertOk()
            ->assertSee('Questions Luma Could Not Answer')->assertSee('No unanswered questions right now.')->assertDontSee('Search Intents');
    }

    public function test_adding_and_editing_an_intent_through_the_modal_routes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->from(route('admin.chatbot.index'))->post(route('admin.chatbot.store'), [
            '_modal' => 'intent-add', 'keywords' => 'delivery', 'response' => 'We deliver.', 'category' => 'Delivery', 'is_active' => 1,
        ])->assertRedirect(route('admin.chatbot.index'))->assertSessionHas('success', 'Intent added successfully.');
        $intent = ChatbotIntent::where('keywords', 'delivery')->firstOrFail();
        $this->assertTrue($intent->is_active);

        $this->put(route('admin.chatbot.update', $intent->id), [
            '_modal' => 'intent-edit-' . $intent->id, 'keywords' => 'delivery', 'response' => 'We deliver in Batangas.', 'category' => 'Delivery',
        ])->assertRedirect(route('admin.chatbot.index'))->assertSessionHas('success', 'Intent updated successfully.');
        $intent->refresh();
        $this->assertSame('We deliver in Batangas.', $intent->response);
        $this->assertFalse($intent->is_active);                                      // unticked box = deactivated, as before
        $this->assertArrayNotHasKey('_modal', $intent->getAttributes());            // the modal marker is never stored
    }

    public function test_a_rejected_add_reopens_the_add_modal_with_values_and_messages(): void
    {
        $admin = $this->admin();
        $before = ChatbotIntent::count();

        $this->actingAs($admin, 'admin')->from(route('admin.chatbot.index'))->post(route('admin.chatbot.store'), [
            '_modal' => 'intent-add', 'keywords' => 'kept words', 'response' => '', 'category' => str_repeat('C', 120), 'is_active' => 1,
        ])->assertRedirect(route('admin.chatbot.index'))->assertSessionHasErrors(['response', 'category']);
        $this->assertSame($before, ChatbotIntent::count());                          // nothing was saved

        $html = $this->get(route('admin.chatbot.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="intent-add"[^>]*data-open-on-load/', $html);
        $this->assertStringContainsString('kept words', $html);
        $this->assertStringContainsString(str_repeat('C', 120), $html);
        $this->assertStringContainsString('is-invalid', $html);
        $this->assertStringContainsString('The response field is required.', $html);
        $this->assertStringContainsString('The category field must not be greater than 100 characters.', $html);
    }

    public function test_a_rejected_edit_reopens_only_that_edit_modal(): void
    {
        $a = $this->intent();
        $b = $this->intent(['keywords' => 'pay', 'response' => 'We take GCash.', 'category' => 'Payment']);

        $this->actingAs($this->admin(), 'admin')->from(route('admin.chatbot.index'))->put(route('admin.chatbot.update', $b->id), [
            '_modal' => 'intent-edit-' . $b->id, 'keywords' => '', 'response' => 'typed but kept',
        ])->assertSessionHasErrors('keywords');

        $html = $this->get(route('admin.chatbot.index'))->getContent();
        $this->assertMatchesRegularExpression('/id="intent-edit-' . $b->id . '"[^>]*data-open-on-load/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="intent-edit-' . $a->id . '"[^>]*data-open-on-load/', $html);
        $segA = substr($html, strpos($html, 'id="intent-edit-' . $a->id . '"'), strpos($html, 'id="intent-edit-' . $b->id . '"') - strpos($html, 'id="intent-edit-' . $a->id . '"'));
        $segB = substr($html, strpos($html, 'id="intent-edit-' . $b->id . '"'));
        $this->assertStringNotContainsString('The keywords field is required.', $segA);   // only the modal that was submitted shows it
        $this->assertStringContainsString('The keywords field is required.', $segB);
        $this->assertStringContainsString('typed but kept', $html);
    }

    public function test_the_old_add_and_edit_urls_redirect_to_the_list_with_the_modal_open(): void
    {
        $intent = $this->intent();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.chatbot.create'))->assertRedirect(route('admin.chatbot.index', ['add' => 1]));
        $this->get(route('admin.chatbot.edit', $intent->id))->assertRedirect(route('admin.chatbot.index', ['edit' => $intent->id]));
        $this->get(route('admin.chatbot.edit', 999999))->assertNotFound();

        $this->assertMatchesRegularExpression('/id="intent-add"[^>]*data-open-on-load/', $this->get(route('admin.chatbot.index', ['add' => 1]))->getContent());
        $this->assertMatchesRegularExpression('/id="intent-edit-' . $intent->id . '"[^>]*data-open-on-load/', $this->get(route('admin.chatbot.index', ['edit' => $intent->id]))->getContent());
    }

    public function test_an_intent_on_a_later_page_still_opens_from_its_edit_link(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $this->intent(['keywords' => "kw $i", 'category' => 'A']);
        }
        $last = ChatbotIntent::orderByDesc('id')->first();

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.chatbot.index', ['edit' => $last->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="intent-edit-' . $last->id . '"[^>]*data-open-on-load/', $html);
    }

    public function test_toggle_and_delete_keep_their_server_behaviour_and_admin_only_access(): void
    {
        $intent = $this->intent();
        $admin = $this->admin();

        $this->post(route('admin.chatbot.toggle', $intent->id))->assertRedirect();                       // guests are turned away
        $this->assertTrue($intent->fresh()->is_active);
        $this->actingAs($this->makeUser('customer'))->delete(route('admin.chatbot.destroy', $intent->id))->assertRedirect();
        $this->assertNotNull(ChatbotIntent::find($intent->id));

        $this->actingAs($admin, 'admin')->post(route('admin.chatbot.toggle', $intent->id))->assertSessionHas('success');
        $this->assertFalse($intent->fresh()->is_active);
        $this->delete(route('admin.chatbot.destroy', $intent->id))->assertSessionHas('success', 'Intent deleted.');
        $this->assertNull(ChatbotIntent::find($intent->id));
    }

    /* ───────────── Contact Settings ───────────── */

    public function test_contact_settings_show_two_read_only_cards_with_not_set_text(): void
    {
        $this->settings(['shop_address' => 'Walk Street, San Juan', 'shop_landmarks' => 'Near the hospital', 'map_lat' => 13.8294, 'map_lng' => 121.4099]);

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.settings.contact.edit'))->assertOk()->getContent();

        foreach (['Business Contact Info', 'Shop Location', 'Where This Shows', '09171234567', 'hello@lpfurniture.test', 'https://facebook.com/lpfurniture',
                  'Walk Street, San Juan', 'Near the hospital'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertMatchesRegularExpression('/13\.8294\d*, 121\.4099\d*/', $html);   // the stored map pin (decimal columns keep trailing zeros)
        $this->assertStringContainsString('Not set. The TikTok button stays hidden in the website footer.', $html);
        $this->assertSame(2, preg_match_all('/data-lp-modal-open="contact-(biz|shop)"/', $html));      // two Edit buttons
        $this->assertStringContainsString('id="contact-biz"', $html);
        $this->assertStringContainsString('id="contact-shop"', $html);
        $this->assertStringContainsString('lp-modal lp-modal--mid', $html);
        // where the details show: what the code really does
        foreach (['website footer', 'checkout Need Help panel', 'design request page', 'Chatbot replies', 'Pickup details', 'Other Services'] as $where) {
            $this->assertStringContainsString($where, $html, $where);
        }
        $this->assertStringContainsString('Not yet saved by an admin', $html);
    }

    public function test_an_empty_shop_card_says_not_set_and_the_map_falls_back_to_the_address(): void
    {
        $this->settings();

        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.settings.contact.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Not set. The address built into the website is used.', $html);
        $this->assertStringContainsString('Not set. The map searches by the address instead.', $html);
    }

    public function test_each_card_saves_only_its_own_fields(): void
    {
        $admin = $this->admin();
        $this->settings(['shop_address' => 'Keep Me Street', 'shop_landmarks' => 'Keep landmark', 'map_lat' => 10.5, 'map_lng' => 120.5, 'tiktok_url' => null]);
        $from = fn () => $this->actingAs($admin, 'admin')->from(route('admin.settings.contact.edit'));

        $from()->put(route('admin.settings.contact.update'), [
            '_section' => 'business', 'phone' => '09179998888', 'email' => 'new@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpnew',
            'tiktok_url' => 'https://www.tiktok.com/@lpnew',
        ])->assertRedirect(route('admin.settings.contact.edit'))->assertSessionHas('success', 'Contact settings saved.')->assertSessionHasNoErrors();
        $row = BusinessSetting::first();
        $this->assertSame('09179998888', $row->phone);
        $this->assertSame('https://www.tiktok.com/@lpnew', $row->tiktok_url);
        $this->assertSame('Keep Me Street', $row->shop_address);                      // the shop card was not posted, so it is untouched
        $this->assertSame('Keep landmark', $row->shop_landmarks);
        $this->assertEquals(10.5, (float) $row->map_lat);

        $from()->put(route('admin.settings.contact.update'), ['_section' => 'shop', 'shop_address' => 'New Street', 'shop_landmarks' => '', 'map_lat' => '', 'map_lng' => ''])
            ->assertSessionHasNoErrors();
        $row = BusinessSetting::first();
        $this->assertSame('New Street', $row->shop_address);
        $this->assertNull($row->shop_landmarks);
        $this->assertNull($row->map_lat);
        $this->assertSame('09179998888', $row->phone);                                // the business card is untouched
        $this->assertSame('https://www.tiktok.com/@lpnew', $row->tiktok_url);
        $this->assertNotNull($row->updated_by);
    }

    public function test_a_rejected_card_reopens_its_own_modal_with_the_typed_values(): void
    {
        $admin = $this->admin();
        $this->settings();

        $this->actingAs($admin, 'admin')->from(route('admin.settings.contact.edit'))->put(route('admin.settings.contact.update'), [
            '_section' => 'business', 'phone' => 'not a phone', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture',
        ])->assertSessionHasErrors('phone');
        $this->assertSame('09171234567', BusinessSetting::first()->phone);

        $html = $this->get(route('admin.settings.contact.edit'))->getContent();
        $this->assertMatchesRegularExpression('/id="contact-biz"[^>]*data-open-on-load/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="contact-shop"[^>]*data-open-on-load/', $html);
        $this->assertStringContainsString('value="not a phone"', $html);
        $this->assertStringContainsString('is-invalid', $html);

        $this->from(route('admin.settings.contact.edit'))->put(route('admin.settings.contact.update'), ['_section' => 'shop', 'map_lat' => '95', 'map_lng' => '121'])
            ->assertSessionHasErrors('map_lat');
        $html = $this->get(route('admin.settings.contact.edit'))->getContent();
        $this->assertMatchesRegularExpression('/id="contact-shop"[^>]*data-open-on-load/', $html);
        $this->assertStringContainsString('Latitude must be between -90 and 90.', $html);
        $this->assertSame('09171234567', BusinessSetting::first()->phone);
    }

    public function test_a_post_without_a_section_still_saves_everything_as_before(): void
    {
        $this->settings();

        $this->actingAs($this->admin(), 'admin')->put(route('admin.settings.contact.update'), [
            'phone' => '09170001111', 'email' => 'all@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpall', 'shop_address' => 'All Street',
        ])->assertSessionHasNoErrors();
        $row = BusinessSetting::first();
        $this->assertSame('09170001111', $row->phone);
        $this->assertSame('All Street', $row->shop_address);
    }

    public function test_saved_contact_details_reach_the_footer_and_chatbot_replies(): void
    {
        $admin = $this->admin();
        $this->settings();
        $this->intent(['keywords' => 'phone number', 'response' => 'Call {{phone}}.', 'category' => 'Contact']);

        $this->actingAs($admin, 'admin')->put(route('admin.settings.contact.update'), ['_section' => 'business', 'phone' => '09175550188', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/lpfurniture', 'tiktok_url' => 'https://www.tiktok.com/@lpwalk'])
            ->assertSessionHasNoErrors();
        auth('admin')->logout();

        $home = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('tiktok.com/@lpwalk', $home);
        $this->assertTrue(str_contains($home, '0917 555 0188') || str_contains($home, '09175550188') || str_contains($home, '0917-555-0188'));
    }

    /* ───────────── System Health ───────────── */

    public function test_system_health_is_a_full_width_layout_with_the_real_numbers(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get(route('admin.system-health.index'))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'class="admin-stat-card'));
        foreach (['Disk Used by Uploads (', 'Orphan Files (', 'Last Storage Cleanup', 'Uploads', 'Maintenance', 'About Orphan Files', 'Clear Cache',
                  'An orphan is an uploaded file with no database row that is more than 24 hours old.', 'Everyone stays signed in.'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('class="sh-grid"', $html);
        $this->assertStringNotContainsString('max-width:820px', $html);              // no longer a narrow column
        $this->assertStringContainsString('id="clear-cache"', $html);                // the confirmation is a modal
        $this->assertStringContainsString('Clear the Cache?', $html);
        $this->assertStringContainsString('action="' . route('admin.system-health.clear-cache') . '"', $html);
    }

    /* ───────────── shared modal, customer side untouched ───────────── */

    public function test_the_shared_modal_renders_exactly_as_before_without_a_size(): void
    {
        $html = Blade::render('<x-modal id="m1" title="Hello">Body</x-modal>');

        $this->assertStringContainsString('<div class="lp-modal" id="m1" hidden >', $html);
        $this->assertStringNotContainsString('lp-modal--', $html);
        $this->assertStringNotContainsString('data-guard', $html);

        $wide = Blade::render('<x-modal id="m2" title="Hi" size="wide" :data-guard="true">Body</x-modal>');
        $this->assertStringContainsString('class="lp-modal lp-modal--wide"', $wide);
        $this->assertStringContainsString('data-guard', $wide);
    }

    public function test_customer_pages_do_not_load_the_admin_modal_assets(): void
    {
        foreach (['/', '/terms', '/catalog', '/login'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('admin-modals.css', $html, $url);
            $this->assertStringNotContainsString('admin-modal-forms.js', $html, $url);
        }
    }

    public function test_the_admin_modal_stylesheet_is_scoped_to_the_admin_body_or_its_own_classes(): void
    {
        $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents(public_path('assets/css/admin-modals.css')));
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $m);
        foreach ($m[1] as $list) {
            foreach (explode(',', $list) as $sel) {
                $sel = trim(preg_replace('/\s+/', ' ', $sel));
                if ($sel === '' || str_starts_with($sel, '@media')) {
                    continue;
                }
                $this->assertMatchesRegularExpression('/^(body\.admin-type |\.am-|\.cb-|\.ct-|\.sh-|\.ua-|\.page-lead)/', $sel, 'unscoped selector: ' . $sel);
            }
        }
    }
}
