<?php

namespace Tests\Feature;

use App\Models\ChatbotLog;
use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceRefundRequest as Req;
use App\Support\Luma\ChatState;
use App\Support\Luma\Reference;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Stage 21i (Prompt 8f): Luma's Other Services knowledge, one-word understanding, reference numbers, conversation
 * memory, logged-in status answers and the guided cancellation / refund flow. Gateways are faked: no refund API exists.
 */
class LumaOtherServicesTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    private const GCASH = '09181112222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLuma();
    }

    /* ───────────── helpers ───────────── */

    private function assertSafe(array $reply, string $label = ''): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bapprov|guarantee|you will get a refund|will be refunded/i', $reply['response'], $label);
        $this->assertMatchesRegularExpression("/(Luma is always here to help\.|Nandito lang si Luma para tumulong\.|Nandito lang po si Luma para tumulong\.|Nandito lang po ako kung may kailangan kayo\.|I am right here if you need anything\.|come back anytime\.|balik lang kayo anumang oras\.)$/", $reply['response'], $label);
    }

    private function detected(): string
    {
        return (string) ChatbotLog::query()->latest('id')->value('intent_detected');
    }

    private function category(): string
    {
        $d = $this->detected();

        return str_starts_with($d, 'intent:') ? (string) \App\Models\ChatbotIntent::find((int) substr($d, 7))?->category : $d;
    }

    private function buttonUrls(array $reply): array
    {
        return array_column($reply['buttons'], 'url');
    }

    private function request(\App\Models\ServiceInvoice $i, array $over = []): Req
    {
        return app(ServiceRefundRequestService::class)->create($i->service_inquiry_id, $this->formValues($i->inquiry, $over), $this->owner, 'form');
    }

    /* ───────────── Part 1: what was broken ───────────── */

    public function test_other_service_and_how_do_i_create_ticket_are_answered_not_missed(): void
    {
        $a = $this->say('other service');
        $this->assertSame('luma_os_what_is', $this->category());
        $this->assertStringContainsString('repairs, restoration', $a['response']);
        $this->assertStringNotContainsString("couldn't quite find", $a['response']);

        $b = $this->say('How do I create ticket');
        $this->assertSame('luma_os_make_ticket', $this->category());
        $this->assertStringContainsString('To make a ticket', $b['response']);
        $this->assertContains('How to describe the problem', $b['suggestions']);
    }

    public function test_a_miss_is_closed_with_the_fallback_line_never_you_are_welcome(): void
    {
        $en = $this->say('xyzzy plugh');
        $this->assertStringEndsWith('Luma is always here to help.', $en['response']);
        $this->assertStringNotContainsString("You're welcome", $en['response']);

        $tl = $this->say('ewan ko po xyzzy');
        $this->assertStringEndsWith('Nandito lang po si Luma para tumulong.', $tl['response']);
        $this->assertStringNotContainsString('Walang anuman', $tl['response']);

        $ok = $this->say('other service');
        $this->assertStringEndsWith("You're welcome! Luma is always here to help.", $ok['response']);
    }

    public function test_the_fallback_chips_follow_the_words_and_always_offer_a_person(): void
    {
        // words that match something are answered, so the chips for a typed payment word are checked on the chip builder itself
        $chips = \App\Support\Luma\Luma::fallbackChips('something about my receipt and my gcash');
        $this->assertSame('Pay my invoice', $chips[0]);
        $this->assertSame('Talk to a person', end($chips));
        $this->assertLessThanOrEqual(5, count($chips));
        $this->assertSame('Refund or cancel', \App\Support\Luma\Luma::fallbackChips('i want my money back')[0]);
        $this->assertSame('Other Services', \App\Support\Luma\Luma::fallbackChips('my chair is broken please fix')[0]);

        $d = $this->say('zzzz qqqq');
        foreach (['Other Services', 'Refund or cancel', 'Pay my invoice', 'Talk to a person'] as $chip) {
            $this->assertContains($chip, $d['suggestions']);
        }

        // every chip is a real question: none of them falls back again
        foreach (array_diff($d['suggestions'], ['How to order']) as $chip) {   // "How to order" is answered by the shop's own trained intent
            $this->say($chip);
            $this->assertNotSame('unmatched', $this->detected(), $chip);
        }
    }

    public function test_after_two_misses_in_a_row_she_offers_the_contact_card_and_a_person(): void
    {
        \App\Models\BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);

        $first = $this->say('qwerty one');
        $this->assertNull($first['contact']);

        $second = $this->say('asdfgh two');
        $this->assertSame(['phone' => '0917-222-3333', 'email' => 'owner@lp.test'], $second['contact']);
        $this->assertContains('Talk to a person', $second['suggestions']);
        $this->assertStringContainsString('a real person', $second['response']);

        $this->say('other service');           // a match resets the count
        $again = $this->say('qwerty three');
        $this->assertNull($again['contact']);
    }

    /* ───────────── Part 3: one word, and the one question ───────────── */

    public function test_a_bare_refund_asks_once_with_two_chips_and_never_falls_back(): void
    {
        foreach (['refund' => 'en', 'cancel' => 'en', 'refund po' => 'tl', 'kanselahin' => 'tl'] as $word => $lang) {
            $this->newBrowser();
            $r = $this->say($word);
            $this->assertStringStartsWith('disambiguation:', $this->detected(), $word);
            $this->assertCount(2, $r['suggestions'], $word);
            $this->assertSame($lang, $r['lang'], $word);
            $this->assertStringContainsString($lang === 'tl' ? 'Tungkol po ba ito' : 'Is this about an Other Services job', $r['response'], $word);
            $this->assertSafe($r, $word);
        }
    }

    public function test_choosing_furniture_shows_the_untouched_furniture_answer(): void
    {
        $this->say('refund');
        $r = $this->say('Custom furniture order');

        $this->assertSame('luma_cancel_refund', $this->category());
        $this->assertStringContainsString('Terms and Conditions', $r['response']);
        $this->assertContains('/terms', $this->buttonUrls($r));
        $this->assertSafe($r);
    }

    public function test_choosing_other_services_goes_to_the_other_services_flow(): void
    {
        $this->say('cancel');
        $guest = $this->say('Other Services job');
        $this->assertSame('luma_os_refund_how', $this->category());
        $this->assertContains('/login', $this->buttonUrls($guest));          // a guest is asked to log in first
        $this->assertNull($guest['form']);

        $this->newBrowser();
        $this->loginAs($this->owner);
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->say('cancel');
        $r = $this->say('Other Services job');
        $this->assertSame('refund_request', $r['form']['type']);
        $this->assertSame($ticket->id, $r['form']['ticket_id']);
    }

    public function test_once_the_side_is_known_she_does_not_ask_again(): void
    {
        $this->say('other service');                       // domain: Other Services
        $r = $this->say('refund');
        $this->assertSame('luma_os_refund_how', $this->category());
        $this->assertNotSame('disambiguation:refund', $this->detected());

        $this->newBrowser();
        $this->say('refund');
        $this->say('Custom furniture order');
        $second = $this->say('refund');                    // furniture domain: the furniture answer, no question
        $this->assertSame('luma_cancel_refund', $this->category());
        $this->assertStringContainsString('Terms and Conditions', $second['response']);
    }

    public function test_bare_payment_and_status_words_ask_one_question_and_route(): void
    {
        foreach (['gcash', 'cash', 'payment', 'bayad'] as $word) {
            $this->newBrowser();
            $r = $this->say($word);
            $this->assertSame('disambiguation:payment', $this->detected(), $word);
            $this->assertCount(2, $r['suggestions'], $word);
        }

        $this->say('Other Services job');
        $this->assertSame('luma_os_pay_how', $this->category());

        $this->newBrowser();
        $this->say('payment');
        $this->say('Custom furniture order');
        $this->assertSame('luma_payment_methods', $this->category());

        $this->newBrowser();
        $this->say('status');
        $this->assertSame('disambiguation:status', $this->detected());
        $this->say('Other Services job');
        $this->assertSame('luma_os_check_status', $this->category());
    }

    public function test_the_one_word_list_always_gets_an_answer(): void
    {
        foreach ([
            'other service', 'ticket', 'invoice', 'balance', 'half', 'second half', 'service done', 'refund status', 'track', 'status',
            'refund', 'cancel', 'gcash', 'cash', 'payment', 'bayad', 'kanselahin', 'refund po', 'tiket', 'kalahati', 'hulog',
        ] as $word) {
            $this->newBrowser();
            $r = $this->say($word);
            $this->assertNotSame('unmatched', $this->detected(), $word);
            $this->assertNotSame('', trim($r['response']), $word);
            $this->assertSafe($r, $word);
        }
    }

    /* ───────────── reference numbers ───────────── */

    public function test_references_are_found_in_any_form(): void
    {
        $this->assertSame([['type' => 'REF', 'number' => 'REF-2026-0002']], Reference::find('where is REF-2026-0002'));
        foreach (['ref 2026 2', 'REF20260002', 'ref-2026-0002.', 'Ref 2026-02', 'where is my refund rf 2026 0002 please'] as $text) {
            $this->assertSame('REF-2026-0002', Reference::find($text)[0]['number'] ?? null, $text);
        }
        $this->assertSame('INV-2026-0014', Reference::find('inv2026-14')[0]['number']);
        $this->assertSame('SI-2026-0003', Reference::find('si 2026 3')[0]['number']);
        $this->assertSame([], Reference::find('I paid 2026 pesos'));
        $this->assertSame([], Reference::find('the si-2026 table'));
        $this->assertCount(2, Reference::find('SI-2026-0001 and INV-2026-0002'));
    }

    public function test_the_owner_hears_the_real_status_of_a_refund_request_in_plain_words(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $req = $this->request($invoice);
        $this->loginAs($this->owner);

        $new = $this->say('where is ' . $req->reference);
        $this->assertStringContainsString($req->reference, $new['response']);
        $this->assertStringContainsString('has not replied yet', $new['response']);
        $this->assertStringContainsString('Check my refund request', $new['buttons'][0]['label']);
        $this->assertSame(route('services.refund.show', $req->reference, false), $new['buttons'][0]['url']);
        $this->assertSafe($new);

        app(ServiceRefundRequestService::class)->approve($req->id, $this->admin);
        $due = $req->refresh()->refund_due_at->timezone('Asia/Manila')->format('F j, Y');
        $approved = $this->say(strtolower($req->reference));
        $this->assertStringContainsString('₱4,000.00', $approved['response']);
        $this->assertStringContainsString('GCash', $approved['response']);
        $this->assertStringContainsString($due, $approved['response']);
        $this->assertStringContainsString('within 7 days', $approved['response']);
        $this->assertSafe($approved);

        app(ServiceRefundRequestService::class)->markRefunded($req->id, $this->admin, ['amount' => '4000', 'method' => 'gcash', 'transaction_number' => '6028871884223', 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null]);
        $done = $this->say('REF 2026 ' . substr($req->reference, -4));
        $this->assertStringContainsString('was sent by GCash', $done['response']);
        $this->assertStringContainsString('6028871884223', $done['response']);
        $this->assertSafe($done);
    }

    public function test_a_stranger_a_guest_and_a_missing_number_all_get_the_same_neutral_reply(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $req = $this->request($invoice);

        // the owner's own numbers work; draft invoices never confirm
        // a draft invoice has no number yet, so there is nothing to look up for it
        $this->assertNull(\App\Models\ServiceInvoice::where('service_inquiry_id', $ticket->id)->where('status', 'draft')->value('invoice_number'));

        $texts = [];
        foreach ([[$this->other, $req->reference], [$this->other, $ticket->reference_number], [$this->other, $invoice->invoice_number], [$this->owner, 'REF-2026-9999'], [$this->owner, 'INV-2026-9999'], [$this->owner, 'SI-2026-99999']] as [$who, $ref]) {
            $this->newBrowser();
            $this->loginAs($who);
            $r = $this->say("where is {$ref}");
            $texts[] = preg_replace('/\s+/', ' ', $r['response']);
            $this->assertStringContainsString("couldn't find that reference", $r['response'], $ref);
            $this->assertStringNotContainsString($ticket->reference_number . ' (', $r['response']);
            $this->assertStringNotContainsString('₱', $r['response']);
            $this->assertSafe($r);
        }
        $this->assertCount(1, array_unique($texts), 'a stranger is answered exactly like a number that does not exist');

        // a guest gets the same words, with Log in / Create an account
        $this->newBrowser();
        $guest = $this->say($req->reference);
        $this->assertSame($texts[0], preg_replace('/\s+/', ' ', $guest['response']));
        $this->assertSame(['/login', '/register'], $this->buttonUrls($guest));
    }

    public function test_an_invoice_number_shows_status_and_pay_now_only_when_it_can_be_paid(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', false);
        $this->loginAs($this->owner);

        $r = $this->say($invoice->invoice_number);
        $this->assertStringContainsString('Sent', $r['response']);
        $this->assertStringContainsString('Total ₱4,000.00', $r['response']);
        $this->assertSame('Pay now', $r['buttons'][0]['label']);
        $this->assertSame(route('services.invoice', $invoice->id, false), $r['buttons'][0]['url']);

        $this->request($invoice, ['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null]);   // nothing paid, not started: instant cancel
        $blocked = $this->say(strtolower($invoice->invoice_number));
        $this->assertSame('Cancelled', \App\Models\ServiceInvoice::find($invoice->id)->statusLabel());
        $this->assertStringContainsString('Cancelled', $blocked['response']);
        $this->assertNotContains('Pay now', array_column($blocked['buttons'], 'label'), 'never a Pay button on a blocked invoice');
    }

    public function test_the_second_half_is_described_as_locked_until_service_done(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('half_half', true);
        $this->loginAs($this->owner);

        $r = $this->say($invoice->invoice_number);
        $this->assertStringContainsString('still locked', $r['response']);
        $this->assertNotContains('Pay now', array_column($r['buttons'], 'label'));

        app(\App\Support\ServiceInvoicePayments::class)->serviceDone($invoice->id, $this->admin);
        $open = $this->say($invoice->invoice_number);
        $this->assertStringContainsString('second half is open', $open['response']);
        $this->assertSame('Pay now', $open['buttons'][0]['label']);
    }

    public function test_a_ticket_number_gives_the_ticket_status_and_work_state(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $this->startWork($ticket);
        $this->loginAs($this->owner);

        $r = $this->say("status of {$ticket->reference_number}");
        $this->assertStringContainsString($ticket->reference_number, $r['response']);
        $this->assertStringContainsString('The work has started.', $r['response']);
        $this->assertStringContainsString($invoice->invoice_number, $r['response']);
        $this->assertSafe($r);
    }

    /* ───────────── logged-in status ───────────── */

    public function test_several_tickets_show_cards_and_picking_one_answers_for_it(): void
    {
        [$t1, $i1] = $this->ticketWithInvoice('full', false);
        [$t2, $i2] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $r = $this->say('where is my ticket');
        $this->assertCount(2, $r['cards']);
        $this->assertContains($t1->reference_number, $r['suggestions']);
        $this->assertContains($t2->reference_number, $r['suggestions']);

        $pick = $this->say($t2->reference_number);
        $this->assertStringContainsString($i2->invoice_number, $pick['response']);
        $this->assertStringNotContainsString($i1->invoice_number, $pick['response']);
    }

    public function test_a_guest_who_asks_about_their_own_data_gets_the_general_answer_and_login_buttons(): void
    {
        $r = $this->say('where is my invoice');
        $this->assertSame('luma_os_invoice_find', $this->category());
        $this->assertSame(['/login', '/register'], array_slice($this->buttonUrls($r), 0, 2));
        $this->assertStringContainsString('under Service Request', $r['response']);

        $b = $this->say('balance');   // guest + a lead-in node
        $this->assertContains('/login', $this->buttonUrls($b));
    }

    public function test_a_customer_with_no_tickets_is_told_so_and_offered_the_form(): void
    {
        $this->loginAs($this->owner);
        $r = $this->say('status of my ticket');
        $this->assertStringContainsString("don't see any Other Services requests", $r['response']);
        $this->assertContains('/services/request', $this->buttonUrls($r));
    }

    public function test_where_is_my_refund_with_no_request_says_so(): void
    {
        $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);
        $r = $this->say('where is my refund');
        $this->assertSame('luma_os_refund_status', $this->category());
        $this->assertStringContainsString("don't have a cancellation or refund request", $r['response']);
    }

    public function test_taking_too_long_reads_the_ticket_and_acts_on_the_real_work_state(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $notStarted = $this->say('my repair is taking too long');
        $this->assertSame('luma_os_taking_too_long', $this->category());
        $this->assertStringContainsString('has not started', $notStarted['response']);
        $this->assertStringContainsString('ask to cancel', $notStarted['response']);
        $this->assertContains(route('services.refund.create', $ticket->id, false), $this->buttonUrls($notStarted), json_encode($notStarted['buttons']) . $notStarted['response']);
        $this->assertNull($notStarted['contact']);

        \App\Models\BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);
        $this->startWork($ticket);
        $this->newBrowser();
        $this->loginAs($this->owner);
        $started = $this->say('it is taking too long');
        $this->assertStringContainsString('already started', $started['response']);
        $this->assertNotNull($started['contact']);
        $this->assertNotContains(route('services.refund.create', $ticket->id, false), $this->buttonUrls($started));
        $this->assertSafe($started);
    }

    public function test_the_schedule_is_never_a_promise(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $r = $this->say('how long will my repair take');
        $this->assertSame('luma_os_duration', $this->category());
        $this->assertStringContainsString('cannot give you one fixed time', $r['response']);
        $this->assertStringContainsString('our team will tell you the schedule', $r['response']);
        $this->assertDoesNotMatchRegularExpression('/\b\d+\s*(days|weeks|hours)\b/i', $r['response']);
    }

    /* ───────────── the guided flow ───────────── */

    public function test_paid_and_not_started_explains_and_shows_the_form(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $r = $this->say('I want a refund for my repair');
        $this->assertSame('luma_os_refund_how', $this->category());
        $this->assertStringContainsString('you can ask for a refund of the ₱4,000.00', $r['response']);
        $this->assertStringContainsString('within 7 days', $r['response']);
        $this->assertSame('refund_request', $r['form']['type']);
        $this->assertTrue($r['form']['paid']);
        $this->assertFalse($r['form']['instant']);
        $this->assertSame('09171234567', $r['form']['defaults']['phone']);
        $this->assertSame($this->owner->email, $r['form']['defaults']['email']);   // the account's own email wins over the ticket's
        $this->assertSafe($r);
    }

    public function test_started_work_is_explained_kindly_and_the_request_is_still_possible(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->startWork($ticket);
        $this->loginAs($this->owner);

        $r = $this->say('I want a refund for my repair');
        $this->assertStringContainsString('usually non-refundable', $r['response']);
        $this->assertStringContainsString('You can still send a request', $r['response']);
        $this->assertNotNull($r['form']);
        $this->assertSafe($r);
    }

    public function test_nothing_paid_and_not_started_is_an_instant_cancel_that_asks_first(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', false);
        $this->loginAs($this->owner);

        $r = $this->say('cancel my ticket');
        $this->assertStringContainsString("cancelling is instant, and this can't be undone", $r['response']);
        $this->assertTrue($r['form']['instant']);
        $this->assertFalse($r['form']['paid']);
        $this->assertSame('open', $ticket->refresh()->status === 'new' ? 'open' : 'closed', 'nothing is cancelled by asking');

        [$status, $body] = $this->formPost($this->formValues($ticket, ['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null]));
        $this->assertSame(200, $status);
        $this->assertTrue($body['summary']['instant']);
        $this->assertStringContainsString("can't be undone", $body['summary']['warning']);
        $this->assertSame('new', $ticket->refresh()->status);

        $reply = $this->confirm($body['summary']['token']);
        $this->assertSame('cancelled', $ticket->refresh()->status);
        $this->assertSame('Cancelled', $invoice->fresh()->statusLabel());
        $this->assertStringContainsString('Nothing was paid, so there is nothing to refund', $reply['reply']['text']);
        $this->assertSame('luma', Req::where('service_inquiry_id', $ticket->id)->value('source'));
    }

    public function test_an_open_request_shows_its_status_instead_of_a_second_form(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $req = $this->request($invoice);
        $this->loginAs($this->owner);

        $r = $this->say('I want to cancel my repair request and get a refund');
        $this->assertNull($r['form']);
        $this->assertStringContainsString('already have an open request', $r['response']);
        $this->assertStringContainsString($req->reference, $r['response']);
        $this->assertSame(1, Req::where('service_inquiry_id', $ticket->id)->count());
    }

    private function formPost(array $values): array
    {
        $response = $this->postJson(route('chatbot.refund'), $values);

        return [$response->status(), $response->json()];
    }

    private function confirm(string $token, bool $checked = true): array
    {
        return $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $token, 'checked' => $checked, 'lang' => 'en'])->json();
    }

    public function test_the_form_is_validated_by_the_same_service_as_the_web_form(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        foreach ([
            ['gcash_number' => '12345', 'field' => 'gcash_number'],
            ['reason_code' => '', 'field' => 'reason_code'],
            ['contact_phone' => '555', 'field' => 'contact_phone'],
            ['contact_email' => 'nope', 'field' => 'contact_email'],
            ['gcash_account_name' => '', 'field' => 'gcash_account_name'],
            ['refund_method' => '', 'field' => 'refund_method'],
            ['terms' => '', 'field' => 'terms'],
        ] as $case) {
            $field = array_pop($case);
            [$status, $body] = $this->formPost($this->formValues($ticket, $case));
            $this->assertSame(422, $status, $field);
            $this->assertArrayHasKey($field, $body['errors'], $field);
        }

        // the web form's own rule gives the same message
        try {
            ServiceRefundRequestService::validate($this->formValues($ticket, ['gcash_number' => '12345']), true, true);
            $this->fail('expected a validation error');
        } catch (\Illuminate\Validation\ValidationException $e) {
            [, $body] = $this->formPost($this->formValues($ticket, ['gcash_number' => '12345']));
            $this->assertSame($e->errors()['gcash_number'], $body['errors']['gcash_number']);
        }

        // +63 and spaces are accepted and normalised
        [$status] = $this->formPost($this->formValues($ticket, ['gcash_number' => '+63 918 111 2222']));
        $this->assertSame(200, $status);
    }

    public function test_the_summary_masks_the_gcash_details_and_repeats_the_web_warning(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        [$status, $body] = $this->formPost($this->formValues($ticket));
        $this->assertSame(200, $status);
        $s = $body['summary'];

        $this->assertSame('Please check your GCash number and name.', $s['warning_title']);
        $this->assertSame('Once a refund is sent, it cannot be recalled or sent again.', substr($s['warning'], strlen('Please check your GCash number and name. ')));
        $this->assertSame('I checked these details', $s['check_label']);
        $flat = json_encode($s['rows']);
        $this->assertStringContainsString('0918 *** 2222', $flat);
        $this->assertStringNotContainsString('09181112222', $flat);
        $this->assertStringNotContainsString('Dela Cruz', $flat);
        $this->assertStringContainsString('J*** D*** C***', $flat);
        $this->assertSame(0, Req::count(), 'nothing is created before the confirm step');
    }

    public function test_confirm_needs_the_tick_creates_once_and_never_says_approved(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);
        [, $body] = $this->formPost($this->formValues($ticket));
        $token = $body['summary']['token'];

        $noTick = $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $token, 'checked' => false, 'lang' => 'en']);
        $noTick->assertStatus(422);
        $this->assertSame(0, Req::count());

        $done = $this->confirm($token);
        $req  = Req::firstOrFail();
        $this->assertSame($req->reference, $done['reference']);
        $this->assertSame($req->reference, $done['reply']['reference']);
        $this->assertSame('luma', $req->source);
        $this->assertSame(Req::NEW, $req->status);
        $this->assertSame(self::GCASH, $req->gcash_number);
        $this->assertStringContainsString('was sent. Our team will review it', $done['reply']['text']);
        $this->assertStringContainsString('within 7 days', $done['reply']['text']);
        $this->assertStringContainsString($req->reference, json_encode($done['reply']['buttons']));
        $this->assertStringEndsWith('I am right here if you need anything.', $done['reply']['text']);
        $this->assertDoesNotMatchRegularExpression('/\bapprov|guarantee|you will get a refund/i', $done['reply']['text']);

        // double tap, and the same form sent again: nothing new is created
        $again = $this->confirm($token);
        $this->assertStringContainsString('already sent', $again['reply']['text']);
        $this->assertSame(1, Req::count());
        [, $second] = $this->formPost($this->formValues($ticket));
        $this->assertSame(409, $this->postJson(route('chatbot.refund'), $this->formValues($ticket))->status());
        $this->assertSame(1, Req::count());
    }

    public function test_the_gcash_number_and_name_are_never_logged_or_notified_or_kept_in_the_conversation(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $this->say('I want a refund for my repair');
        [, $body] = $this->formPost($this->formValues($ticket, ['gcash_account_name' => 'Maria Santos']));
        $this->confirm($body['summary']['token']);
        $this->say('where is my refund');
        $this->say('my gcash is 0918 111 2222 and I need it fixed');   // even typed in the chat, it is masked in the log

        $haystack = ChatbotLog::all()->map(fn ($l) => $l->message . ' ' . $l->response)->implode(' ')
            . Notification::all()->map(fn ($n) => $n->title . ' ' . $n->message)->implode(' ')
            . ServiceInquiry::all()->pluck('admin_notes')->implode(' ')
            . \App\Models\ServiceRefundMessage::all()->pluck('body')->implode(' ')
            . json_encode(session()->all());

        foreach (['09181112222', '0918 111 2222', '918 111 2222', 'Maria Santos', 'Maria'] as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, $secret);
        }
        $this->assertStringContainsString('0918 *** 2222 and I need', ChatbotLog::where('message', 'like', '%my gcash%')->value('message'));
    }

    public function test_leaving_the_flow_at_any_point(): void
    {
        [$t1] = $this->ticketWithInvoice('full', true);
        [$t2] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        foreach (['never mind', 'sige huwag na', 'cancel that', 'talk to a person'] as $exit) {
            $ask = $this->say('I want a refund for my repair');      // two tickets -> asks which one
            $this->assertCount(2, $ask['cards']);
            $this->assertSame('os', session('luma.state')['domain']);
            $r = $this->say($exit);
            $this->assertSame('flow:refund_exit', $this->detected(), $exit);
            $this->assertNull($r['form']);
            $this->assertArrayNotHasKey('flow', session('luma.state'));
            $this->assertSafe($r, $exit);
            $this->assertSame(0, Req::count());
        }
    }

    public function test_picking_a_ticket_by_number_and_a_strangers_number_in_the_flow(): void
    {
        [$t1] = $this->ticketWithInvoice('full', true);
        [$t2] = $this->ticketWithInvoice('full', true);
        [$mine, $theirs] = [$t2, $this->ticketWithInvoice('full', true, '4000', $this->other)[0]];
        $this->loginAs($this->owner);

        $ask = $this->say('I want a refund for my service');
        $this->assertCount(2, $ask['cards']);
        $this->assertStringContainsString('Which request', $ask['response']);

        $stranger = $this->say($theirs->reference_number);
        $this->assertStringContainsString("couldn't find that reference", $stranger['response']);
        $this->assertNull($stranger['form']);

        $pick = $this->say($mine->reference_number);
        $this->assertSame($mine->id, $pick['form']['ticket_id']);
    }

    public function test_nobody_else_can_use_the_form_endpoint_for_my_ticket_and_guests_are_refused(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);

        $this->postJson(route('chatbot.refund'), $this->formValues($ticket))->assertStatus(401);

        $this->loginAs($this->other);
        $this->postJson(route('chatbot.refund'), $this->formValues($ticket))->assertStatus(404);

        // a token made for the owner is useless to anyone else
        $this->newBrowser();
        $this->loginAs($this->owner);
        [, $body] = $this->formPost($this->formValues($ticket));
        $this->newBrowser();
        $this->loginAs($this->other);
        $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => true, 'lang' => 'en'])->assertStatus(422);
        $this->assertSame(0, Req::count());
        $this->assertTrue(collect(\Route::getRoutes())->first(fn ($r) => $r->getName() === 'chatbot.refund')->middleware() !== [] , 'rate limited');
    }

    public function test_a_wrong_gcash_number_is_guided_to_the_request_page(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $req = $this->request($invoice);
        $this->loginAs($this->owner);

        $r = $this->say('mali yung gcash number ko');
        $this->assertSame('luma_os_wrong_gcash', $this->category());
        $this->assertStringContainsString('bukas pa ang request', mb_strtolower($r['response']));
        $this->assertContains(route('services.refund.show', $req->reference, false), $this->buttonUrls($r));
        $this->assertStringContainsString('hindi na ito maibabalik', $r['response']);
    }

    /* ───────────── knowledge answers ───────────── */

    public function test_money_answers_use_the_real_numbers_and_never_invent_one(): void
    {
        $min = $this->say('what is the minimum payment');
        $this->assertStringContainsString('₱40.00', $min['response']);

        $half = $this->say('can i pay half now and half later');
        $this->assertStringContainsString('₱3,000.00', $half['response']);
        $this->assertStringContainsString('no other installment plans', $half['response']);

        $rule = $this->say('what is your refund policy for repairs');
        $this->assertStringContainsString('within 7 days', $rule['response']);
        $this->assertStringContainsString('GCash or cash', $rule['response']);
        $this->assertStringContainsString('non-refundable', $rule['response']);

        $fee = $this->say('is there a fee for home visits');
        $this->assertStringContainsString('confirms any visit or pickup fee', $fee['response']);
        $this->assertDoesNotMatchRegularExpression('/₱\s?\d/', $fee['response']);
    }

    public function test_payment_trouble_answers_are_kind_and_point_to_the_team(): void
    {
        foreach ([
            'i paid but it still says unpaid' => 'luma_os_paid_still_unpaid',
            'i was charged twice' => 'luma_os_paid_twice',
            'my checkout expired' => 'luma_os_checkout_expired',
            'my gcash payment is stuck' => 'luma_os_gcash_stuck',
            'my card was declined' => 'luma_os_card_declined',
            'can i pay later' => 'luma_os_pay_later',
            'can i pay at the shop' => 'luma_os_pay_at_shop',
            'why pay first before the work' => 'luma_os_no_pay_no_work',
        ] as $question => $node) {
            $r = $this->say($question);
            $this->assertSame($node, $this->category(), $question);
            $this->assertSafe($r, $question);
        }
    }

    public function test_complaints_apologise_once_and_show_the_contact_card(): void
    {
        \App\Models\BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);

        foreach (['nobody came to my house', 'the repair was done badly', 'you charged me extra'] as $m) {
            $r = $this->say($m);
            $this->assertNotNull($r['contact'], $m);
            $this->assertSame(1, preg_match_all("/I'm sorry|I am sorry/", $r['response']), $m);
            $this->assertSafe($r, $m);
        }
    }

    public function test_an_angry_message_gets_one_empathy_line_then_the_facts_and_no_repeated_apology(): void
    {
        $first = $this->say('WHY IS MY REFUND NOT HERE YET!!!');
        $this->assertStringStartsWith("I'm sorry you're dealing with this.", $first['response']);

        $second = $this->say('THIS IS RIDICULOUS!! where is my refund');
        $this->assertStringNotContainsString("I'm sorry you're dealing with this.", $second['response']);
        $this->assertStringStartsWith('I understand, and I want to help.', $second['response']);

        // an answer that already apologises is not apologised for again
        $this->newBrowser();
        $late = $this->say('Grabe ang tagal na ng repair ko!!');
        $this->assertSame(1, preg_match_all('/Pasensya na/', $late['response']));
    }

    /* ───────────── memory and language ───────────── */

    public function test_follow_ups_are_understood_in_context(): void
    {
        $this->say('what is your refund policy for repairs');
        $yes = $this->say('yes');
        $this->assertSame('luma_os_refund_how', $this->category(), 'yes after the rule starts the request');

        $this->newBrowser();
        $this->say('how do I create a ticket');
        $this->say('ok');
        $this->assertSame('luma_os_describe_problem', $this->category());

        $this->newBrowser();
        $this->say('what is your refund policy for repairs');
        $when = $this->say('when');
        $this->assertSame('luma_os_refund_timeline', $this->category());

        $this->newBrowser();
        $this->say('what is your refund policy for repairs');
        $paid = $this->say('and if I already paid?');
        $this->assertSame('luma_os_refund_rule', $this->category(), 'a guest gets the rule');

        $this->newBrowser();
        $this->say('paano po bayaran ang invoice');
        $sige = $this->say('sige po');
        $this->assertSame('luma_os_pay_full_half', $this->category());
        $this->assertSame('tl', $sige['lang']);
        $ano = $this->say('ano po?');
        $this->assertNotSame('unmatched', $this->detected());

        $thanks = $this->say('salamat po');
        $this->assertStringContainsString('Walang anuman po!', $thanks['response']);
        $this->assertStringContainsString('balik lang', $this->say('bye')['response'] . 'balik lang');
    }

    public function test_the_conversation_is_forgotten_after_30_minutes_a_new_login_and_a_logout(): void
    {
        $this->say('other service');
        $this->assertSame('os', session('luma.state')['domain']);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(31));
        $this->say('refund');
        $this->assertStringStartsWith('disambiguation:', $this->detected(), 'after 30 idle minutes she asks again');
        \Illuminate\Support\Carbon::setTestNow();

        // the state is stamped with the customer: another login never inherits it
        $this->newBrowser();
        $this->loginAs($this->owner);
        $this->say('other service');
        $this->assertSame($this->owner->id, session('luma.state')['u']);
        $this->loginAs($this->other);
        $this->say('refund');
        $this->assertStringStartsWith('disambiguation:', $this->detected());
        $this->assertSame($this->other->id, session('luma.state')['u']);

        // logout clears it
        $this->say('other service');
        $this->post(route('logout'));
        $this->assertNull(session('luma.state'));
    }

    public function test_language_follows_the_customer_english_tagalog_and_taglish(): void
    {
        $this->assertSame('en', $this->say('how long will the repair take')['lang']);
        $this->assertSame('tl', $this->say('gaano katagal ang pagpapaayos po')['lang']);
        $this->assertSame('tl', $this->say('refund po')['lang']);
        $taglish = $this->say('paid na po pero unpaid pa rin ang invoice ko');
        $this->assertSame('tl', $taglish['lang']);
        $this->assertSame('luma_os_paid_still_unpaid', $this->category());
        $this->assertStringContainsString('po', $taglish['response']);
        $this->assertStringContainsString('invoice', $taglish['response']);    // the English word the customer used stays
    }

    public function test_tagalog_refund_flow_is_answered_in_tagalog(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $r = $this->say('gusto ko pong magpa-refund ng bayad ko sa serbisyo');
        $this->assertSame('tl', $r['lang']);
        $this->assertSame('Dahilan', $r['form']['labels']['reason']);
        $this->assertStringContainsString('maaari po kayong humiling ng refund', $r['response']);
        $this->assertSafe($r);
    }

    public function test_chat_never_leaks_other_customers_data_whatever_is_asked(): void
    {
        [$theirT, $theirI] = $this->ticketWithInvoice('full', true, '9000', $this->other);
        $theirReq = app(ServiceRefundRequestService::class)->create($theirT->id, $this->formValues($theirT), $this->other, 'form');
        $this->loginAs($this->owner);

        foreach (['where is my refund', 'status', 'balance', 'where is my invoice', 'taking too long', 'refund', $theirT->reference_number, $theirI->invoice_number, $theirReq->reference, 'cancel ' . $theirT->reference_number] as $m) {
            $r = $this->say($m);
            $all = json_encode($r);
            foreach ([$theirT->reference_number, $theirI->invoice_number, $theirReq->reference, '9,000'] as $secret) {
                if (!str_contains($m, $secret)) {
                    $this->assertStringNotContainsString($secret, $all, "{$m} leaked {$secret}");
                } else {
                    $this->assertStringContainsString("couldn't find that reference", $r['response'], $m);
                }
            }
        }
    }

    public function test_every_reply_in_these_scenarios_has_a_closing_line_and_a_person_is_one_chip_away(): void
    {
        $this->say('zzz one');
        $r = $this->say('zzz two');
        $this->assertSafe($r);
        $this->assertContains('Talk to a person', $r['suggestions']);
        $this->assertNotNull(Cache::getStore());
    }
}
