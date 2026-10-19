<?php

namespace App\Support\Luma;

use App\Models\ServiceInquiry;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\PipelineException;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceRefundRequestService;
use App\Support\ServiceWorkState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * The guided cancellation / refund conversation. In chat it only gathers and explains; every rule is decided by
 * ServiceRefundRequestService (context, validate, create), the same code the web form uses, so the two can never
 * disagree.
 *
 *   start()   -> picks the ticket (their own list; a typed number is ownership-checked)
 *   afterPick -> explains the rule for what is true RIGHT NOW (paid? started?) and shows the FORM CARD
 *   review()  -> the form posts here: validate() or field errors, then a confirm SUMMARY card (GCash number masked)
 *   confirm() -> "I checked these details" ticked: create(..., 'luma') and the reference-number reply
 *
 * Privacy: the GCash number and account name travel only inside an encrypted, short-lived, single-use token that the
 * customer's own browser holds between review and confirm. They are never put in the session state, the chat log, a
 * notification, an activity-log line or the reply text.
 *
 * Tone: calm, one apology at most, never "approved" and never a promised refund. The reply says the request was SENT.
 */
final class RefundFlow
{
    public const TOKEN_MINUTES = 15;

    public const WARNING_EN = 'Please check your GCash number and name. Once a refund is sent, it cannot be recalled or sent again.';
    public const WARNING_TL = 'Pakisuri po ang GCash number at pangalan ninyo. Kapag naipadala na ang refund, hindi na ito maibabalik o maipapadala ulit.';

    private const EXIT = ['never mind', 'nevermind', 'cancel that', 'forget it', 'stop', 'exit', 'quit', 'sige huwag na', 'huwag na', 'wag na', 'hindi na', 'di na', 'ayoko na pala', 'talk to a person', 'talk to a human', 'talk to someone', 'real person', 'human', 'makausap', 'kausapin', 'nevermind po', 'iwan na'];

    private const REASONS_TL = [
        'change_of_mind'       => 'Nagbago ang isip ko',
        'taking_too_long'      => 'Masyadong matagal',
        'wrong_details'        => 'Mali ang naibigay kong detalye',
        'found_other_provider' => 'May nahanap akong ibang provider',
        'other'                => 'Iba pa',
    ];

    /* ───────────── entering and leaving ───────────── */

    public static function start(ChatState $state, ?User $user, string $lang): array
    {
        $tl = $lang === Language::TL;

        if (!$user) {
            return [
                'text'    => $tl
                    ? 'Para mapadalhan ko kayo ng cancellation o refund request, kailangan ko pong makita ang inyong account. Mag-log in po muna. Ang patakaran: kung hindi pa nagsisimula ang trabaho, maaaring humiling ng refund; kapag nagsimula na, karaniwang hindi na ito refundable, pero maaari pa ring magpadala ng request para suriin ng aming team.'
                    : 'To send a cancellation or refund request I need to see your account, so please log in first. The rule: if the work has not started, you can ask for a refund; once it has started it is usually non-refundable, but you can still send a request for our team to review.',
                'buttons' => self::buttons([[$tl ? 'Mag-log in' : 'Log in', 'login', []], [$tl ? 'Gumawa ng account' : 'Create an account', 'register', []]]),
                'detected' => 'flow:refund_guest',
            ];
        }

        $tickets = ServiceInquiry::where('user_id', $user->id)->orderByDesc('id')->limit(10)->get()
            ->filter(fn ($t) => $t->isOpen() || $t->openRefundRequest())->values();

        if ($tickets->isEmpty()) {
            $state->set('flow', null);

            return [
                'text'    => $tl
                    ? 'Wala po akong nakikitang bukas na Other Services request sa inyong account na maaaring kanselahin. Kung gusto ninyong magpadala ng bago, nandito po ang button.'
                    : "I don't see an open Other Services request on your account that can be cancelled. If you want to send a new one, the button is below.",
                'buttons' => self::buttons([[$tl ? 'Magpadala ng request' : 'Send a request', 'services.request', []]]),
                'detected' => 'flow:refund_none',
            ];
        }

        if ($tickets->count() === 1) {
            return self::afterPick($tickets->first(), $state, $user, $lang);
        }

        $state->set('flow', ['name' => 'refund', 'step' => 'pick']);
        $picker = StatusAnswers::personal('status_ticket', $user, $lang);   // cards + the numbers as chips

        return [
            'text'        => $tl
                ? 'Alin pong request ang tungkol dito? Pumili sa ibaba, o i-type ang numero (halimbawa SI-2026-0001 o INV-2026-0001).'
                : 'Which request is this about? Pick one below, or type its number (for example SI-2026-0001 or INV-2026-0001).',
            'cards'       => $picker['cards'] ?? [],
            'suggestions' => $picker['suggestions'] ?? [],
            'detected'    => 'flow:refund_pick',
        ];
    }

