<?php

namespace Tests\Feature;

use App\Models\AgreementPolicy;
use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;
use App\Models\DesignAgreement;
use App\Support\PolicyText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DrivesCustomOrderFlow;
use Tests\TestCase;

/**
 * Stage 17 part A: Terms and Conditions as policy version 2. Insert-only, version 1
 * untouched, new agreements snapshot version 2, old ones keep what they were accepted with.
 */
class PolicyVersion2Test extends TestCase
{
    use RefreshDatabase;
    use DrivesCustomOrderFlow;

    private const MIGRATION = 'database/migrations/2026_10_19_090000_insert_agreement_policy_v2.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownFlow();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /* ───────────── the row ───────────── */

    public function test_version_2_exists_after_migration_with_structured_sections(): void
    {
        $v2 = AgreementPolicy::where('version', 2)->first();

        $this->assertNotNull($v2);
        $this->assertSame('Terms and Conditions', $v2->title);
        $this->assertSame(2, AgreementPolicy::current()->version);

        $headings = collect(PolicyText::sections($v2->body))->pluck('heading')->all();
        foreach ([
            'What we will do', 'What we will not do', 'When your order becomes binding', 'Deposit and balance payments',
            'Cancellation and refunds', 'Changes to your order', 'Delivery and pickup', 'Defects and warranty',
            'Your responsibilities', 'Your data and privacy', 'Contact us',
        ] as $required) {
            $this->assertContains($required, $headings, $required);
        }

        $this->assertStringContainsString('at least 50 percent', $v2->body);
        $this->assertStringContainsString('{contact_phone}', $v2->body);
        $this->assertGreaterThanOrEqual(8, substr_count($v2->body, '[TO CONFIRM'));
        $this->assertStringNotContainsStringIgnoringCase('draft', $v2->body, 'the draft note lives in docs, not in customer text');
    }

    public function test_version_1_is_unchanged(): void
    {
        $v1 = AgreementPolicy::where('version', 1)->firstOrFail();

        $this->assertSame('Refund and Cancellation Policy', $v1->title);
        $this->assertSame('[Refund and cancellation policy — text to be supplied]', $v1->body);
        $this->assertSame(1, AgreementPolicy::where('version', 1)->count());
    }

    public function test_the_migration_is_idempotent_and_rolls_back_only_version_2(): void
    {
        $m = $this->migration();
        $v2Body = AgreementPolicy::where('version', 2)->value('body');

        $m->up();
        $m->up();
        $this->assertSame(1, AgreementPolicy::where('version', 2)->count(), 'running it again inserts nothing');
        $this->assertSame($v2Body, AgreementPolicy::where('version', 2)->value('body'), 'and changes nothing');

        $m->down();
        $this->assertSame(0, AgreementPolicy::where('version', 2)->count());
        $this->assertSame('[Refund and cancellation policy — text to be supplied]', AgreementPolicy::where('version', 1)->value('body'));
        $m->down();   // rolling back twice is harmless
        $this->assertSame(1, AgreementPolicy::where('version', 1)->count());

        $m->up();
        $this->assertSame(1, AgreementPolicy::where('version', 2)->count(), 'and it can be applied again');
        $this->assertSame($v2Body, AgreementPolicy::where('version', 2)->value('body'));
    }

    public function test_rollback_keeps_version_2_while_an_agreement_uses_it(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);
        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();

        $this->migration()->down();

