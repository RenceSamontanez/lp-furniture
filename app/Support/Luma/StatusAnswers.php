<?php

namespace App\Support\Luma;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceRefundAttempt;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceRefundRequestService;
use App\Support\ServiceWorkState;
use Illuminate\Support\Facades\Route;

/**
 * What Luma tells a signed-in customer about THEIR OWN Other Services tickets, invoices and cancellation requests,
 * in plain words, ending with the right next step as a button.
 *
 * Privacy rule that everything here follows: every query is scoped to the signed-in customer's id in the query itself,
 * and "it belongs to somebody else", "it does not exist" and "it is still a draft" all take the same path to the same
 * neutral reply, so no reference number is ever confirmed to exist for the wrong person.
 *
 * Tone (see docs/luma-tone-rules.md): never "approved", never "guaranteed", never a promised refund or date. A reply
 * says what the record says, and the buttons only offer what the code allows (a Pay button only when
 * ServiceInvoice::paymentBlockReason() is null).
 */
final class StatusAnswers
{
    /* ───────────── references typed or pasted ───────────── */

    /** @param array{type: string, number: string} $ref */
    public static function forReference(array $ref, ?User $user, string $lang): array
    {
        if ($user) {
            $found = match ($ref['type']) {
                'REF' => self::ownRequest($ref['number'], $user),
                'INV' => self::ownInvoice($ref['number'], $user),
                default => self::ownTicket($ref['number'], $user),
            };

            if ($found instanceof Req) {
                return self::requestAnswer($found, $lang);
            }
            if ($found instanceof ServiceInvoice) {
                return self::invoiceAnswer($found, $lang);
            }
            if ($found instanceof ServiceInquiry) {
                return self::ticketAnswer($found, $lang);
            }
        }

        return self::notFound($user, $lang);
    }

    /** The one reply for "no such reference for you": identical whether it is missing, a draft or someone else's. */
    public static function notFound(?User $user, string $lang): array
    {
        $tl = $lang === Language::TL;
        $r  = self::blank($tl
            ? "Hindi ko po makita ang reference number na iyan sa inyong account. Pakitingnan po ang numero, o mag-log in sa account na ginamit ninyo sa pagpapadala ng request. Kung kailangan pa rin ninyo ng tulong, maaaring tingnan ito ng aming team. " . Contact::sentence($lang)
            : "I couldn't find that reference number under your account. Please check the number, or log in to the account you used to send the request. If you still need help, our team can look into it. " . Contact::sentence($lang));

        $r['buttons'] = $user
            ? [self::btn($tl ? 'Buksan ang aking service requests' : 'Open my service requests', 'services.request')]
            : [self::btn($tl ? 'Mag-log in' : 'Log in', 'login'), self::btn($tl ? 'Gumawa ng account' : 'Create an account', 'register')];
        $r['contact'] = self::hasContact();

        return $r;
    }

    private static function ownTicket(string $number, User $user): ?ServiceInquiry
    {
        return ServiceInquiry::where('user_id', $user->id)->where('reference_number', $number)->first();
    }

    private static function ownInvoice(string $number, User $user): ?ServiceInvoice
    {
        return ServiceInvoice::visibleToCustomer()->where('invoice_number', $number)->where('user_id', $user->id)
            ->whereHas('inquiry', fn ($q) => $q->where('user_id', $user->id))->first()
            ?? ServiceInvoice::where('status', ServiceInvoice::VOID)->where('invoice_number', $number)->where('user_id', $user->id)
                ->whereHas('inquiry', fn ($q) => $q->where('user_id', $user->id))->first();
    }

    private static function ownRequest(string $reference, User $user): ?Req
    {
        return Req::where('user_id', $user->id)->where('reference', $reference)->first();
    }

    /* ───────────── one request ───────────── */

