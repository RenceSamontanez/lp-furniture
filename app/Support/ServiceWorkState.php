<?php

namespace App\Support;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;

/**
 * Where the actual work on an Other Services ticket stands: not_started, started or done.
 * Started = the admin tapped "Work started" (or "Service done", which starts it if it was not). Done = the
 * live invoice has "Service done". Used by every admin and customer page and by the refund rules, so there is
 * one definition of "has the laborer started".
 */
final class ServiceWorkState
{
    public const NOT_STARTED = 'not_started';
    public const STARTED     = 'started';
    public const DONE        = 'done';

    public const LABELS = [
        self::NOT_STARTED => 'Not started',
        self::STARTED     => 'Work started',
        self::DONE        => 'Done',
    ];

    public static function for(ServiceInquiry $ticket): string
    {
        $done = ServiceInvoice::where('service_inquiry_id', $ticket->id)
            ->whereIn('status', [ServiceInvoice::ISSUED, ServiceInvoice::PAID])
            ->whereNotNull('service_done_at')->exists();

        return match (true) {
            $done                       => self::DONE,
            (bool) $ticket->service_started_at => self::STARTED,
            default                     => self::NOT_STARTED,
        };
    }

    public const ALLOWED = 'allowed';

    /** Plain-words explanation per reason code (admin wording). */
    public const START_MESSAGES = [
        'no_invoice'        => 'Create an invoice first. Work starts after the customer pays.',
        'invoice_draft'     => 'The invoice has not been sent yet. Work starts after the customer pays.',
        'awaiting_payment'  => 'The customer has not paid enough yet. Work starts after payment.',
        'invoice_cancelled' => 'The invoice or the service request was cancelled, so work cannot start.',
        'on_hold'           => 'A cancellation request is open, so work cannot start until it is decided.',
        'already_started'   => 'Work is already marked as started.',
    ];

    /**
     * "No pay, no work": the ONE decision on whether work may start on a ticket. Returns self::ALLOWED or a reason code:
     * no_invoice, invoice_draft, awaiting_payment, invoice_cancelled, on_hold, already_started.
     * Paid enough = pay in full: the invoice is fully paid; half and half: the first half is paid. Cash recorded by
     * an admin counts the same as online payments (both land in amount_paid).
     */
    public static function canStart(ServiceInquiry $ticket): string
    {
        if ($ticket->service_started_at) {
            return 'already_started';
        }
        if (!$ticket->isOpen()) {
            return 'invoice_cancelled';
        }
        if ($ticket->openRefundRequest()) {
            return 'on_hold';
        }

        $invoice = ServiceInvoice::where('service_inquiry_id', $ticket->id)
            ->whereIn('status', [ServiceInvoice::DRAFT, ServiceInvoice::ISSUED, ServiceInvoice::PAID])
            ->orderByDesc('id')->first();
        if (!$invoice) {
            return ServiceInvoice::where('service_inquiry_id', $ticket->id)->where('status', ServiceInvoice::VOID)->exists()
                ? 'invoice_cancelled' : 'no_invoice';
        }
        if ($invoice->isDraft()) {
            return 'invoice_draft';
        }

        return self::paidEnough($invoice) ? self::ALLOWED : 'awaiting_payment';
    }

    public static function paidEnough(ServiceInvoice $invoice): bool
    {
        if ($invoice->status === ServiceInvoice::PAID) {
            return true;
        }
        $first = $invoice->installmentCents()[1] ?? $invoice->totalCents();

        return $invoice->paidCents() > 0 && $invoice->paidCents() >= $first;
    }

    /** True when work was started although nothing (enough) was paid: data from before this rule existed. */
    public static function startedWithoutPayment(ServiceInquiry $ticket): bool
    {
        if (!$ticket->service_started_at) {
            return false;
        }
        $invoice = ServiceRefundRequestService::liveInvoice($ticket);

        return !$invoice || !self::paidEnough($invoice);
    }


    /** First name of the admin who started the work, read from the ticket's note log (null when unknown). */
    public static function startedBy(ServiceInquiry $ticket): ?string
    {
        $lines = array_reverse(explode("\n", (string) $ticket->admin_notes));
        foreach ($lines as $line) {
            $parts = explode(' · ', $line, 3);
            if (count($parts) === 3 && (str_starts_with($parts[2], 'Work started (') || str_starts_with($parts[2], 'Work started.') || str_contains($parts[2], 'service marked as done'))) {
                return $parts[1];
            }
            if (count($parts) === 3 && str_starts_with($parts[2], 'Work started was undone')) {
                return null;
            }
        }

        return null;
    }
    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /** Refunds are automatic-eligible (can be approved normally) only before the work starts. */
    public static function refundable(string $state): bool
    {
        return $state === self::NOT_STARTED;
    }
}
