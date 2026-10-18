<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment as Payment;
use App\Models\ServiceRefundAttempt;
use App\Models\ServiceRefundMessage;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Rules\PhilippineMobile;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cancellations and refunds for "Other Services" (Stage 21f). The one place that creates and changes a
 * ServiceRefundRequest, so the customer form, the admin screens and Luma (Prompt 8f) all follow the same rules.
 *
 * Money rules: refunds are possible only if the work has not started (the admin can approve an exception, with a
 * written reason). The refund is the FULL amount paid, read from the invoice, never from user input. Refunds are
 * returned by GCash or cash only, up to 7 days after approval. This class only RECORDS a refund; it never calls a
 * Stripe or PayMongo refund API (the InvoiceGateway has no refund method at all).
 *
 * Every state change is one transaction, appends a line to the ticket's activity log and a system message to the
 * thread, and notifies the right people. The GCash number is never written to a log, note, message or notification.
 * Lock order: ticket, then invoice, then the request (like ServiceInvoiceDesk).
 */
final class ServiceRefundRequestService
{
    public const PAYMENT_ON_TICKET = 'This service has a payment on it, so it must go through a cancellation request so the refund is recorded.';
    public const MIN_DECLINE   = 20;
    public const MIN_EXCEPTION = 10;
    public const MIN_MESSAGE   = 5;
    public const TZ = 'Asia/Manila';

    public const PROBLEMS = [
        'wrong_gcash_number' => 'The GCash number was wrong',
        'gcash_not_found'    => 'The GCash account was not found',
        'other'              => 'Something else went wrong',
    ];

    public const DECLINE_STARTERS = [
        'work_started' => 'Work has already started on this service, so the cost of the work cannot be refunded.',
        'work_done'    => 'The work on this service is already done, so it cannot be refunded.',
        'other'        => '',
    ];

    public function __construct(private ServiceInquiryDesk $tickets, private ServiceInvoicePayments $payments)
    {
    }

    /* ───────────── what a form or Luma needs to know ───────────── */

    /** The invoice a request would attach to: the ticket's live (sent or paid) invoice, or null. */
    public static function liveInvoice(ServiceInquiry $ticket): ?ServiceInvoice
    {
        return ServiceInvoice::where('service_inquiry_id', $ticket->id)
            ->whereIn('status', [ServiceInvoice::ISSUED, ServiceInvoice::PAID])->orderByDesc('id')->first();
    }

    /**
     * @return array{invoice: ?ServiceInvoice, paid_cents: int, work: string, open: ?Req, can_request: bool, why_not: ?string}
     */
    public static function context(ServiceInquiry $ticket): array
    {
        $invoice = self::liveInvoice($ticket);
        $open    = $ticket->openRefundRequest();
        $whyNot  = match (true) {
            !$ticket->isOpen()  => 'This service request is already closed or cancelled.',
            (bool) $open        => 'You already have an open request (' . $open->reference . ').',
            default             => null,
        };

        return [
            'invoice'     => $invoice,
            'paid_cents'  => $invoice ? ServiceInvoicePayments::receivedCents($invoice) : 0,
            'work'        => ServiceWorkState::for($ticket),
            'open'        => $open,
            'can_request' => $whyNot === null,
            'why_not'     => $whyNot,
        ];
    }

    /** A Philippine GCash number: 09XXXXXXXXX (11 characters) or +639XXXXXXXXX (13). Returns 09XXXXXXXXX or null. */
    public static function normalizeGcash(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $compact = preg_replace('/[\s\-]/', '', $value);

        return preg_match('/^(?:09|\+639)(\d{9})$/', $compact, $m) ? '09' . $m[1] : null;
    }

