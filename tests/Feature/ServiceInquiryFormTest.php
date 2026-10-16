<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\ServiceInquiryDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 21 part A: the public "Other Services" request form.
 */
class ServiceInquiryFormTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('service-inquiry:127.0.0.1');

        // Stage 21 follow-up: the form needs an account, so every test is a logged-in customer unless it calls guest().
        $this->customer = $this->makeUser();
        $this->actingAs($this->customer);
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'service_type'          => 'labor_only_own_wood',
            'furniture_type'        => 'Table',
            'concern'               => 'I have narra planks and want a dining table built.',
            'name'                  => 'rence samontanez',
            'phone'                 => '09171234567',
            'email'                 => 'rence@example.com',
            'preferred_call_window' => 'morning_8_12',
        ], $over);
    }

    private function nextWeekday(): string
    {
        $d = ServiceInquiryDesk::today()->addDay();
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d->toDateString();
    }

    private function saturday(): string
    {
        return ServiceInquiryDesk::today()->addDays(2)->next('Saturday')->toDateString();
    }

    /* ───────────── the page ───────────── */

    public function test_the_placeholder_is_gone_and_the_real_form_loads(): void
    {
        $this->get('/services/request')->assertOk()
            ->assertSee('Other services request')
            ->assertSee('Monday to Friday, 8:00 AM to 5:00 PM')
            ->assertSee('Terms and Conditions')
            ->assertDontSee('Coming soon');

        $this->assertFalse(view()->exists('services.coming-soon'));
        $this->assertSame('services.request', Route::getRoutes()->match(\Illuminate\Http\Request::create('/services/request'))->getName());
    }

    public function test_the_form_offers_no_evening_slot(): void
    {
        $this->get('/services/request')->assertOk()
            ->assertDontSee('Evening')
            ->assertSee('Morning')->assertSee('Afternoon');

        $this->post('/services/request', $this->payload(['preferred_call_window' => 'evening_5_9']))
            ->assertSessionHasErrors('preferred_call_window');
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_the_card_shows_the_shop_contact_or_the_website_fallback(): void
    {
        BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'hello@lpfurniture.test']);
        $this->get('/services/request')->assertSee('0917-222-3333')->assertSee('hello@lpfurniture.test');

        BusinessSetting::query()->first()->update(['phone' => '', 'email' => '']);
        \Illuminate\Support\Facades\Cache::forget(BusinessSetting::CACHE_KEY);
        $this->get('/services/request')->assertSee('through our website');
    }

    public function test_a_logged_in_customer_gets_saved_contact_prefilled(): void
    {
        $u = $this->makeUser();
        $u->forceFill([
            'first_name' => 'Rence', 'last_name' => 'Samontanez',
            'saved_contact_phone' => '09170001111', 'saved_contact_email' => 'saved@example.com',
        ])->save();

        $this->actingAs($u)->get('/services/request')->assertOk()
            ->assertSee('value="Rence Samontanez"', false)
            ->assertSee('value="09170001111"', false)
            ->assertSee('value="saved@example.com"', false);
    }

    /* ───────────── validation ───────────── */

    public function test_required_fields_are_validated(): void
    {
        $this->post('/services/request', [])->assertSessionHasErrors(['service_type', 'concern', 'name', 'phone', 'email']);
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_phone_must_be_a_philippine_mobile_and_is_stored_normalised(): void
    {
        $this->post('/services/request', $this->payload(['phone' => '12345']))->assertSessionHasErrors('phone');
        $this->post('/services/request', $this->payload(['phone' => '+63 917 123 4567']))->assertSessionHasNoErrors();

        $this->assertSame('09171234567', ServiceInquiry::sole()->phone);
    }

    public function test_service_and_furniture_type_must_come_from_the_lists(): void
    {
        $this->post('/services/request', $this->payload(['service_type' => 'free_stuff']))->assertSessionHasErrors('service_type');
        $this->post('/services/request', $this->payload(['furniture_type' => 'Spaceship']))->assertSessionHasErrors('furniture_type');
        $this->post('/services/request', $this->payload(['concern' => 'short']))->assertSessionHasErrors('concern');
        $this->post('/services/request', $this->payload(['email' => 'not-an-email']))->assertSessionHasErrors('email');
        $this->assertSame(0, ServiceInquiry::count());
    }

    public function test_preferred_date_must_be_a_future_weekday_within_the_horizon(): void
    {
        $this->post('/services/request', $this->payload(['preferred_call_date' => $this->saturday()]))->assertSessionHasErrors('preferred_call_date');
        $this->post('/services/request', $this->payload(['preferred_call_date' => '2020-01-06']))->assertSessionHasErrors('preferred_call_date');
        $this->post('/services/request', $this->payload(['preferred_call_date' => ServiceInquiryDesk::today()->addDays(90)->toDateString()]))->assertSessionHasErrors('preferred_call_date');

        $this->post('/services/request', $this->payload(['preferred_call_date' => $this->nextWeekday()]))->assertSessionHasNoErrors();
        $this->assertSame($this->nextWeekday(), ServiceInquiry::sole()->preferred_call_date->toDateString());
    }

    /* ───────────── submit ───────────── */

    /* ───────────── account required ───────────── */

    public function test_a_guest_is_sent_to_login_and_nothing_is_saved(): void
    {
        $this->makeUser('admin');
        $this->guest();

        $this->get('/services/request')->assertRedirect(route('login'));
        $this->post('/services/request', $this->payload())->assertRedirect(route('login'));
        $this->get(route('services.request.sent'))->assertRedirect(route('login'));

        $this->assertSame(0, ServiceInquiry::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_the_login_page_explains_why_after_the_redirect(): void
    {
        $this->guest()->get('/services/request');

        $this->get(route('login'))->assertOk()
            ->assertSee('Please log in or create an account to send a service request so we can contact you')
            ->assertDontSee('Please log in to continue.');
    }

    public function test_after_login_the_customer_returns_to_the_request_form(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'score' => 0.9])]);
        $u = $this->makeUser();
        $u->forceFill(['email' => 'return@example.com', 'password' => \Illuminate\Support\Facades\Hash::make('Passw0rd!x')])->save();
        $this->guest()->get('/services/request')->assertRedirect(route('login'));

        $this->post(route('login.post'), [
            'email' => 'return@example.com', 'password' => 'Passw0rd!x', 'g-recaptcha-response' => 'token',
        ])->assertRedirect(url('/services/request'));

        $this->get('/services/request')->assertOk()->assertSee('Other services request');
    }

    public function test_after_registration_the_customer_returns_to_the_request_form(): void
    {
        $this->guest()->get('/services/request')->assertRedirect(route('login'));

        $this->post(route('register.post'), [
            'first_name' => 'New', 'last_name' => 'Customer', 'email' => 'newbie@gmail.com', 'phone_number' => '09171234567',
            'password' => 'Passw0rd!x', 'password_confirmation' => 'Passw0rd!x',
        ])->assertRedirect(url('/services/request'));

        $this->assertAuthenticated();
    }

    public function test_a_logged_in_customer_can_submit_and_sees_the_reference(): void
    {
        $this->post('/services/request', $this->payload())->assertRedirect(route('services.request.sent'));

        $t = ServiceInquiry::sole();
        $this->assertSame($this->customer->id, $t->user_id);
        $this->assertSame('new', $t->status);
        $this->assertSame('Rence Samontanez', $t->name);
        $this->assertMatchesRegularExpression('/^SI-\d{4}-0001$/', $t->reference_number);

        $this->get(route('services.request.sent'))->assertOk()
            ->assertSee($t->reference_number)->assertSee('We will call you');
    }

    public function test_the_success_page_without_a_submission_goes_back_to_the_form(): void
    {
        $this->get(route('services.request.sent'))->assertRedirect(route('services.request'));
    }

    public function test_a_logged_in_customer_is_linked_notified_and_sees_my_requests(): void
    {
        $admin = $this->makeUser('admin');
        $u     = $this->makeUser();

        $this->actingAs($u)->post('/services/request', $this->payload())->assertRedirect(route('services.request.sent'));

        $t = ServiceInquiry::sole();
        $this->assertSame($u->id, $t->user_id);

        $n = Notification::where('user_id', $u->id)->sole();
        $this->assertSame('Service Request Received', $n->title);
        $this->assertStringContainsString($t->reference_number, $n->message);
        $this->assertStringContainsString('09171234567', $n->message);
        $this->assertStringContainsString('Morning', $n->message);

        $a = Notification::where('user_id', $admin->id)->sole();
        $this->assertSame('New Service Request', $a->title);
        $this->assertStringContainsString($t->reference_number, $a->message);
        $this->assertStringContainsString('/admin/service-inquiries/' . $t->id, (string) $a->url);

        $this->get('/services/request')->assertSee('My requests')->assertSee($t->reference_number);

        // another customer never sees it
        $other = $this->makeUser();
        $this->actingAs($other)->get('/services/request')->assertDontSee($t->reference_number);
    }

    public function test_notification_wording_has_no_emojis_or_exclamation_marks(): void
    {
        $this->makeUser('admin');
        $u = $this->makeUser();
        $this->actingAs($u)->post('/services/request', $this->payload());

        foreach (Notification::all() as $n) {
            $this->assertStringNotContainsString('!', $n->title . $n->message);
            $this->assertDoesNotMatchRegularExpression('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $n->title . $n->message);
        }
    }

    /* ───────────── spam guards ───────────── */

    public function test_the_honeypot_saves_nothing(): void
    {
        $this->makeUser('admin');
        $this->post('/services/request', $this->payload(['website' => 'http://spam.example']))->assertRedirect(route('home'));

        $this->assertSame(0, ServiceInquiry::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_the_sixth_request_from_one_ip_in_an_hour_is_refused(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/services/request', $this->payload())->assertRedirect(route('services.request.sent'));
        }

        $this->post('/services/request', $this->payload())->assertSessionHasErrors('rate');
        $this->assertSame(5, ServiceInquiry::count());

        // a different address is unaffected
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])
            ->post('/services/request', $this->payload())->assertRedirect(route('services.request.sent'));
        $this->assertSame(6, ServiceInquiry::count());
    }

    public function test_invalid_submissions_do_not_use_up_the_rate_limit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->post('/services/request', $this->payload(['phone' => '123']));
        }
        $this->post('/services/request', $this->payload())->assertRedirect(route('services.request.sent'));
    }

    /* ───────────── reference numbers ───────────── */

    public function test_reference_numbers_are_sequential_unique_and_per_year(): void
    {
        $desk = app(ServiceInquiryDesk::class);
        $data = $this->payload();

        $refs = [];
        for ($i = 0; $i < 4; $i++) {
            $refs[] = $desk->create($data)->reference_number;
        }

        $year = ServiceInquiryDesk::today()->format('Y');
        $this->assertSame(["SI-{$year}-0001", "SI-{$year}-0002", "SI-{$year}-0003", "SI-{$year}-0004"], $refs);
        $this->assertCount(4, array_unique($refs));
    }

    public function test_a_taken_reference_is_skipped_not_reused(): void
    {
        $year = ServiceInquiryDesk::today()->format('Y');
        ServiceInquiry::create([
            'reference_number' => "SI-{$year}-0007", 'service_type' => 'repair', 'concern' => 'A legacy row',
            'name' => 'Old', 'phone' => '09170000000', 'status' => 'new',
        ]);

        $this->assertSame("SI-{$year}-0008", app(ServiceInquiryDesk::class)->create($this->payload())->reference_number);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ServiceInquiry::create([
            'reference_number' => "SI-{$year}-0007", 'service_type' => 'repair', 'concern' => 'Duplicate',
            'name' => 'Dup', 'phone' => '09170000000', 'status' => 'new',
        ]);
    }
}
