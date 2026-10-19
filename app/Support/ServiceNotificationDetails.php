<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Models\ServiceRefundRequest;

/**
 * Read-only "details" for an Other Services notification (Stage 21h). Every Other Services notification is recognised by
 * its link (always under /services/) and its title (the NotificationTemplates titles), so older notifications work too.
 *
 * There is no JSON data column on the notifications table, so no snapshot is stored. What the page shows is the
 * notification's own stored text (what was true when it was sent) plus facts from the records it points to, which are
 * write-once (a sent invoice is locked, a request's reason, decision and refund attempts are never rewritten). When the
 * request or invoice has moved on since, a "Current status" line says so.
 */
final class ServiceNotificationDetails
{
    /** notification title => kind */
    private const TITLES = [
        'Service Request Received'              => 'request_received',
        'We Could Not Reach You'                => 'call_update',
        'We Called You'                         => 'call_update',
        'Update on Your Service Request'        => 'call_update',
        'Your Invoice Is Ready'                 => 'invoice_ready',
        'Your Invoice Was Cancelled'            => 'invoice_cancelled',
        'Payment Received'                      => 'payment_received',
        'Payment Recorded'                      => 'payment_received',
        'Balance Due Now'                       => 'balance_due',
        'Invoice Fully Paid'                    => 'fully_paid',
        'Payment Could Not Be Completed'        => 'payment_failed',
        'Your Service Request Was Cancelled'    => 'request_cancelled',
        'Cancellation Request Received'         => 'cancellation_received',
        'We Need a Little More Information'     => 'needs_info',
        'Your Request Was Approved'             => 'approved',
        'Your Refund Was Sent'                  => 'refunded',
        'Your Refund Needs Your Attention'      => 'refund_problem',
        'Your Request Was Not Approved'         => 'declined',
        'Service Request Cancelled'             => 'cancelled_no_refund',
    ];

    public const KINDS = [
        'request_received', 'call_update', 'invoice_ready', 'invoice_cancelled', 'payment_received', 'balance_due', 'fully_paid',
        'payment_failed', 'request_cancelled', 'cancellation_received', 'needs_info', 'approved', 'refunded', 'refund_problem',
        'declined', 'cancelled_no_refund',
    ];

    /** True when this notification is an Other Services one (its link points under /services/). */
    public static function isServiceNotification(Notification $n): bool
    {
        return self::kind($n) !== null;
    }

