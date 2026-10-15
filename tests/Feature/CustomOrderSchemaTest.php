<?php

namespace Tests\Feature;

use App\Exceptions\ImmutableRecordException;
use App\Models\AgreementPolicy;
use App\Models\CustomOrderCallLog;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\PaymentRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsCustomOrderFixtures;
use Tests\TestCase;

/**
 * Stage 1: the new tables, constraints and model guards. Built from the
 * migrations on the scratch database (migrate:fresh), so this also
 * proves the migrations run clean from nothing.
 */
class CustomOrderSchemaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsCustomOrderFixtures;

    private const NEW_TABLES = [
        'custom_order_call_logs', 'custom_quotes', 'agreement_policies',
        'design_agreements', 'payment_records', 'custom_order_events',
    ];

    /* ───────────── schema shape ───────────── */

    public function test_new_tables_and_columns_exist(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
        }

        $this->assertTrue(Schema::hasColumns('custom_design_requests', [
            'pipeline_status', 'request_mode', 'modification_notes', 'selected_suggestion_id',
            'contact_phone', 'contact_email', 'preferred_call_window', 'submitted_at',
            'decline_reason', 'lock_version',
        ]));
        $this->assertTrue(Schema::hasColumns('orders', [
            'design_agreement_id', 'current_agreement_id', 'amount_paid',
            'expected_ready_date', 'needs_review', 'review_reason',
        ]));
        $this->assertTrue(Schema::hasColumn('payments', 'design_agreement_id'));
        $this->assertTrue(Schema::hasColumns('order_items', ['product_name', 'product_image']), 'M0 reconciliation');
    }

    public function test_no_triggers_and_no_generated_columns_anywhere(): void
    {
        $triggers = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::raw('DATABASE()'))
            ->count();
        $this->assertSame(0, $triggers, 'the approved design forbids MySQL triggers');

        $generated = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where(fn ($q) => $q->where('EXTRA', 'like', '%VIRTUAL GENERATED%')
                                 ->orWhere('EXTRA', 'like', '%STORED GENERATED%')
                                 ->orWhere('EXTRA', 'like', '%PERSISTENT%'))
            ->count();
        $this->assertSame(0, $generated, 'the approved design forbids generated columns');
    }

    public function test_policy_v1_placeholder_is_seeded(): void
    {
        $policy = AgreementPolicy::where('version', 1)->first();

        $this->assertNotNull($policy);
        $this->assertStringContainsString('text to be supplied', $policy->body);
        $this->assertSame(1, AgreementPolicy::current()->version);
    }

    public function test_agreement_foreign_keys_are_restrict(): void
    {
        $rows = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'design_agreements')
            ->get(['CONSTRAINT_NAME', 'DELETE_RULE']);

        $this->assertGreaterThanOrEqual(6, $rows->count());

        foreach ($rows as $row) {
            $this->assertSame('RESTRICT', $row->DELETE_RULE, "{$row->CONSTRAINT_NAME} must be RESTRICT");
        }
    }

    /* ───────────── uniqueness the database enforces ───────────── */

    public function test_one_agreement_per_quote(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $quote    = $this->makeQuote($this->makeRequest($customer));

        $this->makeAgreement($quote, $customer, $admin);

        $this->expectException(QueryException::class);
        $this->makeAgreement($quote, $customer, $admin);
    }

    public function test_one_order_per_agreement(): void
    {
        $customer  = $this->makeUser();
        $admin     = $this->makeUser('admin');
        $agreement = $this->makeAgreement($this->makeQuote($this->makeRequest($customer)), $customer, $admin);

        DB::table('orders')->insert($this->orderRow($customer->id, $agreement->id));

        $this->expectException(QueryException::class);
        DB::table('orders')->insert($this->orderRow($customer->id, $agreement->id));
    }

    public function test_many_catalog_orders_without_an_agreement_are_still_allowed(): void
    {
        $customer = $this->makeUser();

        $this->makeOrder($customer);
        $this->makeOrder($customer);

        $this->assertSame(2, DB::table('orders')->where('user_id', $customer->id)->whereNull('design_agreement_id')->count());
    }

    public function test_quote_version_is_unique_per_request(): void
    {
        $request = $this->makeRequest($this->makeUser());
        $this->makeQuote($request, ['version' => 1]);
        $this->makeQuote($request, ['version' => 2]);

        $this->expectException(QueryException::class);
        $this->makeQuote($request, ['version' => 2]);
    }

    public function test_gateway_reference_cannot_be_recorded_twice_but_cash_can(): void
    {
        $customer = $this->makeUser();
        $order    = $this->makeOrder($customer);
        $payment  = $this->makePayment($order);

        $base = ['order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 100, 'paid_on' => now()->toDateString()];

        PaymentRecord::create($base + ['method' => 'cash']);
        PaymentRecord::create($base + ['method' => 'cash']);              // NULL reference: allowed
        PaymentRecord::create($base + ['method' => 'stripe', 'gateway_reference' => 'pi_1']);
        $this->assertSame(3, PaymentRecord::where('order_id', $order->id)->count());

        $this->expectException(QueryException::class);
        PaymentRecord::create($base + ['method' => 'stripe', 'gateway_reference' => 'pi_1']);
    }

    public function test_call_attempt_numbers_are_unique_per_request(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $request  = $this->makeRequest($customer);

        CustomOrderCallLog::create(['request_id' => $request->id, 'admin_id' => $admin->id, 'attempt_no' => 1, 'called_at' => now(), 'answered' => false]);
        CustomOrderCallLog::create(['request_id' => $request->id, 'admin_id' => $admin->id, 'attempt_no' => 2, 'called_at' => now(), 'answered' => true]);

        $this->expectException(QueryException::class);
        CustomOrderCallLog::create(['request_id' => $request->id, 'admin_id' => $admin->id, 'attempt_no' => 2, 'called_at' => now(), 'answered' => true]);
    }

    /* ───────────── RESTRICT: agreements survive deletes ───────────── */

    public function test_deleting_the_customer_is_blocked_while_an_agreement_exists(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $this->makeAgreement($this->makeQuote($this->makeRequest($customer)), $customer, $admin);

        try {
            DB::table('users')->where('id', $customer->id)->delete();
            $this->fail('deleting a user with an agreement must be refused by the database');
        } catch (QueryException) {
            $this->assertTrue(DB::table('users')->where('id', $customer->id)->exists());
            $this->assertSame(1, DB::table('design_agreements')->count());
        }
    }

    public function test_deleting_the_admin_who_finalized_is_blocked(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $this->makeAgreement($this->makeQuote($this->makeRequest($customer)), $customer, $admin);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $admin->id)->delete();
    }

    public function test_deleting_the_request_or_quote_is_blocked_while_an_agreement_exists(): void
    {
        $customer  = $this->makeUser();
        $admin     = $this->makeUser('admin');
        $request   = $this->makeRequest($customer);
        $quote     = $this->makeQuote($request);
        $this->makeAgreement($quote, $customer, $admin);

        try {
            DB::table('custom_quotes')->where('id', $quote->id)->delete();
            $this->fail('quote delete must be refused');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('custom_design_requests')->where('id', $request->id)->delete();
    }

    public function test_deleting_a_user_without_agreements_still_cascades_as_before(): void
    {
        $customer = $this->makeUser();
        $request  = $this->makeRequest($customer);
        $this->makeQuote($request);

        DB::table('users')->where('id', $customer->id)->delete();

        $this->assertSame(0, DB::table('custom_design_requests')->where('id', $request->id)->count());
        $this->assertSame(0, DB::table('custom_quotes')->where('request_id', $request->id)->count());
    }

    /* ───────────── model guards: DesignAgreement ───────────── */

    private function agreement(): DesignAgreement
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');

        return $this->makeAgreement($this->makeQuote($this->makeRequest($customer)), $customer, $admin);
    }

    public function test_agreement_cannot_be_updated_through_the_model(): void
    {
        $agreement = $this->agreement();
        $agreement->total_amount = 1;

        $this->expectException(ImmutableRecordException::class);
        $agreement->save();
    }

    public function test_agreement_cannot_be_updated_with_update(): void
    {
        $agreement = $this->agreement();

        $this->expectException(ImmutableRecordException::class);
        $agreement->update(['ip_address' => '10.0.0.1']);
    }

    public function test_agreement_cannot_be_touched_or_saved_unchanged(): void
    {
        $agreement = $this->agreement();

        try {
            $agreement->touch();
            $this->fail('touch() must throw');
        } catch (ImmutableRecordException) {
        }

        $this->expectException(ImmutableRecordException::class);
        $agreement->save();
    }

    public function test_agreement_cannot_be_deleted_through_the_model(): void
    {
        $agreement = $this->agreement();

        try {
            $agreement->delete();
            $this->fail('delete() must throw');
        } catch (ImmutableRecordException) {
        }

        $this->assertTrue(DesignAgreement::whereKey($agreement->id)->exists());
    }

    public function test_agreement_cannot_be_changed_through_the_query_builder(): void
    {
        $agreement = $this->agreement();
        $original  = $agreement->total_amount;

        foreach ([
            fn () => DesignAgreement::query()->update(['total_amount' => 1]),
            fn () => DesignAgreement::where('id', $agreement->id)->update(['upfront_amount' => 1]),
            fn () => DesignAgreement::where('id', $agreement->id)->delete(),
            fn () => DesignAgreement::query()->forceDelete(),
            fn () => DesignAgreement::where('id', $agreement->id)->increment('total_amount'),
            fn () => DesignAgreement::query()->truncate(),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("builder attempt #{$i} should have thrown");
            } catch (ImmutableRecordException) {
                $this->assertTrue(true);
            }
        }

        $fresh = DesignAgreement::findOrFail($agreement->id);
        $this->assertEquals($original, $fresh->total_amount);
    }

    public function test_agreement_can_still_be_read_and_related(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $request  = $this->makeRequest($customer);
        $quote    = $this->makeQuote($request);
        $agreement = $this->makeAgreement($quote, $customer, $admin);

        $this->assertSame($customer->id, $agreement->customer->id);
        $this->assertSame($admin->id, $agreement->finalizedBy->id);
        $this->assertSame($quote->id, $agreement->quote->id);
        $this->assertSame($request->id, $agreement->request->id);
        $this->assertSame(1, $agreement->policy->version);
        $this->assertSame($agreement->id, $quote->agreement->id);
        $this->assertSame(1, $request->agreements()->count());
        $this->assertFalse($agreement->order()->exists());
    }

    /* ───────────── model guards: AgreementPolicy ───────────── */

    public function test_policy_versions_are_immutable_but_new_versions_can_be_added(): void
    {
        $v1 = AgreementPolicy::where('version', 1)->firstOrFail();

        try {
            $v1->update(['body' => 'changed']);
            $this->fail('policy update must throw');
        } catch (ImmutableRecordException) {
        }

        try {
            $v1->delete();
            $this->fail('policy delete must throw');
        } catch (ImmutableRecordException) {
        }

        try {
            AgreementPolicy::query()->update(['body' => 'changed']);
            $this->fail('policy builder update must throw');
        } catch (ImmutableRecordException) {
        }

        $v2 = AgreementPolicy::create(['version' => 2, 'title' => 'Refund policy', 'body' => 'new wording']);
        $this->assertSame(2, AgreementPolicy::current()->version);
        $this->assertStringContainsString('text to be supplied', $v1->fresh()->body);
        $this->assertNotNull($v2->created_at);
    }

    /* ───────────── snapshot hash ───────────── */

    public function test_snapshot_hash_ignores_key_order_and_detects_any_change(): void
    {
        $a = ['price' => '20000.00', 'design' => ['title' => 'Table', 'size' => ['w' => 100, 'd' => 50]], 'items' => ['x', 'y']];
        $b = ['items' => ['x', 'y'], 'design' => ['size' => ['d' => 50, 'w' => 100], 'title' => 'Table'], 'price' => '20000.00'];

        $this->assertSame(DesignAgreement::hashSnapshot($a), DesignAgreement::hashSnapshot($b));
        $this->assertSame(64, strlen(DesignAgreement::hashSnapshot($a)));

        $changedValue = $a;
        $changedValue['price'] = '20000.01';
        $this->assertNotSame(DesignAgreement::hashSnapshot($a), DesignAgreement::hashSnapshot($changedValue));

        $changedList = $a;
        $changedList['items'] = ['y', 'x'];
        $this->assertNotSame(DesignAgreement::hashSnapshot($a), DesignAgreement::hashSnapshot($changedList));
    }

    public function test_stored_snapshot_verifies_and_tampering_is_detected(): void
    {
        $agreement = $this->agreement();

        $this->assertTrue($agreement->fresh()->snapshotIsIntact());

        // Simulate someone bypassing every PHP guard with raw SQL.
        DB::table('design_agreements')->where('id', $agreement->id)->update([
            'terms_snapshot' => json_encode(['price' => '1.00']),
        ]);

        $this->assertFalse($agreement->fresh()->snapshotIsIntact());
    }

    /* ───────────── open-quote lookup (code-level, no generated column) ───────────── */

    public function test_find_open_quote_returns_only_draft_or_sent(): void
    {
        $request = $this->makeRequest($this->makeUser());

        $this->assertNull(CustomQuote::findOpenForRequest($request->id));

        $this->makeQuote($request, ['version' => 1, 'status' => 'superseded']);
        $this->makeQuote($request, ['version' => 2, 'status' => 'expired']);
        $this->assertNull(CustomQuote::findOpenForRequest($request->id));

        $open = $this->makeQuote($request, ['version' => 3, 'status' => 'draft']);
        $this->assertSame($open->id, CustomQuote::findOpenForRequest($request->id)->id);
        $this->assertSame($open->id, CustomQuote::findOpenForRequest($request->id, lock: true)->id);
    }

    public function test_quote_money_helpers(): void
    {
        $quote = new CustomQuote([
            'price' => 18000, 'delivery_fee' => CustomQuote::DEFAULT_DELIVERY_FEE,
            'payment_terms' => 'deposit', 'deposit_amount' => 9250,
        ]);

        $this->assertSame(18500.00, $quote->totalAmount());
        $this->assertSame(9250.00, $quote->minimumDeposit());
        $this->assertSame(9250.00, $quote->upfrontAmount());

        $quote->payment_terms = 'full';
        $this->assertSame(18500.00, $quote->upfrontAmount());

        $this->assertSame(7, CustomQuote::VALID_DAYS);
        $this->assertSame(999999, CustomQuote::MAX_PRICE);
    }

    public function test_events_and_call_logs_relate_to_a_request(): void
    {
        $customer = $this->makeUser();
        $admin    = $this->makeUser('admin');
        $request  = $this->makeRequest($customer);

        CustomOrderEvent::create([
            'request_id' => $request->id, 'type' => 'submitted',
            'actor_type' => 'customer', 'actor_id' => $customer->id,
            'data' => ['window' => 'anytime'], 'occurred_at' => now(),
        ]);
        CustomOrderCallLog::create([
            'request_id' => $request->id, 'admin_id' => $admin->id, 'attempt_no' => 1,
            'called_at' => now(), 'answered' => true, 'note' => 'Agreed on oak',
        ]);

        $this->assertSame(['window' => 'anytime'], $request->events()->first()->data);
        $this->assertSame($admin->id, $request->callLogs()->first()->admin->id);
    }

    private function orderRow(int $userId, ?int $agreementId): array
    {
        return [
            'user_id'             => $userId,
            'design_agreement_id' => $agreementId,
            'order_number'        => 'LP-T-' . bin2hex(random_bytes(5)),
            'total_amount'        => 20000,
            'delivery_option'     => 'pickup',
            'delivery_fee'        => 0,
            'production_status'   => 'pending',
            'order_status'        => 'pending',
            'created_at'          => now(),
            'updated_at'          => now(),
        ];
    }
}
