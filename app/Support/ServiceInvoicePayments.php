<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment as Payment;
use App\Models\ServiceInvoiceRefundNote;
use App\Models\ServiceInquiry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Online and cash payments for "Other Services" invoices. A separate flow: it does not use the custom-order
 * payment ledger, quotes or agreements, and it never changes how those orders are paid.
 *
 * Rules (see InvoicePaymentRules): one plan per invoice, "full" or "half_half". The second half can only be paid
 * after the admin taps "Service done". Only a verified gateway confirmation (webhook, or a server-side check of
 * the gateway's own record) or an admin recording cash marks a payment paid. Amounts always come from the
 * invoice, never from the request. Nothing here ever triggers a refund.
 *
 * Lock order is the same as ServiceInvoiceDesk: ticket first, then invoice (payment confirmations lock only the invoice).
 */
final class ServiceInvoicePayments
{
    public const CANCEL_BLOCKED = 'This invoice has a payment on it. If the customer needs a refund, record a refund note instead.';

    public function __construct(private ServiceInquiryDesk $tickets, private InvoiceGateway $gateway)
    {
    }

    /* ───────────── what is due ───────────── */

    /**
     * The next installment of an invoice.
     *
     * @return array{installment: ?int, cents: int, available: bool, reason: ?string, label: string, paid: list<int>}
     *   installment null = nothing left to pay. available false = shown but disabled (reason says why).
     */
    public static function due(ServiceInvoice $invoice): array
    {
        $plan  = $invoice->installmentCents();
        $paid  = $invoice->payments()->where('status', Payment::PAID)->pluck('installment')->map(fn ($n) => (int) $n)->all();
        $next  = null;
        foreach (array_keys($plan) as $n) {
            if (!in_array($n, $paid, true)) {
                $next = $n;
                break;
            }
        }

        $base = ['installment' => $next, 'cents' => $next ? $plan[$next] : 0, 'available' => false, 'reason' => null, 'paid' => $paid,
            'label' => $next === null ? 'Paid' : (count($plan) === 1 ? 'Pay in full' : ($next === 1 ? 'Pay first half' : 'Pay remaining balance'))];

        if ($next === null || $invoice->status === ServiceInvoice::PAID) {
            return ['installment' => null] + $base;
        }
        if (!$invoice->isPayable()) {
            $base['reason'] = 'This invoice can no longer be paid.';

            return $base;
        }
        if ($next === 2 && !$invoice->service_done_at) {
            $base['reason'] = 'Available when the service is done';

            return $base;
        }

        $base['available'] = true;

        return $base;
    }

    /** Why this amount cannot be paid online (GCash or card), or null. */
    public static function onlineProblem(int $cents): ?string
    {
        if ($cents < InvoicePaymentRules::minOnlineCents()) {
            return 'Online payments must be at least ' . ServiceInvoiceDesk::peso(InvoicePaymentRules::MIN_ONLINE_PAYMENT) . '. Please pay this one in cash, or contact us.';
        }

        return StripeCheckoutParams::amountProblem($cents);
    }

    /* ───────────── starting an online payment ───────────── */

    /**
     * Creates (or reuses) a pending payment for the installment due now and returns where to send the customer.
     * Throws PipelineException with a message that is safe to show. The caller has already checked ownership.
     */
    public function start(int $invoiceId, string $method): string
    {
        if (!in_array($method, [Payment::STRIPE, Payment::PAYMONGO], true)) {
            throw new PipelineException('Choose GCash or card.');
        }

        $reuse = null;
        $row   = null;

        DB::transaction(function () use ($invoiceId, $method, &$reuse, &$row) {
            $invoice = ServiceInvoice::lockForUpdate()->findOrFail($invoiceId);

            // Belt and braces: the route middleware already refuses these, but a payment must never start on a
            // cancelled, draft or paid invoice even if a route is ever missed.
            if (!$invoice->isPayable()) {
                throw new PipelineException($invoice->status === ServiceInvoice::PAID
                    ? 'This invoice is already paid.' : 'This invoice can no longer be paid.');
            }

            $due = self::due($invoice);
            if ($due['installment'] === null) {
                throw new PipelineException('This invoice is already paid.');
            }
            if (!$due['available']) {
                throw new PipelineException(($due['reason'] ?? 'This payment is not available yet') . '.');
            }
            if ($problem = self::onlineProblem($due['cents'])) {
                throw new PipelineException($problem);
            }

            $existing = Payment::where('service_invoice_id', $invoice->id)->where('installment', $due['installment'])
                ->where('method', $method)->where('status', Payment::PENDING)->orderByDesc('id')->lockForUpdate()->first();

            if ($existing) {
                $stillValid = (!$existing->expires_at || $existing->expires_at->isFuture())
                    && ServiceInvoiceDesk::cents($existing->amount) === $due['cents'];

                if ($stillValid && $existing->checkout_url) {
                    $reuse = $existing->checkout_url;

                    return;
                }
                if ($stillValid && $existing->created_at && $existing->created_at->gt(now()->subSeconds(60))) {
                    throw new PipelineException('Your payment is being prepared. Please wait a moment and try again.');
                }
                $existing->forceFill(['status' => Payment::EXPIRED, 'note' => 'Replaced by a newer attempt'])->save();
            }

            $row = Payment::create([
                'service_invoice_id' => $invoice->id, 'installment' => $due['installment'], 'amount' => ServiceInvoiceDesk::money($due['cents'] / 100),
                'method' => $method, 'status' => Payment::PENDING,
            ]);
        });

        if ($reuse !== null) {
            return $reuse;
        }

        $invoice = ServiceInvoice::findOrFail($invoiceId);

        try {
            [$reference, $url, $expires] = $method === Payment::STRIPE
                ? $this->createStripe($invoice, $row)
                : $this->createPaymongo($invoice, $row);
        } catch (\Throwable $e) {
            \Log::error("Invoice {$invoice->id}: {$method} payment could not be started: " . StripeErrors::describe($e));
            $row->forceFill(['status' => Payment::FAILED, 'note' => 'Could not be started'])->save();

            throw new PipelineException("We couldn't start the payment. Please try again, or use the other payment method.");
        }

        $row->forceFill(['provider_reference' => $reference, 'checkout_url' => $url, 'expires_at' => $expires])->save();

        return $url;
    }

    private function createStripe(ServiceInvoice $invoice, Payment $row): array
    {
        $cents   = ServiceInvoiceDesk::cents($row->amount);
        $expires = now()->addHours(2);
        $half    = $invoice->isHalfHalf() ? ($row->installment === 1 ? ' (first half)' : ' (remaining balance)') : '';

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'php',
                    'product_data' => ['name' => mb_substr('LP Furniture Invoice ' . $invoice->invoice_number . $half, 0, StripeCheckoutParams::NAME_MAX)],
                    'unit_amount' => $cents,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'client_reference_id' => 'inv-' . $invoice->id,
            'success_url' => route('services.invoice.payment.return', [$invoice->id, 'success']) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('services.invoice.payment.return', [$invoice->id, 'cancelled']),
            'metadata' => [
                'kind' => 'service_invoice', 'service_invoice_payment_id' => (string) $row->id, 'service_invoice_id' => (string) $invoice->id,
                'installment' => (string) $row->installment, 'user_id' => (string) $invoice->user_id,
            ],
        ];
        if ($at = StripeCheckoutParams::expiresAt($expires)) {
            $params['expires_at'] = $at;
        }

        $session = $this->gateway->createStripeSession($params);

        return [(string) $session->id, (string) $session->url, $at ? Carbon::createFromTimestamp($at) : null];
    }

    private function createPaymongo(ServiceInvoice $invoice, Payment $row): array
    {
        $half = $invoice->isHalfHalf() ? ($row->installment === 1 ? ' (first half)' : ' (remaining balance)') : '';

        $result = $this->gateway->createPaymongoGcash(
            ServiceInvoiceDesk::cents($row->amount),
            'LP Furniture Invoice ' . $invoice->invoice_number . $half,
            ['kind' => 'service_invoice', 'service_invoice_payment_id' => (string) $row->id, 'service_invoice_id' => (string) $invoice->id],
            ['name' => $invoice->customer_name, 'email' => $invoice->customer_email, 'phone' => $invoice->customer_phone],
            route('services.invoice.payment.return', [$invoice->id, 'success'])
        );

        return [$result['intent_id'], $result['url'], now()->addHours(2)];
    }

    /* ───────────── confirmations (webhook, or server-side verification of the gateway's own record) ───────────── */

    /**
     * A Stripe Checkout Session event that belongs to an invoice payment. Idempotent.
     * Returns what happened, for logging and the return page.
     */
    public function handleStripeSession(object $session, string $eventType): string
    {
        $row = $this->rowFromStripeSession($session);
        $paid = ($session->payment_status ?? null) === 'paid';

        if (!$row) {
            if ($paid) {
                $this->alertAdmins('Stripe', ((int) ($session->amount_total ?? 0)) / 100, (string) ($session->payment_intent ?: $session->id), 'no invoice payment matches this payment', null);
            }

            return 'unmatched';
        }

        if ($eventType === 'checkout.session.expired') {
            Payment::whereKey($row->id)->where('status', Payment::PENDING)->update(['status' => Payment::EXPIRED]);

            return 'expired';
        }
        if ($eventType === 'checkout.session.async_payment_failed') {
            return $this->markFailed($row->id, 'Card payment failed');
        }
        if (!$paid) {
            return 'not_paid';
        }

        return $this->confirm($row->id, (int) ($session->amount_total ?? 0), 'Card (Stripe)', (string) ($session->payment_intent ?: $session->id));
    }

    /** The browser came back from Stripe: check the session with Stripe itself, never trust the query string. */
    public function verifyStripeReturn(ServiceInvoice $invoice, string $sessionId): ?string
    {
        $row = Payment::where('service_invoice_id', $invoice->id)->where('method', Payment::STRIPE)->where('provider_reference', $sessionId)->first();
        if (!$row || $row->isPaid()) {
            return $row ? 'already_paid' : null;
        }

        try {
            $session = $this->gateway->retrieveStripeSession($sessionId);
        } catch (\Throwable $e) {
            \Log::warning("Invoice {$invoice->id}: Stripe session lookup failed: " . StripeErrors::describe($e));

            return null;
        }

        return $this->handleStripeSession($session, 'checkout.session.completed');
    }

    /** PayMongo `payment.paid` or `payment.failed` for an intent we started for an invoice. */
    public function handlePaymongo(string $intentId, string $eventType, int $amountCents): string
    {
        $row = Payment::where('method', Payment::PAYMONGO)->where('provider_reference', $intentId)->first();

        if (!$row) {
            if ($eventType === 'payment.paid') {
                $this->alertAdmins('GCash (PayMongo)', $amountCents / 100, $intentId, 'no invoice payment matches this payment', null);
            }

            return 'unmatched';
        }

        return $eventType === 'payment.failed'
            ? $this->markFailed($row->id, 'GCash payment failed')
            : $this->confirm($row->id, $amountCents, 'GCash (PayMongo)', $intentId);
    }

    public function verifyPaymongoReturn(ServiceInvoice $invoice): ?string
    {
        $row = Payment::where('service_invoice_id', $invoice->id)->where('method', Payment::PAYMONGO)->where('status', Payment::PENDING)
            ->orderByDesc('id')->first();
        if (!$row || !$row->provider_reference) {
            return null;
        }

        try {
            $intent = $this->gateway->retrievePaymongoIntent($row->provider_reference);
        } catch (\Throwable $e) {
            \Log::warning("Invoice {$invoice->id}: PayMongo intent lookup failed: " . StripeErrors::describe($e));

            return null;
        }

        return $intent['status'] === 'succeeded'
            ? $this->confirm($row->id, $intent['amount_cents'], 'GCash (PayMongo)', $row->provider_reference)
            : null;
    }

    private function rowFromStripeSession(object $session): ?Payment
    {
        $meta = $session->metadata ?? null;
        $id   = $meta->service_invoice_payment_id ?? null;
        $row  = $id ? Payment::find($id) : null;

        // The session must be the very one we created for that payment.
        return ($row && $row->method === Payment::STRIPE && $row->provider_reference === ($session->id ?? null)) ? $row : null;
    }

    /**
     * Money arrived for a payment row. One transaction: lock the invoice, flip the row with a conditional UPDATE
     * (only one of a racing redirect / webhook wins), and set amount_paid from the paid rows in the same commit.
     * Outcomes: paid, already_paid, late (invoice cancelled meanwhile), mismatch (wrong amount), duplicate.
     */
    private function confirm(int $rowId, int $receivedCents, string $via, string $gatewayRef): string
    {
        $outcome = null;
        $notify  = null;
        $alert   = null;

        DB::transaction(function () use ($rowId, $receivedCents, $via, $gatewayRef, &$outcome, &$notify, &$alert) {
            $peek    = Payment::findOrFail($rowId);
            $invoice = ServiceInvoice::lockForUpdate()->findOrFail($peek->service_invoice_id);
            $row     = Payment::lockForUpdate()->findOrFail($rowId);

            if ($row->isPaid()) {
                $outcome = 'already_paid';

                return;
            }

            $money = ServiceInvoiceDesk::money($receivedCents / 100);

            // Cancelled (or never sent) while the customer was paying: keep the money on record, change nothing else.
            if (!in_array($invoice->status, [ServiceInvoice::ISSUED, ServiceInvoice::PAID], true)) {
                $row->forceFill(['status' => Payment::PAID, 'paid_at' => now(), 'amount' => $money,
                    'paid_slot' => $invoice->id . '-' . $row->installment . '-late-' . $row->id,
                    'note' => 'Arrived after the invoice was cancelled. Refund needed.'])->save();
                $outcome = 'late';
                $alert   = [$via, $receivedCents / 100, $gatewayRef, "invoice {$invoice->invoice_number} was cancelled before this payment arrived. Refund it and record a refund note", $invoice->id];

                return;
            }

            $expected = $invoice->installmentCents()[$row->installment] ?? null;
            if ($expected === null || $receivedCents !== $expected || ServiceInvoiceDesk::cents($row->amount) !== $expected) {
                $row->forceFill(['status' => Payment::FAILED, 'amount' => $money,
                    'note' => 'Amount mismatch: received ' . ServiceInvoiceDesk::peso($receivedCents / 100) . ', expected ' . ServiceInvoiceDesk::peso(($expected ?? 0) / 100)])->save();
                $outcome = 'mismatch';
                $alert   = [$via, $receivedCents / 100, $gatewayRef, "the amount does not match what invoice {$invoice->invoice_number} expected for this installment (" . ServiceInvoiceDesk::peso(($expected ?? 0) / 100) . ')', $invoice->id];

                return;
            }

            // Another payment already settled this installment (or the invoice was paid in cash meanwhile).
            $alreadySettled = $invoice->status === ServiceInvoice::PAID
                || Payment::where('service_invoice_id', $invoice->id)->where('installment', $row->installment)->where('status', Payment::PAID)->exists();
            if ($alreadySettled) {
                $row->forceFill(['status' => Payment::FAILED, 'note' => 'Duplicate: this installment was already paid. Refund needed.'])->save();
                $outcome = 'duplicate';
                $alert   = [$via, $receivedCents / 100, $gatewayRef, "invoice {$invoice->invoice_number} was already paid for this installment, so this is a duplicate. Refund it and record a refund note", $invoice->id];

                return;
            }

            try {
                $won = Payment::whereKey($row->id)->where('status', '!=', Payment::PAID)
                    ->update(['status' => Payment::PAID, 'paid_at' => now(), 'paid_slot' => $invoice->id . '-' . $row->installment, 'note' => null, 'updated_at' => now()]);
            } catch (QueryException $e) {
                // The UNIQUE paid_slot index: another request settled this installment a moment ago.
                $outcome = 'duplicate';

                return;
            }
            if (!$won) {
                $outcome = 'already_paid';

                return;
            }

            $notify  = $this->applyPaidTotals($invoice, $row->method);
            $outcome = 'paid';
        });

        if ($alert) {
            $this->alertAdmins($alert[0], $alert[1], $alert[2], $alert[3], $alert[4]);
        }
        if ($notify) {
            $this->notifyPaid($notify['invoice'], $notify['amount'], $notify['fully_paid']);
        }

        return $outcome;
    }

    /**
     * Inside the transaction, after a payment row turned paid: amount_paid = the sum of the paid rows
     * (so a repeat can never double count), and the invoice becomes Paid only when the full total is in.
     *
     * @return array{invoice: ServiceInvoice, amount: string, fully_paid: bool}
     */
    private function applyPaidTotals(ServiceInvoice $invoice, string $lastMethod, ?string $lastAmount = null): array
    {
        $paidCents = ServiceInvoiceDesk::cents(Payment::where('service_invoice_id', $invoice->id)->where('status', Payment::PAID)->sum('amount'));
        $last      = Payment::where('service_invoice_id', $invoice->id)->where('status', Payment::PAID)->orderByDesc('paid_at')->orderByDesc('id')->first();

        $invoice->forceFill(['amount_paid' => ServiceInvoiceDesk::money($paidCents / 100)]);
        $full = $paidCents >= $invoice->totalCents();

        if ($full) {
            $invoice->forceFill([
                'status' => ServiceInvoice::PAID, 'paid_at' => now(), 'paid_method' => $lastMethod,
                'amount_received' => $invoice->total, 'paid_reference' => null,
            ]);
        }
        $invoice->save();

        return ['invoice' => $invoice->refresh(), 'amount' => (string) ($last?->amount ?? $lastAmount), 'fully_paid' => $full];
    }

    private function markFailed(int $rowId, string $note): string
    {
        $changed = Payment::whereKey($rowId)->where('status', Payment::PENDING)->update(['status' => Payment::FAILED, 'note' => $note, 'updated_at' => now()]);

        if ($changed) {
            $row = Payment::find($rowId);
            $inv = ServiceInvoice::find($row->service_invoice_id);
            if ($inv && $inv->user_id && $inv->isPayable()) {
                $this->notify($inv, 'service_invoice_payment_failed', ['amount' => $row->amount]);
            }
        }

        return $changed ? 'failed' : 'ignored';
    }

    /* ───────────── cash, recorded by an admin ───────────── */

    /**
     * Cash taken in person. The amount must equal the installment due; the admin has confirmed on a separate step.
     */
    public function recordCash(int $invoiceId, User $admin, mixed $amount, bool $confirmed): ServiceInvoice
    {
        if (!$confirmed) {
            throw new PipelineException('Please confirm that you received this cash payment.');
        }

        $notify = null;
        $invoice = DB::transaction(function () use ($invoiceId, $admin, $amount, &$notify) {
            $ticketId = ServiceInvoice::whereKey($invoiceId)->value('service_inquiry_id');
            if (!$ticketId) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $ticket  = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            $invoice = ServiceInvoice::lockForUpdate()->findOrFail($invoiceId);

            if (!$invoice->isPayable()) {
                throw new PipelineException('This invoice is ' . strtolower($invoice->statusLabel()) . ', so you cannot record a payment for it.');
            }

            $due = self::due($invoice);
            if ($due['installment'] === null) {
                throw new PipelineException('This invoice is already paid.');
            }
            if (!$due['available']) {
                throw new PipelineException(($due['reason'] ?? 'This payment is not due yet') . '. Tap "Service done" on the invoice first.');
            }
            if (!is_numeric($amount) || ServiceInvoiceDesk::cents($amount) !== $due['cents']) {
                throw new PipelineException('The cash amount must be exactly ' . ServiceInvoiceDesk::peso($due['cents'] / 100) . ', the amount due now.');
            }

            // Anything the customer may still complete online for this installment is closed, so it cannot be paid twice.
            Payment::where('service_invoice_id', $invoice->id)->where('installment', $due['installment'])->where('status', Payment::PENDING)
                ->update(['status' => Payment::EXPIRED, 'note' => 'Cash was recorded instead', 'updated_at' => now()]);

            try {
                Payment::create([
                    'service_invoice_id' => $invoice->id, 'installment' => $due['installment'], 'amount' => ServiceInvoiceDesk::money($due['cents'] / 100),
                    'method' => Payment::CASH, 'status' => Payment::PAID, 'paid_at' => now(), 'recorded_by' => $admin->id,
                    'paid_slot' => $invoice->id . '-' . $due['installment'],
                ]);
            } catch (QueryException $e) {
                throw new PipelineException('A payment for this installment was just recorded. Refresh the page to see it.');
            }

            $notify = $this->applyPaidTotals($invoice, Payment::CASH);

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number}: cash payment of " . ServiceInvoiceDesk::peso($due['cents'] / 100)
                . ($invoice->isHalfHalf() ? ($due['installment'] === 1 ? ' (first half)' : ' (remaining balance)') : '') . ' recorded'
                . ($notify['fully_paid'] ? '. The invoice is now fully paid' : ''));
            $ticket->save();

            return $invoice;
        });

        $this->notifyPaid($notify['invoice'], $notify['amount'], $notify['fully_paid']);

        return $invoice;
    }

    /* ───────────── service done ───────────── */

    public function serviceDone(int $invoiceId, User $admin): ServiceInvoice
    {
        $notifyBalance = false;

        $invoice = DB::transaction(function () use ($invoiceId, $admin, &$notifyBalance) {
            $ticketId = ServiceInvoice::whereKey($invoiceId)->value('service_inquiry_id');
            if (!$ticketId) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $ticket  = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            $invoice = ServiceInvoice::lockForUpdate()->findOrFail($invoiceId);

            if (!in_array($invoice->status, [ServiceInvoice::ISSUED, ServiceInvoice::PAID], true)) {
                throw new PipelineException('This invoice is ' . strtolower($invoice->statusLabel()) . ', so you cannot mark the service as done.');
            }
            if ($invoice->service_done_at) {
                throw new PipelineException('This service is already marked as done.');
            }

            $invoice->forceFill(['service_done_at' => now()])->save();

            $notifyBalance = $invoice->isHalfHalf() && $invoice->status === ServiceInvoice::ISSUED && $invoice->paidCents() > 0 && $invoice->balanceCents() > 0;

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number}: service marked as done"
                . ($notifyBalance ? '. The remaining balance of ' . ServiceInvoiceDesk::peso($invoice->balanceCents() / 100) . ' is now due' : ''));
            $ticket->save();

            return $invoice;
        });

        if ($notifyBalance && $invoice->user_id) {
            $this->notify($invoice, 'service_invoice_balance_due', ['amount' => $invoice->balanceCents() / 100]);
        }

        return $invoice;
    }

    /* ───────────── refund notes (a record only) ───────────── */

    /** Money received on this invoice, in centavos: the paid rows, or the old-style recorded amount. */
    public static function receivedCents(ServiceInvoice $invoice): int
    {
        $rows = ServiceInvoiceDesk::cents(Payment::where('service_invoice_id', $invoice->id)->where('status', Payment::PAID)->sum('amount'));

        return max($rows, $invoice->paidCents(), ServiceInvoiceDesk::cents($invoice->amount_received));
    }

    public function addRefundNote(int $invoiceId, User $admin, mixed $amount, string $method, string $note): ServiceInvoiceRefundNote
    {
        if (!isset(ServiceInvoiceRefundNote::METHODS[$method])) {
            throw new PipelineException('Choose how the refund was or will be given.');
        }
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw new PipelineException('Please write a short note about the refund (at least 5 characters).');
        }

        return DB::transaction(function () use ($invoiceId, $admin, $amount, $method, $note) {
            $ticketId = ServiceInvoice::whereKey($invoiceId)->value('service_inquiry_id');
            if (!$ticketId) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
            }
            $ticket  = ServiceInquiry::lockForUpdate()->findOrFail($ticketId);
            $invoice = ServiceInvoice::lockForUpdate()->findOrFail($invoiceId);

            $received = self::receivedCents($invoice);
            if ($received <= 0) {
                throw new PipelineException('This invoice has no payment on it, so there is nothing to refund.');
            }
            if (!is_numeric($amount) || ServiceInvoiceDesk::cents($amount) <= 0) {
                throw new PipelineException('Enter the refund amount.');
            }
            $already = ServiceInvoiceDesk::cents(ServiceInvoiceRefundNote::where('service_invoice_id', $invoice->id)->sum('amount'));
            if (ServiceInvoiceDesk::cents($amount) + $already > $received) {
                throw new PipelineException('Refund notes cannot add up to more than the ' . ServiceInvoiceDesk::peso($received / 100) . ' received.');
            }

            $refund = ServiceInvoiceRefundNote::create([
                'service_invoice_id' => $invoice->id, 'amount' => ServiceInvoiceDesk::money($amount), 'method' => $method,
                'note' => $note, 'recorded_by' => $admin->id,
            ]);

            $this->tickets->appendNote($ticket, $admin, "Invoice {$invoice->invoice_number}: refund note recorded, " . ServiceInvoiceDesk::peso($refund->amount)
                . ' by ' . ServiceInvoiceRefundNote::METHODS[$method] . '. This is a record only; the refund itself is done outside the system');
            $ticket->save();

            return $refund;
        });
    }

    /* ───────────── notifications ───────────── */

    private function notifyPaid(ServiceInvoice $invoice, string $amount, bool $fullyPaid): void
    {
        if ($invoice->user_id) {
            if ($fullyPaid) {
                $this->notify($invoice, 'service_invoice_fully_paid', ['amount' => $invoice->total]);
            } else {
                $note = $invoice->isHalfHalf() && $invoice->balanceCents() > 0
                    ? ' The remaining ' . ServiceInvoiceDesk::peso($invoice->balanceCents() / 100) . ' is due after the service.' : '';
                $this->notify($invoice, 'service_invoice_payment_received', ['amount' => $amount, 'balance_note' => $note]);
            }
        }

        $message = 'Invoice ' . $invoice->invoice_number . ': payment of ' . ServiceInvoiceDesk::peso($amount) . ' received'
            . ($fullyPaid ? ', now fully paid.' : ', ' . ServiceInvoiceDesk::peso($invoice->balanceCents() / 100) . ' still to pay.');
        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            Notification::create(['user_id' => $adminId, 'title' => 'Invoice Payment Received', 'message' => $message, 'type' => 'general',
                'url' => route('admin.service-invoices.show', $invoice->id), 'is_read' => false]);
        }
    }

    private function notify(ServiceInvoice $invoice, string $template, array $values): void
    {
        $t = NotificationTemplates::render($template, $values + ['invoice' => $invoice->invoice_number, 'balance_note' => '']);
        Notification::create([
            'user_id' => $invoice->user_id, 'title' => $t['title'], 'message' => $t['message'],
            'type' => 'general', 'url' => route('services.invoice', $invoice->id), 'is_read' => false,
        ]);
    }

    /**
     * A verified payment that could not be applied. Logged and sent to every admin once per message, never silently
     * dropped. Only the last 8 characters of the gateway reference are written.
     */
    private function alertAdmins(string $gateway, float $amount, string $reference, string $why, ?int $invoiceId): void
    {
        $tail    = substr($reference, -8);
        $message = $gateway . ' payment of ' . ServiceInvoiceDesk::peso($amount) . " (reference …{$tail}) was not applied: {$why}. Check the gateway dashboard.";

        \Log::warning("Unmatched invoice payment ({$gateway}, …{$tail}): {$why}.");

        if (Notification::where('title', 'Unmatched Payment')->where('message', $message)->exists()) {
            return;
        }

        $url = $invoiceId ? route('admin.service-invoices.show', $invoiceId) : route('admin.service-inquiries.index');
        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            Notification::create(['user_id' => $adminId, 'title' => 'Unmatched Payment', 'message' => $message, 'type' => 'general', 'url' => $url, 'is_read' => false]);
        }
    }
}