    public static function kind(Notification $n): ?string
    {
        $path = (string) parse_url((string) $n->url, PHP_URL_PATH);
        if (!str_starts_with($path, '/services/')) {
            return null;
        }

        return self::TITLES[$n->title] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function build(Notification $n): ?array
    {
        $kind = self::kind($n);
        if ($kind === null) {
            return null;
        }

        [$ticket, $invoice, $req] = self::records($n);
        $peso = fn ($v) => ServiceInvoiceDesk::peso($v);
        $day  = fn ($d) => $d?->timezone('Asia/Manila')->format('F j, Y');

        $facts = [];
        if ($ticket) {
            $facts['Service type'] = $ticket->serviceLabel();
            if ($ticket->furniture_type) {
                $facts['Furniture type'] = $ticket->furniture_type;
            }
        }
        $refs = [];
        if ($ticket) {
            $refs['Service request'] = $ticket->reference_number;
        }
        if ($invoice && $invoice->invoice_number) {
            $refs['Invoice'] = $invoice->invoice_number;
            $facts['Invoice total'] = $peso($invoice->total);
        }
        if ($req) {
            $refs['Cancellation request'] = $req->reference;
        }

        $reason = null;
        $next   = null;
        $important = null;
        $headline  = $n->title;
        $current   = null;

        switch ($kind) {
            case 'request_received':
                $headline  = 'We received your service request';
                $next      = 'Our team will call you on a weekday between 8:00 AM and 5:00 PM to talk about it. No payment is due at this stage.';
                $important = ['info', 'No payment is due yet', 'This is a request, not an order.'];
                break;
            case 'call_update':
                $next = 'Please call or email us if you missed us, or wait for our next call. You can follow your request under My service requests.';
                break;
            case 'invoice_ready':
                $headline  = 'Your invoice is ready';
                $next      = 'Open your invoice to see the details and pay online by GCash or card, or pay in cash in person.';
                $important = $invoice && $invoice->isHalfHalf()
                    ? ['waiting', 'Half now, half when the service is done', 'You pay the first half now. The second half opens when we mark your service as done.'] : null;
                break;
            case 'invoice_cancelled':
                $headline  = 'Your invoice was cancelled';
                $reason    = $invoice?->void_reason ? ['Why it was cancelled', $invoice->void_reason] : null;
                $important = ['blocked', 'You do not need to pay this invoice', 'It can no longer be paid.'];
                $next      = 'If a new invoice is needed, we will send it to you and tell you here.';
                break;
            case 'payment_received':
                $headline = 'We received your payment';
                if ($invoice) {
                    $facts['Paid so far'] = $peso($invoice->paidCents() / 100);
                    $facts['Balance'] = $peso($invoice->balanceCents() / 100);
                }
                $next = 'Your invoice shows every payment. A half and half invoice opens the second half after the service is done.';
                break;
            case 'balance_due':
                $headline  = 'Your balance is due now';
                $important = ['waiting', 'Your service is done', 'The remaining balance can now be paid online from your invoice page, or in cash.'];
                if ($invoice) {
                    $facts['Balance'] = $peso($invoice->balanceCents() / 100);
                }
                $next = 'Open your invoice to pay the balance.';
                break;
            case 'fully_paid':
                $headline  = 'Your invoice is fully paid';
                $important = ['success', 'Fully paid', 'Thank you. Nothing more is due on this invoice.'];
                $next      = 'You can open or print the paid invoice any time.';
                break;
            case 'payment_failed':
                $headline  = 'Your payment could not be completed';
                $important = ['problem', 'You were not charged', 'You can try again from your invoice page.'];
                $next      = 'Open your invoice and choose a payment method again.';
                break;
            case 'request_cancelled':
                $headline  = 'Your service request was cancelled';
                $reason    = ($ticket?->closed_reason ?: $invoice?->void_reason) ? ['Why it was cancelled', $ticket?->closed_reason ?: $invoice->void_reason] : null;
                $important = ['blocked', 'Our team cancelled this request', 'You do not need to pay anything more for it.'];
                $next      = 'If you have questions, call or email us and mention the reference number.';
                break;
            case 'cancellation_received':
                $headline  = 'We received your cancellation request';
                $reason    = $req ? ['Your reason', trim($req->reasonLabel() . ($req->reason_text ? ': ' . $req->reason_text : ''))] : null;
                if ($req && $req->amount_paid_snapshot > 0) {
                    $facts['Amount paid when you asked'] = $peso($req->amount_paid_snapshot);
                }
                $important = ['waiting', 'Payments on this service are paused', 'They stay paused until we reply. If a refund is approved, it takes up to ' . ServiceRefundRequest::REFUND_DAYS . ' days after approval.'];
                $next      = 'We will review your request and reply here.';
                break;
            case 'needs_info':
                $headline  = 'We need a little more information';
                $important = ['waiting', 'Please reply from the request page', 'Payments stay paused until we reply.'];
                $next      = 'Open the request and answer our question.';
                break;
            case 'approved':
                $headline = 'Your cancellation request was approved';
                if ($req) {
                    $facts['Refund amount'] = $req->approved_amount !== null ? $peso($req->approved_amount) : 'Nothing to refund';
                    if ($req->methodLabel()) {
                        $facts['Refund method'] = $req->methodLabel();
                    }
                    if ($req->refund_due_at) {
                        $facts['Refund due by'] = $day($req->refund_due_at);
                    }
                }
                $important = ['success', 'The full amount you paid', 'A refund takes up to ' . ServiceRefundRequest::REFUND_DAYS . ' days after approval.'];
                $next      = 'We will tell you here when the refund is sent.';
                break;
            case 'refunded':
                $headline = 'Your refund was sent';
                $sent     = $req?->attempts()->where('outcome', 'sent')->orderByDesc('id')->first();
                if ($sent) {
                    $facts['Refund amount']     = $peso($sent->amount);
                    $facts['Refund method']     = ServiceRefundRequest::METHODS[$sent->method] ?? $sent->method;
                    $facts['Transaction number'] = $sent->transaction_number;
                    $facts['Date sent']         = $sent->sent_on?->format('F j, Y');
                }
                $important = ['success', 'Refund sent', 'Your service request and invoice are cancelled.'];
                $next      = 'Please check your GCash or ask us for your cash refund. Call or email us if anything looks wrong.';
                break;
            case 'refund_problem':
                $headline  = 'Your refund needs your attention';
                $important = ['problem', 'We could not send your refund', 'Please send the correct details from the request page and we will try again.'];
                $next      = 'Open the request and send your corrected details.';
                break;
            case 'declined':
                $headline  = 'We could not approve your cancellation request';
                $reason    = $req?->decision_note ? ['Our explanation', $req->decision_note] : null;
                $important = ['blocked', 'Your service request stays open', 'Payments open again as before.'];
                $next      = 'If you have questions, call or email us and mention the reference number.';
                break;
            case 'cancelled_no_refund':
                $headline  = 'Your service request was cancelled';
                $reason    = $req ? ['Your reason', trim($req->reasonLabel() . ($req->reason_text ? ': ' . $req->reason_text : ''))] : null;
                $important = ['success', 'Cancelled, nothing to refund', 'No payment was made.'];
                $next      = 'You can send a new service request any time.';
                break;
        }

        if ($req && !in_array($kind, ['request_received', 'call_update', 'invoice_ready', 'invoice_cancelled', 'payment_received', 'balance_due', 'fully_paid', 'payment_failed', 'request_cancelled'], true)) {
            $expected = self::requestStatusFor($kind);
            if ($expected && $req->status !== $expected && !($kind === 'needs_info' && $req->status === 'new')) {
                $current = 'Current status: ' . $req->statusLabel();
            }
            if ($kind === 'needs_info' && $req->status === 'new') {
                $current = 'Current status: ' . $req->statusLabel() . ' (we have your reply)';
            }
        } elseif ($invoice && in_array($kind, ['invoice_ready', 'payment_received', 'balance_due'], true)) {
            $label = $invoice->status === ServiceInvoice::PAID ? 'Paid' : ($invoice->status === ServiceInvoice::VOID ? 'Cancelled' : null);
            if ($label) {
                $current = 'Current status: ' . $label;
            }
        }

        return [
            'kind'        => $kind,
            'headline'    => $headline,
            'when'        => $n->created_at?->timezone('Asia/Manila')->format('F j, Y g:i A'),
            'happened'    => self::plain($n->message),
            'facts'       => array_filter($facts, fn ($v) => $v !== null && $v !== ''),
            'refs'        => $refs,
            'reason'      => $reason,
            'next'        => $next,
            'important'   => $important,
            'current'     => $current,
            'ask_ref'     => $req?->reference ?? ($invoice?->invoice_number ?: $ticket?->reference_number),
            'list_url'    => route('services.request'),
            'item_url'    => $req ? route('services.refund.show', $req->reference) : ($invoice && $invoice->status !== 'draft' ? route('services.invoice', $invoice->id) : null),
            'ticket'      => $ticket,
        ];
    }

    /** What status a request had when the notification of this kind was sent. */
    private static function requestStatusFor(string $kind): ?string
    {
        return match ($kind) {
            'cancellation_received' => 'new',
            'needs_info'            => 'needs_info',
            'approved'              => 'approved',
            'refunded'              => 'refunded',
            'refund_problem'        => 'refund_problem',
            'declined'              => 'declined',
            'cancelled_no_refund'   => 'closed',
            default                 => null,
        };
    }

    /** The stored message without the "Call us at ... or email ..." contact sentence the templates append. */
    private static function plain(string $message): string
    {
        return trim($message);
    }

    /**
     * The ticket, invoice and request this notification is about, found from its link (and the reference in its text).
     * Always scoped to the notification's own user, so a crafted link can never expose another customer's record.
     *
     * @return array{0: ?ServiceInquiry, 1: ?ServiceInvoice, 2: ?ServiceRefundRequest}
     */
    private static function records(Notification $n): array
    {
        $path = (string) parse_url((string) $n->url, PHP_URL_PATH);
        $ticket = $invoice = $req = null;

        if (preg_match('#^/services/refunds/(REF-\d{4}-\d{4,})#', $path, $m)) {
            $req = ServiceRefundRequest::where('user_id', $n->user_id)->where('reference', $m[1])->first();
            $ticket = $req?->inquiry;
            $invoice = $req?->invoice;
        } elseif (preg_match('#^/services/invoices/(\d+)#', $path, $m)) {
            $invoice = ServiceInvoice::where('user_id', $n->user_id)->find((int) $m[1]);
            $ticket = $invoice?->inquiry;
            $req = $ticket?->refundRequests()->where('user_id', $n->user_id)->first();
            if ($req && !in_array(self::kind($n), ['cancellation_received', 'needs_info', 'approved', 'refunded', 'refund_problem', 'declined'], true)) {
                $req = null;
            }
        } elseif (preg_match('/SI-\d{4}-\d{4,}/', (string) $n->message, $m)) {
            $ticket = ServiceInquiry::where('user_id', $n->user_id)->where('reference_number', $m[0])->first();
        }

        if (!$ticket && $req) {
            $ticket = $req->inquiry;
        }

        return [$ticket, $invoice, $req];
    }
}
