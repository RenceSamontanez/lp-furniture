<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\ServiceRefundRequest as Req;
use App\Support\ServiceRefundRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\TalksToLuma;
use Tests\TestCase;

/**
 * Stage 21i: more multi-turn Luma scenarios, end to end through the real routes: picking by invoice number, a started job,
 * Tagalog and Taglish, the "Ask Luma about this" button on a notification, a refund that did not go through, a declined
 * request, the rate limit, and the widget's own safety.
 */
class LumaScenariosTest extends TestCase
{
    use RefreshDatabase;
    use TalksToLuma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLuma();
    }

    private function review(array $values): array
    {
        $r = $this->postJson(route('chatbot.refund'), $values);

        return [$r->status(), $r->json()];
    }

    public function test_pick_by_typing_an_invoice_number_then_send_the_request(): void
    {
        [$t1, $i1] = $this->ticketWithInvoice('full', true);
        [$t2, $i2] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $ask = $this->say('I want a refund for my repair');
        $this->assertCount(2, $ask['cards']);
        $this->assertNull($ask['form']);

        $pick = $this->say('can it be ' . $i1->invoice_number . ' please');
        $this->assertSame($t1->id, $pick['form']['ticket_id']);

        [$status, $body] = $this->review($this->formValues($t1));
        $this->assertSame(200, $status);
        $done = $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => true, 'lang' => 'en'])->json();
        $req = Req::firstOrFail();
        $this->assertSame($t1->id, $req->service_inquiry_id);
        $this->assertSame($req->reference, $done['reply']['reference']);

        // the conversation moved on: asking about the other one still works and is not on hold
        $other = $this->say($t2->reference_number);
        $this->assertStringNotContainsString('paused', $other['response']);
    }

    public function test_started_work_can_still_be_submitted_and_is_recorded_as_started(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $this->startWork($ticket);
        $this->loginAs($this->owner);

        $r = $this->say('cancel my ticket');
        $this->assertStringContainsString('usually non-refundable', $r['response']);

        [, $body] = $this->review($this->formValues($ticket, ['reason_code' => 'taking_too_long']));
        $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => true, 'lang' => 'en'])->assertOk();

        $req = Req::firstOrFail();
        $this->assertSame('started', $req->work_state_snapshot);
        $this->assertSame(Req::NEW, $req->status, 'it waits for the team: nothing was decided in chat');
        $this->assertNotNull($ticket->fresh()->openRefundRequest(), 'the request holds the ticket');
    }

    public function test_the_whole_flow_in_tagalog(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $r = $this->say('gusto ko pong ikansela ang service request ko at i-refund');
        $this->assertSame('tl', $r['lang']);
        $this->assertSame('Humiling ng pagkansela o refund', $r['form']['title']);
        $this->assertSame('GCash number', $r['form']['labels']['gcash_number']);

        [$status, $body] = $this->review($this->formValues($ticket, ['lang' => 'tl']));
        $this->assertSame('Suriin ang request ninyo', $body['summary']['title']);
        $this->assertSame('Sinuri ko ang mga detalyeng ito', $body['summary']['check_label']);
        $this->assertSame('Pakisuri po ang GCash number at pangalan ninyo.', $body['summary']['warning_title']);
        $this->assertSame('Kapag naipadala na ang refund, hindi na ito maibabalik o maipapadala ulit.', $body['summary']['warning']);

        $no = $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => false, 'lang' => 'tl']);
        $this->assertSame('Pakitiyak muna na sinuri ninyo ang mga detalye.', $no->json('errors.check.0'));

        $done = $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => true, 'lang' => 'tl'])->json();
        $this->assertStringContainsString('Naipadala na po ang request ninyo', $done['reply']['text']);
        $this->assertStringContainsString('7 araw', $done['reply']['text']);
        $this->assertStringNotContainsString('Nandito lang po ako', $done['reply']['text']);   // no sign-off line
        $this->assertDoesNotMatchRegularExpression('/approv|guarantee/i', $done['reply']['text']);
    }

    public function test_ask_luma_about_this_from_a_notification_end_to_end(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('full', true);
        $req = app(ServiceRefundRequestService::class)->create($ticket->id, $this->formValues($ticket), $this->owner, 'form');
        $note = Notification::where('user_id', $this->owner->id)->where('message', 'like', '%' . $req->reference . '%')->latest('id')->firstOrFail();

        $this->loginAs($this->owner);
        $page = $this->get(route('notifications.show', $note->id))->assertOk()->assertSee('Ask Luma about this');
        preg_match('/id="nd-ask-luma" data-ref="([^"]+)"/', $page->getContent(), $m);
        $this->assertSame($req->reference, $m[1] ?? null);
        $page->assertSee('sendChat()', false);   // the button opens the chat and sends the number

        $reply = $this->say($m[1]);
        $this->assertStringContainsString($req->reference, $reply['response']);
        $this->assertStringContainsString('has not replied yet', $reply['response']);
        $this->assertSame(route('services.refund.show', $req->reference, false), $reply['buttons'][0]['url']);
    }

    public function test_a_refund_that_did_not_go_through_and_a_declined_request_are_explained_with_the_next_step(): void
    {
        \App\Models\BusinessSetting::query()->first()->update(['phone' => '0917-222-3333', 'email' => 'owner@lp.test']);
        [$t1, $i1] = $this->ticketWithInvoice('full', true);
        $svc = app(ServiceRefundRequestService::class);
        $r1 = $svc->create($t1->id, $this->formValues($t1), $this->owner, 'form');
        $svc->approve($r1->id, $this->admin);
        $svc->markRefundFailed($r1->id, $this->admin, 'wrong_gcash_number', null);

        $this->loginAs($this->owner);
        $problem = $this->say($r1->reference);
        $this->assertStringContainsString('problem sending the refund', $problem['response']);
        $this->assertStringContainsString('correct GCash number', $problem['response']);
        $this->assertSame(route('services.refund.show', $r1->reference, false), $problem['buttons'][0]['url']);

        [$t2] = $this->ticketWithInvoice('full', true);
        $this->startWork($t2);
        $r2 = $svc->create($t2->id, $this->formValues($t2), $this->owner, 'form');
        $svc->decline($r2->id, $this->admin, 'Work has already started, so we cannot refund it.');
        $declined = $this->say($r2->reference);
        $this->assertStringContainsString('declined', $declined['response']);
        $this->assertNotNull($declined['contact'], 'a declined request shows the contact card');
        $this->assertDoesNotMatchRegularExpression('/approv|guarantee/i', $declined['response']);
    }

    public function test_logged_in_payment_questions_get_the_real_numbers_and_never_a_pay_button_when_blocked(): void
    {
        [$ticket, $invoice] = $this->ticketWithInvoice('half_half', true);
        $this->loginAs($this->owner);

        $locked = $this->say('how much do i still owe');
        $this->assertStringContainsString('balance ₱2,000.00', $locked['response']);
        $this->assertStringContainsString('still locked', $locked['response']);
        $this->assertNotContains('Pay now', array_column($locked['buttons'], 'label'));

        app(\App\Support\ServiceInvoicePayments::class)->serviceDone($invoice->id, $this->admin);
        $open = $this->say('when can i pay the second half');
        $this->assertStringContainsString('second half is open', $open['response']);
        $this->assertSame('Pay now', $open['buttons'][0]['label']);

        $held = app(ServiceRefundRequestService::class)->create($ticket->id, $this->formValues($ticket), $this->owner, 'form');
        $blocked = $this->say('my checkout expired');
        $this->assertStringContainsString('paused', $blocked['response']);
        $this->assertNotContains('Pay now', array_column($blocked['buttons'], 'label'), 'a cancellation request holds the invoice');
        $this->assertSame($held->reference, Req::first()->reference);
    }

    public function test_the_form_endpoint_is_rate_limited(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $codes = [];
        for ($i = 0; $i < 22; $i++) {
            $codes[] = $this->postJson(route('chatbot.refund'), $this->formValues($ticket, ['reason_code' => '']))->status();
        }
        $this->assertSame(422, $codes[0]);
        $this->assertSame(429, end($codes));
    }

    public function test_the_widget_draws_the_form_with_text_nodes_and_the_shared_button_and_notice_classes(): void
    {
        $js = file_get_contents(public_path('assets/main.js'));
        $start = strpos($js, 'function lumaRefundForm');
        $end   = strpos($js, '/* Add a message bubble from the bot side.');
        $form  = substr($js, $start, $end - $start);

        $this->assertStringNotContainsString('innerHTML', $form, 'customer-typed values never go through innerHTML');
        $this->assertStringContainsString('lp-btn lp-btn--primary', $form);
        $this->assertStringContainsString('lp-btn lp-btn--secondary', $form);
        $this->assertStringContainsString('lumaNotice(', $form);
        $this->assertStringContainsString("lp-notice lp-notice--", $js);

        $css = file_get_contents(public_path('assets/style.css'));
        $this->assertStringContainsString('.chatbot-window .chat-form', $css, 'scoped under .chatbot-window');
        $this->assertDoesNotMatchRegularExpression('/^\.chat-form[^-]/m', $css);
        $this->assertStringContainsString('min-height: 44px', substr($css, strpos($css, 'Luma form card')));
    }

    public function test_the_chat_log_never_holds_a_gcash_shaped_number_from_the_form_or_the_chat(): void
    {
        [$ticket] = $this->ticketWithInvoice('full', true);
        $this->loginAs($this->owner);

        $this->say('send my refund to 09181112222 or +63 918 111 2222');
        [, $body] = $this->review($this->formValues($ticket));
        $this->postJson(route('chatbot.refund'), ['step' => 'confirm', 'token' => $body['summary']['token'], 'checked' => true, 'lang' => 'en']);

        $logs = \App\Models\ChatbotLog::all()->map(fn ($l) => $l->message . ' ' . $l->response . ' ' . $l->intent_detected)->implode("\n");
        $this->assertDoesNotMatchRegularExpression('/09\d{9}|\+?639\d{9}|9\d{2}\s?\d{3}\s?\d{4}/', $logs);
        $this->assertStringContainsString('0918 *** 2222', $logs);
    }
}