    /**
     * Server-side validation shared by the form and Luma. Throws ValidationException. $paid = money is on the invoice.
     *
     * @return array<string, mixed> clean data (phone and GCash number normalised)
     */
    public static function validate(array $input, bool $paid, bool $termsRequired): array
    {
        $gcash = ($input['refund_method'] ?? null) === 'gcash';

        $validator = Validator::make($input, [
            'reason_code'        => ['required', Rule::in(array_keys(Req::REASONS))],
            'reason_text'        => ['nullable', 'string', 'max:1000'],
            'contact_phone'      => ['required', 'string', 'max:30', new PhilippineMobile()],
            'contact_email'      => ['required', 'email:rfc', 'max:150'],
            'refund_method'      => $paid ? ['required', Rule::in(array_keys(Req::METHODS))] : ['nullable'],
            'gcash_number'       => $paid && $gcash ? ['required', 'string', 'max:30', function ($a, $v, $fail) {
                if (self::normalizeGcash($v) === null) {
                    $fail('Enter a GCash number like 09171234567 or +639171234567.');
                }
            }] : ['nullable'],
            'gcash_account_name' => $paid && $gcash ? ['required', 'string', 'min:2', 'max:120'] : ['nullable'],
            'terms'              => $termsRequired ? ['accepted'] : ['nullable'],
        ], [
            'reason_code.required'        => 'Choose a reason.',
            'reason_code.in'              => 'Choose one of the listed reasons.',
            'contact_phone.required'      => 'Enter your mobile number.',
            'contact_email.required'      => 'Enter your email.',
            'contact_email.email'         => 'Enter a valid email address.',
            'refund_method.required'      => 'Choose how you want your refund: GCash or cash.',
            'refund_method.in'            => 'Choose GCash or cash.',
            'gcash_number.required'       => 'Enter the GCash number to send your refund to.',
            'gcash_account_name.required' => 'Enter the name on that GCash account.',
            'terms.accepted'              => 'Please confirm that you have read the Other Services cancellation and refund terms.',
        ]);

        $data = $validator->validate();

        return [
            'reason_code'        => $data['reason_code'],
            'reason_text'        => self::blank($data['reason_text'] ?? null),
            'contact_phone'      => PhilippineMobile::normalize($data['contact_phone']),
            'contact_email'      => trim($data['contact_email']),
            'refund_method'      => $paid ? $data['refund_method'] : null,
            'gcash_number'       => $paid && $gcash ? self::normalizeGcash($data['gcash_number']) : null,
            'gcash_account_name' => $paid && $gcash ? NameFormat::proper(trim($data['gcash_account_name'])) : null,
        ];
    }

    /* ───────────── creating a request ───────────── */

    /**
     * Creates a request for a ticket. $source: form | luma | admin. For form and luma, $actor must be the ticket's owner
     * (anyone else gets a ModelNotFoundException = 404); for admin, $actor is the staff member.
     *
     * Nothing paid and work not started: the ticket and invoice are cancelled at once (status closed, no refund needed).
     * Otherwise the request opens, the invoice goes on hold, and our team decides.
     */
    public function create(int $ticketId, array $input, User $actor, string $source): Req
    {
        if (!isset(Req::SOURCES[$source])) {
            throw new PipelineException('Unknown request source.');
        }
        $ticket = ServiceInquiry::findOrFail($ticketId);
        $this->assertCanAct($ticket, $actor, $source);

        $ctx   = self::context($ticket);
        $paid  = $ctx['paid_cents'] > 0;
        $clean = self::validate($input, $paid, $source !== 'admin');

        $created = DB::transaction(function () use ($ticketId, $actor, $source, $clean) {
            $ticket = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            $this->assertCanAct($ticket, $actor, $source);
            if (!$ticket->isOpen()) {
                throw new PipelineException('This service request is already closed or cancelled.');
            }
            if ($existing = $ticket->openRefundRequest()) {
                throw new PipelineException('There is already an open cancellation request for this service (' . $existing->reference . ').');
            }

            $invoice = ServiceInvoice::where('service_inquiry_id', $ticket->id)->whereIn('status', [ServiceInvoice::ISSUED, ServiceInvoice::PAID])
                ->orderByDesc('id')->lockForUpdate()->first();
            $paidCents = $invoice ? ServiceInvoicePayments::receivedCents($invoice) : 0;
            if (($paidCents > 0) !== ($clean['refund_method'] !== null)) {
                throw new PipelineException('The payment on this service changed while you were filling in the form. Please open the form again.');
            }

            $work    = ServiceWorkState::for($ticket);
            $instant = $paidCents === 0 && $work === ServiceWorkState::NOT_STARTED;

            $req = $this->insert([
                'service_inquiry_id'   => $ticket->id,
                'service_invoice_id'   => $invoice?->id,
                'user_id'              => $ticket->user_id,
                'source'               => $source,
                'amount_paid_snapshot' => ServiceInvoiceDesk::money($paidCents / 100),
                'work_state_snapshot'  => $work,
                'status'               => $instant ? Req::CLOSED : Req::NEW,
                'open_slot'            => $instant ? null : 't' . $ticket->id,
            ] + $clean);

            $this->note($ticket, $actor, "Cancellation request {$req->reference} " . ($source === 'admin' ? 'opened by the shop' : 'sent by the customer')
                . ($paidCents > 0 ? ', ' . ServiceInvoiceDesk::peso($paidCents / 100) . ' paid' : ', nothing paid')
                . ', work: ' . strtolower(ServiceWorkState::label($work)));

            if ($instant) {
                $this->say($req, 'system', 'No payment was made and work had not started, so the service request was cancelled right away. Nothing needs to be refunded.');
                $this->cancelTicketAndInvoice($ticket, $invoice, "Cancelled at the customer's request, {$req->reference}", "Cancelled at the customer's request ({$req->reference})");
                $this->notifyCustomer($req, 'refund_cancelled_no_refund', ['reference' => $ticket->reference_number]);
            } else {
                $this->say($req, 'system', 'Request received. Payments on this service are paused until our team replies.');
                $this->notifyCustomer($req, 'refund_received', ['reference' => $ticket->reference_number]);
            }
            $this->notifyAdmins($req, $ticket, 'admin_refund_request', [
                'customer'    => $ticket->name,
                'reference'   => $ticket->reference_number,
                'amount_line' => $paidCents > 0 ? ServiceInvoiceDesk::peso($paidCents / 100) . ' paid.' : 'Nothing paid.',
                'work'        => strtolower(ServiceWorkState::label($work)),
            ], $actor);

            return [$req, $invoice?->id];
        });

        [$req, $invoiceId] = $created;
        if ($invoiceId) {
            $this->payments->closeOpenCheckouts($invoiceId, 'Cancellation requested');
        }

        return $req->refresh();
    }