        $this->assertSame(1, AgreementPolicy::where('version', 2)->count(), 'an agreement points at it');
        $this->assertSame(1, AgreementPolicy::where('version', 1)->count());
    }

    /* ───────────── agreements ───────────── */

    public function test_new_agreements_snapshot_version_2_and_verify(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        [$r, $s] = $this->generatedRequest($customer);
        $this->submitRequest($customer, $r, $s)->assertSessionHasNoErrors();
        $quote = $this->quoteAndSend($admin, $r);

        $this->asCustomer($customer)->post(route('design.quote.accept', [$r->id, $quote->id]), ['agree' => '1'])->assertRedirect();
        $a = $this->agreementOf($r);

        $this->assertSame(2, (int) $a->policy_version);
        $this->assertSame(2, $a->terms_snapshot['policy']['version']);
        $this->assertSame(AgreementPolicy::where('version', 2)->value('body'), $a->terms_snapshot['policy']['text']);
        $this->assertTrue($a->snapshotIsIntact());
    }

    public function test_an_existing_agreement_keeps_its_stored_snapshot_hash_and_version(): void
    {
        $admin = $this->flowAdmin();
        $customer = $this->flowCustomer();
        $request = $this->makeRequest($customer);
        $quote = $this->makeQuote($request, ['status' => 'accepted']);
        $old = $this->makeAgreement($quote, $customer, $admin);   // accepted under policy version 1

        $before = [$old->terms_snapshot, $old->snapshot_sha256, (int) $old->policy_version];
        $this->assertSame(1, $before[2]);

        // version 2 exists now; the old agreement is read back exactly as stored and still verifies
        $fresh = DesignAgreement::findOrFail($old->id);
        $this->assertSame($before[0], $fresh->terms_snapshot);
        $this->assertSame($before[1], $fresh->snapshot_sha256);
        $this->assertSame(1, (int) $fresh->policy_version);
        $this->assertTrue($fresh->snapshotIsIntact());

        $this->assertSame('placeholder', $fresh->terms_snapshot['policy']['text'], 'it still holds its own text, not version 2');
    }

    /* ───────────── the text format ───────────── */

    public function test_policy_text_parses_headings_paragraphs_and_lists(): void
    {
        $sections = PolicyText::sections("## One\n\nFirst paragraph\nsecond line.\n\n- a\n- b\n\n## Two\n\nOnly this.");

        $this->assertCount(2, $sections);
        $this->assertSame('One', $sections[0]['heading']);
        $this->assertSame(['type' => 'p', 'text' => 'First paragraph second line.'], $sections[0]['blocks'][0]);
        $this->assertSame(['type' => 'list', 'items' => ['a', 'b']], $sections[0]['blocks'][1]);
        $this->assertSame('Two', $sections[1]['heading']);
    }

    public function test_legacy_text_without_headings_is_one_section(): void
    {
        $sections = PolicyText::sections('[Refund and cancellation policy — text to be supplied]');

        $this->assertCount(1, $sections);
        $this->assertNull($sections[0]['heading']);
        $this->assertSame([], PolicyText::sections(''));
    }

    public function test_contact_placeholders_use_contact_settings_with_a_fallback(): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::create(['phone' => '0917-555-0100', 'email' => 'hello@lpfurniture.test', 'facebook_url' => 'https://facebook.com/x']);
        Cache::forget(BusinessSetting::CACHE_KEY);
        $filled = PolicyText::fillContact('Call {contact_phone} or email {contact_email}.');
        $this->assertSame('Call 0917-555-0100 or email hello@lpfurniture.test.', $filled);

        BusinessSetting::query()->delete();
        Cache::forget(BusinessSetting::CACHE_KEY);
        $fallback = PolicyText::fillContact('Call {contact_phone} or email {contact_email}.');
        $this->assertStringNotContainsString('0917-000-0000', $fallback);
        $this->assertStringNotContainsString('contact@example.com', $fallback);
        $this->assertStringContainsString('on our website', $fallback);
    }

    public function test_the_owner_doc_lists_every_open_item(): void
    {
        $doc = file_get_contents(base_path('docs/terms-draft-v2.md'));
        $body = AgreementPolicy::where('version', 2)->value('body');

        $this->assertStringContainsString('not customer text', $doc);
        $this->assertStringContainsString('owner and a lawyer', $doc);
        $this->assertStringContainsString('## Items the owner must decide', $doc);
        // every paragraph of the stored text is in the doc as well
        foreach (PolicyText::sections($body) as $section) {
            $this->assertStringContainsString($section['heading'], $doc);
        }
        // sentences with a marker are listed together before the text
        $listed = substr($doc, 0, strpos($doc, '## Draft text as customers see it'));
        $sentences = 0;
        foreach (preg_split('/\n/', $body) as $line) {
            $sentences += preg_match('/\[TO CONFIRM/', $line) ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual(8, substr_count($listed, '[TO CONFIRM'));
        $this->assertGreaterThanOrEqual(1, $sentences);
    }
}
