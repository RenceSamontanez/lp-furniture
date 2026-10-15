<?php

namespace Tests\Feature;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\InstallmentSchedule;
use App\Models\Notification;
use App\Models\User;
use App\Support\InstallmentScheduler;
use App\Support\QuoteTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 5: quote drafts, versions, send, expiry.
 */
class CustomQuotesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const SECRET_NOTE = 'INTERNAL-ONLY-haggle-down-to-18k';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => 'Rhea'])->save();

        return $a;
    }

    private function customer(): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => 'Ana', 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    /** A request that has had an answered call (status contacting). */
    private function contacting(?User $owner = null, array $overrides = []): CustomDesignRequest
    {
        $owner ??= $this->customer();
        $r = CustomDesignRequest::create(array_merge([
            'user_id'               => $owner->id,
            'title'                 => 'Dining Table — Ana',
            'furniture_type'        => 'Dining Table',
            'description'           => 'A six-seater narra table',
            'preferred_material'    => 'Narra',
            'status'                => 'approved',
            'pipeline_status'       => 'contacting',
            'contact_phone'         => '09171234567',
            'contact_email'         => 'ana@example.com',
            'preferred_call_window' => 'anytime',
            'request_mode'          => 'exact',
            'submitted_at'          => now(),
        ], $overrides));

        $admin = User::where('role', 'admin')->first() ?? $this->admin();
        CustomOrderCallLog::create([
            'request_id' => $r->id, 'admin_id' => $admin->id, 'attempt_no' => 1,
            'called_at' => now(), 'answered' => true,
        ]);

        return $r;
    }

    private function terms(array $o = []): array
    {
        return array_merge([
            'final_description' => 'Six-seater narra dining table, natural stain',
            'width_cm'          => 90,
            'depth_cm'          => 180,
            'height_cm'         => 75,
            'material'          => 'Narra',
            'finish'            => 'Natural stain',
            'modifications'     => '20 cm longer than the design',
            'price'             => '20000',
            'delivery_option'   => 'pickup',
            'production_days'   => 30,
            'payment_terms'     => 'full',
            'call_notes'        => self::SECRET_NOTE,
        ], $o);
    }

    private function dNew(User $admin, CustomDesignRequest $r, array $terms = [], ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')->withSession(['admin_last_activity' => now()])
            ->from(route('admin.custom-orders.quotes.create', $r->id))
            ->post(route('admin.custom-orders.quotes.store', $r->id), array_merge(
                $this->terms($terms),
                ['lock_version' => $version ?? (int) $r->fresh()->lock_version]
            ));
    }

    private function dEdit(User $admin, CustomDesignRequest $r, CustomQuote $q, array $terms = [], ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')->withSession(['admin_last_activity' => now()])
            ->from(route('admin.custom-orders.quotes.edit', [$r->id, $q->id]))
            ->post(route('admin.custom-orders.quotes.update', [$r->id, $q->id]), array_merge(
                $this->terms($terms),
                ['lock_version' => $version ?? (int) $r->fresh()->lock_version]
            ));
    }

    private function dSend(User $admin, CustomDesignRequest $r, CustomQuote $q, ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')->withSession(['admin_last_activity' => now()])
            ->from(route('admin.custom-orders.quotes.confirm', [$r->id, $q->id]))
            ->post(route('admin.custom-orders.quotes.send', [$r->id, $q->id]), [
                'lock_version' => $version ?? (int) $r->fresh()->lock_version,
            ]);
    }

    private function dDel(User $admin, CustomDesignRequest $r, CustomQuote $q, ?int $version = null)
    {
        return $this->actingAs($admin, 'admin')->withSession(['admin_last_activity' => now()])
            ->post(route('admin.custom-orders.quotes.delete', [$r->id, $q->id]), [
                'lock_version' => $version ?? (int) $r->fresh()->lock_version,
            ]);
    }

    /** Draft saved + sent, the quickest way to a quoted request. */
    private function sentQuote(User $admin, CustomDesignRequest $r, array $terms = []): CustomQuote
    {
        $this->dNew($admin, $r, $terms)->assertSessionHasNoErrors();
        $q = CustomQuote::where('request_id', $r->id)->where('status', 'draft')->firstOrFail();
        $this->dSend($admin, $r, $q)->assertSessionHasNoErrors();

        return $q->fresh();
    }

    /* ───────────── RBAC ───────────── */

    public static function accessMatrix(): array
    {
        $routes = [
            'preview' => ['get',  'admin.custom-orders.quotes.preview', false],
            'create'  => ['get',  'admin.custom-orders.quotes.create',  false],
            'store'   => ['post', 'admin.custom-orders.quotes.store',   false],
            'edit'    => ['get',  'admin.custom-orders.quotes.edit',    true],
            'update'  => ['post', 'admin.custom-orders.quotes.update',  true],
            'delete'  => ['post', 'admin.custom-orders.quotes.delete',  true],
            'confirm' => ['get',  'admin.custom-orders.quotes.confirm', true],
            'send'    => ['post', 'admin.custom-orders.quotes.send',    true],
        ];
        $cases = [];
        foreach (['guest', 'customer_web', 'customer_on_admin_guard'] as $who) {
            foreach ($routes as $name => [$method, $route, $needsQuote]) {
                $cases["$who → $name"] = [$who, $method, $route, $needsQuote];
            }
        }

        return $cases;
    }

    /** @dataProvider accessMatrix */
    public function test_non_admins_are_redirected_to_admin_login(string $who, string $method, string $route, bool $needsQuote): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r)->assertSessionHasNoErrors();
        $quote = CustomQuote::where('request_id', $r->id)->firstOrFail();
        $r = $r->fresh();
        $quoteCount = CustomQuote::count();
        $events = CustomOrderEvent::count();
        $before = $r->toArray();
        $quoteBefore = $quote->fresh()->toArray();

        $visitor = $this->customer();
        if ($who === 'customer_web') {
            $this->actingAs($visitor);
        } elseif ($who === 'customer_on_admin_guard') {
            $this->actingAs($visitor, 'admin');
        } else {
            $this->app['auth']->forgetGuards();
        }

        $url = $needsQuote ? route($route, [$r->id, $quote->id]) : route($route, $r->id);
        $resp = $method === 'get' ? $this->get($url) : $this->post($url, $this->terms(['lock_version' => $r->lock_version]));

        $resp->assertRedirect(route('admin.login'));
        $this->assertSame($before, $r->fresh()->toArray());
        $this->assertSame($quoteBefore, $quote->fresh()->toArray());
        $this->assertSame($quoteCount, CustomQuote::count());
        $this->assertSame($events, CustomOrderEvent::count());
        $this->assertSame(0, Notification::count());
    }

    public function test_admin_reaches_every_quote_page(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))->assertOk();
        $this->dNew($admin, $r);
        $q = CustomQuote::firstOrFail();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.edit', [$r->id, $q->id]))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.confirm', [$r->id, $q->id]))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.preview', $r->id))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))->assertOk();
    }

    public function test_quote_belonging_to_another_request_is_404(): void
    {
        $admin = $this->admin();
        $a = $this->contacting();
        $b = $this->contacting();
        $this->dNew($admin, $a);
        $qa = CustomQuote::where('request_id', $a->id)->firstOrFail();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.edit', [$b->id, $qa->id]))->assertNotFound();
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.confirm', [$b->id, $qa->id]))->assertNotFound();
        $this->dEdit($admin, $b, $qa)->assertSessionHasErrors('pipeline');
        $this->assertSame('draft', $qa->fresh()->status);
    }

    /* ───────────── who may create a quote ───────────── */

    public function test_create_is_disabled_without_an_answered_call(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        CustomOrderCallLog::where('request_id', $r->id)->delete();
        CustomOrderCallLog::create(['request_id' => $r->id, 'admin_id' => $admin->id, 'attempt_no' => 1, 'called_at' => now(), 'answered' => false]);

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))
            ->assertOk()
            ->assertSee('Log at least one answered call before creating a quote.')
            ->assertDontSee(route('admin.custom-orders.quotes.create', $r->id), false);

        $this->dNew($admin, $r)->assertSessionHasErrors('pipeline');
        $this->assertSame(0, CustomQuote::count());
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))
            ->assertRedirect(route('admin.custom-orders.show', $r->id));
    }

    public function test_create_is_enabled_with_an_answered_call(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))
            ->assertSee(route('admin.custom-orders.quotes.create', $r->id), false);
    }

    /** @dataProvider nonQuotableStatuses */
    public function test_no_quote_from_other_statuses(string $status): void
    {
        $admin = $this->admin();
        $r = $this->contacting(null, ['pipeline_status' => $status]);

        $this->dNew($admin, $r)->assertSessionHasErrors('pipeline');
        $this->assertSame(0, CustomQuote::count());
        $this->assertSame(0, CustomOrderEvent::count());
    }

    public static function nonQuotableStatuses(): array
    {
        $s = ['submitted', 'unreachable', 'accepted', 'ordered', 'declined_by_admin', 'cancelled'];

        return array_combine($s, array_map(fn ($x) => [$x], $s));
    }

    public function test_cannot_send_from_non_quotable_status(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r);
        $q = CustomQuote::firstOrFail();
        $r->fresh()->forceFill(['pipeline_status' => 'accepted'])->save();

        $this->dSend($admin, $r, $q)->assertSessionHasErrors('pipeline');
        $this->assertSame('draft', $q->fresh()->status);
        $this->assertSame(0, Notification::count());
    }

    /* ───────────── draft lifecycle ───────────── */

    public function test_saving_a_draft_changes_nothing_for_the_customer(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r)->assertSessionHasNoErrors();

        $q = CustomQuote::sole();
        $this->assertSame('draft', $q->status);
        $this->assertSame(1, $q->version);
        $this->assertSame($admin->id, $q->created_by);
        $this->assertSame('20000.00', $q->price);
        $this->assertSame('0.00', $q->delivery_fee);
        $this->assertNull($q->sent_at);
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
        $this->assertSame(0, Notification::count());

        $e = CustomOrderEvent::where('type', 'quote_draft_created')->sole();
        $this->assertSame($admin->id, $e->actor_id);
        $this->assertSame(1, $e->data['version']);
    }

    public function test_draft_can_be_edited_and_deleted_freely(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r);
        $q = CustomQuote::sole();

        $this->dEdit($admin, $r, $q, ['price' => '22500.50', 'material' => 'Mahogany'])->assertSessionHasNoErrors();
        $this->dEdit($admin, $r, $q, ['price' => '23000'])->assertSessionHasNoErrors();
        $q->refresh();
        $this->assertSame('23000.00', $q->price);
        $this->assertSame(2, CustomOrderEvent::where('type', 'quote_draft_updated')->count());

        $this->dDel($admin, $r, $q)->assertSessionHasNoErrors();
        $this->assertSame(0, CustomQuote::count());
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_draft_deleted')->count());

        // and a fresh draft can be started again, as version 1
        $this->dNew($admin, $r)->assertSessionHasNoErrors();
        $this->assertSame(1, CustomQuote::sole()->version);
    }

    public function test_only_one_draft_per_request(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r);

        $this->dNew($admin, $r, ['price' => '99'])->assertSessionHasErrors('pipeline');

        $this->assertSame(1, CustomQuote::where('request_id', $r->id)->count());
        $this->assertSame('20000.00', CustomQuote::sole()->price);

        // the create page sends the admin to the existing draft instead
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.create', $r->id))
            ->assertRedirect(route('admin.custom-orders.quotes.edit', [$r->id, CustomQuote::sole()->id]));
    }

    public function test_one_open_quote_rule_holds_across_versions(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $v1 = $this->sentQuote($admin, $r);

        // revision draft may coexist with the sent quote, never two drafts
        $this->dNew($admin, $r, ['price' => '21000'])->assertSessionHasNoErrors();
        $this->dNew($admin, $r, ['price' => '22000'])->assertSessionHasErrors('pipeline');
        $this->assertSame(1, CustomQuote::where('request_id', $r->id)->where('status', 'draft')->count());
        $this->assertSame(1, CustomQuote::where('request_id', $r->id)->where('status', 'sent')->count());

        $v2 = CustomQuote::where('status', 'draft')->sole();
        $this->dSend($admin, $r, $v2)->assertSessionHasNoErrors();

        $this->assertSame(1, CustomQuote::where('request_id', $r->id)->where('status', 'sent')->count());
        $this->assertSame(0, CustomQuote::where('request_id', $r->id)->where('status', 'draft')->count());
        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame('sent', $v2->fresh()->status);
    }

    public function test_sent_quote_cannot_be_edited_or_deleted(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $q = $this->sentQuote($admin, $r);

        $this->dEdit($admin, $r, $q, ['price' => '1'])->assertSessionHasErrors('pipeline');
        $this->dDel($admin, $r, $q)->assertSessionHasErrors('pipeline');
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.edit', [$r->id, $q->id]))
            ->assertRedirect(route('admin.custom-orders.show', $r->id));

        $this->assertSame('20000.00', $q->fresh()->price);
        $this->assertSame('sent', $q->fresh()->status);
    }

    public function test_stale_lock_version_is_rejected_everywhere(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $stale = (int) $r->fresh()->lock_version;

        $this->dNew($admin, $r); // moves the version on
        $q = CustomQuote::sole();

        $this->dNew($admin, $r, [], $stale)->assertSessionHasErrors('pipeline');
        $this->dEdit($admin, $r, $q, ['price' => '1'], $stale)->assertSessionHasErrors('pipeline');
        $this->dSend($admin, $r, $q, $stale)->assertSessionHasErrors('pipeline');
        $this->dDel($admin, $r, $q, $stale)->assertSessionHasErrors('pipeline');

        $this->assertStringContainsString('changed by someone else', session('errors')->first('pipeline'));
        $q->refresh();
        $this->assertSame('draft', $q->status);
        $this->assertSame('20000.00', $q->price);
        $this->assertSame(0, Notification::count());
    }

    /* ───────────── validation edges ───────────── */

    public function test_price_edges(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        foreach (['0', '-5', '0.00', '1000000', '999999.01', 'abc', '', '10.505'] as $bad) {
            $this->dNew($admin, $r, ['price' => $bad])->assertSessionHasErrors('price');
        }
        $this->assertSame(0, CustomQuote::count());

        $this->dNew($admin, $r, ['price' => '999999.00'])->assertSessionHasNoErrors();
        $this->assertSame('999999.00', CustomQuote::sole()->price);
    }

    public function test_minimum_price_and_size_and_days_edges(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['production_days' => 0])->assertSessionHasErrors('production_days');
        $this->dNew($admin, $r, ['production_days' => 1.5])->assertSessionHasErrors('production_days');
        $this->dNew($admin, $r, ['width_cm' => 0])->assertSessionHasErrors('width_cm');
        $this->dNew($admin, $r, ['height_cm' => ''])->assertSessionHasErrors('height_cm');
        $this->dNew($admin, $r, ['material' => ''])->assertSessionHasErrors('material');
        $this->dNew($admin, $r, ['final_description' => ''])->assertSessionHasErrors('final_description');
        $this->assertSame(0, CustomQuote::count());

        $this->dNew($admin, $r, ['production_days' => 1, 'price' => '0.01'])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomQuote::sole()->production_days);
    }

    public function test_pickup_forces_fee_zero_whatever_is_posted(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['delivery_option' => 'pickup', 'delivery_fee' => '999', 'delivery_address' => 'Somewhere'])
            ->assertSessionHasNoErrors();

        $q = CustomQuote::sole();
        $this->assertSame('0.00', $q->delivery_fee);
        $this->assertNull($q->delivery_address);
        $this->assertSame(20000.0, $q->totalAmount());
    }

    public function test_delivery_fee_defaults_to_500_and_accepts_an_admin_value(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['delivery_option' => 'delivery', 'delivery_address' => 'Lipa City'])
            ->assertSessionHasNoErrors();
        $q = CustomQuote::sole();
        $this->assertSame('500.00', $q->delivery_fee);
        $this->assertSame(20500.0, $q->totalAmount());

        $this->dEdit($admin, $r, $q, ['delivery_option' => 'delivery', 'delivery_address' => 'Lipa City', 'delivery_fee' => '1250.50'])
            ->assertSessionHasNoErrors();
        $this->assertSame('1250.50', $q->fresh()->delivery_fee);

        $this->dEdit($admin, $r, $q, ['delivery_option' => 'delivery', 'delivery_address' => 'Lipa City', 'delivery_fee' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertSame('0.00', $q->fresh()->delivery_fee);

        $this->dEdit($admin, $r, $q, ['delivery_option' => 'delivery', 'delivery_address' => 'Lipa City', 'delivery_fee' => '-1'])
            ->assertSessionHasErrors('delivery_fee');
    }

    public function test_delivery_needs_an_address(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['delivery_option' => 'delivery', 'delivery_address' => ''])
            ->assertSessionHasErrors('delivery_address');
        $this->dNew($admin, $r, ['delivery_option' => 'courier'])->assertSessionHasErrors('delivery_option');
        $this->assertSame(0, CustomQuote::count());
    }

    public function test_deposit_must_be_at_least_50_percent_and_less_than_total(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $dep = ['payment_terms' => 'deposit', 'installment_duration_months' => 2, 'installment_frequency_days' => 30];

        // total 10000.00: 49.99% refused, exactly 50% accepted, equal and above refused
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '4999'])->assertSessionHasErrors('deposit_amount');
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '4999.99'])->assertSessionHasErrors('deposit_amount');
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '10000'])->assertSessionHasErrors('deposit_amount');
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '10000.01'])->assertSessionHasErrors('deposit_amount');
        $this->assertSame(0, CustomQuote::count());

        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '5000'])->assertSessionHasNoErrors();
        $q = CustomQuote::sole();
        $this->assertSame('5000.00', $q->deposit_amount);

        $this->dEdit($admin, $r, $q, $dep + ['price' => '10000', 'deposit_amount' => '9999.99'])->assertSessionHasNoErrors();
        $this->assertSame('9999.99', $q->fresh()->deposit_amount);
    }

    public function test_deposit_minimum_includes_the_delivery_fee(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $dep = ['payment_terms' => 'deposit', 'installment_duration_months' => 1, 'installment_frequency_days' => 15,
                'delivery_option' => 'delivery', 'delivery_address' => 'Lipa', 'delivery_fee' => '500'];

        // total = 10000 + 500 = 10500, minimum 5250
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '5000'])->assertSessionHasErrors('deposit_amount');
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '5249.99'])->assertSessionHasErrors('deposit_amount');
        $this->dNew($admin, $r, $dep + ['price' => '10000', 'deposit_amount' => '5250'])->assertSessionHasNoErrors();
    }

    public function test_the_minimum_uses_the_named_constant(): void
    {
        $this->assertSame(50, InstallmentScheduler::MIN_DEPOSIT_PERCENT);
        $this->assertSame(5000.0, InstallmentScheduler::minimumDeposit(10000));
        $this->assertSame(5000.0, (new CustomQuote(['price' => 10000, 'delivery_fee' => 0]))->minimumDeposit());
    }

    public function test_deposit_needs_a_valid_installment_plan(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $base = ['payment_terms' => 'deposit', 'deposit_amount' => '10000'];

        $this->dNew($admin, $r, $base)->assertSessionHasErrors(['installment_duration_months', 'installment_frequency_days']);
        $this->dNew($admin, $r, $base + ['installment_duration_months' => 4, 'installment_frequency_days' => 30])
            ->assertSessionHasErrors('installment_duration_months');
        $this->dNew($admin, $r, $base + ['installment_duration_months' => 3, 'installment_frequency_days' => 7])
            ->assertSessionHasErrors('installment_frequency_days');
        $this->dNew($admin, $r, ['payment_terms' => 'barter'])->assertSessionHasErrors('payment_terms');
        $this->assertSame(0, CustomQuote::count());
    }

    public function test_full_payment_ignores_deposit_fields(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['payment_terms' => 'full', 'deposit_amount' => '123', 'installment_duration_months' => 3, 'installment_frequency_days' => 15])
            ->assertSessionHasNoErrors();

        $q = CustomQuote::sole();
        $this->assertNull($q->deposit_amount);
        $this->assertNull($q->installment_duration_months);
        $this->assertSame(20000.0, $q->upfrontAmount());
    }

    public function test_posted_totals_are_ignored(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $this->dNew($admin, $r, ['price' => '1000', 'total' => '1', 'upfront' => '1', 'total_amount' => '1'])
            ->assertSessionHasNoErrors();

        $q = CustomQuote::sole();
        $this->assertSame(1000.0, $q->totalAmount());
        $this->assertSame(1000.0, QuoteTerms::summary($q)['total']);
    }

    /* ───────────── send ───────────── */

    public function test_confirm_page_asks_the_question_with_the_full_terms(): void
    {
        $admin = $this->admin();
        $r = $this->contacting(null, ['contact_phone' => '09181112222']);
        $this->dNew($admin, $r, ['delivery_option' => 'delivery', 'delivery_address' => 'Lipa City', 'delivery_fee' => '750']);
        $q = CustomQuote::sole();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.quotes.confirm', [$r->id, $q->id]))
            ->assertOk()
            ->assertSee('Send ₱20,750.00 quote to Ana Cruz (09181112222)?')
            ->assertSee('Lipa City')
            ->assertSee('₱750.00')
            ->assertSee('30 working days')
            ->assertSee(self::SECRET_NOTE); // internal notes are visible to admins
    }

    public function test_send_does_everything_in_one_go(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:30:00', 'Asia/Manila'));

        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $this->dNew($admin, $r);
        $q = CustomQuote::sole();
        $this->assertSame(0, Notification::count());

        $this->dSend($admin, $r, $q)->assertSessionHasNoErrors();

        $q->refresh();
        $this->assertSame('sent', $q->status);
        $this->assertSame($admin->id, $q->sent_by);
        $this->assertTrue($q->sent_at->equalTo(now()));
        $this->assertTrue($q->expires_at->equalTo(now()->addDays(7)));
        $this->assertSame('2026-11-09 10:30:00', $q->expires_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertSame('quoted', $r->fresh()->pipeline_status);

        $e = CustomOrderEvent::where('type', 'quote_sent')->sole();
        $this->assertSame($admin->id, $e->actor_id);
        $this->assertSame('contacting', $e->data['from']);
        $this->assertSame('quoted', $e->data['to']);

        $n = Notification::where('user_id', $owner->id)->sole();
        $this->assertSame('custom_order', $n->type);
        $this->assertSame(
            'Your quote for Dining Table — Ana is ready: ₱20,000.00. Please review and accept by Nov 09, 2026 10:30 AM.',
            $n->message
        );
        $this->assertStringContainsString('/design/' . $r->id, $n->url);
        $this->assertSame(1, Notification::count());
    }

    public function test_send_needs_an_answered_call_even_with_a_draft(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r);
        CustomOrderCallLog::where('request_id', $r->id)->update(['answered' => false]);

        $this->dSend($admin, $r, CustomQuote::sole())->assertSessionHasErrors('pipeline');
        $this->assertSame('draft', CustomQuote::sole()->status);
        $this->assertSame(0, Notification::count());
    }

    public function test_incomplete_stored_draft_is_not_sent(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->dNew($admin, $r);
        $q = CustomQuote::sole();
        $q->forceFill(['price' => 0])->save(); // corrupted / pre-rule draft

        $this->dSend($admin, $r, $q)->assertSessionHasErrors('pipeline');
        $this->assertSame('draft', $q->fresh()->status);
        $this->assertSame('contacting', $r->fresh()->pipeline_status);
    }

    public function test_customer_sees_a_read_only_summary_without_internal_notes(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $this->sentQuote($admin, $r, [
            'delivery_option' => 'delivery', 'delivery_address' => 'Lipa City', 'delivery_fee' => '500',
            'payment_terms' => 'deposit', 'deposit_amount' => '10250', 'installment_duration_months' => 1, 'installment_frequency_days' => 15,
        ]);

        $html = $this->actingAs($owner)->get(route('design.show', $r->id))
            ->assertOk()
            ->assertSee('Your quote is ready (version 1)')
            ->assertSee('₱20,500.00')
            ->assertSee('₱10,250.00')
            ->assertSee('Review and accept is coming next')
            ->assertDontSee(self::SECRET_NOTE)
            ->assertDontSee('Internal')
            ->assertDontSee('call_notes')
            ->getContent();

        $this->assertStringNotContainsString(self::SECRET_NOTE, $html);
        $this->assertStringNotContainsString('Accept quote', $html);
    }

    public function test_call_notes_never_reach_notifications_or_customer_pages(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $this->sentQuote($admin, $r);
        $this->dNew($admin, $r, ['price' => '21000']);
        $this->dSend($admin, $r, CustomQuote::where('status', 'draft')->sole());

        foreach (Notification::all() as $n) {
            $this->assertStringNotContainsString(self::SECRET_NOTE, $n->title . ' ' . $n->message . ' ' . $n->url);
        }

        foreach ([route('design.show', $r->id), route('design.index'), route('design.gallery'), route('notifications.index')] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()->assertDontSee(self::SECRET_NOTE);
        }
    }

    public function test_a_draft_is_invisible_to_the_customer(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $this->dNew($admin, $r);

        $this->actingAs($owner)->get(route('design.show', $r->id))
            ->assertOk()
            ->assertDontSee('Your quote is ready');
    }

    /* ───────────── versions ───────────── */

    public function test_revision_creates_next_version_and_supersedes_on_send(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $v1 = $this->sentQuote($admin, $r);
        $this->assertSame(1, $v1->version);

        $this->dNew($admin, $r, ['price' => '18500.50'])->assertSessionHasNoErrors();
        $v2 = CustomQuote::where('status', 'draft')->sole();
        $this->assertSame(2, $v2->version);
        $this->assertSame('20000.00', $v2->previous_price);
        // the customer still sees v1 and v1 is still the live sent quote
        $this->assertSame('sent', $v1->fresh()->status);
        $this->assertSame('quoted', $r->fresh()->pipeline_status);

        $this->dSend($admin, $r, $v2)->assertSessionHasNoErrors();

        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame('sent', $v2->fresh()->status);
        $this->assertSame('quoted', $r->fresh()->pipeline_status);
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_superseded')->count());

        $latest = Notification::where('user_id', $owner->id)->latest('id')->first();
        $this->assertSame(
            'Your quote for Dining Table — Ana was updated (version 2, ₱20,000.00 → ₱18,500.50). The previous quote can no longer be accepted.',
            $latest->message
        );

        $this->actingAs($owner)->get(route('design.show', $r->id))
            ->assertSee('version 2')
            ->assertDontSee('version 1)');
    }

    public function test_version_history_shows_prices_and_who_sent(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $this->sentQuote($admin, $r);
        $this->dNew($admin, $r, ['price' => '18000']);
        $this->dSend($admin, $r, CustomQuote::where('status', 'draft')->sole());

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))
            ->assertOk()
            ->assertSee('Quote versions')
            ->assertSee('v1')->assertSee('v2')
            ->assertSee('Superseded')
            ->assertSee('was ₱20,000.00')
            ->assertSee('by Rhea');
    }

    /* ───────────── expiry ───────────── */

    public function test_expiry_command_expires_quote_and_request_and_notifies(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $q = $this->sentQuote($admin, $r);
        $other = $this->admin();
        Notification::query()->delete();

        // not yet
        Artisan::call('custom-orders:expire');
        $this->assertSame('sent', $q->fresh()->status);

        Carbon::setTestNow($q->expires_at->copy()->addMinute());
        $this->assertSame(0, Artisan::call('custom-orders:expire'));

        $this->assertSame('expired', $q->fresh()->status);
        $this->assertSame('expired', $r->fresh()->pipeline_status);

        $e = CustomOrderEvent::where('type', 'quote_expired')->sole();
        $this->assertSame('system', $e->actor_type);
        $this->assertSame('quoted', $e->data['from']);

        $mine = Notification::where('user_id', $owner->id)->sole();
        $this->assertStringContainsString("Your quote for Dining Table — Ana expired on", $mine->message);
        $this->assertStringContainsString("Contact us if you'd still like to proceed.", $mine->message);

        foreach ([$admin, $other] as $a) {
            $n = Notification::where('user_id', $a->id)->sole();
            $this->assertSame('Quote for Dining Table — Ana (Ana Cruz) expired without acceptance.', $n->message);
            $this->assertSame('custom_order', $n->type);
        }

        // idempotent
        Artisan::call('custom-orders:expire');
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_expired')->count());
        $this->assertSame(3, Notification::count());
    }

    public function test_lazy_check_expires_on_admin_and_customer_page_loads(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $a = $this->contacting($owner);
        $b = $this->contacting($owner);
        $qa = $this->sentQuote($admin, $a);
        $qb = $this->sentQuote($admin, $b);
        Carbon::setTestNow($qa->expires_at->copy()->addHour());

        $this->actingAs($owner)->get(route('design.show', $a->id))
            ->assertOk()
            ->assertSee('This quote expired on')
            ->assertDontSee('Review and accept is coming next');
        $this->assertSame('expired', $a->fresh()->pipeline_status);
        $this->assertSame('expired', $qa->fresh()->status);

        $this->actingAs($admin, 'admin')->withSession(['admin_last_activity' => now()])->get(route('admin.custom-orders.index'))->assertOk();
        $this->assertSame('expired', $b->fresh()->pipeline_status);
        $this->assertSame('expired', $qb->fresh()->status);
    }

    public function test_expired_request_can_get_a_new_version_sent(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $r = $this->contacting($owner);
        $v1 = $this->sentQuote($admin, $r);
        Carbon::setTestNow($v1->expires_at->copy()->addDay());
        Artisan::call('custom-orders:expire');
        $this->assertSame('expired', $r->fresh()->pipeline_status);

        $this->dNew($admin, $r, ['price' => '19000'])->assertSessionHasNoErrors();
        $v2 = CustomQuote::where('status', 'draft')->sole();
        $this->dSend($admin, $r, $v2)->assertSessionHasNoErrors();

        $this->assertSame('quoted', $r->fresh()->pipeline_status);
        $this->assertSame(2, $v2->fresh()->version);
        $this->assertSame('expired', $v1->fresh()->status); // not rewritten
        $this->assertTrue($v2->fresh()->expires_at->greaterThan(now()));
    }

    public function test_declined_by_customer_request_can_get_a_new_version_sent(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $v1 = $this->sentQuote($admin, $r);
        // Stage 6 will do this transition; set it directly for the test
        $v1->forceFill(['status' => 'declined'])->save();
        $r->fresh()->forceFill(['pipeline_status' => 'declined_by_customer'])->save();

        $this->dNew($admin, $r, ['price' => '17000'])->assertSessionHasNoErrors();
        $v2 = CustomQuote::where('status', 'draft')->sole();
        $this->dSend($admin, $r, $v2)->assertSessionHasNoErrors();

        $this->assertSame('quoted', $r->fresh()->pipeline_status);
        $this->assertSame('declined', $v1->fresh()->status);
        $this->assertSame('sent', $v2->fresh()->status);
    }

    public function test_expiry_does_not_touch_a_quote_that_is_no_longer_sent(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();
        $v1 = $this->sentQuote($admin, $r);
        $v1->forceFill(['status' => 'accepted'])->save(); // as if Stage 6 had accepted it
        Carbon::setTestNow($v1->expires_at->copy()->addDay());

        Artisan::call('custom-orders:expire');

        $this->assertSame('accepted', $v1->fresh()->status);
        $this->assertSame('quoted', $r->fresh()->pipeline_status);
    }

    /* ───────────── preview matches the scheduler ───────────── */

    public static function plans(): array
    {
        $out = [];
        foreach ([1, 2, 3] as $m) {
            foreach ([15, 30] as $d) {
                $out["{$m}m / {$d}d"] = [$m, $d];
            }
        }

        return $out;
    }

    /** @dataProvider plans */
    public function test_preview_matches_real_schedule_rows(int $months, int $days): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        $terms = [
            'price' => 12345.67, 'delivery_option' => 'delivery', 'delivery_fee' => 500,
            'payment_terms' => 'deposit', 'deposit_amount' => 7000,
            'installment_duration_months' => $months, 'installment_frequency_days' => $days,
        ];

        $summary = QuoteTerms::summary($terms);
        $this->assertSame(12845.67, $summary['total']);
        $this->assertSame(7000.0, $summary['upfront']);
        $this->assertSame(5845.67, $summary['balance']);

        $order = $this->makeOrder($owner, ['total_amount' => $summary['total']]);
        $payment = $this->makePayment($order, ['amount' => $summary['upfront'], 'payment_type' => 'installment']);
        InstallmentScheduler::createScheduleForDeposit($payment, $order, $summary['total'], $summary['upfront'], $months, $days);

        $rows = InstallmentSchedule::where('order_id', $order->id)->orderBy('installment_no')->get();
        $this->assertCount(count($summary['schedule']), $rows);
        $this->assertSame(InstallmentScheduler::paymentCount($months, $days), $rows->count());

        foreach ($rows as $i => $row) {
            $p = $summary['schedule'][$i];
            $this->assertSame($p['no'], (int) $row->installment_no);
            $this->assertEqualsWithDelta($p['amount'], (float) $row->amount_due, 0.001);
            $this->assertSame($order->created_at->copy()->addDays($p['day_offset'])->toDateString(), $row->due_date->toDateString());
        }
        $this->assertEqualsWithDelta($summary['balance'], $rows->sum('amount_due'), 0.001);

        // and the JSON preview the form uses is the same calculation
        $json = $this->actingAs($admin, 'admin')->getJson(route('admin.custom-orders.quotes.preview', $this->contacting($owner)->id) . '?' . http_build_query([
            'price' => 12345.67, 'delivery_option' => 'delivery', 'delivery_fee' => 500, 'payment_terms' => 'deposit',
            'deposit_amount' => 7000, 'installment_duration_months' => $months, 'installment_frequency_days' => $days,
        ]))->assertOk()->json();

        $this->assertEqualsWithDelta(12845.67, $json['total'], 0.001);
        $this->assertNull($json['deposit_problem']);
        $this->assertCount($rows->count(), $json['schedule']);
        foreach ($json['schedule'] as $i => $p) {
            $this->assertEqualsWithDelta((float) $rows[$i]->amount_due, $p['amount'], 0.001);
        }
    }

    public function test_preview_flags_a_bad_deposit(): void
    {
        $admin = $this->admin();
        $r = $this->contacting();

        $json = $this->actingAs($admin, 'admin')->getJson(route('admin.custom-orders.quotes.preview', $r->id) . '?' . http_build_query([
            'price' => 10000, 'delivery_option' => 'pickup', 'payment_terms' => 'deposit', 'deposit_amount' => 4999,
            'installment_duration_months' => 1, 'installment_frequency_days' => 30,
        ]))->assertOk()->json();

        $this->assertStringContainsString('at least 50%', $json['deposit_problem']);
    }

    /* ───────────── command registration ───────────── */

    public function test_expire_command_is_registered(): void
    {
        $this->assertArrayHasKey('custom-orders:expire', Artisan::all());
    }
}