    /* ───────────── admin decisions ───────────── */

    /** Approve. Only while the LIVE work state is not_started. Nothing paid: the request just closes and the service is cancelled. */
    public function approve(int $id, User $admin): Req
    {
        return $this->approveInner($id, $admin, null);
    }

    /** Approve after work started or finished. A written reason is required; flagged approved_as_exception and logged. */
    public function approveAsException(int $id, User $admin, string $reason): Req
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_EXCEPTION) {
            throw new PipelineException('Write the reason for this exception (at least ' . self::MIN_EXCEPTION . ' characters).');
        }
        if (mb_strlen($reason) > 1000) {
            throw new PipelineException('Please keep the reason under 1,000 characters.');
        }

        return $this->approveInner($id, $admin, $reason);
    }

    private function approveInner(int $id, User $admin, ?string $exceptionReason): Req
    {
        $voidInvoiceId = null;
        $req = DB::transaction(function () use ($id, $admin, $exceptionReason, &$voidInvoiceId) {
            [$ticket, $invoice, $req] = $this->lock($id);
            $this->requireStatus($req, [Req::NEW, Req::NEEDS_INFO], 'approve it');

            $work = ServiceWorkState::for($ticket);
            if ($exceptionReason === null && $work !== ServiceWorkState::NOT_STARTED) {
                throw new PipelineException('Work has already started on this service, so it cannot be approved normally. Decline it with an explanation, or approve it as an exception.');
            }
            if ($exceptionReason !== null && $work === ServiceWorkState::NOT_STARTED) {
                throw new PipelineException('Work has not started, so use the normal Approve.');
            }

            $paidCents = $invoice ? ServiceInvoicePayments::receivedCents($invoice) : 0;   // read from the invoice at approval time
            $req->forceFill([
                'decided_by' => $admin->id, 'decided_at' => now(),
                'approved_as_exception' => $exceptionReason !== null, 'exception_reason' => $exceptionReason,
            ]);

            if ($paidCents === 0) {
                $this->closeWithoutRefund($req, $ticket, $invoice, $admin, $exceptionReason);

                return $req;
            }
            if (!$req->refund_method || ($req->refund_method === 'gcash' && (!$req->gcash_number || !$req->gcash_account_name))) {
                throw new PipelineException('The customer has not given a refund method and details yet. Ask them for more information first.');
            }

            $req->forceFill([
                'status' => Req::APPROVED, 'approved_amount' => ServiceInvoiceDesk::money($paidCents / 100),
                'refund_due_at' => now()->addDays(Req::REFUND_DAYS),
            ])->save();

            $amount = ServiceInvoiceDesk::peso($paidCents / 100);
            $due    = $req->refund_due_at->timezone(self::TZ)->format('F j, Y');
            $this->say($req, 'system', ($exceptionReason !== null ? 'Approved as an exception. ' : 'Approved. ') . "We will return {$amount} by {$req->methodLabel()}. It takes up to " . Req::REFUND_DAYS . " days after approval, so by {$due}.");
            $this->note($ticket, $admin, "Cancellation request {$req->reference} approved" . ($exceptionReason !== null ? ' AS AN EXCEPTION (' . $exceptionReason . ')' : '')
                . ", refund of {$amount} by {$req->methodLabel()} due by {$due}");
            $this->notifyCustomer($req, 'refund_approved', [
                'reference'   => $ticket->reference_number,
                'refund_line' => "We will return {$amount} by {$req->methodLabel()}. A refund takes up to " . Req::REFUND_DAYS . " days after approval, so you should have it by {$due}.",
            ]);

            return $req;
        });

        return $req->refresh();
    }

    /** Close a request that has nothing to refund: cancel the invoice and ticket, release the hold. Runs inside the caller's transaction. */
    private function closeWithoutRefund(Req $req, ServiceInquiry $ticket, ?ServiceInvoice $invoice, User $admin, ?string $exceptionReason): void
    {
        $req->forceFill(['status' => Req::CLOSED, 'open_slot' => null])->save();
        $this->cancelTicketAndInvoice($ticket, $invoice, "Cancelled at the customer's request, {$req->reference}", "Cancelled at the customer's request ({$req->reference})");
        $this->say($req, 'system', 'Approved. Nothing was paid, so there is nothing to refund. The service request was cancelled.');
        $this->note($ticket, $admin, "Cancellation request {$req->reference} approved" . ($exceptionReason !== null ? ' as an exception' : '') . '; nothing paid, so the service request was cancelled');
        $this->notifyCustomer($req, 'refund_cancelled_no_refund', ['reference' => $ticket->reference_number]);
    }

    public function decline(int $id, User $admin, string $explanation): Req
    {
        $explanation = trim($explanation);
        if (mb_strlen($explanation) < self::MIN_DECLINE) {
            throw new PipelineException('Write an explanation for the customer (at least ' . self::MIN_DECLINE . ' characters).');
        }
        if (mb_strlen($explanation) > 1000) {
            throw new PipelineException('Please keep the explanation under 1,000 characters.');
        }

        return DB::transaction(function () use ($id, $admin, $explanation) {
            [$ticket, , $req] = $this->lock($id);
            $this->requireStatus($req, [Req::NEW, Req::NEEDS_INFO], 'decline it');

            // Declining lifts the hold: the invoice can be paid again if it could be before.
            $req->forceFill([
                'status' => Req::DECLINED, 'open_slot' => null, 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $explanation,
            ])->save();

            $this->say($req, 'admin', "Declined. {$explanation}", $admin->id);
            $this->note($ticket, $admin, "Cancellation request {$req->reference} declined");
            $this->notifyCustomer($req, 'refund_declined', ['reference' => $ticket->reference_number, 'explanation' => rtrim($explanation, '.') . '.']);

            return $req;
        })->refresh();
    }

    public function askForInfo(int $id, User $admin, string $message): Req
    {
        $message = trim($message);
        if (mb_strlen($message) < self::MIN_MESSAGE) {
            throw new PipelineException('Write your question for the customer (at least ' . self::MIN_MESSAGE . ' characters).');
        }
        if (mb_strlen($message) > 1000) {
            throw new PipelineException('Please keep the message under 1,000 characters.');
        }

        return DB::transaction(function () use ($id, $admin, $message) {
            [$ticket, , $req] = $this->lock($id);
            $this->requireStatus($req, [Req::NEW, Req::NEEDS_INFO], 'ask for more information');

            $req->forceFill(['status' => Req::NEEDS_INFO])->save();
            $this->say($req, 'admin', $message, $admin->id);
            $this->note($ticket, $admin, "Cancellation request {$req->reference}: asked the customer for more information");
            $this->notifyCustomer($req, 'refund_needs_info', ['reference' => $ticket->reference_number, 'message' => rtrim($message, '.') . '.']);

            return $req;
        })->refresh();
    }

    /**
     * "Mark refund as sent": a transaction (reference) number is required for transparency. The refund itself is
     * done outside the system. Cancels the invoice and the ticket (the only way a paid invoice is ever cancelled).
     *
     * @param array{amount: mixed, method: ?string, transaction_number: ?string, sent_on: ?string, note: ?string} $in
     */
    public function markRefunded(int $id, User $admin, array $in): Req
    {
        $tx = trim((string) ($in['transaction_number'] ?? ''));
        if (mb_strlen($tx) < 4) {
            throw new PipelineException('Enter the transaction number (the GCash reference or the cash receipt number, at least 4 characters).');
        }
        if (mb_strlen($tx) > 60) {
            throw new PipelineException('The transaction number is too long.');
        }
        if (!is_numeric($in['amount'] ?? null) || ServiceInvoiceDesk::cents($in['amount']) <= 0) {
            throw new PipelineException('Enter the amount that was sent.');
        }
        $sentOn = Carbon::parse($in['sent_on'] ?: 'today', self::TZ)->startOfDay();
        if ($sentOn->gt(Carbon::now(self::TZ)->startOfDay())) {
            throw new PipelineException('The date sent cannot be in the future.');
        }
        $note = self::blank($in['note'] ?? null);

        $req = DB::transaction(function () use ($id, $admin, $in, $tx, $sentOn, $note) {
            [$ticket, $invoice, $req] = $this->lock($id);
            $this->requireStatus($req, [Req::APPROVED], 'mark the refund as sent. It must be approved first');

            $method = $in['method'] ?: $req->refund_method;
            if (!isset(Req::METHODS[$method])) {
                throw new PipelineException('Choose GCash or cash.');
            }
            $cents    = ServiceInvoiceDesk::cents($in['amount']);
            $approved = ServiceInvoiceDesk::cents($req->approved_amount);
            if ($cents !== $approved && ($note === null || mb_strlen($note) < 10)) {
                throw new PipelineException('The amount sent (' . ServiceInvoiceDesk::peso($cents / 100) . ') is not the approved ' . ServiceInvoiceDesk::peso($approved / 100)
                    . '. Write a note explaining the difference (at least 10 characters).');
            }

            ServiceRefundAttempt::create([
                'service_refund_request_id' => $req->id, 'amount' => ServiceInvoiceDesk::money($cents / 100), 'method' => $method,
                'transaction_number' => $tx, 'sent_on' => $sentOn->toDateString(), 'outcome' => ServiceRefundAttempt::SENT,
                'note' => $note, 'admin_id' => $admin->id,
            ]);

            $req->forceFill(['status' => Req::REFUNDED, 'open_slot' => null])->save();
            $this->cancelTicketAndInvoice($ticket, $invoice, "Cancelled at the customer's request, {$req->reference}", "Cancelled at the customer's request ({$req->reference}), refunded");

            $amount = ServiceInvoiceDesk::peso($cents / 100);
            $label  = Req::METHODS[$method];
            $this->say($req, 'system', "Refund of {$amount} sent by {$label} on {$sentOn->format('F j, Y')}. Transaction number: {$tx}.");
            $this->note($ticket, $admin, "Cancellation request {$req->reference}: refund of {$amount} marked as sent by {$label}, transaction {$tx}. The service request and invoice were cancelled");
            $this->notifyCustomer($req, 'refund_refunded', ['reference' => $ticket->reference_number, 'amount' => $cents / 100, 'method' => $label, 'transaction' => $tx]);

            return $req;
        });

        return $req->refresh();
    }

    /** "Refund did not go through": the customer is told and can send corrected details. The attempt stays in the history. */
    public function markRefundFailed(int $id, User $admin, string $problem, ?string $note): Req
    {
        if (!isset(self::PROBLEMS[$problem])) {
            throw new PipelineException('Choose what went wrong.');
        }
        $note = self::blank($note);
        if ($problem === 'other' && ($note === null || mb_strlen($note) < 5)) {
            throw new PipelineException('Write a short note about what went wrong.');
        }

        return DB::transaction(function () use ($id, $admin, $problem, $note) {
            [$ticket, , $req] = $this->lock($id);
            $this->requireStatus($req, [Req::APPROVED], 'record a failed refund. It must be approved first');

            ServiceRefundAttempt::create([
                'service_refund_request_id' => $req->id, 'amount' => $req->approved_amount, 'method' => $req->refund_method,
                'sent_on' => Carbon::now(self::TZ)->toDateString(),
                'outcome' => $problem === 'other' ? ServiceRefundAttempt::FAILED_OTHER : ServiceRefundAttempt::FAILED_WRONG_DETAILS,
                'note' => self::PROBLEMS[$problem] . ($note ? '. ' . $note : ''), 'admin_id' => $admin->id,
            ]);
            $req->forceFill(['status' => Req::REFUND_PROBLEM])->save();

            $text = mb_strtolower(self::PROBLEMS[$problem]);
            $this->say($req, 'admin', 'We could not send your refund: ' . $text . '.' . ($note ? ' ' . $note : '') . ' Please send the correct details below.', $admin->id);
            $this->note($ticket, $admin, "Cancellation request {$req->reference}: refund did not go through ({$text})");
            $this->notifyCustomer($req, 'refund_problem', ['reference' => $ticket->reference_number, 'problem' => $text]);

            return $req;
        })->refresh();
    }

    /* ───────────── customer replies ───────────── */

    /**
     * The customer answers a question (needs_info) or sends corrected refund details (refund_problem).
     *
     * @param array{body?: ?string, gcash_number?: ?string, gcash_account_name?: ?string} $in
     */
    public function customerReply(int $id, User $customer, array $in): Req
    {
        $req = Req::findOrFail($id);
        if ((int) $req->user_id !== (int) $customer->id) {
            throw new ModelNotFoundException();
        }
        $body = trim((string) ($in['body'] ?? ''));

        return DB::transaction(function () use ($id, $customer, $in, $body) {
            [$ticket, , $req] = $this->lock($id);
            if ((int) $req->user_id !== (int) $customer->id) {
                throw new ModelNotFoundException();
            }
            $this->requireStatus($req, [Req::NEEDS_INFO, Req::REFUND_PROBLEM], 'reply to it');

            if ($req->status === Req::REFUND_PROBLEM) {
                if ($req->refund_method === 'gcash') {
                    $number = self::normalizeGcash($in['gcash_number'] ?? null);
                    $name   = trim((string) ($in['gcash_account_name'] ?? ''));
                    if ($number === null || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                        throw ValidationException::withMessages(['gcash_number' => 'Enter your GCash number (like 09171234567) and the name on the account.']);
                    }
                    $req->forceFill(['gcash_number' => $number, 'gcash_account_name' => NameFormat::proper($name)]);
                }
                $req->forceFill(['status' => Req::APPROVED])->save();
                $this->say($req, 'customer', $body !== '' ? $body : 'I sent corrected refund details.');
                $this->note($ticket, $customer, "Cancellation request {$req->reference}: the customer sent corrected refund details");
            } else {
                if (mb_strlen($body) < 2 || mb_strlen($body) > 1000) {
                    throw ValidationException::withMessages(['body' => 'Write your reply (up to 1,000 characters).']);
                }
                $req->forceFill(['status' => Req::NEW])->save();
                $this->say($req, 'customer', $body);
                $this->note($ticket, $customer, "Cancellation request {$req->reference}: the customer replied");
            }

            $this->notifyAdmins($req, $ticket, 'admin_refund_reply', ['customer' => $ticket->name], $customer);

            return $req;
        })->refresh();
    }

    /* ───────────── closing a ticket, and "Work started" ───────────── */

    /**
     * The admin's "close / cancel ticket". A ticket with money on it is NOT cancelled here (it must go through a cancellation
     * request so the refund is recorded), unless the service is finished and fully paid, which is a normal completion.
     * A ticket with an unpaid sent invoice is cancelled together with the invoice and the customer is told.
     */
    public function adminClose(int $ticketId, User $admin, string $reason): ServiceInquiry
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PipelineException('A reason is required to close a ticket.');
        }

        $voidedInvoiceId = null;
        $ticket = DB::transaction(function () use ($ticketId, $admin, $reason, &$voidedInvoiceId) {
            $ticket = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            if (!$ticket->isOpen()) {
                throw new PipelineException('This ticket is closed, so you cannot close it.');
            }
            if ($open = $ticket->openRefundRequest()) {
                throw new PipelineException("This ticket has an open cancellation request ({$open->reference}). Decide that request first.");
            }

            $invoice = ServiceInvoice::where('service_inquiry_id', $ticket->id)->where('status', '!=', ServiceInvoice::VOID)->orderByDesc('id')->lockForUpdate()->first();

            if ($invoice && $invoice->status !== ServiceInvoice::DRAFT && $invoice->hasPayment()) {
                if ($invoice->status === ServiceInvoice::PAID && $invoice->service_done_at) {
                    $ticket->status = 'closed';   // finished and paid: a normal completion
                    $ticket->closed_reason = $reason;
                    $this->tickets->appendNote($ticket, $admin, 'Closed. ' . $reason);
                    $ticket->save();

                    return $ticket;
                }
                throw new PipelineException(self::PAYMENT_ON_TICKET);
            }

            if ($invoice && $invoice->status === ServiceInvoice::ISSUED) {
                $this->cancelTicketAndInvoice($ticket, $invoice, 'Our team cancelled this service request. ' . $reason, $reason);
                $voidedInvoiceId = $invoice->id;
                $this->tickets->appendNote($ticket, $admin, "Cancelled. Invoice {$invoice->invoice_number} was cancelled with it. {$reason}");
                $ticket->save();
                if ($ticket->user_id) {
                    $t = NotificationTemplates::render('service_request_cancelled', ['reference' => $ticket->reference_number]);
                    Notification::create(['user_id' => $ticket->user_id, 'title' => $t['title'], 'message' => $t['message'], 'type' => 'general',
                        'url' => route('services.invoice', $invoice->id), 'is_read' => false]);
                }

                return $ticket;
            }

            $ticket->status = 'closed';
            $ticket->closed_reason = $reason;
            $this->tickets->appendNote($ticket, $admin, 'Closed. ' . $reason);
            $ticket->save();

            return $ticket;
        });

        if ($voidedInvoiceId) {
            $this->payments->closeOpenCheckouts($voidedInvoiceId, 'The service request was cancelled');
        }

        return $ticket;
    }

    public function startWork(int $ticketId, User $admin): ServiceInquiry
    {
        return DB::transaction(function () use ($ticketId, $admin) {
            $ticket = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            if (!$ticket->isOpen()) {
                throw new PipelineException('This ticket is closed or cancelled, so work cannot be started.');
            }
            if ($ticket->service_started_at) {
                throw new PipelineException('Work is already marked as started.');
            }
            $ticket->service_started_at = now();
            $this->tickets->appendNote($ticket, $admin, 'Work started. After this, the customer can no longer get an automatic refund');
            $ticket->save();

            return $ticket;
        });
    }

    /** Undo is allowed only while no cancellation request is open, and only if the service is not already done. */
    public function undoStartWork(int $ticketId, User $admin): ServiceInquiry
    {
        return DB::transaction(function () use ($ticketId, $admin) {
            $ticket = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            if (!$ticket->service_started_at) {
                throw new PipelineException('Work is not marked as started.');
            }
            if ($ticket->openRefundRequest()) {
                throw new PipelineException('A cancellation request is open on this ticket, so "Work started" cannot be undone.');
            }
            if (ServiceWorkState::for($ticket) === ServiceWorkState::DONE) {
                throw new PipelineException('The service is already marked as done, so "Work started" cannot be undone.');
            }
            $ticket->service_started_at = null;
            $this->tickets->appendNote($ticket, $admin, 'Work started was undone');
            $ticket->save();

            return $ticket;
        });
    }

    /* ───────────── internals ───────────── */

    private function assertCanAct(ServiceInquiry $ticket, User $actor, string $source): void
    {
        if ($source === 'admin') {
            if ($actor->role !== 'admin') {
                throw new ModelNotFoundException();
            }

            return;
        }
        if (!$ticket->user_id || (int) $ticket->user_id !== (int) $actor->id) {
            throw new ModelNotFoundException();   // someone else's ticket: indistinguishable from a missing one
        }
    }

    /** @return array{0: ServiceInquiry, 1: ?ServiceInvoice, 2: Req} ticket, invoice, request: the same lock order everywhere */
    private function lock(int $requestId): array
    {
        $peek = Req::findOrFail($requestId);
        $ticket  = ServiceInquiry::lockForUpdate()->findOrFail($peek->service_inquiry_id);
        $invoice = $peek->service_invoice_id ? ServiceInvoice::lockForUpdate()->find($peek->service_invoice_id) : null;
        $req     = Req::lockForUpdate()->findOrFail($requestId);

        return [$ticket, $invoice, $req];
    }

    private function requireStatus(Req $req, array $allowed, string $action): void
    {
        if (!in_array($req->status, $allowed, true)) {
            throw new PipelineException('This request is ' . strtolower($req->statusLabel()) . ", so you cannot {$action}.");
        }
    }

    /** The invoice becomes Cancelled (even a paid one: this is the only path that may) and the ticket Cancelled. */
    private function cancelTicketAndInvoice(ServiceInquiry $ticket, ?ServiceInvoice $invoice, string $invoiceReason, string $ticketReason): void
    {
        if ($invoice && in_array($invoice->status, [ServiceInvoice::ISSUED, ServiceInvoice::PAID], true)) {
            $invoice->forceFill([
                'status' => ServiceInvoice::VOID, 'voided_at' => Carbon::now(self::TZ)->setTimezone(config('app.timezone')), 'void_reason' => $invoiceReason,
            ])->save();
        }
        $ticket->status = 'cancelled';
        $ticket->closed_reason = $ticketReason;
        $ticket->save();
    }

    private function insert(array $attributes): Req
    {
        for ($try = 0; ; $try++) {
            try {
                // A savepoint keeps a duplicate-key error from poisoning the outer transaction.
                return DB::transaction(fn () => Req::create($attributes + ['reference' => self::nextReference()]));
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'srr_one_open_per_invoice') || str_contains($e->getMessage(), 'open_slot')) {
                    throw new PipelineException('There is already an open cancellation request for this service.');
                }
                if ($try >= 4 || !str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }
            }
        }
    }

    /** REF-YYYY-NNNN for the Manila year. The unique index is the real guard; insert retries. */
    public static function nextReference(): string
    {
        $prefix = 'REF-' . Carbon::now(self::TZ)->format('Y') . '-';
        $last   = Req::where('reference', 'like', $prefix . '%')->orderByRaw('LENGTH(reference) DESC')->orderByDesc('reference')->value('reference');
        $n      = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function say(Req $req, string $sender, string $body, ?int $adminId = null): void
    {
        ServiceRefundMessage::create(['service_refund_request_id' => $req->id, 'sender' => $sender, 'admin_id' => $adminId, 'body' => $body]);
    }

    /** A line in the ticket's activity log. Never contains a GCash number. */
    private function note(ServiceInquiry $ticket, User $who, string $text): void
    {
        $this->tickets->appendNote($ticket, $who, $text);
        $ticket->save();
    }

    private function notifyCustomer(Req $req, string $template, array $values): void
    {
        if (!$req->user_id) {
            return;
        }
        $t = NotificationTemplates::render($template, $values + ['request' => $req->reference]);
        Notification::create([
            'user_id' => $req->user_id, 'title' => $t['title'], 'message' => $t['message'],
            'type' => 'general', 'url' => route('services.refund.show', $req->reference), 'is_read' => false,
        ]);
    }

    private function notifyAdmins(Req $req, ServiceInquiry $ticket, string $template, array $values, User $except): void
    {
        $t = NotificationTemplates::render($template, $values + ['request' => $req->reference]);
        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            if ((int) $adminId === (int) $except->id) {
                continue;
            }
            Notification::create([
                'user_id' => $adminId, 'title' => $t['title'], 'message' => $t['message'],
                'type' => 'general', 'url' => route('admin.refunds.show', $req->id), 'is_read' => false,
            ]);
        }
    }

    private static function blank(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
