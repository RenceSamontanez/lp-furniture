<?php

namespace App\Support;

use App\Models\CustomOrderEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\StripeClient;

/** A refused payment method switch; the message is safe to show the customer. */
class PaymentSwitchRefused extends RuntimeException
{
}

/**
 * Lets the owner of a customized order switch between Stripe and GCash
 * while the order is pending and nothing has been paid. Never onsite.
 *
 * Inside one transaction (order row locked first): checks, updates the
 * single payments row, clears the old gateway's tracking fields so a
 * stale PayMongo intent can no longer match the payment, and writes a
 * custom_order_events row. After the commit, an open Stripe Checkout
 * Session of the old method is expired at Stripe. If a payment from the
 * old method still lands, the existing ledger / needs_review logic in
 * PaymentController flags it instead of confirming twice.
 */
class PaymentMethodSwitch
{
    public const ALLOWED = ['stripe', 'gcash'];

    /** @return array{order: Order, changed: bool} */
    public function switch(int $orderId, int $userId, string $method): array
    {
        if (!in_array($method, self::ALLOWED, true)) {
            throw new PaymentSwitchRefused('Custom orders can only be paid by card (Stripe) or GCash.');
        }

        $oldSession = null;

        $result = DB::transaction(function () use ($orderId, $userId, $method, &$oldSession) {
            // 404 for another customer's order, before anything else.
            $order = Order::where('user_id', $userId)->lockForUpdate()->findOrFail($orderId);
            $payment = Payment::where('order_id', $order->id)->lockForUpdate()->first();

            if ($order->design_agreement_id === null || !$payment) {
                throw new PaymentSwitchRefused('This order\'s payment method cannot be changed here.');
            }
            if ($order->order_status !== 'pending') {
                throw new PaymentSwitchRefused('The payment method can only be changed while the order is pending.');
            }
            if ($payment->payment_status !== 'pending'
                || (float) $order->amount_paid > 0
                || PaymentRecord::where('order_id', $order->id)->exists()) {
                throw new PaymentSwitchRefused('A payment has already been received for this order, so the method can no longer be changed.');
            }

            if ($payment->payment_method === $method) {
                return ['order' => $order, 'changed' => false];
            }

            $from = $payment->payment_method;
            $oldSession = $from === 'stripe' && str_starts_with((string) $payment->stripe_client_secret, 'cs_')
                ? $payment->stripe_client_secret
                : null;

            // Same set of tracking fields the catalog switch clears.
            Payment::where('order_id', $order->id)->update([
                'payment_method'             => $method,
                'payment_status'             => 'pending',
                'stripe_payment_intent_id'   => null,
                'stripe_client_secret'       => null,
                'stripe_charge_id'           => null,
                'stripe_customer_id'         => null,
                'paymongo_payment_intent_id' => null,
                'paymongo_payment_method_id' => null,
                'gcash_reference_number'     => null,
                'receipt_image_path'         => null,
                'verified_by'                => null,
                'verified_at'                => null,
                'qr_hash'                    => null,
                'qr_is_active'               => false,
            ]);

            if ($order->design_request_id) {
                CustomOrderEvent::create([
                    'request_id'  => $order->design_request_id,
                    'order_id'    => $order->id,
                    'type'        => 'payment_method_changed',
                    'actor_type'  => CustomOrderEvent::ACTOR_CUSTOMER,
                    'actor_id'    => $userId,
                    'data'        => ['from' => $from, 'to' => $method, 'old_stripe_session_expired' => $oldSession !== null],
                    'occurred_at' => now(),
                ]);
            }

            return ['order' => $order, 'changed' => true];
        });

        if ($oldSession) {
            $this->expireStripeSession($oldSession, $orderId);
        }

        return $result;
    }

    /** Best effort: a session that is already paid or expired just can't be expired. */
    private function expireStripeSession(string $sessionId, int $orderId): void
    {
        try {
            $stripe = new StripeClient(config('services.stripe.secret'));
            $session = $stripe->checkout->sessions->retrieve($sessionId);

            if (($session->status ?? null) === 'open') {
                $stripe->checkout->sessions->expire($sessionId);
            }
        } catch (\Throwable $e) {
            Log::warning("Could not expire the old Stripe session for order {$orderId}: " . StripeErrors::describe($e));
        }
    }
}
