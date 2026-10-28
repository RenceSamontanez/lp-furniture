<?php

namespace Tests\Feature;

use App\Models\ServiceRefundRequest as Req;
use App\Support\Luma\OtherServicesKnowledge as K;
use App\Support\Luma\StatusAnswers;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Stage 21i: docs/luma-tone-rules.md as tests. Calm, one apology, never "approved" or "guaranteed", never a promised refund
 * or date, "po" in Tagalog, every status of a request explained without promising.
 */
class LumaToneTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    private const FORBIDDEN = '/\bapprov|guarantee|you will get a refund|will be refunded|we promise|hereby|pursuant|liable/i';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLuma();
    }

    public function test_every_authored_answer_follows_the_tone_rules(): void
    {
        foreach (K::nodes() as $id => $node) {
            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, $node['en'] . ' ' . $node['tl'], $id);
            $this->assertLessThanOrEqual(1, preg_match_all("/I'm sorry|I am sorry/", $node['en']), "{$id}: apologise once");
            $this->assertLessThanOrEqual(1, preg_match_all('/Pasensya na|Paumanhin/', $node['tl']), "{$id}: humingi ng paumanhin nang isang beses");
            $this->assertStringContainsStringIgnoringCase(' po', ' ' . $node['tl'], "{$id}: respectful Tagalog");
            $this->assertDoesNotMatchRegularExpression('/\byou should have\b|\byou forgot\b|\byou did not read\b|\bhindi ninyo binasa\b/i', $node['en'] . $node['tl'], "{$id}: never blame the customer");
            $this->assertDoesNotMatchRegularExpression('/\b(will be done by|ready by|finished by) (monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d)/i', $node['en'], "{$id}: never a promised date");
        }
    }

    public function test_every_status_of_a_request_is_explained_without_promising(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $svc = app(ServiceRefundRequestService::class);
        $req = $svc->create($ticket->id, $this->formValues($ticket), $this->owner, 'form');

        $say = function (string $lang) use (&$req) {
            return StatusAnswers::requestAnswer($req->fresh(['inquiry']), $lang)['text'];
        };

        $texts = [Req::NEW => [$say('en'), $say('tl')]];

        $svc->askForInfo($req->id, $this->admin, 'Please tell us why you want to cancel.');
        $texts[Req::NEEDS_INFO] = [$say('en'), $say('tl')];

        $svc->approve($req->id, $this->admin);
        $texts[Req::APPROVED] = [$say('en'), $say('tl')];

        $svc->markRefundFailed($req->id, $this->admin, 'wrong_gcash_number', null);
        $texts[Req::REFUND_PROBLEM] = [$say('en'), $say('tl')];

        $svc->customerReply($req->id, $this->owner, ['body' => 'The right number is below.', 'gcash_number' => '09181113333', 'gcash_account_name' => 'Juan Dela Cruz']);
        $svc->markRefunded($req->id, $this->admin, ['amount' => '4000', 'method' => 'gcash', 'transaction_number' => '6028871884223', 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null]);
        $texts[Req::REFUNDED] = [$say('en'), $say('tl')];

        foreach ($texts as $status => [$en, $tl]) {
            $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, $en . ' ' . $tl, $status);
            $this->assertStringContainsString('po', $tl, "{$status}: Tagalog is polite");
            $this->assertStringContainsString($req->reference, $en, $status);
            $this->assertDoesNotMatchRegularExpression('/09\d{9}|\*\*\*/', $en . $tl, "{$status}: no GCash number in any status answer");
        }
        $this->assertStringContainsString('has not replied yet', $texts[Req::NEW][0]);
        $this->assertStringContainsString('needs more information', $texts[Req::NEEDS_INFO][0]);
        $this->assertStringContainsString('is due to be sent', $texts[Req::APPROVED][0]);
        $this->assertStringContainsString('problem sending the refund', $texts[Req::REFUND_PROBLEM][0]);
        $this->assertStringContainsString('6028871884223', $texts[Req::REFUNDED][0]);
    }

    public function test_thanks_and_goodbye_get_one_short_polite_line_and_no_answer_has_a_sign_off(): void
    {
        $this->assertNull(config('luma.closing'));
        $this->assertSame("You're welcome.", $this->say('thank you')['response']);
        $this->assertSame('Walang anuman po.', $this->say('salamat po')['response']);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN, $this->say('thanks')['response']);
    }
}
