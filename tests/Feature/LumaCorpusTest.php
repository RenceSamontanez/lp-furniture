<?php

namespace Tests\Feature;

use App\Models\ChatbotIntent;
use App\Models\ChatbotLog;
use App\Models\ServiceRefundRequest as Req;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Stage 21i (Prompt 8f): the labelled corpus in tests/Luma/corpus.php, run through the real chat endpoint. Needs 95% overall
 * and 100% on the safety-critical groups (refund, cancel, payment, "I paid but it says unpaid"). Every non-Other-Services
 * row proves the older furniture answers still route as before.
 */
class LumaCorpusTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    private const SAFETY = ['refund', 'cancel', 'payment', 'unpaid'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLuma();
    }

    public function test_the_corpus_has_the_required_shape(): void
    {
        $rows = require base_path('tests/Luma/corpus.php');
        $by   = collect($rows)->groupBy(2)->map->count();

        $this->assertGreaterThanOrEqual(280, count($rows));
        $this->assertGreaterThanOrEqual(60, $by['short'] + collect($rows)->filter(fn ($r) => str_word_count($r[0]) <= 2)->count() - $by['short'], 'one-word and very short cases');
        $this->assertGreaterThanOrEqual(20, $by['ref'], 'reference-number cases');
        $this->assertGreaterThanOrEqual(40, $by['notos'], 'cases that are not about Other Services');
        foreach (['en', 'tl', 'typo', 'long', 'angry', 'elder'] as $group) {
            $this->assertArrayHasKey($group, $by->all(), $group);
        }
    }

    public function test_every_message_reaches_its_expected_answer(): void
    {
        $rows = require base_path('tests/Luma/corpus.php');

        // the signed-in customer's fixtures: SI-2026-0001 with a paid INV-2026-0001 and an open REF-2026-0001; SI-2026-0002 paid, no request
        [$t1, $i1] = $this->ticketWithInvoice('full', true);
        $t1->update(['reference_number' => 'SI-2026-0001']);
        $svc = app(ServiceRefundRequestService::class);
        $svc->create($t1->id, $this->formValues($t1), $this->owner, 'form');
        [$t2] = $this->ticketWithInvoice('full', true);
        $t2->update(['reference_number' => 'SI-2026-0002']);
        $this->assertSame('INV-2026-0001', $i1->invoice_number);
        $this->assertSame('REF-2026-0001', Req::first()->reference);

        $stats = [];
        $fails = [];

        foreach ($rows as [$message, $expected, $group, $who]) {
            $this->newBrowser();
            if ($who === 'owner') {
                $this->loginAs($this->owner);
            } elseif ($who === 'other') {
                $this->loginAs($this->other);
            }
            if ($expected === 'flow' && $who === 'owner') {
                $message = str_replace('SI-2026-0001', 'SI-2026-0002', $message);
            }

            $reply    = $this->say($message);
            $detected = (string) ChatbotLog::query()->latest('id')->value('intent_detected');
            $got      = str_starts_with($detected, 'intent:') ? (string) ChatbotIntent::find((int) substr($detected, 7))?->category : $detected;

            $ok = match (true) {
                str_starts_with($expected, 'ask:') => $detected === 'disambiguation:' . substr($expected, 4),
                $expected === 'ref'     => str_starts_with($detected, 'reference:') && !str_contains($reply['response'], "couldn't find that reference"),
                $expected === 'refnone' => str_contains($reply['response'], "couldn't find that reference"),
                $expected === 'flow'    => !empty($reply['form']),
                default                 => $got === $expected,
            };

            // the rules that hold for EVERY reply
            $clean = !preg_match('/\bapprov|guarantee|you will get a refund/i', $reply['response']);
            $ok    = $ok && $clean;

            $stats[$group]['n'] = ($stats[$group]['n'] ?? 0) + 1;
            $stats[$group]['ok'] = ($stats[$group]['ok'] ?? 0) + ($ok ? 1 : 0);
            if (!$ok) {
                $fails[] = sprintf('[%s] "%s" wanted %s, got %s%s', $group, $message, $expected, $got ?: '(none)', $clean ? '' : ' AND FORBIDDEN WORDS');
            }
        }

        ksort($stats);
        $total = array_sum(array_column($stats, 'n'));
        $pass  = array_sum(array_column($stats, 'ok'));
        $report = "CORPUS {$pass}/{$total} (" . round(100 * $pass / $total, 1) . "%)\n"
            . implode("\n", array_map(fn ($g, $s) => sprintf('  %-8s %3d/%-3d %s', $g, $s['ok'], $s['n'], in_array($g, self::SAFETY, true) ? '(safety)' : ''), array_keys($stats), $stats))
            . "\nFAILING:\n" . implode("\n", $fails);
        fwrite(STDERR, "\n" . $report . "\n");

        $this->assertGreaterThanOrEqual(0.95, $pass / $total, $report);
        foreach (self::SAFETY as $group) {
            $this->assertSame($stats[$group]['n'], $stats[$group]['ok'], "safety group {$group} must be 100%\n" . $report);
        }
    }
}