    public static function isExit(string $message): bool
    {
        $plain = ' ' . Text::plain($message) . ' ';
        foreach (self::EXIT as $phrase) {
            if (str_contains($plain, ' ' . Text::plain($phrase) . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** Handles one message while a flow is open. Returns null when the message is a new question (the flow is dropped). */
    public static function handle(string $message, string $lang, ChatState $state, ?User $user): ?array
    {
        $flow = $state->get('flow');
        $tl   = $lang === Language::TL;

        if (!is_array($flow) || ($flow['name'] ?? null) !== 'refund') {
            return null;
        }

        if (self::isExit($message)) {
            $state->set('flow', null);
            $wantsPerson = (bool) preg_match('/person|human|someone|makausap|kausapin/i', $message);

            return [
                'text'     => $tl ? 'Sige po, itinigil ko na. Walang naipadala at walang nabago.' : 'No problem, I stopped. Nothing was sent and nothing was changed.',
                'contact'  => $wantsPerson && (Contact::phone() || Contact::email()),
                'detected' => 'flow:refund_exit',
            ];
        }

        if (!$user) {
            $state->set('flow', null);

            return null;
        }

        if (($flow['step'] ?? '') === 'pick') {
            $found = false;
            foreach (Reference::find($message) as $ref) {
                $found = true;
                if ($ticket = self::ticketForReference($ref, $user)) {
                    return self::afterPick($ticket, $state, $user, $lang);
                }
            }
            if ($found) {
                // Not theirs (or not there): the same neutral reply, and the flow stays open.
                return StatusAnswers::notFound($user, $lang) + ['detected' => 'flow:refund_pick'];
            }
        }

        // A different question: let it through and drop the flow.
        $words = Text::words($message);
        if ($words !== [] && !Context::isAffirmation($message)) {
            $state->set('flow', null);

            return null;
        }

        return [
            'text'     => ($flow['step'] ?? '') === 'pick'
                ? ($tl ? 'Pumili po ng isa sa mga request sa itaas, o sabihin ang numero nito. Kung ayaw na ninyo, sabihin lang po "never mind".' : 'Please pick one of the requests above, or tell me its number. If you changed your mind, just say "never mind".')
                : ($tl ? 'Punan po ang form sa itaas, o sabihin ang "never mind" kung ayaw na ninyo.' : 'Please fill in the form above, or say "never mind" if you changed your mind.'),
            'detected' => 'flow:refund_wait',
        ];
    }

    public static function ticketForReference(array $ref, User $user): ?ServiceInquiry
    {
        $own = ServiceInquiry::where('user_id', $user->id);

        return match ($ref['type']) {
            'SI'  => $own->where('reference_number', $ref['number'])->first(),
            'INV' => ($i = \App\Models\ServiceInvoice::where('invoice_number', $ref['number'])->where('user_id', $user->id)->first())
                ? ServiceInquiry::where('user_id', $user->id)->find($i->service_inquiry_id) : null,
            default => ($r = Req::where('reference', $ref['number'])->where('user_id', $user->id)->first())
                ? ServiceInquiry::where('user_id', $user->id)->find($r->service_inquiry_id) : null,
        };
    }

    /* ───────────── explaining, then the form card ───────────── */

    public static function afterPick(ServiceInquiry $ticket, ChatState $state, User $user, string $lang): array
    {
        $tl  = $lang === Language::TL;
        $ctx = ServiceRefundRequestService::context($ticket);
        $state->set('ticket_id', $ticket->id);

        if ($ctx['open']) {
            $state->set('flow', null);
            $body = StatusAnswers::requestAnswer($ctx['open'], $lang);
            $body['text'] = ($tl ? 'May bukas na po kayong request para sa serbisyong ito, kaya hindi na kayo kailangang gumawa ng isa pa. ' : 'You already have an open request for this service, so you do not need to send another. ') . $body['text'];
            $body['detected'] = 'flow:refund_open';

            return $body;
        }
        if (!$ctx['can_request']) {
            $state->set('flow', null);

            return [
                'text'     => $tl ? 'Sarado o kinansela na po ang service request na ito, kaya wala na pong maaaring kanselahin.' : 'This service request is already closed or cancelled, so there is nothing left to cancel.',
                'buttons'  => self::buttons([[$tl ? 'Buksan ang aking service requests' : 'Open my service requests', 'services.request', []]]),
                'detected' => 'flow:refund_closed',
            ];
        }

        $paid    = $ctx['paid_cents'] > 0;
        $work    = $ctx['work'];
        $instant = !$paid && $work === ServiceWorkState::NOT_STARTED;
        $peso    = $paid ? ServiceInvoiceDesk::peso($ctx['paid_cents'] / 100) : null;
        $days    = Req::REFUND_DAYS;

        $text = match (true) {
            $instant => $tl
                ? "Wala pa po kayong nababayaran at hindi pa nagsisimula ang trabaho para sa {$ticket->reference_number}, kaya agad po itong makakansela at hindi na ito mababawi. Punan po ang maikling form para tapusin."
                : "Nothing has been paid and the work has not started on {$ticket->reference_number}, so cancelling is instant, and this can't be undone. Please fill in the short form to finish.",
            $paid && $work === ServiceWorkState::NOT_STARTED => $tl
                ? "Hindi pa nagsisimula ang trabaho sa {$ticket->reference_number}, kaya maaari po kayong humiling ng refund ng {$peso} na binayaran ninyo. Susuriin ito ng aming team at sasagot sila. Kung kukumpirmahin nila, ipapadala ang refund sa GCash o cash na pipiliin ninyo, sa loob ng {$days} araw pagkatapos ng kumpirmasyon."
                : "The work has not started on {$ticket->reference_number}, so you can ask for a refund of the {$peso} you paid. Our team will review it and reply. If they confirm it, the refund goes back by the GCash or cash you choose, within {$days} days after that.",
            default => $tl
                ? "Nasimulan na po ang trabaho sa {$ticket->reference_number}, kaya karaniwang hindi na ito refundable. Maaari pa rin kayong magpadala ng request at susuriin ito ng aming team at sasagot sila. Hindi ko po masasabi kung ano ang magiging sagot."
                : "The work on {$ticket->reference_number} has already started, so it is usually non-refundable. You can still send a request, and our team will review it and reply. I can't say what the answer will be.",
        };

        $state->set('flow', ['name' => 'refund', 'step' => 'form', 'ticket_id' => $ticket->id]);

        return [
            'text'     => $text,
            'form'     => self::formCard($ticket, $user, $paid, $instant, $lang),
            'detected' => 'flow:refund_form',
        ];
    }

    private static function formCard(ServiceInquiry $ticket, User $user, bool $paid, bool $instant, string $lang): array
    {
        $tl = $lang === Language::TL;

        return [
            'type'      => 'refund_request',
            'ticket_id' => $ticket->id,
            'reference' => $ticket->reference_number,
            'paid'      => $paid,
            'instant'   => $instant,
            'title'     => $instant ? ($tl ? 'Kanselahin ang service request' : 'Cancel this service request') : ($tl ? 'Humiling ng pagkansela o refund' : 'Request cancellation or refund'),
            'reasons'   => array_map(fn ($code, $label) => ['value' => $code, 'label' => $tl ? (self::REASONS_TL[$code] ?? $label) : $label], array_keys(Req::REASONS), Req::REASONS),
            'defaults'  => [
                'phone' => $user->saved_contact_phone ?: ($user->phone_number ?: $ticket->phone),
                'email' => $user->saved_contact_email ?: ($user->email ?: $ticket->email),
            ],
            'methods'   => [['value' => 'gcash', 'label' => 'GCash'], ['value' => 'cash', 'label' => $tl ? 'Cash' : 'Cash']],
            'post_url'  => Route::has('chatbot.refund') ? route('chatbot.refund', [], false) : '',
            'terms_url' => Route::has('services.terms') ? route('services.terms', [], false) : '',
            'labels'    => $tl ? [
                'reason' => 'Dahilan', 'choose' => 'Pumili ng dahilan', 'details' => 'Mga detalye (opsyonal)', 'phone' => 'Mobile number', 'email' => 'Email',
                'method' => 'Paano ibabalik ang refund?', 'gcash_number' => 'GCash number', 'gcash_name' => 'Pangalan o initials sa GCash account',
                'terms' => 'Nabasa ko ang mga terms ng pagkansela at refund ng Other Services', 'terms_link' => 'Basahin ang terms',
                'submit' => 'Suriin ang request ko', 'sending' => 'Sandali po...', 'hint_method' => 'Maaari ang GCash o cash, kahit card ang ginamit ninyo sa pagbayad.',
            ] : [
                'reason' => 'Reason', 'choose' => 'Choose a reason', 'details' => 'Details (optional)', 'phone' => 'Mobile number', 'email' => 'Email',
                'method' => 'How should the refund go back?', 'gcash_number' => 'GCash number', 'gcash_name' => 'Name or initials on the GCash account',
                'terms' => 'I have read the Other Services cancellation and refund terms', 'terms_link' => 'Read the terms',
                'submit' => 'Review my request', 'sending' => 'One moment...', 'hint_method' => 'GCash or cash, even if you paid by card.',
            ],
        ];
    }

    /* ───────────── the form posts here ───────────── */

    /**
     * Step 1: validate with the same rules as the web form, then build the confirm summary.
     *
     * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body
     */
    public static function review(array $input, User $user, string $lang): array
    {
        $tl     = $lang === Language::TL;
        $ticket = ServiceInquiry::where('user_id', $user->id)->find((int) ($input['ticket_id'] ?? 0));
        if (!$ticket) {
            return [404, ['message' => $tl ? 'Hindi ko po makita ang request na iyan.' : "I couldn't find that request."]];
        }

        $ctx = ServiceRefundRequestService::context($ticket);
        if (!$ctx['can_request']) {
            return [409, ['message' => (string) $ctx['why_not']]];
        }

        $paid  = $ctx['paid_cents'] > 0;
        $clean = ServiceRefundRequestService::validate($input, $paid, true);   // ValidationException -> 422 with field messages
        $instant = !$paid && $ctx['work'] === ServiceWorkState::NOT_STARTED;

        $token = Crypt::encryptString(json_encode([
            'u' => $user->id, 't' => $ticket->id, 'x' => now()->getTimestamp() + self::TOKEN_MINUTES * 60, 'c' => $clean, 'n' => bin2hex(random_bytes(8)),
        ]));

        $rows = [
            [$tl ? 'Service request' : 'Service request', $ticket->reference_number],
            [$tl ? 'Dahilan' : 'Reason', $tl ? (self::REASONS_TL[$clean['reason_code']] ?? Req::REASONS[$clean['reason_code']]) : Req::REASONS[$clean['reason_code']]],
        ];
        if ($clean['reason_text']) {
            $rows[] = [$tl ? 'Mga detalye' : 'Details', $clean['reason_text']];
        }
        $rows[] = ['Mobile', $clean['contact_phone']];
        $rows[] = ['Email', $clean['contact_email']];
        if ($paid) {
            $rows[] = [$tl ? 'Halagang binayaran' : 'Amount paid', ServiceInvoiceDesk::peso($ctx['paid_cents'] / 100)];
            $rows[] = [$tl ? 'Ibabalik sa pamamagitan ng' : 'Refund by', $clean['refund_method'] === 'gcash' ? 'GCash' : 'Cash'];
            if ($clean['refund_method'] === 'gcash') {
                $rows[] = ['GCash number', self::maskNumber($clean['gcash_number'])];
                $rows[] = [$tl ? 'Pangalan sa GCash' : 'GCash account name', self::maskName($clean['gcash_account_name'])];
            }
        }

        $warning = $instant
            ? ($tl ? 'Agad itong makakansela at hindi na ito mababawi.' : "Cancelling is instant, and this can't be undone.")
            : ($paid && $clean['refund_method'] === 'gcash' ? ($tl ? self::WARNING_TL : self::WARNING_EN) : ($tl ? 'Pakisuri po ang mga detalye bago kumpirmahin.' : 'Please check the details before you confirm.'));

        return [200, ['summary' => [
            'title'         => $tl ? 'Suriin ang request ninyo' : 'Check your request',
            'rows'          => $rows,
            'warning'       => $warning,
            'warning_title' => $paid && $clean['refund_method'] === 'gcash' ? ($tl ? 'Pakisuri ang GCash number at pangalan.' : 'Please check your GCash number and name.') : ($instant ? ($tl ? 'Hindi na ito mababawi.' : "This can't be undone.") : ($tl ? 'Pakisuri ang mga detalye.' : 'Please check the details.')),
            'check_label'   => $tl ? 'Sinuri ko ang mga detalyeng ito' : 'I checked these details',
            'confirm_label' => $instant ? ($tl ? 'Kanselahin ngayon' : 'Cancel it now') : ($tl ? 'Ipadala ang request' : 'Send my request'),
            'back_label'    => $tl ? 'Bumalik at baguhin' : 'Go back and change',
            'check_error'   => $tl ? 'Pakitiyak muna na sinuri ninyo ang mga detalye.' : 'Please tick that you checked the details first.',
            'token'         => $token,
            'post_url'      => route('chatbot.refund', [], false),
            'instant'       => $instant,
        ]]];
    }

    /**
     * Step 2: the customer ticked "I checked these details". Creates the request exactly once.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function confirm(string $token, bool $checked, User $user, string $lang, ?ChatState $state = null): array
    {
        $tl = $lang === Language::TL;

        if (!$checked) {
            return [422, ['errors' => ['check' => [$tl ? 'Pakitiyak muna na sinuri ninyo ang mga detalye.' : 'Please tick that you checked the details first.']]]];
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException) {
            return [422, ['message' => $tl ? 'Hindi na valid ang form na ito. Pakisimulan po ulit.' : 'That form is no longer valid. Please start again.']];
        }
        if (($data['u'] ?? null) !== $user->id || ($data['x'] ?? 0) < now()->getTimestamp()) {
            return [422, ['message' => $tl ? 'Nag-expire na ang form na ito. Pakisimulan po ulit.' : 'That form has expired. Please start again.']];
        }

        $ticket = ServiceInquiry::where('user_id', $user->id)->find((int) ($data['t'] ?? 0));
        if (!$ticket) {
            return [404, ['message' => $tl ? 'Hindi ko po makita ang request na iyan.' : "I couldn't find that request."]];
        }

        // Single use: a double tap or a second submission never creates a second request.
        $key = 'luma:refund:token:' . sha1($token);
        if (!Cache::add($key, 1, 3600)) {
            return [200, self::successOrExisting($ticket, $lang, true)];
        }

        try {
            $req = app(ServiceRefundRequestService::class)->create($ticket->id, $data['c'] + ['terms' => '1'], $user, 'luma');
        } catch (ValidationException $e) {
            Cache::forget($key);

            return [422, ['errors' => $e->errors()]];
        } catch (PipelineException $e) {
            Cache::forget($key);
            $existing = $ticket->openRefundRequest();

            return $existing ? [200, self::successOrExisting($ticket, $lang, true)] : [409, ['message' => $e->getMessage()]];
        }

        $state?->set('flow', null);

        return [200, self::successBody($req, $ticket, $lang)];
    }

    private static function successOrExisting(ServiceInquiry $ticket, string $lang, bool $already): array
    {
        $tl  = $lang === Language::TL;
        $req = $ticket->refundRequests()->first();
        if (!$req) {
            return ['reply' => ['text' => $tl ? 'Naipadala na ang request ninyo.' : 'Your request was already sent.', 'detected' => 'flow:refund_done']];
        }
        $body = self::successBody($req, $ticket, $lang);
        $body['reply']['text'] = ($tl ? 'Naipadala na po ang request na ito, kaya hindi na ako gagawa ng pangalawa. ' : 'This request was already sent, so I did not make a second one. ') . $body['reply']['text'];

        return $body;
    }

    private static function successBody(Req $req, ServiceInquiry $ticket, string $lang): array
    {
        $tl   = $lang === Language::TL;
        $days = Req::REFUND_DAYS;
        $paid = (float) $req->amount_paid_snapshot > 0;

        if ($req->status === Req::CLOSED) {
            $text = $tl
                ? "Nakansela na po ang {$ticket->reference_number}. Walang nabayaran kaya walang ire-refund. Ito ang reference ng inyong request:"
                : "{$ticket->reference_number} is now cancelled. Nothing was paid, so there is nothing to refund. This is the reference of your request:";
        } else {
            $method = $req->refund_method === 'gcash' ? 'GCash' : ($req->refund_method === 'cash' ? 'cash' : null);
            $text = $tl
                ? "Naipadala na po ang request ninyo para sa {$ticket->reference_number}. Susuriin ito ng aming team at sasagot sila sa inyo. Naka-pause ang mga bayad sa serbisyong ito habang naghihintay." . ($paid && $method ? " Kung kukumpirmahin nila, ibabalik ang refund sa {$method} sa loob ng {$days} araw pagkatapos ng kumpirmasyon." : '') . ' Ito ang reference ng request ninyo:'
                : "Your request for {$ticket->reference_number} was sent. Our team will review it and reply to you. Payments on this service are paused while you wait." . ($paid && $method ? " If they confirm it, the refund goes back by {$method} within {$days} days after that." : '') . ' This is the reference of your request:';
        }

        $reply = [
            'text'      => $text,
            'reference' => $req->reference,
            'buttons'   => self::buttons([[$tl ? 'Tingnan ang refund request ko' : 'Check my refund request', 'services.refund.show', ['reference' => $req->reference]]]),
            'detected'  => 'flow:refund_done',
        ];

        return ['reply' => $reply, 'reference' => $req->reference];
    }

    /* ───────────── masking and small helpers ───────────── */

    /** 09171234567 -> 0917 *** 4567 */
    public static function maskNumber(?string $number): string
    {
        $d = preg_replace('/\D+/', '', (string) $number);
        if (strlen($d) < 10) {
            return '*** ***';
        }
        $d = str_starts_with($d, '63') ? '0' . substr($d, 2) : $d;

        return substr($d, 0, 4) . ' *** ' . substr($d, -4);
    }

    /** "Juan Dela Cruz" -> "J*** D*** C***": enough to recognise, never the full name. */
    public static function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];

        return implode(' ', array_map(fn ($p) => mb_substr($p, 0, 1) . '***', array_filter($parts)));
    }

    /** @return list<array{label: string, url: string}> */
    private static function buttons(array $defs): array
    {
        $out = [];
        foreach ($defs as [$label, $route, $params]) {
            if (Route::has($route)) {
                $out[] = ['label' => $label, 'url' => route($route, $params, false)];
            }
        }

        return $out;
    }
}
