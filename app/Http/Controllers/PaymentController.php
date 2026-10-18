<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Notification;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Support\AgreementCheckout;
use App\Support\PaymentLedger;
use App\Support\StripeCheckoutParams;
use App\Support\StripeErrors;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;

class PaymentController extends Controller
{
    // Outcomes of recordPaymentArrived(), shared by the Stripe and
    // PayMongo confirmation paths (browser return AND webhook).
    private const PAID_CONFIRMED       = 'confirmed';        // this call flipped the payment to paid and confirmed the order
    private const PAID_ALREADY         = 'already_paid';     // another path got there first - nothing more to do
    private const PAID_ORDER_CANCELLED = 'order_cancelled';  // money arrived, but the order was already cancelled
    private const PAID_AGREEMENT_OUTDATED = 'agreement_outdated'; // customized order whose agreement is no longer current: recorded, flagged, not confirmed
    private const PAID_ORDER_ADVANCED  = 'order_advanced';   // money arrived, but the order had already moved past pending

    /* ════════════════════════════════
       SHOW STRIPE PAYMENT PAGE
    ════════════════════════════════ */
    public function stripeForm($orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        return view('payment.stripe', compact('order'));
    }

    /* ════════════════════════════════
       CREATE STRIPE CHECKOUT SESSION
       (Redirects to Stripe's hosted payment page)
    ════════════════════════════════ */
    public function stripeCreateSession(Request $request, $orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        // placeOrder() already created the Payment row with the correct
        // amount — the downpayment for installment orders, the full
        // total otherwise. Charge THAT, not the order's full total,
        // and don't touch amount/payment_type/installment_* below —
        // this method only owns the Stripe-specific tracking fields.
        $chargeAmount = $order->payment->amount;

        // A NEW payment attempt on a customized order needs a current
        // agreement and an open payment window. (Completing a payment
        // that already began never asks this: see recordPaymentArrived.)
        if ($problem = app(AgreementCheckout::class)->paymentStartProblem($order)) {
            return redirect()->route('orders.show', $order->id)->withErrors(['payment' => $problem]);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        // Reuse the open Checkout Session for this payment instead of
        // creating a second payable one (two tabs, a refresh, a back
        // button). Only reused while Stripe still says it is open and it
        // charges exactly what this payment asks for now.
        $existingId = $order->payment->stripe_client_secret;
        if ($existingId && str_starts_with((string) $existingId, 'cs_')) {
            try {
                $open = $stripe->checkout->sessions->retrieve($existingId);

                if (($open->status ?? null) === 'open' && !empty($open->url)
                    && (int) $open->amount_total === (int) round($chargeAmount * 100)) {
                    return redirect($open->url);
                }
            } catch (\Throwable $e) {
                \Log::info("Stripe session {$existingId} could not be reused for order {$order->id}: " . $e->getMessage());
            }
        }

        // Everything we send is shaped by one class that knows Stripe's
        // limits (expires_at window, amount range, name length, metadata
        // sizes, absolute URLs).
        if ($problem = StripeCheckoutParams::amountProblem(StripeCheckoutParams::amountCentavos($order))) {
            return redirect()->route('payment.stripe', $order->id)->withErrors(['payment' => $problem]);
        }

        $sessionParams = StripeCheckoutParams::build($order, Auth::id());

        try {
            $session = $stripe->checkout->sessions->create($sessionParams);
        } catch (\Throwable $e) {
            // Stripe rejected the request or could not be reached: never a 500.
            \Log::error("Stripe Checkout Session could not be created for order {$order->id}: " . StripeErrors::describe($e));

            return redirect()->route('payment.stripe', $order->id)
                ->withErrors(['payment' => "We couldn't start the card payment. Please try again, or switch to GCash."]);
        }

        // Save the session/payment-intent reference before redirecting
        Payment::where('order_id', $order->id)->update([
            'stripe_payment_intent_id' => $session->payment_intent,
            'stripe_client_secret'     => $session->id, // storing checkout session id for lookup
        ]);

        return redirect($session->url);
    }

    /* ════════════════════════════════
       STRIPE PAYMENT SUCCESS
       (Stripe redirects here after checkout)
    ════════════════════════════════ */
    public function stripeSuccess(Request $request, $orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        // Idempotent: a repeat visit to this URL (back button, refresh,
        // retry) for an order already marked paid is a harmless no-op —
        // don't re-verify with Stripe and don't send a second notification.
        if ($order->payment->payment_status === 'paid') {
            return redirect()->route('payment.confirmed', $order->id)
                ->with('success', 'Payment successful! Your order is confirmed.');
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            // session_id comes from the URL's query string, which a
            // customer can freely edit to name a DIFFERENT Stripe
            // session — one they legitimately paid for a different
            // (e.g. cheaper) order of their own. Passing $order->id as
            // the expected order makes confirmStripeSession() refuse
            // that session outright instead of confirming the wrong
            // order. The webhook below doesn't pass one, since there
            // it's deriving the order FROM the session's own (trusted,
            // signature-verified) metadata rather than a URL a visitor
            // can edit.
            $session = $stripe->checkout->sessions->retrieve($request->session_id);

            $outcome = $this->confirmStripeSession($session, $order->id);

            if ($outcome === self::PAID_ORDER_CANCELLED) {
                return $this->cancelledOrderPaymentRedirect($order);
            }

            if ($outcome === self::PAID_AGREEMENT_OUTDATED) {
                return $this->outdatedAgreementPaymentRedirect($order);
            }

            if ($outcome !== null) {
                return redirect()->route('payment.confirmed', $order->id)
                    ->with('success', 'Payment successful! Your order is confirmed.');
            }

        } catch (\Exception $e) {
            \Log::error('Stripe session verification failed: ' . $e->getMessage());
        }

        return redirect()->route('payment.stripe', $order->id)
            ->withErrors(['payment' => 'We could not verify your payment. Please try again or contact support.']);
    }

    /* ════════════════════════════════
       CONFIRM A PAID STRIPE CHECKOUT SESSION
       Shared by stripeSuccess() (the customer's browser returning from
       Stripe) and handleWebhook() (Stripe's own server notifying us
       directly) — both are real, independent confirmation paths for
       the exact same event, so they go through one method rather than
       two copies that could quietly drift apart. $expectedOrderId is
       only ever passed by stripeSuccess(): the webhook already trusts
       the session's own metadata (verified by Stripe's signature), so
       it has nothing external to cross-check it against.
    ════════════════════════════════ */
    private function confirmStripeSession($session, ?int $expectedOrderId = null): ?string
    {
        $metadataOrderId = $session->metadata->order_id ?? null;

        if (!$metadataOrderId) {
            return null;
        }

        if ($expectedOrderId !== null && (string) $metadataOrderId !== (string) $expectedOrderId) {
            \Log::warning("Stripe session {$session->id} names order {$metadataOrderId} but was presented for order {$expectedOrderId} — refusing to confirm.");
            return null;
        }

        $order = Order::with('payment')->find($metadataOrderId);

        if (!$order || !$order->payment) {
            return null;
        }

        // Idempotent: the browser return and the webhook can both
        // arrive for the same payment — the second one is a no-op,
        // not a second confirmation or a second notification.
        if ($order->payment->payment_status === 'paid') {
            return $this->recordDuplicateCapture(
                $order,
                'stripe',
                $session->payment_intent ?: $session->id,
                $order->payment->stripe_payment_intent_id,
                $session->payment_status === 'paid',
                ((int) $session->amount_total) / 100
            );
        }

        $amountMatches = (int) $session->amount_total === (int) round((float) $order->payment->amount * 100);

        if ($session->payment_status !== 'paid' || !$amountMatches) {
            \Log::warning("Stripe session {$session->id} for order {$order->id}: payment_status={$session->payment_status}, amount_matches=" . ($amountMatches ? 'yes' : 'no') . ' — refusing to confirm.');
            return null;
        }

        // Same principle as stripeCreateSession() — only touch the
        // fields this step owns. amount/payment_type/installment_*
        // were already set correctly at checkout.
        return $this->recordPaymentArrived(
            $order->id,
            [
                'stripe_payment_intent_id' => $session->payment_intent,
                'stripe_charge_id'         => $session->payment_intent,
                'stripe_customer_id'       => $session->customer,
            ],
            \App\Support\NotificationTemplates::message('payment_confirmed', ['order_number' => $order->order_number]),
            "Stripe session {$session->id}",
            'stripe',
            $session->payment_intent ?: $session->id
        );
    }

    /* ════════════════════════════════
       A verified Stripe capture arrived for an order whose payment is
       already paid. If it is the SAME capture (same payment intent as
       the one already recorded) it is a harmless repeat: nothing
       happens. If it is a DIFFERENT capture, real money was taken a
       second time: it goes into the ledger, the order is flagged for a
       refund check and admins are told. The order status is not touched.
    ════════════════════════════════ */
    private function recordDuplicateCapture(Order $order, string $method, string $reference, ?string $storedReference, bool $captured, float $amount): string
    {
        if (!$captured || $reference === '' || $reference === $storedReference) {
            return self::PAID_ALREADY;
        }

        DB::transaction(function () use ($order, $method, $reference, $amount) {
            $locked = Order::lockForUpdate()->find($order->id);
            $payment = Payment::where('order_id', $order->id)->first();

            if (!$locked || !$payment) {
                return;
            }

            $row = PaymentLedger::insert($locked, $payment, [
                'amount'            => round($amount, 2),
                'method'            => $method,
                'gateway_reference' => substr($reference, 0, 191),
                'paid_on'           => now()->toDateString(),
                'recorded_by'       => null,
                'note'              => 'Second capture for an order that was already paid',
            ]);

            if ($row === null) {
                return; // this exact capture was already recorded
            }

            PaymentLedger::refreshAmountPaid($locked);
            PaymentLedger::flagForReview(
                $locked,
                'duplicate payment of ₱' . number_format($amount, 2) . " ({$reference}), check for refund"
            );
        });

        return self::PAID_ALREADY;
    }

    /* ════════════════════════════════
       RECORD THAT A VERIFIED PAYMENT ARRIVED — the single place where
       a payment becomes 'paid' and its order becomes 'confirmed', for
       both gateways and both confirmation paths (browser return and
       webhook).

       Atomic: the payment row is flipped with a conditional UPDATE
       (WHERE payment_status <> 'paid') inside a transaction, so when a
       redirect and a webhook arrive at the same moment exactly one of
       them changes the row — and only that one confirms the order and
       sends the notification. The other gets PAID_ALREADY and does
       nothing, instead of racing past a read-then-write "already paid"
       check and notifying twice.

       A cancelled order is never flipped back to confirmed. The money
       did arrive, so the payment is still recorded as paid (it must not
       look unpaid), but the order stays cancelled and a warning is
       logged so it can be refunded/handled by a person.
    ════════════════════════════════ */
    private function recordPaymentArrived(int $orderId, array $gatewayFields, string $customerMessage, string $logLabel, string $ledgerMethod = 'stripe', ?string $gatewayReference = null): string
    {
        return DB::transaction(function () use ($orderId, $gatewayFields, $customerMessage, $logLabel, $ledgerMethod, $gatewayReference) {
            // Lock the order row first: every money write (this one, the
            // ledger entry, amount_paid) and a concurrent admin cancel or
            // Record Payment are serialized on it.
            $order = Order::lockForUpdate()->find($orderId);

            if (!$order) {
                return self::PAID_ALREADY;
            }

            $changed = Payment::where('order_id', $orderId)
                ->where('payment_status', '<>', 'paid')
                ->update($gatewayFields + [
                    'payment_status' => 'paid',
                    'paid_at'        => now(),
                ]);

            if ($changed === 0) {
                return self::PAID_ALREADY;
            }

            // The money arrived: write it to the ledger (UNIQUE method +
            // reference makes a repeat capture a no-op) and keep
            // orders.amount_paid equal to the ledger sum. This happens
            // for every outcome below, including a cancelled order.
            $payment = Payment::where('order_id', $orderId)->first();
            PaymentLedger::insert($order, $payment, [
                'amount'            => $payment->amount,
                'method'            => $ledgerMethod,
                'gateway_reference' => $gatewayReference !== null ? substr($gatewayReference, 0, 191) : null,
                'paid_on'           => now()->toDateString(),
                'recorded_by'       => null,
                'note'              => null,
            ]);
            PaymentLedger::refreshAmountPaid($order);

            if ($order->order_status === 'cancelled') {
                PaymentLedger::flagForReview($order, 'payment received for a cancelled order, check for refund');

                \Log::warning("{$logLabel}: payment arrived for order {$order->id} ({$order->order_number}), which is cancelled. Payment recorded as paid; order left cancelled — needs manual refund/review.");
                return self::PAID_ORDER_CANCELLED;
            }

            // A customized order whose agreement is no longer the current
            // one: the money is in the ledger, but nothing is confirmed.
            // A person decides (needs_review + admin alert). The payment
            // deadline is deliberately NOT checked here: a payment that
            // began in time is honored.
            $checkout = app(AgreementCheckout::class);
            if (!$checkout->agreementIsCurrent($order)) {
                PaymentLedger::flagForReview($order, 'payment arrived for an outdated agreement, review before confirming');
                \Log::warning("{$logLabel}: payment for order {$order->id} ({$order->order_number}) arrived for an agreement that is no longer current; not confirmed, flagged for review.");

                return self::PAID_AGREEMENT_OUTDATED;
            }

            // A payment may only move an order pending -> confirmed. An order
            // that has already moved on (confirmed, in_production, ready,
            // out_for_delivery, completed) keeps its status and gets no
            // "confirmed" notification; the payment itself is still recorded.
            if ($order->order_status !== 'pending') {
                \Log::info("{$logLabel}: payment recorded for order {$order->id} ({$order->order_number}), already '{$order->order_status}'; order status left unchanged.");
                return self::PAID_ORDER_ADVANCED;
            }

            $order->update(['order_status' => 'confirmed']);

            if ($order->design_request_id) {
                \App\Models\CustomOrderEvent::create([
                    'request_id'  => $order->design_request_id,
                    'order_id'    => $order->id,
                    'type'        => 'order_confirmed',
                    'actor_type'  => \App\Models\CustomOrderEvent::ACTOR_SYSTEM,
                    'actor_id'    => null,
                    'data'        => ['via' => $ledgerMethod, 'amount' => number_format((float) $payment->amount, 2, '.', '')],
                    'occurred_at' => now(),
                ]);
            }

            // Customized orders: production time counts from the payment date.
            $ready = $checkout->expectedReadyDate($order, now());
            if ($ready) {
                $order->forceFill(['expected_ready_date' => $ready->toDateString()])->save();
                $customerMessage .= ' Expected ready date: about ' . $ready->format('M d, Y') . '.';
            }

            Notification::create([
                'user_id' => $order->user_id,
                'title'   => \App\Support\NotificationTemplates::TEMPLATES['payment_confirmed']['title'],
                'message' => $customerMessage,
                'type'    => $order->design_agreement_id !== null ? 'custom_order' : 'payment_confirmed',
                'url'     => route('orders.show', $order->id),
                'is_read' => false,
            ]);

            return self::PAID_CONFIRMED;
        });
    }

    // A payment arrived for a customized order whose agreement has been
    // replaced: not "confirmed", our team reviews it.
    private function outdatedAgreementPaymentRedirect(Order $order)
    {
        return redirect()->route('orders.show', $order->id)
            ->with('info', "We received your payment for order {$order->order_number}. Its agreement has changed, so our team will review it and contact you.");
    }

    // Where to send a customer whose payment went through on an order
    // that had already been cancelled — never the "confirmed" page.
    private function cancelledOrderPaymentRedirect(Order $order)
    {
        return redirect()->route('orders.show', $order->id)
            ->with('error', "We received your payment, but order {$order->order_number} had already been cancelled. Our team will contact you about a refund.");
    }

    /* ════════════════════════════════
       GCASH VIA PAYMONGO — real payment gateway, the only GCash
       path now. The manual receipt-upload flow (gcashForm/
       uploadReceipt) was removed — it had become unreachable dead
       code once checkout, the order page's resume action, and the
       payment-method switcher all routed GCash through PayMongo
       exclusively, with no UI path left pointing at it.
    ════════════════════════════════ */
    public function paymongoForm($orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        return view('payment.gcash-paymongo', compact('order'));
    }

    /* ════════════════════════════════
       CREATE PAYMONGO PAYMENT INTENT
       (Redirects to PayMongo's hosted GCash authorization page)
    ════════════════════════════════ */
    public function paymongoCreateIntent(Request $request, $orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        // Same principle as the Stripe flow — placeOrder() already set
        // Payment.amount correctly (downpayment for installment orders,
        // full total otherwise). Charge that, don't recompute it.
        $chargeAmount = $order->payment->amount;
        $secretKey    = config('services.paymongo.secret_key');

        if ($problem = app(AgreementCheckout::class)->paymentStartProblem($order)) {
            return redirect()->route('orders.show', $order->id)->withErrors(['payment' => $problem]);
        }

        try {
            return $this->paymongoStart($order, $chargeAmount, $secretKey);
        } catch (\Throwable $e) {
            // PayMongo could not be reached or answered badly: never a 500.
            \Log::error("PayMongo payment could not be started for order {$order->id}: " . StripeErrors::describe($e));

            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);
        }
    }

