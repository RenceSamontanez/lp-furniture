<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Stripe\StripeClient;

class PaymentController extends Controller
{
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

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'php',
                    'product_data' => [
                        'name' => 'LP Furniture Order ' . $order->order_number
                            . ($order->payment->payment_type === 'installment' ? ' (Downpayment)' : ''),
                    ],
                    'unit_amount' => (int) round($chargeAmount * 100), // centavos
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('payment.stripe.success', $order->id) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => route('payment.stripe', $order->id),
            'metadata' => [
                'order_id' => $order->id,
                'user_id'  => Auth::id(),
            ],
        ]);

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

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $session = $stripe->checkout->sessions->retrieve($request->session_id);

            if ($session->payment_status === 'paid') {

                // Same principle as stripeCreateSession() — only touch
                // the fields this step owns. amount/payment_type/
                // installment_* were already set correctly at checkout.
                Payment::where('order_id', $order->id)->update([
                    'payment_status'           => 'paid',
                    'stripe_payment_intent_id' => $session->payment_intent,
                    'stripe_charge_id'         => $session->payment_intent,
                    'stripe_customer_id'       => $session->customer,
                    'paid_at'                  => now(),
                ]);

                $order->update(['order_status' => 'confirmed']);

                Notification::create([
                    'user_id' => Auth::id(),
                    'title'   => 'Payment Successful',
                    'message' => "Your payment for order {$order->order_number} was successful. Your order is now confirmed!",
                    'type'    => 'payment_confirmed',
                    'url'     => route('orders.show', $order->id),
                    'is_read' => false,
                ]);

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
                ->withErrors(['payment' => 'We could not start your GCash payment. Please try again or contact support.']);
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
                ->withErrors(['payment' => 'We could not start your GCash payment. Please try again or contact support.']);
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
                ->withErrors(['payment' => 'We could not start your GCash payment. Please try again or contact support.']);
        }

        $redirectUrl = $attachResponse->json('data.attributes.next_action.redirect.url');

        Payment::where('order_id', $order->id)->update([
            'paymongo_payment_intent_id' => $intentId,
            'paymongo_payment_method_id' => $methodId,
        ]);

        if (!$redirectUrl) {
            \Log::error('PayMongo attach succeeded but no redirect URL was returned: ' . $attachResponse->body());
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => 'We could not start your GCash payment. Please try again or contact support.']);
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

        $intentId = $order->payment->paymongo_payment_intent_id;

        if (!$intentId) {
            return redirect()->route('payment.gcash.paymongo', $order->id)
                ->withErrors(['payment' => 'No GCash payment was found for this order. Please try again.']);
        }

        try {
            $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')
                ->get("https://api.paymongo.com/v1/payment_intents/{$intentId}");

            $status = $response->json('data.attributes.status');

            if ($response->successful() && $status === 'succeeded') {
                $this->markGcashPaymentPaid($order, $intentId);

                return redirect()->route('payment.confirmed', $order->id)
                    ->with('success', 'Payment successful! Your order is confirmed.');
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
    private function markGcashPaymentPaid(Order $order, string $intentId): void
    {
        if ($order->payment->payment_status === 'paid') {
            return;
        }

        Payment::where('order_id', $order->id)->update([
            'payment_status'             => 'paid',
            'paymongo_payment_intent_id' => $intentId,
            'paid_at'                    => now(),
        ]);

        $order->update(['order_status' => 'confirmed']);

        Notification::create([
            'user_id' => $order->user_id,
            'title'   => 'Payment Successful',
            'message' => "Your GCash payment for order {$order->order_number} was successful. Your order is now confirmed!",
            'type'    => 'payment_confirmed',
            'url'     => route('orders.show', $order->id),
            'is_read' => false,
        ]);
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
    ════════════════════════════════ */
    public function handleWebhook(Request $request)
    {
        \Log::info('Stripe webhook received: ' . $request->input('type'));

        return response()->json(['status' => 'received']);
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

        if ($eventType === 'payment.paid') {
            $resource = $payload['data']['attributes']['data'] ?? [];
            $intentId = $resource['attributes']['payment_intent_id'] ?? null;

            if ($intentId) {
                $payment = Payment::where('paymongo_payment_intent_id', $intentId)->first();

                if ($payment && $payment->order) {
                    $this->markGcashPaymentPaid($payment->order, $intentId);
                } else {
                    \Log::warning("PayMongo webhook: no matching payment found for intent {$intentId}.");
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