    public static function requestAnswer(Req $req, string $lang): array
    {
        $tl      = $lang === Language::TL;
        $ticket  = $req->inquiry;
        $amount  = ServiceInvoiceDesk::peso($req->amount_paid_snapshot);
        $paid    = (float) $req->amount_paid_snapshot > 0;
        $method  = $req->refund_method === 'gcash' ? 'GCash' : ($req->refund_method === 'cash' ? ($tl ? 'cash' : 'cash') : null);
        $due     = $req->refund_due_at?->timezone(ServiceRefundRequestService::TZ)->format('F j, Y');
        $sentTx  = $req->attempts()->where('outcome', ServiceRefundAttempt::SENT)->latest('id')->first();
        $for     = $tl ? "para sa service request {$ticket->reference_number}" : "for service request {$ticket->reference_number}";
        $head    = $tl ? "Ang request ninyo na {$req->reference} {$for}" : "Your request {$req->reference} {$for}";

        $lines = match ($req->status) {
            Req::NEW => $tl
                ? "{$head} ay natanggap po namin at hindi pa sumasagot ang aming team. Naka-pause po ang mga bayad sa serbisyong ito habang sinusuri."
                : "{$head} was received and our team has not replied yet. Payments on this service are paused while they review it.",
            Req::NEEDS_INFO => $tl
                ? "{$head} ay nangangailangan ng dagdag na impormasyon mula sa inyo. Buksan po ang request at mag-reply."
                : "{$head} needs more information from you. Please open the request and reply.",
            Req::APPROVED => $tl
                ? "May sagot na po ang aming team sa {$req->reference}." . ($paid ? " Ang refund na {$amount} ay dapat maipadala sa {$method}" . ($due ? " bago ang {$due}" : '') . ' (sa loob ng ' . Req::REFUND_DAYS . ' araw pagkatapos ng kumpirmasyon).' : '')
                : "Our team has replied to {$req->reference}." . ($paid ? " The refund of {$amount} is due to be sent to you by {$method}" . ($due ? " by {$due}" : '') . ' (within ' . Req::REFUND_DAYS . ' days after they confirmed it).' : ''),
            Req::REFUNDED => $tl
                ? "Naipadala na po ang refund na {$amount} para sa {$req->reference} sa {$method}." . ($sentTx?->transaction_number ? " Transaction number: {$sentTx->transaction_number}." : '')
                : "The refund of {$amount} for {$req->reference} was sent by {$method}." . ($sentTx?->transaction_number ? " Transaction number: {$sentTx->transaction_number}." : ''),
            Req::REFUND_PROBLEM => $tl
                ? "May problema sa pagpapadala ng refund para sa {$req->reference}. Buksan po ang request para makita ang detalye at mag-reply (halimbawa, ang tamang GCash number)."
                : "There was a problem sending the refund for {$req->reference}. Please open the request to see the details and reply, for example with the correct GCash number.",
            Req::DECLINED => $tl
                ? "Hindi po natuloy ang {$req->reference}. Nakasulat sa pahina ng request ang dahilan ng aming team."
                : "{$req->reference} was declined by our team. The reason is written on the request page.",
            default => $paid
                ? ($tl ? "Sarado na po ang {$req->reference}." : "{$req->reference} is closed.")
                : ($tl ? "Sarado na po ang {$req->reference}. Walang bayad na nagawa kaya walang ire-refund, at kinansela ang service request." : "{$req->reference} is closed. Nothing had been paid, so there is nothing to refund, and the service request was cancelled."),
        };

        $r = self::blank($lines);
        $r['buttons'] = [self::btn($tl ? 'Tingnan ang refund request ko' : 'Check my refund request', 'services.refund.show', ['reference' => $req->reference])];
        if (in_array($req->status, [Req::DECLINED, Req::REFUND_PROBLEM], true)) {
            $r['contact'] = self::hasContact();
        }

        return $r;
    }

    /* ───────────── one invoice ───────────── */

    public static function invoiceAnswer(ServiceInvoice $invoice, string $lang): array
    {
        $tl    = $lang === Language::TL;
        $ticket = $invoice->inquiry;
        $r     = self::blank(self::invoiceLines($invoice, $tl));

        $r['buttons'] = self::invoiceButtons($invoice, $ticket, $tl);

        return $r;
    }

    private static function invoiceLines(ServiceInvoice $invoice, bool $tl): string
    {
        $total   = ServiceInvoiceDesk::peso($invoice->total);
        $paid    = ServiceInvoiceDesk::peso($invoice->paidCents() / 100);
        $balance = ServiceInvoiceDesk::peso($invoice->balanceCents() / 100);
        $status  = $invoice->statusLabel();
        $ticket  = $invoice->inquiry;
        $num     = $invoice->invoice_number;

        if ($invoice->status === ServiceInvoice::VOID) {
            return $tl
                ? "Ang invoice na {$num} para sa {$ticket->reference_number} ay Cancelled, kaya hindi na ito mababayaran."
                : "Invoice {$num} for {$ticket->reference_number} is Cancelled, so it can no longer be paid.";
        }

        $s = $tl
            ? "Invoice {$num} para sa {$ticket->reference_number}: {$status}. Kabuuan {$total}, nabayaran {$paid}, natitira {$balance}."
            : "Invoice {$num} for {$ticket->reference_number}: {$status}. Total {$total}, paid {$paid}, balance {$balance}.";

        if ($invoice->isHalfHalf() && $invoice->status !== ServiceInvoice::PAID) {
            $s .= ' ' . match (true) {
                $invoice->paidCents() === 0 => $tl ? 'Half and half ito: kalahati muna ngayon, at ang kalahati kapag tapos na ang serbisyo.' : 'This is half and half: half now, and the other half when the service is done.',
                $invoice->service_done_at !== null => $tl ? 'Bukas na ang ikalawang kalahati dahil tapos na ang serbisyo.' : 'The second half is open because the service is done.',
                default => $tl ? 'Naka-lock pa ang ikalawang kalahati: bubukas ito kapag minarkahang tapos na ang serbisyo.' : 'The second half is still locked: it opens when the service is marked done.',
            };
        }

        $why = $invoice->paymentBlockReason();
        if ($why && !in_array($why, ['already_paid', 'installment_not_open'], true)) {
            $s .= ' ' . self::blockText($why, $tl);
        }

        return $s;
    }

