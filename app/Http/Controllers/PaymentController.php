<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'php',
                    'product_data' => [
                        'name' => 'LP Furniture Order ' . $order->order_number,
                    ],
                    'unit_amount' => (int) round($order->total_amount * 100), // centavos
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
        Payment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'user_id'                  => Auth::id(),
                'amount'                   => $order->total_amount,
                'payment_method'           => 'stripe',
                'payment_type'             => 'full',
                'payment_status'           => 'pending',
                'stripe_payment_intent_id' => $session->payment_intent,
                'stripe_client_secret'     => $session->id, // storing checkout session id for lookup
            ]
        );

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

                Payment::updateOrCreate(
                    ['order_id' => $order->id],
                    [
                        'user_id'                  => Auth::id(),
                        'amount'                   => $order->total_amount,
                        'payment_method'           => 'stripe',
                        'payment_type'             => 'full',
                        'payment_status'           => 'paid',
                        'stripe_payment_intent_id' => $session->payment_intent,
                        'stripe_charge_id'         => $session->payment_intent,
                        'stripe_customer_id'       => $session->customer,
                        'paid_at'                  => now(),
                    ]
                );

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
       GCASH FORM
    ════════════════════════════════ */
    public function gcashForm($orderId)
    {
        $order = Order::where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        // Generate a verification hash for the QR display, tied to this order
        $qrHash = strtoupper(substr(md5($order->order_number . $order->id), 0, 10));

        return view('payment.gcash', compact('order', 'qrHash'));
    }

    /* ════════════════════════════════
       UPLOAD GCASH RECEIPT
    ════════════════════════════════ */
    public function uploadReceipt(Request $request)
    {
        $request->validate([
            'order_id'      => 'required|exists:orders,id',
            'reference_no'  => 'required|string|min:8|max:100',
            'receipt_image' => 'required|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $order = Order::where('user_id', Auth::id())
            ->where('id', $request->order_id)
            ->firstOrFail();

        $receiptPath = $request->file('receipt_image')
            ->store("receipts/{$order->id}", 'private');

        Payment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'user_id'                => Auth::id(),
                'amount'                 => $order->total_amount,
                'payment_method'         => 'gcash',
                'payment_type'           => 'full',
                'payment_status'         => 'pending',
                'gcash_reference_number' => $request->reference_no,
                'receipt_image_path'     => $receiptPath,
            ]
        );

        Notification::create([
            'user_id' => Auth::id(),
            'title'   => 'GCash Receipt Submitted',
            'message' => "Your GCash payment receipt for order {$order->order_number} has been submitted for verification.",
            'type'    => 'payment_confirmed',
            'url'     => route('orders.show', $order->id),
            'is_read' => false,
        ]);

        return redirect()->route('payment.confirmed', $order->id)
            ->with('success', 'Receipt submitted! We will verify your payment within a few hours.');
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
}