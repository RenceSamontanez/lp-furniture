<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\DesignAgreement;
use App\Models\Delivery;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A refused agreement checkout; the message is safe to show the customer. */
class CheckoutRefused extends RuntimeException
{
}

/**
 * Turns an accepted agreement into an order.
 *
 * Everything that matters (total, upfront amount, delivery, schedule,
 * payment type) is read from the agreement's stored terms_snapshot
 * after its SHA-256 has been re-verified. The only thing the customer
 * chooses is the payment method (stripe or gcash). This is a dedicated
 * path: it never touches cart items, stock or placeOrder().
 *
 * Money and order creation run in one transaction that first takes
 * lockForUpdate on the custom_design_requests row.
 */
class AgreementCheckout
{
    public const METHODS = ['stripe', 'gcash'];

    /**
     * Why this agreement cannot be checked out right now, or null.
     * Does not look at an existing order (callers handle that first).
     */
    public function problem(DesignAgreement $agreement, CustomDesignRequest $request): ?string
    {
        if ($request->pipeline_status !== 'accepted') {
            return 'This agreement is not waiting for payment.';
        }

        $current = DesignAgreement::where('request_id', $request->id)->orderByDesc('id')->first();
        if (!$current || $current->id !== $agreement->id) {
            return 'This is no longer the current agreement for this request.';
        }

        $quote = $agreement->quote;
        if (!$quote || $quote->status !== 'accepted') {
            return 'The quote behind this agreement is no longer accepted.';
        }

        if (!AgreementIntegrity::check($agreement)) {
            return 'This agreement could not be verified, so it cannot be paid right now. Our team has been alerted.';
        }

        $due = $this->dueAt($agreement, $request);
        if ($due === null || now()->greaterThanOrEqualTo($due)) {
            return 'The time to start payment for this agreement has passed. Please contact us.';
        }

        return null;
    }

    /** The payment deadline: the request column, else the one in the snapshot. */
    public function dueAt(DesignAgreement $agreement, CustomDesignRequest $request): ?Carbon
    {
        if ($request->payment_due_at) {
            return Carbon::instance($request->payment_due_at);
        }

        $s = $agreement->terms_snapshot['payment_due_at'] ?? null;

        return $s ? Carbon::parse($s, AgreementTerms::TZ) : null;
    }