    private static function blockText(string $code, bool $tl): string
    {
        return match ($code) {
            'on_hold_cancellation_request' => $tl ? 'May bukas na cancellation request, kaya naka-pause ang mga bayad hanggang makasagot ang aming team.' : 'A cancellation request is open, so payments are paused until our team replies.',
            'invoice_cancelled' => $tl ? 'Kinansela ang invoice na ito.' : 'This invoice was cancelled.',
            'ticket_cancelled' => $tl ? 'Kinansela ang service request na ito, kaya wala nang babayaran.' : 'This service request was cancelled, so nothing more can be paid.',
            'draft' => $tl ? 'Hindi pa naipapadala ang invoice na ito.' : 'This invoice has not been sent yet.',
            default => '',
        };
    }

    /** Primary button first. Pay now only when the invoice can really be paid right now. */
    private static function invoiceButtons(ServiceInvoice $invoice, ServiceInquiry $ticket, bool $tl): array
    {
        $buttons = [];
        $open    = $ticket->openRefundRequest();

        if ($open) {
            $buttons[] = self::btn($tl ? 'Tingnan ang refund request ko' : 'Check my refund request', 'services.refund.show', ['reference' => $open->reference]);
        } elseif ($invoice->isPayable()) {
            $buttons[] = self::btn($tl ? 'Bayaran ngayon' : 'Pay now', 'services.invoice', ['id' => $invoice->id]);
        }
        $buttons[] = self::btn($tl ? 'Tingnan ang invoice ko' : 'View my invoice', 'services.invoice', ['id' => $invoice->id]);

        if (!$open && $ticket->isOpen() && !in_array($invoice->status, [ServiceInvoice::VOID], true)) {
            $buttons[] = self::btn($tl ? 'Humiling ng pagkansela o refund' : 'Request cancellation or refund', 'services.refund.create', ['id' => $ticket->id]);
        }

        return array_values(array_filter($buttons));
    }

    /* ───────────── one ticket ───────────── */