    // The three PayMongo calls (intent, payment method, attach).
    private function paymongoStart(Order $order, $chargeAmount, $secretKey)
    {
        // Step 1: create the Payment Intent
        $intentResponse = Http::withBasicAuth($secretKey, '')
            ->post('https://api.paymongo.com/v1/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount'                 => (int) round($chargeAmount * 100), // centavos
                        'currency'               => 'PHP',
                        'payment_method_allowed' => ['gcash'],
                        'description'            => 'LP Furniture Order ' . $order->order_number
                            . ($order->payment->payment_type === 'installment' ? ' (Downpayment)' : ''),
                        'metadata' => [
                            'order_id' => (string) $order->id,
                            'user_id'  => (string) Auth::id(),
                        ],
                    ],
                ],
            ]);

        if (!$intentResponse->successful()) {
            \Log::error('PayMongo payment intent creation failed: ' . $intentResponse->body());
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);
        }

        $intentId  = $intentResponse->json('data.id');
        $clientKey = $intentResponse->json('data.attributes.client_key');

        // Step 2: create a GCash payment method. No sensitive card data
        // involved (unlike Stripe's card flow), so this is safe to do
        // fully server-side with the secret key — no PCI reason to
        // route it through the browser with the public key.
        $methodResponse = Http::withBasicAuth($secretKey, '')
            ->post('https://api.paymongo.com/v1/payment_methods', [
                'data' => [
                    'attributes' => [
                        'type'    => 'gcash',
                        'billing' => [
                            'name'  => Auth::user()->first_name . ' ' . Auth::user()->last_name,
                            'email' => Auth::user()->email,
                            'phone' => Auth::user()->phone_number,
                        ],
                    ],
                ],
            ]);

        if (!$methodResponse->successful()) {
            \Log::error('PayMongo payment method creation failed: ' . $methodResponse->body());
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);
        }

        $methodId = $methodResponse->json('data.id');

        // Step 3: attach the payment method — this is what actually
        // returns the redirect URL to send the customer to.
        $attachResponse = Http::withBasicAuth($secretKey, '')
            ->post("https://api.paymongo.com/v1/payment_intents/{$intentId}/attach", [
                'data' => [
                    'attributes' => [
                        'payment_method' => $methodId,
                        'client_key'      => $clientKey,
                        'return_url'      => route('payment.gcash.paymongo.success', $order->id),
                    ],
                ],
            ]);

        if (!$attachResponse->successful()) {
            \Log::error('PayMongo attach failed: ' . $attachResponse->body());
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);
        }

        $redirectUrl = $attachResponse->json('data.attributes.next_action.redirect.url');

        Payment::where('order_id', $order->id)->update([
            'paymongo_payment_intent_id' => $intentId,
            'paymongo_payment_method_id' => $methodId,
        ]);

        if (!$redirectUrl) {
            \Log::error('PayMongo attach succeeded but no redirect URL was returned: ' . $attachResponse->body());
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => "We couldn't start the GCash payment. Please try again, or switch to card."]);
        }

        return redirect($redirectUrl);
    }

    /* ════════════════════════════════
       PAYMONGO GCASH SUCCESS
       (PayMongo redirects here after the customer authorizes —
       PayMongo appends ?payment_intent_id=... to this URL itself,
       but we re-verify by ID against what we stored, not just trust
       the query string.)
    ════════════════════════════════ */
    public function paymongoSuccess(Request $request, $orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        // Idempotent: a repeat visit (back button, refresh, retry) for
        // an order already marked paid is a harmless no-op — don't
        // re-verify with PayMongo and don't send a second notification.
        // markGcashPaymentPaid() below already guards this too (shared
        // with the webhook path), but skipping the API call entirely
        // here avoids a pointless external request on every repeat hit.
        if ($order->payment->payment_status === 'paid') {
            return redirect()->route('payment.confirmed', $order->id)
                ->with('success', 'Payment successful! Your order is confirmed.');
        }

        $intentId = $order->payment->paymongo_payment_intent_id;

        if (!$intentId) {
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => 'No GCash payment was found for this order. Please try again.']);
        }

        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
                ->get("https://api.paymongo.com/v1/payment_intents/{$intentId}");

            $status = $response->json('data.attributes.status');
            $amount = $response->json('data.attributes.amount');

            // $intentId is already scoped to this order (set in
            // paymongoCreateIntent(), read from this order's own
            // Payment row above), so there's no cross-order replay here
            // the way Stripe's URL-supplied session_id had. Still worth
            // cross-checking the amount against this order's own
            // Payment row for the same defense-in-depth reason as the
            // Stripe fix — refuse to confirm if it's ever out of sync.
            $amountMatches = (int) $amount === (int) round((float) $order->payment->amount * 100);

            if ($response->successful() && $status === 'succeeded' && $amountMatches) {
                $outcome = $this->markGcashPaymentPaid($order, $intentId);

                if ($outcome === self::PAID_ORDER_CANCELLED) {
                    return $this->cancelledOrderPaymentRedirect($order);
                }

                if ($outcome === self::PAID_AGREEMENT_OUTDATED) {
                    return $this->outdatedAgreementPaymentRedirect($order);
                }

                return redirect()->route('payment.confirmed', $order->id)
                    ->with('success', 'Payment successful! Your order is confirmed.');
            }

            if ($status === 'succeeded' && !$amountMatches) {
                \Log::warning("PayMongo intent {$intentId} succeeded but amount ({$amount}) doesn't match order {$order->id}'s Payment amount — refusing to confirm.");
            }

        } catch (\Exception $e) {
            \Log::error('PayMongo payment intent verification failed: ' . $e->getMessage());
        }

        return redirect()->route('payment.gcash.paymongo', $order->id)
            ->withErrors(['payment' => 'We could not verify your payment. Please try again or contact support.']);
    }

    /* ════════════════════════════════
       Shared by paymongoSuccess() and paymongoWebhook() — both are
       real, independent confirmation paths (return_url redirect vs
       webhook notification), so both can arrive for the same
       payment. Guarded so the second arrival is a harmless no-op
       rather than double-confirming or double-notifying.
    ════════════════════════════════ */
    private function markGcashPaymentPaid(Order $order, string $intentId): string
    {
        if ($order->payment->payment_status === 'paid') {
            return self::PAID_ALREADY;
        }

        return $this->recordPaymentArrived(
            $order->id,
            ['paymongo_payment_intent_id' => $intentId],
            \App\Support\NotificationTemplates::message('payment_confirmed', ['order_number' => $order->order_number]),
            "PayMongo intent {$intentId}",
            'gcash_paymongo',
            $intentId
        );
    }

    /* ════════════════════════════════
       PAYMENT CONFIRMED PAGE
    ════════════════════════════════ */
    public function confirmed($orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        return view('payment.confirmed', compact('order'));
    }

    /* ════════════════════════════════
       STRIPE WEBHOOK
       Signature verification via Stripe's own SDK helper (handles the
       HMAC-SHA256 + timestamp-tolerance scheme the same way the
       PayMongo webhook does it by hand) — rejects with 400 if the
       "Stripe-Signature" header is missing or doesn't match.

       This is the reliable confirmation path — stripeSuccess() above
       covers the common case (the customer's browser completes the
       return_url redirect), but this covers the case where they close
       the tab, their session expires, or the redirect otherwise never
       arrives. checkout.session.async_payment_succeeded covers
       payment methods that confirm asynchronously rather than at
       checkout completion; both events carry the same Checkout
       Session object in data.object, so confirmStripeSession() handles
       them identically. Any other event type is acknowledged and
       ignored — Stripe only needs to know we received it.
    ════════════════════════════════ */
    public function handleWebhook(Request $request)
    {
        $payload       = $request->getContent();
        $sigHeader     = $request->header('Stripe-Signature', '');
        $webhookSecret = config('services.stripe.webhook');

        // With no secret configured the signature can't be trusted at all (an empty key is forgeable): refuse everything.
        if (!$webhookSecret) {
            \Log::error('Stripe webhook rejected: STRIPE_WEBHOOK_SECRET is not set.');
            return response()->json(['error' => 'Webhook not configured'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            \Log::warning('Stripe webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        \Log::info('Stripe webhook received: ' . $event->type);

        // Record every verified event id and skip ones already processed
        // (Stripe retries, and can deliver the same event more than once).
        // This sits AFTER signature verification, which is unchanged.
        $record = $this->recordStripeEvent($event, $payload);

        if ($record && $record->processed) {
            \Log::info("Stripe webhook {$event->id} already processed - ignoring duplicate.");
            return response()->json(['status' => 'duplicate']);
        }

        // Other Services invoice payments are a separate flow: they are tagged in the session metadata and
        // handled by their own service. Custom-order and catalog payments never reach that branch.
        if (str_starts_with((string) $event->type, 'checkout.session.')
            && (($event->data->object->metadata->kind ?? null) === 'service_invoice')) {
            app(\App\Support\ServiceInvoicePayments::class)->handleStripeSession($event->data->object, $event->type);
            $record?->markProcessed();

            return response()->json(['status' => 'received']);
        }

        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'])) {
            $session = $event->data->object;

            // A paid session that no longer belongs to an order's current
            // Stripe payment (unknown order, or the customer has since
            // switched to another method) is never confirmed or ledgered
            // here: admins are told to refund or match it by hand.
            $why = $this->stripeSessionMismatch($session);

            if ($why !== null) {
                if (($session->payment_status ?? null) === 'paid') {
                    $this->alertUnmatchedPayment(
                        'Stripe',
                        ((int) ($session->amount_total ?? 0)) / 100,
                        (string) ($session->payment_intent ?: $session->id),
                        $why
                    );
                }
            } else {
                $this->confirmStripeSession($session);
            }
        }

        // Only reached if handling didn't throw, so a failed attempt stays
        // unprocessed and Stripe's retry gets handled again.
        $record?->markProcessed();

        return response()->json(['status' => 'received']);
    }

    // Why a verified Stripe session cannot be matched to an order's current
    // Stripe payment, or null when it can.
    private function stripeSessionMismatch($session): ?string
    {
        $orderId = $session->metadata->order_id ?? null;
        $order   = $orderId ? Order::with('payment')->find($orderId) : null;

        if (!$order || !$order->payment) {
            return 'no order matches this payment';
        }

        // An already-paid order keeps the existing duplicate-capture path
        // (ledgered and flagged for refund review).
        if ($order->payment->payment_method !== 'stripe' && $order->payment->payment_status !== 'paid') {
            return "order {$order->order_number} now uses {$order->payment->payment_method}";
        }

        return null;
    }

    // A verified gateway payment that matches no current order payment. Logged
    // and sent to every admin once per reference; nothing else is touched
    // (no order status change, no ledger row). Only the last 8 characters of
    // the gateway reference are ever written.
    private function alertUnmatchedPayment(string $gateway, float $amount, string $reference, string $why): void
    {
        $tail    = substr($reference, -8);
        $amount  = number_format($amount, 2);
        $message = "{$gateway} payment of ₱{$amount} (reference …{$tail}) was not applied: {$why}. "
            . 'Check the gateway dashboard and refund or match this payment.';

        \Log::warning("Unmatched {$gateway} payment of ₱{$amount} (reference …{$tail}): {$why}.");

        // The gateway may deliver the same event repeatedly.
        if (Notification::where('title', 'Unmatched Payment')->where('message', $message)->exists()) {
            return;
        }

        foreach (User::where('role', 'admin')->pluck('id') as $adminId) {
            Notification::create([
                'user_id' => $adminId,
                'title'   => 'Unmatched Payment',
                'message' => $message,
                'type'    => 'general',
                'url'     => route('admin.orders.index'),
                'is_read' => false,
            ]);
        }
    }

    // Find-or-create the StripeWebhookEvent row for this event id. The
    // stripe_event_id column is UNIQUE, so if two deliveries race to
    // insert it the loser hits a duplicate-key error and just reads the
    // winner's row instead.
    private function recordStripeEvent($event, string $payload): ?StripeWebhookEvent
    {
        if (empty($event->id)) {
            return null;
        }

        try {
            return StripeWebhookEvent::firstOrCreate(
                ['stripe_event_id' => $event->id],
                [
                    'event_type' => $event->type,
                    'payload'    => json_decode($payload, true) ?? [],
                    'processed'  => false,
                ]
            );
        } catch (QueryException $e) {
            return StripeWebhookEvent::where('stripe_event_id', $event->id)->first();
        }
    }

    /* ════════════════════════════════
       PAYMONGO WEBHOOK
       The reliable confirmation path — paymongoSuccess() above
       covers the common case (customer's browser completes the
       return_url redirect), but this covers the case where they
       close the tab/lose connection right after authorizing.
       Signature verification follows PayMongo's documented scheme:
       header "Paymongo-Signature: t=<ts>,te=<test_sig>,li=<live_sig>",
       HMAC-SHA256 of "{timestamp}.{raw_body}" using the webhook
       secret, compared with hash_equals(). Test mode only here, so
       only the te= (test) signature is checked.
    ════════════════════════════════ */
    public function paymongoWebhook(Request $request)
    {
        $signatureHeader = $request->header('Paymongo-Signature', '');
        $rawPayload      = $request->getContent();
        $webhookSecret   = config('services.paymongo.webhook_secret');

        if (!$this->verifyPaymongoSignature($rawPayload, $signatureHeader, $webhookSecret)) {
            \Log::warning('PayMongo webhook signature verification failed.');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $payload = json_decode($rawPayload, true);
        $eventType = $payload['data']['attributes']['type'] ?? null;

        \Log::info('PayMongo webhook received: ' . $eventType);

        // An intent we started for an Other Services invoice belongs to the invoice flow, not to an order.
        if (in_array($eventType, ['payment.paid', 'payment.failed'], true)) {
            $res      = $payload['data']['attributes']['data'] ?? [];
            $invIntent = $res['attributes']['payment_intent_id'] ?? null;
            if ($invIntent && \App\Models\ServiceInvoicePayment::where('method', 'paymongo')->where('provider_reference', $invIntent)->exists()) {
                app(\App\Support\ServiceInvoicePayments::class)->handlePaymongo($invIntent, $eventType, (int) ($res['attributes']['amount'] ?? 0));

                return response()->json(['status' => 'received']);
            }
        }

        if ($eventType === 'payment.paid') {
            $resource = $payload['data']['attributes']['data'] ?? [];
            $intentId = $resource['attributes']['payment_intent_id'] ?? null;

            if ($intentId) {
                $payment = Payment::where('paymongo_payment_intent_id', $intentId)->first();

                $amount = ((int) ($resource['attributes']['amount'] ?? 0)) / 100;

                if ($payment && $payment->order
                    && ($payment->payment_method === 'gcash' || $payment->payment_status === 'paid')) {
                    $this->markGcashPaymentPaid($payment->order, $intentId);
                } elseif ($payment && $payment->order) {
                    $this->alertUnmatchedPayment('GCash (PayMongo)', $amount, $intentId,
                        "order {$payment->order->order_number} now uses {$payment->payment_method}");
                } else {
                    $this->alertUnmatchedPayment('GCash (PayMongo)', $amount, $intentId, 'no order matches this payment');
                }
            }
        }

        if ($eventType === 'payment.failed') {
            $resource = $payload['data']['attributes']['data'] ?? [];
            $intentId = $resource['attributes']['payment_intent_id'] ?? null;

            if ($intentId) {
                Payment::where('paymongo_payment_intent_id', $intentId)
                    ->where('payment_status', '!=', 'paid')
                    ->update(['payment_status' => 'failed']);
            }
        }

        return response()->json(['status' => 'received']);
    }

    private function verifyPaymongoSignature(string $payload, string $signatureHeader, ?string $webhookSecret): bool
    {
        if (!$webhookSecret || !$signatureHeader) {
            return false;
        }

        $timestamp = null;
        $testSignature = null;
        $liveSignature = null;

        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            match ($pair[0]) {
                't'    => $timestamp = $pair[1],
                'te'   => $testSignature = $pair[1],
                'li'   => $liveSignature = $pair[1],
                default => null,
            };
        }

        if (!$timestamp || (!$testSignature && !$liveSignature)) {
            return false;
        }

        // 5-minute tolerance against replay attacks, same window
        // PayMongo's own reference implementation uses.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $webhookSecret);

        // Test mode only — check the test signature.
        return $testSignature && hash_equals($expected, $testSignature);
    }
}