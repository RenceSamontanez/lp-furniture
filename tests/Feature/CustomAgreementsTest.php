<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\AgreementPolicy;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Notification;
use App\Models\User;
use App\Support\WorkingDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 6: Review and Accept, the immutable agreement record, the
 * agreement/print/image pages, and the admin notifications page.
 */
class CustomAgreementsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const IP = '203.0.113.9';
    private const UA = 'AgreementTest/1.0 (Windows NT 10.0)';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ───────────── fixtures ───────────── */

    private function admin(string $first = 'Rhea'): User
    {
        $a = $this->makeUser('admin');
        $a->forceFill(['first_name' => $first, 'last_name' => 'Santos'])->save();

        return $a;
    }

    private function customer(string $first = 'Ana'): User
    {
        $u = $this->makeUser();
        $u->forceFill(['first_name' => $first, 'last_name' => 'Cruz', 'phone_number' => '09170000001'])->save();

        return $u;
    }

    /** A quoted request with one sent quote. Returns [request, quote]. */
    private function quoted(User $owner, User $admin, array $quote = [], array $request = []): array
    {
        $r = CustomDesignRequest::create(array_merge([
            'user_id'               => $owner->id,
            'title'                 => 'Dining Table - ' . $owner->first_name,
            'furniture_type'        => 'Dining Table',
            'description'           => 'A six-seater narra table',
            'status'                => 'approved',
            'pipeline_status'       => 'quoted',
            'contact_phone'         => '09171234567',
            'contact_email'         => 'ana@example.com',
            'preferred_call_window' => 'anytime',
            'request_mode'          => 'exact',
            'submitted_at'          => now(),
        ], $request));

        $q = CustomQuote::create(array_merge([
            'request_id'        => $r->id,
            'version'           => 1,
            'status'            => 'sent',
            'final_description' => 'Six-seater dining table in narra',
            'width_cm'          => 180,
            'depth_cm'          => 90,
            'height_cm'         => 75,
            'material'          => 'Narra',
            'finish'            => 'Matte varnish',
            'modifications'     => 'Rounded corners',
            'price'             => 20000,
            'delivery_option'   => 'delivery',
            'delivery_fee'      => 500,
            'delivery_address'  => '12 Mabini St, Batangas City',
            'production_days'   => 20,
            'payment_terms'     => 'deposit',
            'deposit_amount'    => 10250,
            'installment_duration_months' => 2,
            'installment_frequency_days'  => 30,
            'call_notes'        => 'INTERNAL-ONLY-note',
            'created_by'        => $admin->id,
            'sent_by'           => $admin->id,
            'sent_at'           => now()->subHour(),
            'expires_at'        => now()->addDays(5),
        ], $quote));

        return [$r, $q];
    }

    private function accept(User $customer, CustomDesignRequest $r, CustomQuote $q, array $data = ['agree' => '1'])
    {
        return $this->actingAs($customer)
            ->withServerVariables(['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => self::UA])
            ->post(route('design.quote.accept', [$r->id, $q->id]), $data);
    }

    /** A request that has been accepted through the real endpoint. */
    private function accepted(?User $customer = null, ?User $admin = null, array $quote = []): array
    {
        $customer ??= $this->customer();
        $admin ??= $this->admin();
        [$r, $q] = $this->quoted($customer, $admin, $quote);
        $this->accept($customer, $r, $q)->assertRedirect();

        return [$customer, $admin, $r->fresh(), $q->fresh(), DesignAgreement::where('quote_id', $q->id)->firstOrFail()];
    }

    /* ───────────── review page ───────────── */

    public function test_review_page_shows_every_term_policy_and_no_store(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin());

        $res = $this->actingAs($c)->get(route('design.quote.review', [$r->id, $q->id]));

        $res->assertOk();
        $res->assertSee('Six-seater dining table in narra');
        $res->assertSee('180 x 90 x 75 cm');
        $res->assertSee('Narra, Matte varnish');
        $res->assertSee('Rounded corners');
        $res->assertSee('12 Mabini St, Batangas City');
        $res->assertSee('₱20,000.00', false);
        $res->assertSee('₱500.00', false);
        $res->assertSee('₱20,500.00', false);
        $res->assertSee('₱10,250.00', false);
        $res->assertSee('20 working days');
        $res->assertSee('ready about');
        $res->assertSee('Payment 1: ₱5,125.00', false);
        $res->assertSee('Terms and Conditions');
        $res->assertSee('Cancellation and refunds');
        $res->assertSee('[TO CONFIRM', false);
        $res->assertSee('I have read and agree to these terms');
        $res->assertSee('Accept agreement');
        $res->assertDontSee('INTERNAL-ONLY-note');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_ready_date_counts_monday_to_saturday(): void
    {
        // Friday + 2 working days = Monday (Sunday skipped); Saturday counts.
        $this->assertSame('2026-10-19', WorkingDays::add(Carbon::parse('2026-10-16'), 2)->format('Y-m-d'));
        $this->assertSame('2026-10-17', WorkingDays::add(Carbon::parse('2026-10-16'), 1)->format('Y-m-d'));
    }

    public function test_accept_requires_the_checkbox(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin());

        $this->accept($c, $r, $q, [])->assertSessionHasErrors('agree');

        $this->assertSame(0, DesignAgreement::count());
        $this->assertSame('sent', $q->fresh()->status);
    }

    /* ───────────── accept ───────────── */

    public function test_accept_records_every_field(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 14:05:09', 'Asia/Manila'));
        $customer = $this->customer();
        $admin    = $this->admin('Rhea');
        [$r, $q]  = $this->quoted($customer, $admin, ['expires_at' => now()->addDays(5)]);
        Notification::query()->delete();

        $res = $this->accept($customer, $r, $q);

        $a = DesignAgreement::where('quote_id', $q->id)->firstOrFail();
        $res->assertRedirect(route('design.agreement', $a->id));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->assertMatchesRegularExpression('/^LP-A-2026-0001$/', $a->agreement_number);
        $this->assertSame($r->id, $a->request_id);
        $this->assertSame($customer->id, $a->user_id);
        $this->assertSame($admin->id, $a->finalized_by);
        $this->assertSame(1, $a->quote_version);
        $this->assertSame('2026-10-16 14:05:09', $a->accepted_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertMatchesRegularExpression('/^2026-10-16 14:05:09 [A-Z0-9+-]+$/', $a->accepted_at_local);
        $this->assertSame(self::IP, $a->ip_address);
        $this->assertSame(self::UA, $a->user_agent);
        $this->assertSame(2, (int) $a->policy_version);
        $this->assertSame('20500.00', (string) $a->total_amount);
        $this->assertSame('10250.00', (string) $a->upfront_amount);
        $this->assertSame($q->sent_at->timestamp, $a->sent_at->timestamp);

        $s = $a->terms_snapshot;
        $this->assertSame('Custom Furniture — Ana', $s['design']['title']);
        $this->assertSame('Six-seater dining table in narra', $s['quote']['description']);
        $this->assertSame('180.00', $s['quote']['width_cm']);
        $this->assertSame('Narra', $s['quote']['material']);
        $this->assertSame('12 Mabini St, Batangas City', $s['delivery']['address']);
        $this->assertSame('20000.00', $s['money']['price']);
        $this->assertSame('20500.00', $s['money']['total']);
        $this->assertSame('10250.00', $s['money']['balance']);
        $this->assertCount(2, $s['schedule']);
        $this->assertSame(20, $s['production']['days']);
        $this->assertSame(2, $s['policy']['version']);
        $this->assertSame('Terms and Conditions', $s['policy']['title']);
        $this->assertStringContainsString('## Cancellation and refunds', $s['policy']['text']);
        $this->assertSame('Ana Cruz', $s['people']['customer']['name']);
        $this->assertSame('Rhea Santos', $s['people']['finalized_by']['name']);
        $this->assertSame($s['payment_due_at'], $q->expires_at->timezone('Asia/Manila')->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('INTERNAL-ONLY', json_encode($s));

        // the hash recomputes from the stored snapshot
        $this->assertSame($a->snapshot_sha256, DesignAgreement::hashSnapshot($a->fresh()->terms_snapshot));
        $this->assertTrue($a->fresh()->snapshotIsIntact());

        // states
        $this->assertSame('accepted', $q->fresh()->status);
        $fresh = $r->fresh();
        $this->assertSame('accepted', $fresh->pipeline_status);
        $this->assertSame($q->expires_at->timestamp, $fresh->payment_due_at->timestamp);

        // event + notifications
        $event = CustomOrderEvent::where('request_id', $r->id)->where('type', 'quote_accepted')->firstOrFail();
        $this->assertSame('customer', $event->actor_type);
        $this->assertSame($customer->id, (int) $event->actor_id);

        $cn = Notification::where('user_id', $customer->id)->where('type', 'custom_order')->firstOrFail();
        $this->assertStringContainsString($a->agreement_number, $cn->message);
        $this->assertStringContainsString('Oct 16, 2026 02:05 PM', $cn->message);
        $this->assertStringContainsString('₱10,250.00', $cn->message);

        $an = Notification::where('user_id', $admin->id)->where('type', 'custom_order')->firstOrFail();
        $this->assertStringContainsString('Ana Cruz accepted agreement', $an->message);
        $this->assertStringContainsString('Oct 16, 2026 02:05 PM', $an->message);
    }

    public function test_agreement_numbers_are_sequential(): void
    {
        $admin = $this->admin();
        [, , , , $a1] = $this->accepted($this->customer('A'), $admin);
        [, , , , $a2] = $this->accepted($this->customer('B'), $admin);

        $year = now('Asia/Manila')->format('Y');
        $this->assertSame("LP-A-{$year}-0001", $a1->agreement_number);
        $this->assertSame("LP-A-{$year}-0002", $a2->agreement_number);
    }

    public function test_double_accept_creates_one_agreement(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin());

        $this->accept($c, $r, $q)->assertRedirect();
        Notification::query()->delete();
        $second = $this->accept($c, $r, $q);

        $a = DesignAgreement::where('quote_id', $q->id)->firstOrFail();
        $this->assertSame(1, DesignAgreement::count());
        $second->assertRedirect(route('design.agreement', $a->id));
        $this->assertSame(1, CustomOrderEvent::where('type', 'quote_accepted')->count());
        $this->assertSame(0, Notification::count(), 'a repeat accept must not notify again');
    }

    public function test_accept_after_expiry_is_refused_and_copy_removed(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin(), ['expires_at' => now()->addMinute()], ['reference_image' => 'design-refs/ref.png']);
        Storage::disk('public')->put('design-refs/ref.png', 'png-bytes');

        Carbon::setTestNow(now()->addMinutes(2));
        $res = $this->accept($c, $r, $q);

        $res->assertRedirect(route('design.show', $r->id));
        $this->assertSame(0, DesignAgreement::count());
        $this->assertSame('expired', $q->fresh()->status);
        $this->assertSame('expired', $r->fresh()->pipeline_status);
        $this->assertSame([], Storage::disk('local')->allFiles('agreements'), 'the pre-copied image must be removed');
    }

    public function test_accept_of_a_superseded_quote_is_refused(): void
    {
        $c = $this->customer();
        $admin = $this->admin();
        [$r, $v1] = $this->quoted($c, $admin);
        $v1->update(['status' => 'superseded']);
        $this->makeQuote($r, ['version' => 2, 'status' => 'sent', 'sent_by' => $admin->id, 'sent_at' => now(),
            'expires_at' => now()->addDays(7), 'price' => 25000, 'delivery_fee' => 0, 'delivery_option' => 'pickup']);

        $res = $this->accept($c, $r, $v1);

        $res->assertRedirect(route('design.show', $r->id));
        $res->assertSessionHasErrors('quote');
        $this->assertSame(0, DesignAgreement::count());
        $this->assertSame('quoted', $r->fresh()->pipeline_status);
    }

    public function test_price_comes_from_the_stored_quote_not_the_form(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin());

        $this->accept($c, $r, $q, ['agree' => '1', 'price' => '1', 'total' => '1', 'total_amount' => '1',
            'upfront_amount' => '1', 'deposit_amount' => '1', 'delivery_fee' => '0'])->assertRedirect();

        $a = DesignAgreement::firstOrFail();
        $this->assertSame('20500.00', (string) $a->total_amount);
        $this->assertSame('10250.00', (string) $a->upfront_amount);
        $this->assertSame('20000.00', $a->terms_snapshot['money']['price']);
    }

    public function test_payment_due_is_the_later_of_expiry_and_acceptance_plus_24h(): void
    {
        $admin = $this->admin();
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00', 'Asia/Manila'));

        // Plenty of validity left: due = quote expiry
        $c1 = $this->customer('One');
        [$r1, $q1] = $this->quoted($c1, $admin, ['expires_at' => now()->addDays(5)]);
        $this->accept($c1, $r1, $q1);
        $this->assertSame(
            now()->addDays(5)->format('Y-m-d H:i:s'),
            $r1->fresh()->payment_due_at->timezone('Asia/Manila')->format('Y-m-d H:i:s')
        );

        // Accepting 2 hours before expiry: due = acceptance + 24h
        $c2 = $this->customer('Two');
        [$r2, $q2] = $this->quoted($c2, $admin, ['expires_at' => now()->addHours(2)]);
        $this->accept($c2, $r2, $q2);
        $this->assertSame(
            now()->addHours(24)->format('Y-m-d H:i:s'),
            $r2->fresh()->payment_due_at->timezone('Asia/Manila')->format('Y-m-d H:i:s')
        );
        $a2 = DesignAgreement::where('quote_id', $q2->id)->firstOrFail();
        $this->assertSame('2026-10-17 10:00:00', $a2->terms_snapshot['payment_due_at']);
    }

    public function test_accept_not_possible_unless_request_is_quoted(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin(), [], ['pipeline_status' => 'declined_by_admin']);

        $this->accept($c, $r, $q)->assertSessionHasErrors('quote');
        $this->assertSame(0, DesignAgreement::count());
    }

    /* ───────────── reference image ───────────── */

    public function test_reference_image_is_copied_and_original_untouched(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin(), [], ['reference_image' => 'design-refs/ref.png']);
        Storage::disk('public')->put('design-refs/ref.png', 'ORIGINAL-IMAGE-BYTES');

        $this->accept($c, $r, $q)->assertRedirect();

        $a = DesignAgreement::firstOrFail();
        $copy = $a->terms_snapshot['design']['reference_image'];

        $this->assertMatchesRegularExpression('#^agreements/r\d+-q\d+-[A-Za-z0-9]{16}\.png$#', $copy);
        $this->assertSame('ORIGINAL-IMAGE-BYTES', Storage::disk('local')->get($copy));
        $this->assertSame('ORIGINAL-IMAGE-BYTES', Storage::disk('public')->get('design-refs/ref.png'));
        $this->assertSame('design-refs/ref.png', $r->fresh()->reference_image);
        $this->assertFalse(Storage::disk('public')->exists($copy), 'the copy is private, never in the public disk');
    }

    public function test_generated_concept_image_is_copied_without_touching_it(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin());

        $name = 'zz-agreement-test-' . bin2hex(random_bytes(6)) . '.png';
        $dir  = public_path('images/ai-designs');
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($full, 'CONCEPT-BYTES');

        try {
            $s = $r->suggestions()->create([
                'image_path' => 'images/ai-designs/' . $name, 'is_selected' => true,
            ]);
            $r->forceFill(['selected_suggestion_id' => $s->id])->save();

            $this->accept($c, $r, $q)->assertRedirect();

            $copy = DesignAgreement::firstOrFail()->terms_snapshot['design']['reference_image'];
            $this->assertSame('CONCEPT-BYTES', Storage::disk('local')->get($copy));
            $this->assertSame('CONCEPT-BYTES', file_get_contents($full), 'original must be unchanged');
        } finally {
            if (is_file($full)) {
                unlink($full); // only the unique file this test created
            }
        }
    }

    public function test_image_route_is_ownership_checked_and_private(): void
    {
        $customer = $this->customer();
        $other    = $this->customer('Other');
        $admin    = $this->admin();
        [$r, $q]  = $this->quoted($customer, $admin, [], ['reference_image' => 'design-refs/ref.png']);
        Storage::disk('public')->put('design-refs/ref.png', 'IMG');
        $this->accept($customer, $r, $q);
        $a = DesignAgreement::firstOrFail();

        $own = $this->actingAs($customer)->get(route('design.agreement.image', $a->id));
        $own->assertOk();
        $this->assertSame('IMG', file_get_contents($own->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString('no-store', $own->headers->get('Cache-Control'));

        $this->actingAs($other)->get(route('design.agreement.image', $a->id))->assertNotFound();

        $adm = $this->actingAs($admin, 'admin')->get(route('admin.agreements.image', $a->id));
        $adm->assertOk();
    }

    /* ───────────── decline ───────────── */

    public function test_customer_can_decline_with_a_reason(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        [$r, $q] = $this->quoted($customer, $admin);

        $res = $this->actingAs($customer)->post(route('design.quote.decline', [$r->id, $q->id]), ['reason' => 'Too expensive']);

        $res->assertRedirect(route('design.show', $r->id));
        $this->assertSame('declined', $q->fresh()->status);
        $this->assertSame('Too expensive', $q->fresh()->customer_decline_reason);
        $this->assertSame('declined_by_customer', $r->fresh()->pipeline_status);
        $this->assertSame(0, DesignAgreement::count());

        $e = CustomOrderEvent::where('type', 'quote_declined_by_customer')->firstOrFail();
        $this->assertSame('customer', $e->actor_type);

        $this->assertStringContainsString(
            'You declined the quote for Custom Furniture — Ana.',
            Notification::where('user_id', $customer->id)->firstOrFail()->message
        );
        $this->assertStringContainsString(
            'Ana Cruz declined the quote for Custom Furniture — Ana. Reason: Too expensive.',
            Notification::where('user_id', $admin->id)->firstOrFail()->message
        );

        // and the status line shows on the design page, and accept is now refused
        $this->actingAs($customer)->get(route('design.show', $r->id))->assertOk()->assertSee('You declined this quote');
        $this->accept($customer, $r, $q)->assertSessionHasErrors('quote');
        $this->assertSame(0, DesignAgreement::count());
    }

    public function test_decline_reason_is_optional(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        [$r, $q] = $this->quoted($customer, $admin);

        $this->actingAs($customer)->post(route('design.quote.decline', [$r->id, $q->id]))->assertRedirect();

        $this->assertNull($q->fresh()->customer_decline_reason);
        $this->assertStringContainsString('Reason: none given.', Notification::where('user_id', $admin->id)->firstOrFail()->message);
    }

    /* ───────────── agreement pages, integrity ───────────── */

    public function test_agreement_and_print_pages_render_the_snapshot(): void
    {
        [$c, , , , $a] = $this->accepted();

        $page = $this->actingAs($c)->get(route('design.agreement', $a->id));
        $page->assertOk()->assertSee($a->agreement_number)->assertSee('Agreed on')->assertSee('Terms and Conditions');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));

        $print = $this->actingAs($c)->get(route('design.agreement.print', $a->id));
        $print->assertOk()->assertSee($a->snapshot_sha256)->assertSee('agreement-print.css', false);
        $this->assertStringContainsString('no-store', $print->headers->get('Cache-Control'));
    }

    public function test_tampered_snapshot_blocks_the_page_and_alerts_admins(): void
    {
        [$c, $admin, , , $a] = $this->accepted();
        Notification::query()->delete();

        $s = $a->terms_snapshot;
        $s['money']['total'] = '1.00';
        DB::table('design_agreements')->where('id', $a->id)->update(['terms_snapshot' => json_encode($s)]);

        $res = $this->actingAs($c)->get(route('design.agreement', $a->id));

        $res->assertStatus(409);
        $res->assertDontSee('1.00');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->actingAs($c)->get(route('design.agreement.print', $a->id))->assertStatus(409);

        $alerts = Notification::where('user_id', $admin->id)->where('title', 'Agreement Integrity Check Failed')->get();
        $this->assertCount(1, $alerts, 'one unread alert per agreement, not one per page view');
        $this->assertStringContainsString($a->agreement_number, $alerts->first()->message);

        $this->actingAs($admin, 'admin')->get(route('admin.agreements.show', $a->id))->assertStatus(409);
        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $a->request_id))
            ->assertOk()->assertSee('Hash mismatch');
    }

    /* ───────────── immutability ───────────── */

    public function test_model_guards_still_block_every_write(): void
    {
        [, , , , $a] = $this->accepted();
        $policy = AgreementPolicy::current();

        foreach ([
            fn () => tap($a, fn ($m) => $m->total_amount = 1)->save(),
            fn () => $a->update(['total_amount' => 1]),
            fn () => $a->delete(),
            fn () => DesignAgreement::query()->where('id', $a->id)->update(['total_amount' => 1]),
            fn () => DesignAgreement::query()->where('id', $a->id)->delete(),
            fn () => $policy->update(['body' => 'changed']),
            fn () => $policy->delete(),
            fn () => AgreementPolicy::query()->update(['body' => 'x']),
        ] as $i => $write) {
            try {
                $write();
                $this->fail("write #{$i} was not blocked");
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('20500.00', (string) $a->fresh()->total_amount);
    }

    public function test_no_route_can_change_or_delete_an_agreement(): void
    {
        $seen = 0;
        foreach (Route::getRoutes() as $route) {
            if (!str_contains($route->uri(), 'agreements')) {
                continue;
            }
            $seen++;
            $this->assertSame(['GET', 'HEAD'], $route->methods(), $route->uri() . ' must be read only');
        }
        $this->assertGreaterThanOrEqual(6, $seen);

        $this->assertTrue(Schema::hasColumn('custom_design_requests', 'payment_due_at'));
    }

    /* ───────────── ownership (customer B gets 404 on A's records) ───────────── */

    public function test_other_customer_gets_404_everywhere(): void
    {
        $a = $this->customer('Alice');
        $b = $this->customer('Bob');
        $admin = $this->admin();

        [$r, $q] = $this->quoted($a, $admin);

        $this->actingAs($b)->get(route('design.show', $r->id))->assertNotFound();
        $this->actingAs($b)->get(route('design.quote.review', [$r->id, $q->id]))->assertNotFound();
        $this->actingAs($b)->post(route('design.quote.accept', [$r->id, $q->id]), ['agree' => '1'])->assertNotFound();
        $this->actingAs($b)->post(route('design.quote.decline', [$r->id, $q->id]))->assertNotFound();
        $this->assertSame('sent', $q->fresh()->status);
        $this->assertSame(0, DesignAgreement::count());

        // B's own request, A's quote id in the URL
        [$rb] = $this->quoted($b, $admin);
        $this->actingAs($b)->get(route('design.quote.review', [$rb->id, $q->id]))->assertNotFound();
        $this->actingAs($b)->post(route('design.quote.accept', [$rb->id, $q->id]), ['agree' => '1'])->assertNotFound();

        // after A accepts
        $this->accept($a, $r, $q)->assertRedirect();
        $ag = DesignAgreement::firstOrFail();

        $this->actingAs($b)->get(route('design.agreement', $ag->id))->assertNotFound();
        $this->actingAs($b)->get(route('design.agreement.print', $ag->id))->assertNotFound();
        $this->actingAs($b)->get(route('design.agreement.image', $ag->id))->assertNotFound();

        $this->actingAs($a)->get(route('design.agreement', $ag->id))->assertOk();
    }

    public function test_drafts_are_not_reachable_by_the_customer(): void
    {
        $c = $this->customer();
        [$r, $q] = $this->quoted($c, $this->admin(), ['status' => 'draft']);

        $this->actingAs($c)->get(route('design.quote.review', [$r->id, $q->id]))->assertNotFound();
        $this->accept($c, $r, $q)->assertNotFound();
    }

    /* ───────────── customer design page ───────────── */

    public function test_design_page_status_lines_and_links(): void
    {
        $c = $this->customer();
        $admin = $this->admin();
        [$r, $q] = $this->quoted($c, $admin);

        $this->actingAs($c)->get(route('design.show', $r->id))
            ->assertOk()
            ->assertSee('Review and accept')
            ->assertSee(route('design.quote.review', [$r->id, $q->id]), false)
            ->assertDontSee('Review and accept is coming next');

        $this->accept($c, $r, $q);
        $ag = DesignAgreement::firstOrFail();

        $this->actingAs($c)->get(route('design.show', $r->id))
            ->assertOk()
            ->assertSee('Agreed on')
            ->assertSee('next step is payment')
            ->assertSee('Proceed to payment')
            ->assertSee(route('design.agreement', $ag->id), false);

        // the review URL of an accepted quote goes to the agreement
        $this->actingAs($c)->get(route('design.quote.review', [$r->id, $q->id]))
            ->assertRedirect(route('design.agreement', $ag->id));
    }

    public function test_expired_quote_status_line(): void
    {
        $c = $this->customer();
        [$r] = $this->quoted($c, $this->admin(), ['expires_at' => now()->subMinute()]);

        $this->actingAs($c)->get(route('design.show', $r->id))->assertOk()->assertSee('This quote expired on');
        $this->assertSame('expired', $r->fresh()->pipeline_status);
    }

    /* ───────────── admin ───────────── */

    public function test_admin_detail_shows_agreement_and_print_link(): void
    {
        [, $admin, $r, , $a] = $this->accepted();

        $this->actingAs($admin, 'admin')->get(route('admin.custom-orders.show', $r->id))
            ->assertOk()
            ->assertSee($a->agreement_number)
            ->assertSee(self::IP)
            ->assertSee(self::UA)
            ->assertSee('Policy version')
            ->assertSee('Hash verified')
            ->assertSee('Payment due by')
            ->assertSee(route('admin.agreements.print', $a->id), false);

        $this->actingAs($admin, 'admin')->get(route('admin.agreements.show', $a->id))->assertOk()->assertSee($a->agreement_number);
        $this->actingAs($admin, 'admin')->get(route('admin.agreements.print', $a->id))->assertOk()->assertSee($a->snapshot_sha256);
    }

    public function test_admin_notifications_are_scoped_to_the_admin(): void
    {
        $one = $this->admin('One');
        $two = $this->admin('Two');
        $mine = Notification::create(['user_id' => $one->id, 'title' => 'Mine', 'message' => 'for admin one', 'type' => 'custom_order', 'is_read' => false]);
        $theirs = Notification::create(['user_id' => $two->id, 'title' => 'Theirs', 'message' => 'for admin two', 'type' => 'custom_order', 'is_read' => false]);
        $customerN = Notification::create(['user_id' => $this->customer()->id, 'title' => 'Cust', 'message' => 'customer only', 'type' => 'custom_order', 'is_read' => false]);

        $page = $this->actingAs($one, 'admin')->get(route('admin.notifications.index'));
        $page->assertOk()->assertSee('for admin one')->assertDontSee('for admin two')->assertDontSee('customer only');

        // cannot mark another admin's / a customer's notification
        $this->actingAs($one, 'admin')->post(route('admin.notifications.read', $theirs->id))->assertNotFound();
        $this->actingAs($one, 'admin')->post(route('admin.notifications.read', $customerN->id))->assertNotFound();
        $this->assertFalse($theirs->fresh()->is_read);

        $this->actingAs($one, 'admin')->post(route('admin.notifications.read', $mine->id))->assertRedirect();
        $this->assertTrue($mine->fresh()->is_read);

        // read-all touches only this admin's
        $second = Notification::create(['user_id' => $one->id, 'title' => 'Mine 2', 'message' => 'also one', 'type' => 'custom_order', 'is_read' => false]);
        $this->actingAs($one, 'admin')->post(route('admin.notifications.readAll'))->assertRedirect();
        $this->assertTrue($second->fresh()->is_read);
        $this->assertFalse($theirs->fresh()->is_read);
        $this->assertFalse($customerN->fresh()->is_read);
    }

    public function test_unread_badge_in_admin_sidebar(): void
    {
        $admin = $this->admin();
        Notification::create(['user_id' => $admin->id, 'title' => 'A', 'message' => 'a', 'type' => 'custom_order', 'is_read' => false]);
        Notification::create(['user_id' => $admin->id, 'title' => 'B', 'message' => 'b', 'type' => 'custom_order', 'is_read' => false]);
        Notification::create(['user_id' => $admin->id, 'title' => 'C', 'message' => 'c', 'type' => 'custom_order', 'is_read' => true]);

        $this->actingAs($admin, 'admin')->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSeeInOrder(['Notifications', '<span class="admin-nav-badge">2</span>'], false);
    }

    /* ───────────── RBAC matrix on every new route (fresh app per case) ───────────── */

    public static function rbacMatrix(): array
    {
        $customer = ['review', 'accept', 'decline', 'agreement', 'print', 'image'];
        $admin    = ['a-show', 'a-print', 'a-image', 'n-index', 'n-read', 'n-readAll'];

        $expect = [
            'guest on customer routes'              => ['guest', $customer, 'login'],
            'guest on admin routes'                 => ['guest', $admin, 'admin.login'],
            'customer (web) on admin routes'        => ['customer_web', $admin, 'admin.login'],
            'customer on admin guard, admin routes' => ['customer_on_admin_guard', $admin, 'admin.login'],
            'admin on admin guard, customer routes' => ['admin_on_admin_guard', $customer, 'login'],
            'admin user on web guard, customer'     => ['admin_on_web_guard', $customer, '404'],
            'other customer on customer routes'     => ['other_customer', $customer, '404'],
        ];

        $cases = [];
        foreach ($expect as $label => [$who, $routes, $outcome]) {
            foreach ($routes as $r) {
                $cases["$label → $r"] = [$who, $r, $outcome];
            }
        }

        return $cases;
    }

    /** @dataProvider rbacMatrix */
    public function test_rbac_matrix(string $who, string $route, string $outcome): void
    {
        $admin = $this->admin();
        $owner = $this->customer('Owner');
        [$r, $q] = $this->quoted($owner, $admin);
        // a separate accepted record to hit the agreement URLs, built without the endpoint
        [, $q2] = $this->quoted($owner, $admin, ['status' => 'accepted']);
        $ag = $this->makeAgreement($q2, $owner, $admin);
        $n  = Notification::create(['user_id' => $admin->id, 'title' => 'x', 'message' => 'x', 'type' => 'custom_order', 'is_read' => false]);

        $urls = [
            'review'    => ['get',  route('design.quote.review', [$r->id, $q->id])],
            'accept'    => ['post', route('design.quote.accept', [$r->id, $q->id])],
            'decline'   => ['post', route('design.quote.decline', [$r->id, $q->id])],
            'agreement' => ['get',  route('design.agreement', $ag->id)],
            'print'     => ['get',  route('design.agreement.print', $ag->id)],
            'image'     => ['get',  route('design.agreement.image', $ag->id)],
            'a-show'    => ['get',  route('admin.agreements.show', $ag->id)],
            'a-print'   => ['get',  route('admin.agreements.print', $ag->id)],
            'a-image'   => ['get',  route('admin.agreements.image', $ag->id)],
            'n-index'   => ['get',  route('admin.notifications.index')],
            'n-read'    => ['post', route('admin.notifications.read', $n->id)],
            'n-readAll' => ['post', route('admin.notifications.readAll')],
        ];

        $visitor = $this->customer('Visitor');
        match ($who) {
            'customer_web'            => $this->actingAs($visitor),
            'customer_on_admin_guard' => $this->actingAs($visitor, 'admin'),
            'admin_on_admin_guard'    => $this->actingAs($admin, 'admin'),
            'admin_on_web_guard'      => $this->actingAs($admin),
            'other_customer'          => $this->actingAs($visitor),
            default                   => null, // guest
        };

        // actingAs($user, 'admin') also makes 'admin' the default guard for the
        // rest of the test; in production the default stays 'web', so put it back.
        \Illuminate\Support\Facades\Auth::shouldUse('web');

        [$method, $url] = $urls[$route];
        $resp = $method === 'get' ? $this->get($url) : $this->post($url, ['agree' => '1']);

        match ($outcome) {
            'login'       => $resp->assertRedirect(route('login')),
            'admin.login' => $resp->assertRedirect(route('admin.login')),
            '404'         => $resp->assertNotFound(),
        };

        // nothing changed, nothing was created
        $this->assertSame('sent', $q->fresh()->status);
        $this->assertSame('quoted', $r->fresh()->pipeline_status);
        $this->assertSame(1, DesignAgreement::count());
        $this->assertFalse($n->fresh()->is_read);
        $this->assertSame(0, CustomOrderEvent::count());
    }

    public function test_owner_and_admin_do_reach_their_pages(): void
    {
        $admin = $this->admin();
        $owner = $this->customer();
        [, , , , $ag] = $this->accepted($owner, $admin);

        $this->actingAs($owner)->get(route('design.agreement', $ag->id))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.agreements.show', $ag->id))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.notifications.index'))->assertOk();
    }
}