    public static function ticketAnswer(ServiceInquiry $ticket, string $lang, string $focus = 'status'): array
    {
        $tl      = $lang === Language::TL;
        $invoice = self::invoiceFor($ticket);
        $open    = $ticket->openRefundRequest();
        $work    = ServiceWorkState::for($ticket);

        $statusText = match ($ticket->status) {
            'new'         => $tl ? 'natanggap at hinihintay pang suriin ng aming team' : 'received and waiting for our team to review',
            'contacting'  => $tl ? 'sinusuri at sinusubukan kayong abutin ng aming team' : 'being reviewed, and our team is trying to reach you',
            'unreachable' => $tl ? 'hindi pa kayo naaabot ng aming team; pakitingnan ang telepono ninyo o kontakin kami' : 'our team could not reach you yet; please check your phone or contact us',
            'called'      => $tl ? 'nasuri na at natawagan na kayo ng aming team' : 'reviewed, and our team has called you',
            'closed'      => $tl ? 'sarado na' : 'closed',
            'cancelled'   => $tl ? 'kinansela' : 'cancelled',
            default       => $ticket->statusLabel(),
        };
        $workText = match ($work) {
            ServiceWorkState::STARTED => $tl ? 'Nasimulan na ang trabaho.' : 'The work has started.',
            ServiceWorkState::DONE    => $tl ? 'Tapos na ang serbisyo.' : 'The service is done.',
            default                   => $tl ? 'Hindi pa nagsisimula ang trabaho.' : 'The work has not started yet.',
        };

        $text = ($tl
            ? "Service request {$ticket->reference_number} ({$ticket->serviceLabel()}): {$statusText}. "
            : "Service request {$ticket->reference_number} ({$ticket->serviceLabel()}): {$statusText}. ");
        if ($ticket->isOpen() || $ticket->status === 'closed') {
            $text .= $workText . ' ';
        }

        if ($invoice) {
            $text .= self::invoiceLines($invoice, $tl) . ' ';
        } elseif ($ticket->isOpen()) {
            $text .= $tl ? 'Wala pa pong invoice: ipapadala ito ng aming team pagkatapos nilang suriin ang ticket.' : 'There is no invoice yet: our team sends it after they review the ticket.';
            $text .= ' ';
        }

        if ($open && !($invoice && $invoice->paymentBlockReason() === 'on_hold_cancellation_request')) {
            $text .= self::blockText('on_hold_cancellation_request', $tl) . ' ';
        }

        if ($ticket->isOpen() && $focus === 'schedule') {
            $text .= $tl
                ? 'Wala pa po akong nakikitang target na petsa para sa ticket na ito; sasabihin sa inyo ng aming team ang schedule. Hindi po ako makapangangako ng petsa.'
                : 'I do not see a target date on this ticket yet; our team will tell you the schedule. I cannot promise a date myself.';
        }

        $r = self::blank(trim($text));

        $buttons = [];
        if ($open) {
            $buttons[] = self::btn($tl ? 'Tingnan ang refund request ko' : 'Check my refund request', 'services.refund.show', ['reference' => $open->reference]);
        } elseif ($invoice) {
            $buttons = self::invoiceButtons($invoice, $ticket, $tl);
        } elseif ($ticket->isOpen()) {
            $buttons[] = self::btn($tl ? 'Humiling ng pagkansela o refund' : 'Request cancellation or refund', 'services.refund.create', ['id' => $ticket->id]);
        }
        $r['buttons'] = $buttons ?: [self::btn($tl ? 'Buksan ang aking service requests' : 'Open my service requests', 'services.request')];

        return $r;
    }

    /** The invoice a customer should hear about: the live one (sent or paid), else the latest cancelled one. */
    private static function invoiceFor(ServiceInquiry $ticket): ?ServiceInvoice
    {
        return ServiceRefundRequestService::liveInvoice($ticket)
            ?? ServiceInvoice::where('service_inquiry_id', $ticket->id)->where('status', ServiceInvoice::VOID)->orderByDesc('id')->first();
    }

    /* ───────────── "my ticket / invoice / payment / refund / schedule / taking too long" ───────────── */

    /**
     * @param  string  $handler  status_ticket | status_invoice | status_pay | status_refund | status_schedule | status_late
     * @return array<string, mixed>|null a reply body, or null when the customer has nothing to look at
     */
    public static function personal(string $handler, User $user, string $lang, ?int $chosenTicketId = null): array
    {
        $tl      = $lang === Language::TL;
        $tickets = ServiceInquiry::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get();

        if ($tickets->isEmpty()) {
            $r = self::blank($tl
                ? 'Wala pa po akong nakikitang Other Services request sa inyong account. Maaari kayong magpadala ng bago anumang oras.'
                : "I don't see any Other Services requests on your account yet. You can send a new one anytime.");
            $r['buttons'] = [self::btn($tl ? 'Magpadala ng request' : 'Send a request', 'services.request')];

            return $r;
        }

        // Narrow to the tickets that matter for the question.
        $relevant = match ($handler) {
            'status_refund' => $tickets->filter(fn ($t) => $t->refundRequests()->exists()),
            'status_pay', 'status_invoice' => $tickets->filter(fn ($t) => self::invoiceFor($t) !== null),
            default         => $tickets,
        };

        if ($handler === 'status_refund' && $relevant->isEmpty()) {
            $r = self::blank($tl
                ? 'Wala pa po kayong cancellation o refund request sa inyong account. Kung gusto ninyong magpadala, sabihin lang po sa akin.'
                : "You don't have a cancellation or refund request on your account yet. If you'd like to send one, just tell me.");
            $r['suggestions'] = [$tl ? 'Humiling ng pagkansela o refund' : 'Request cancellation or refund'];

            return $r;
        }
        if ($relevant->isEmpty()) {
            $relevant = $tickets;
        }

        $chosen = $chosenTicketId ? $relevant->firstWhere('id', $chosenTicketId) : null;
        if (!$chosen && $relevant->count() === 1) {
            $chosen = $relevant->first();
        }

        if (!$chosen) {
            return self::picker($relevant, $tl);
        }

        return self::forTicket($handler, $chosen, $lang);
    }