    /**
     * Create the order for this agreement, or return the one that
     * already exists. Returns [Order, bool $created].
     *
     * @throws CheckoutRefused
     */
    public function place(int $agreementId, User $customer, string $method): array
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new CheckoutRefused('Choose Stripe or GCash.');
        }

        $attempt = 0;

        while (true) {
            try {
                return DB::transaction(fn () => $this->placeLocked($agreementId, $customer, $method, $attempt));
            } catch (QueryException $e) {
                // order_number collision (design_agreement_id is handled
                // under the lock before insert): retry with a later number.
                if (str_contains($e->getMessage(), 'order_number') && $attempt < 4) {
                    $attempt++;
                    continue;
                }
                if (str_contains($e->getMessage(), 'design_agreement_id')) {
                    // lost a race that slipped past the lock: use the winner's order
                    $order = Order::where('design_agreement_id', $agreementId)->first();
                    if ($order) {
                        return [$order, false];
                    }
                }
                throw $e;
            }
        }
    }

    private function placeLocked(int $agreementId, User $customer, string $method, int $attempt): array
    {
        $agreement = DesignAgreement::where('user_id', $customer->id)->findOrFail($agreementId);

        // 1. the request row first
        $request = CustomDesignRequest::with('user')->lockForUpdate()->findOrFail($agreement->request_id);

        // 2. an order already exists for this agreement: that is the answer
        if ($existing = Order::where('design_agreement_id', $agreement->id)->lockForUpdate()->first()) {
            return [$existing, false];
        }

        if ($problem = $this->problem($agreement->load('quote'), $request)) {
            throw new CheckoutRefused($problem);
        }

        $terms = $agreement->terms_snapshot;
        $money = $terms['money'];
        $total = round((float) $money['total'], 2);
        $upfront = round((float) $money['upfront'], 2);

        // the columns and the snapshot must agree (belt and braces)
        if (abs($total - (float) $agreement->total_amount) > 0.001 || abs($upfront - (float) $agreement->upfront_amount) > 0.001) {
            throw new CheckoutRefused('This agreement\'s amounts do not match its record, so it cannot be paid. Our team has been alerted.');
        }

        $deposit = ($money['payment_terms'] ?? 'full') === 'deposit' && $upfront < $total;
        $schedule = $terms['schedule'] ?? [];
        $months = $frequency = null;

        if ($deposit) {
            $frequency = (int) ($terms['schedule_every_days'] ?? 0);
            $months = (int) round((count($schedule) * $frequency) / 30);

            $expected = InstallmentScheduler::splitAmounts(round($total - $upfront, 2), $months, $frequency);
            $matches = count($expected) === count($schedule);
            foreach ($expected as $i => $row) {
                $matches = $matches && isset($schedule[$i])
                    && abs($row['amount'] - (float) $schedule[$i]['amount']) < 0.001;
            }
            if (!$matches) {
                throw new CheckoutRefused('This agreement\'s payment schedule could not be verified, so it cannot be paid. Our team has been alerted.');
            }
        }

        $delivery = ($terms['delivery']['option'] ?? 'pickup') === 'delivery';
        $title = (string) ($terms['design']['title'] ?? $request->order_name);

        $order = new Order();
        $order->forceFill([
            'user_id'             => $customer->id,
            'design_request_id'   => $request->id,
            'design_agreement_id' => $agreement->id,
            'order_number'        => $this->orderNumber($attempt),
            'total_amount'        => $total,
            'delivery_option'     => $delivery ? 'delivery' : 'pickup',
            'delivery_address'    => $delivery ? $terms['delivery']['address'] : null,
            'delivery_fee'        => round((float) ($terms['delivery']['fee'] ?? 0), 2),
            'production_status'   => 'pending',
            'order_status'        => 'pending',
        ])->save();

        OrderItem::create([
            'order_id'      => $order->id,
            'product_id'    => null,
            'product_name'  => $title,
            'product_image' => $terms['design']['reference_image'] ?? null,
            'quantity'      => 1,
            'unit_price'    => round((float) $money['price'], 2),
            'subtotal'      => round((float) $money['price'], 2),
        ]);

        $payment = new Payment();
        $payment->forceFill([
            'order_id'                    => $order->id,
            'user_id'                     => $customer->id,
            'amount'                      => $upfront,
            'payment_method'              => $method,
            'payment_type'                => $deposit ? 'installment' : 'full',
            'installment_duration_months' => $deposit ? $months : null,
            'installment_frequency_days'  => $deposit ? $frequency : null,
            'payment_status'              => 'pending',
            'design_agreement_id'         => $agreement->id,
        ])->save();

        if ($deposit) {
            InstallmentScheduler::createScheduleForDeposit($payment, $order, $total, $upfront, $months, $frequency);
        }

        if ($delivery) {
            Delivery::create([
                'order_id'         => $order->id,
                'delivery_address' => $terms['delivery']['address'],
                'contact_number'   => $request->contact_phone ?: $customer->phone_number,
                'status'           => 'waiting_for_courier',
            ]);
        }

        $from = $request->pipeline_status;
        $request->forceFill([
            'pipeline_status' => 'ordered',
            'lock_version'    => (int) $request->lock_version + 1,
        ])->save();

        CustomOrderEvent::create([
            'request_id'  => $request->id,
            'order_id'    => $order->id,
            'type'        => 'order_placed',
            'actor_type'  => CustomOrderEvent::ACTOR_CUSTOMER,
            'actor_id'    => $customer->id,
            'data'        => [
                'from' => $from, 'to' => 'ordered', 'agreement_id' => $agreement->id,
                'order_number' => $order->order_number, 'payment_method' => $method,
                'upfront' => number_format($upfront, 2, '.', ''), 'total' => number_format($total, 2, '.', ''),
            ],
            'occurred_at' => now(),
        ]);

        $due = $this->dueAt($agreement, $request)->copy()->timezone(AgreementTerms::TZ)->format('M d, Y h:i A');
        Notification::create([
            'user_id' => $customer->id,
            'title'   => 'Order Created',
            'message' => "Order {$order->order_number} was created for {$title} (agreement {$agreement->agreement_number}). "
                . 'Pay ₱' . number_format($upfront, 2) . " by {$due} to start production.",
            'type'    => 'custom_order',
            'url'     => route('orders.show', $order->id),
            'is_read' => false,
        ]);

        return [$order, true];
    }

    /* ───────────── helpers for the payment handlers (Stage 9 part B) ───────────── */

    /** Is this order's agreement still the request's current one, with its quote accepted? */
    public function agreementIsCurrent(Order $order): bool
    {
        if ($order->design_agreement_id === null) {
            return true;
        }

        $agreement = DesignAgreement::with('quote')->find($order->design_agreement_id);
        if (!$agreement || !$agreement->quote || $agreement->quote->status !== 'accepted') {
            return false;
        }

        $latest = DesignAgreement::where('request_id', $agreement->request_id)->max('id');

        return (int) $latest === (int) $agreement->id;
    }

    /**
     * Why a NEW payment attempt may not start on this order, or null.
     * Only used when starting a payment; completing one never asks.
     */
    public function paymentStartProblem(Order $order): ?string
    {
        if ($order->design_agreement_id === null) {
            return null;
        }

        $agreement = DesignAgreement::with(['quote', 'request'])->find($order->design_agreement_id);
        if (!$agreement) {
            return 'This order\'s agreement could not be found.';
        }

        if (!$this->agreementIsCurrent($order)) {
            return 'This order\'s agreement has been replaced, so a new payment cannot be started. Please contact us.';
        }

        if (!AgreementIntegrity::check($agreement)) {
            return 'This order\'s agreement could not be verified, so a new payment cannot be started. Our team has been alerted.';
        }

        $due = $this->dueAt($agreement, $agreement->request);
        if ($due === null || now()->greaterThanOrEqualTo($due)) {
            return 'The time to start payment for this order has passed. Please contact us.';
        }

        return null;
    }

    /** The payment due date for a customized order's agreement, or null. */
    public function orderDueAt(Order $order): ?Carbon
    {
        if ($order->design_agreement_id === null) {
            return null;
        }

        $agreement = DesignAgreement::with('request')->find($order->design_agreement_id);

        return $agreement ? $this->dueAt($agreement, $agreement->request) : null;
    }

    /** Payment date + the agreement's production days, Monday to Saturday. Null for catalog orders. */
    public function expectedReadyDate(Order $order, \Carbon\CarbonInterface $paidOn): ?Carbon
    {
        if ($order->design_agreement_id === null) {
            return null;
        }

        $days = (int) (DesignAgreement::find($order->design_agreement_id)?->terms_snapshot['production']['days'] ?? 0);

        return $days > 0 ? WorkingDays::add($paidOn->copy()->timezone(AgreementTerms::TZ), $days) : null;
    }
    /** Same format as Order::generateOrderNumber(); $attempt skips past a collision. */
    private function orderNumber(int $attempt): string
    {
        return 'LP-' . date('Y') . '-' . str_pad((string) (Order::count() + 1 + $attempt), 5, '0', STR_PAD_LEFT);
    }
}