    /** The answer for one ticket, shaped by what the customer asked. */
    public static function forTicket(string $handler, ServiceInquiry $ticket, string $lang): array
    {
        $tl = $lang === Language::TL;

        if ($handler === 'status_refund') {
            $req = $ticket->refundRequests()->first();

            return $req ? self::requestAnswer($req, $lang) : self::ticketAnswer($ticket, $lang);
        }
        if ($handler === 'status_late') {
            return self::lateAnswer($ticket, $lang);
        }

        return self::ticketAnswer($ticket, $lang, $handler === 'status_schedule' ? 'schedule' : 'status');
    }

    /** "It is taking too long": the real status first; not started -> offer the cancellation; started -> the contact card, kindly. */
    private static function lateAnswer(ServiceInquiry $ticket, string $lang): array
    {
        $tl   = $lang === Language::TL;
        $base = self::ticketAnswer($ticket, $lang, 'schedule');
        $work = ServiceWorkState::for($ticket);

        if (!$ticket->isOpen()) {
            return $base;
        }

        if ($work === ServiceWorkState::NOT_STARTED) {
            $why = ServiceWorkState::canStart($ticket);
            $extra = $why === 'awaiting_payment' || $why === 'no_invoice' || $why === 'invoice_draft'
                ? ($tl ? ' Magsisimula lamang ang trabaho pagkatapos ng bayad (no pay, no work), kaya maaaring iyon po ang hinihintay.' : ' Work only starts after payment (no pay, no work), so that may be what it is waiting for.')
                : '';
            $base['text'] .= $extra . ($tl
                ? ' Dahil hindi pa nagsisimula ang trabaho, maaari po kayong humiling na magkansela kung gusto ninyo.'
                : ' Since the work has not started, you can ask to cancel if you would like to.');
            $open = $ticket->openRefundRequest();
            if (!$open) {
                $base['buttons'][] = self::btn($tl ? 'Humiling ng pagkansela o refund' : 'Request cancellation or refund', 'services.refund.create', ['id' => $ticket->id]);
                $base['buttons'] = self::unique($base['buttons']);
            }
        } else {
            $base['text'] .= ' ' . ($tl
                ? 'Pasensya na po sa paghihintay. Nasimulan na ang trabaho, kaya pinakamabuting kausapin ang aming team para sa schedule ng pagtatapos.'
                : 'I am sorry you are still waiting. The work has already started, so the best step is to ask our team about the finish schedule.');
            $base['contact'] = self::hasContact();
            // Work has started: the contact card is the next step, not a cancel button.
            $base['buttons'] = array_values(array_filter($base['buttons'], fn ($b) => !str_contains($b['url'], '/cancellation')));
        }

        return $base;
    }

    /** Several tickets: one card each (number, service, status) and the numbers as chips to pick from. */
    private static function picker($tickets, bool $tl): array
    {
        $r = self::blank($tl
            ? 'Marami po kayong request. Alin po ang gusto ninyong tingnan? Pumili sa ibaba.'
            : 'You have more than one request. Which one would you like me to look at? Pick one below.');

        foreach ($tickets->take(6) as $t) {
            $inv = self::invoiceFor($t);
            $r['cards'][] = [
                'name'            => $t->reference_number . ' · ' . $t->serviceLabel(),
                'price_label'     => $inv ? ($inv->invoice_number . ' · ' . $inv->statusLabel()) : $t->statusLabel(),
                'old_price_label' => null,
                'image'           => null,
                'url'             => route('services.request', [], false),
            ];
            $r['suggestions'][] = $t->reference_number;
        }

        return $r;
    }

    /* ───────────── helpers ───────────── */

    /** @return array{text: string, buttons: list<array>, cards: list<array>, suggestions: list<string>, contact: bool} */
    private static function blank(string $text): array
    {
        return ['text' => $text, 'buttons' => [], 'cards' => [], 'suggestions' => [], 'contact' => false];
    }

    /** @return array{label: string, url: string}|null */
    private static function btn(string $label, string $route, array $params = [], bool $include = true): ?array
    {
        if (!$include || !Route::has($route)) {
            return null;
        }

        return ['label' => $label, 'url' => route($route, $params, false)];
    }

    private static function unique(array $buttons): array
    {
        $seen = [];

        return array_values(array_filter($buttons, function ($b) use (&$seen) {
            if (!$b || isset($seen[$b['url']])) {
                return false;
            }
            $seen[$b['url']] = true;

            return true;
        }));
    }

    private static function hasContact(): bool
    {
        return (bool) (Contact::phone() || Contact::email());
    }
}
