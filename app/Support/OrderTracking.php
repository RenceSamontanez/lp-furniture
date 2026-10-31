<?php

namespace App\Support;

use App\Mail\OrderStageMail;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Stage rules and history for CATALOG orders (design_agreement_id null). Customized orders keep CustomOrderStatus.
 *
 *  - one step forward at a time, only by an admin, only while the order still has the stage the admin saw
 *  - Pending can only be left once the payment requirement is met (payments.amount: the full total, or the down payment);
 *    Completed needs the full total in the ledger
 *  - corrections go back exactly one stage, from In production onward, with a reason, and the customer is never told
 *  - cancel is possible from Pending or Confirmed only, with a reason (refunds are handled outside the system, as before;
 *    the ordered items go back into stock once, through StockRestorer, shared with orders:release-abandoned)
 *
 * Every write runs in a transaction under a row lock on the order. Emails go out after the commit and can fail
 * without undoing anything.
 */
class OrderTracking
{
    public const MESSAGE_MAX = 300;
    public const REASON_MIN  = 8;
    public const REASON_MAX  = 300;

    public const PAID_AND_CONFIRMED = 'Payment received and your order is confirmed.';

    public static function handles(Order $order): bool
    {
        return $order->design_agreement_id === null;
    }

    /** @return array{paid: float, total: float, remaining: float, required: float, met: bool, state: string, label: string} */
    public static function payment(Order $order): array
    {
        $paid  = round((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
        $total = round((float) $order->total_amount, 2);
        $required = round((float) ($order->payment?->amount ?? $order->payment()->value('amount') ?? 0), 2);

        $state = $total > 0 && $paid + 0.001 >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'awaiting');

        return [
            'paid'      => $paid,
            'total'     => $total,
            'remaining' => max(0.0, round($total - $paid, 2)),
            'required'  => $required,
            'met'       => $required > 0 && $paid + 0.001 >= $required,
            'state'     => $state,
            'label'     => ['paid' => 'Paid in full', 'partial' => 'Partly paid', 'awaiting' => 'Awaiting payment'][$state],
        ];
    }

    /* ───────────── history written by the order and payment code ───────────── */

    public static function recordPlaced(Order $order): void
    {
        OrderStatusEvent::create([
            'order_id' => $order->id, 'type' => 'placed', 'title' => 'Order placed',
            'to_status' => 'pending', 'customer_notified' => true,
        ]);
    }

    /** A payment reached the ledger (gateway or admin). Only catalog orders keep this history. */
    public static function recordPaymentEvent(Order $order, float $amount, string $methodLabel, ?User $admin = null): void
    {
        if (!self::handles($order)) {
            return;
        }

        OrderStatusEvent::create([
            'order_id' => $order->id, 'admin_user_id' => $admin?->id, 'type' => 'payment',
            'title' => 'Payment received: ₱' . number_format($amount, 2) . ' (' . $methodLabel . ')',
            'customer_notified' => true,
        ]);
    }

    /**
     * Pending -> Confirmed once the payment requirement is met. The caller holds the transaction and the row lock
     * (the ledger row is already written). Returns true when the order was confirmed by this call.
     */
    public function autoConfirm(Order $order, ?User $admin = null, bool $afterPayment = false): bool
    {
        if (!self::handles($order) || $order->order_status !== 'pending' || !self::payment($order)['met']) {
            return false;
        }

        $order->forceFill([
            'order_status'      => 'confirmed',
            'status_updated_by' => $admin?->id,
            'status_updated_at' => now(),
        ])->save();

        // Pending -> Confirmed: the estimated materials come off the inventory (own savepoint; never blocks the confirmation).
        MaterialDeduction::deduct($order);

        $event = OrderStatusEvent::create([
            'order_id' => $order->id, 'admin_user_id' => $admin?->id, 'type' => 'stage',
            'title' => 'Order confirmed automatically', 'from_status' => 'pending', 'to_status' => 'confirmed',
            'customer_notified' => true,
        ]);

        // When a payment is what confirmed the order the customer gets this one notice instead of two.
        $this->tell($order, $event, 'confirmed', $afterPayment);

        return true;
    }

    /* ───────────── admin actions ───────────── */

    public function advance(int $orderId, User $admin, ?string $expected, bool $confirmed, ?string $message, ?UploadedFile $photo = null): Order
    {
        $message = self::cleanMessage($message);
        $stored = null;

        try {
            return DB::transaction(function () use ($orderId, $admin, $expected, $confirmed, $message, $photo, &$stored) {
                $order = $this->lock($orderId, $expected);

                if (!$confirmed) {
                    throw new OrderTrackingException('Tick the confirmation box to continue.');
                }
                if (in_array($order->order_status, ['completed', 'cancelled'], true)) {
                    throw new OrderTrackingException('This order is ' . strtolower(OrderStages::labelFor($order)) . '; there is no next step.');
                }

                $next = OrderStages::next($order);
                if ($next === null) {
                    throw new OrderTrackingException('This order has no next step.');
                }

                $pay = self::payment($order);
                if ($order->order_status === 'pending' && !$pay['met']) {
                    throw new OrderTrackingException('Waiting for payment. The order cannot move past Pending until the customer pays or you record a payment.');
                }
                if ($next === 'completed' && $pay['state'] !== 'paid') {
                    throw new OrderTrackingException('Only ₱' . number_format($pay['paid'], 2) . ' of ₱' . number_format($pay['total'], 2)
                        . ' has been received. Record the balance before completing this order.');
                }

                $stored = $photo ? OrderUpdatePhotos::store($photo) : null;
                $from = $order->order_status;

                $order->forceFill([
                    'order_status' => $next, 'status_updated_by' => $admin->id, 'status_updated_at' => now(),
                ])->save();

                if ($from === 'pending' && $next === 'confirmed') {
                    MaterialDeduction::deduct($order);
                }

                $event = OrderStatusEvent::create([
                    'order_id' => $order->id, 'admin_user_id' => $admin->id, 'type' => 'stage',
                    'title' => OrderStages::labelFor($order, $next), 'from_status' => $from, 'to_status' => $next,
                    'note' => $message, 'photo_path' => $stored, 'customer_notified' => true,
                ]);

                $this->tell($order, $event, $next);

                return $order;
            });
        } catch (\Throwable $e) {
            OrderUpdatePhotos::delete($stored);
            throw $e;
        }
    }

    /** A message and/or a photo for the customer; the stage does not change. */
    public function sendUpdate(int $orderId, User $admin, ?string $expected, ?string $message, ?UploadedFile $photo = null): Order
    {
        $message = self::cleanMessage($message);
        $stored = null;

        try {
            return DB::transaction(function () use ($orderId, $admin, $expected, $message, $photo, &$stored) {
                $order = $this->lock($orderId, $expected);

                if ($order->order_status === 'cancelled') {
                    throw new OrderTrackingException('This order was cancelled. Updates are not sent for a cancelled order.');
                }
                if ($message === null && $photo === null) {
                    throw new OrderTrackingException('Write a message or add a photo to send an update.');
                }

                $stored = $photo ? OrderUpdatePhotos::store($photo) : null;

                $event = OrderStatusEvent::create([
                    'order_id' => $order->id, 'admin_user_id' => $admin->id, 'type' => 'update',
                    'title' => 'Progress update', 'from_status' => $order->order_status, 'to_status' => $order->order_status,
                    'note' => $message, 'photo_path' => $stored, 'customer_notified' => true,
                ]);

                $this->tell($order, $event, $order->order_status);

                return $order;
            });
        } catch (\Throwable $e) {
            OrderUpdatePhotos::delete($stored);
            throw $e;
        }
    }

    /** One stage back, with a reason. The customer is not told and never sees it. */
    public function correct(int $orderId, User $admin, ?string $expected, ?string $reason): Order
    {
        $reason = self::cleanReason($reason);

        return DB::transaction(function () use ($orderId, $admin, $expected, $reason) {
            $order = $this->lock($orderId, $expected);

            if (in_array($order->order_status, ['pending', 'confirmed'], true)) {
                throw new OrderTrackingException('The first two stages cannot be undone. Cancel the order instead if it must be stopped.');
            }
            $prev = OrderStages::previous($order);
            if ($order->order_status === 'cancelled' || $prev === null) {
                throw new OrderTrackingException('There is no step to correct on this order.');
            }

            $from = $order->order_status;
            $order->forceFill(['order_status' => $prev, 'status_updated_by' => $admin->id, 'status_updated_at' => now()])->save();

            OrderStatusEvent::create([
                'order_id' => $order->id, 'admin_user_id' => $admin->id, 'type' => 'correction',
                'title' => 'Corrected: ' . OrderStages::labelFor($order, $from) . ' back to ' . OrderStages::labelFor($order, $prev),
                'from_status' => $from, 'to_status' => $prev, 'note' => $reason, 'customer_notified' => false,
            ]);

            return $order;
        });
    }

    public function cancel(int $orderId, User $admin, ?string $expected, ?string $reason): Order
    {
        $reason = self::cleanReason($reason);

        return DB::transaction(function () use ($orderId, $admin, $expected, $reason) {
            $order = $this->lock($orderId, $expected);

            if (!in_array($order->order_status, ['pending', 'confirmed'], true)) {
                throw new OrderTrackingException('An order can only be cancelled while it is Pending or Confirmed.');
            }

            $from = $order->order_status;
            $order->forceFill(['order_status' => 'cancelled', 'status_updated_by' => $admin->id, 'status_updated_at' => now()])->save();

            $event = OrderStatusEvent::create([
                'order_id' => $order->id, 'admin_user_id' => $admin->id, 'type' => 'cancel',
                'title' => 'Order cancelled', 'from_status' => $from, 'to_status' => 'cancelled',
                'note' => $reason, 'customer_notified' => true,
            ]);

            // Catalog items go back into stock, in this same transaction and once only (the payment is not touched).
            $units = StockRestorer::restore($order);
            MaterialDeduction::restore($order);
            if ($units > 0) {
                OrderStatusEvent::create([
                    'order_id' => $order->id, 'admin_user_id' => $admin->id, 'type' => 'stock',
                    'title' => 'Items returned to stock (' . $units . ' ' . ($units === 1 ? 'unit' : 'units') . ')',
                    'from_status' => 'cancelled', 'to_status' => 'cancelled', 'customer_notified' => false,
                ]);
            }

            $this->tell($order, $event, 'cancelled');

            return $order;
        });
    }

    /** What the customer can pick when they cancel their own unpaid order (stored as readable text in the history note). */
    public const CUSTOMER_CANCEL_REASONS = [
        'Changed My Mind',
        'Ordered by Mistake',
        'Do Not Want to Order Anymore',
        'Found a Better Price',
        'Want to Change the Items or Details',
        'Other',
    ];
    public const CUSTOMER_CANCEL_OTHER_MAX = 200;

    /**
     * Why this order cannot be cancelled by its customer right now, or null when it can: a catalog order that is still
     * Pending with no money received (nothing in the payment ledger, payment not paid or partial).
     */
    public static function customerCancelBlock(Order $order): ?string
    {
        if (!self::handles($order)) {
            return 'This is a customized order. Please contact us to change it.';
        }
        if ($order->order_status === 'cancelled') {
            return 'This order was already cancelled.';
        }
        if ($order->order_status !== 'pending') {
            return 'This order has already moved on, so it can no longer be cancelled here. Please contact us.';
        }
        $payment = $order->payment ?? $order->payment()->first();
        $paid = (float) PaymentRecord::where('order_id', $order->id)->sum('amount');
        if ($paid > 0 || (float) $order->amount_paid > 0 || ($payment && in_array($payment->payment_status, ['paid', 'partial'], true))) {
            return 'This order already has a payment, so it can no longer be cancelled here. Please contact us.';
        }

        return null;
    }

    /**
     * The customer cancels their own unpaid order: no admin approval. Same effects as the admin cancel (status, history row,
     * stock back once) plus the customer's notification. A pending payment row is marked failed, as the abandoned-order sweep
     * does; a payment that still arrives later is handled by PaymentController (recorded and flagged for review, the order
     * is never revived).
     *
     * @throws OrderTrackingException when the order is not the customer's, is paid, or has already moved on
     */
    public function customerCancel(int $orderId, User $customer, string $reason, ?string $other): Order
    {
        if (!in_array($reason, self::CUSTOMER_CANCEL_REASONS, true)) {
            throw new OrderTrackingException('Choose a reason for cancelling.');
        }
        $detail = null;
        if ($reason === 'Other') {
            $detail = TextSanitizer::line((string) $other);
            if (mb_strlen($detail) > self::CUSTOMER_CANCEL_OTHER_MAX) {
                throw new OrderTrackingException('Keep the reason to ' . self::CUSTOMER_CANCEL_OTHER_MAX . ' characters or fewer.');
            }
        }
        $note = 'Cancelled by the customer. Reason: ' . $reason . ($detail !== null && $detail !== '' ? ' - ' . $detail : '');

        return DB::transaction(function () use ($orderId, $customer, $note, $reason, $detail) {
            $order = Order::with('payment')->where('user_id', $customer->id)->lockForUpdate()->findOrFail($orderId);

            if ($block = self::customerCancelBlock($order)) {
                throw new OrderTrackingException($block);
            }

            $order->forceFill(['order_status' => 'cancelled', 'status_updated_by' => null, 'status_updated_at' => now()])->save();

            OrderStatusEvent::create([
                'order_id' => $order->id, 'admin_user_id' => null, 'type' => 'cancel',
                'title' => 'Order Cancelled', 'from_status' => 'pending', 'to_status' => 'cancelled',
                'note' => $note, 'customer_notified' => true,
            ]);

            $units = StockRestorer::restore($order);
            MaterialDeduction::restore($order);
            if ($units > 0) {
                OrderStatusEvent::create([
                    'order_id' => $order->id, 'admin_user_id' => null, 'type' => 'stock',
                    'title' => 'Items returned to stock (' . $units . ' ' . ($units === 1 ? 'unit' : 'units') . ')',
                    'from_status' => 'cancelled', 'to_status' => 'cancelled', 'customer_notified' => false,
                ]);
            }

            if ($order->payment && $order->payment->payment_status === 'pending') {
                $order->payment()->update(['payment_status' => 'failed']);
            }

            Notification::create([
                'user_id' => $order->user_id,
                'title'   => 'Order ' . $order->order_number . ': Cancelled',
                'message' => 'You cancelled this order. Nothing was charged. Reason: ' . $reason . ($detail ? ' - ' . $detail : '') . '.',
                'type'    => 'order_update',
                'url'     => route('orders.show', $order->id),
                'is_read' => false,
            ]);

            return $order;
        });
    }

    /** The abandoned-order sweep and other system cancellations; no admin, no form. */
    public function systemCancel(Order $order, string $reason): void
    {
        // The sweep sends its own notification text; only the history row is added here (and any materials taken are put back).
        MaterialDeduction::restore($order);
        OrderStatusEvent::create([
            'order_id' => $order->id, 'type' => 'cancel', 'title' => 'Order cancelled',
            'from_status' => 'pending', 'to_status' => 'cancelled',
            'note' => $reason, 'customer_notified' => true,
        ]);
    }

    /* ───────────── internals ───────────── */

    private function lock(int $orderId, ?string $expected): Order
    {
        $order = Order::with('payment')->lockForUpdate()->findOrFail($orderId);

        if (!self::handles($order)) {
            throw new OrderTrackingException('This is a customized order. Use its Custom Orders page.');
        }
        if ($expected === null || $order->order_status !== $expected) {
            throw new OrderTrackingException('This order was already updated. The page has been reloaded with its current stage.');
        }

        return $order;
    }

    private static function cleanMessage(?string $message): ?string
    {
        $message = TextSanitizer::block($message);
        if ($message === '') {
            return null;
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            throw new OrderTrackingException('The message is too long (' . self::MESSAGE_MAX . ' characters at most).');
        }

        return $message;
    }

    private static function cleanReason(?string $reason): string
    {
        $reason = TextSanitizer::block($reason);
        $len = mb_strlen($reason);
        if ($len < self::REASON_MIN || $len > self::REASON_MAX) {
            throw new OrderTrackingException('Write a reason of ' . self::REASON_MIN . ' to ' . self::REASON_MAX . ' characters.');
        }

        return $reason;
    }

    /** In-site notification now (inside the transaction); the email after the commit, where a failure only logs. */
    private function tell(Order $order, OrderStatusEvent $event, string $stage, bool $withPayment = false): void
    {
        $pickup = OrderStages::isPickup($order);
        $label = OrderStages::label($stage, $pickup);
        $text = OrderStages::customerCopy($order, $stage);
        $extra = $event->note ? ($stage === 'cancelled' ? 'Reason: ' : 'Message from our team: ') . $event->note : null;

        Notification::create([
            'user_id' => $order->user_id,
            'title'   => $withPayment ? self::PAID_AND_CONFIRMED
                : ($event->type === 'update' ? 'Update on order ' . $order->order_number : 'Order ' . $order->order_number . ': ' . $label),
            'message' => trim(($withPayment ? 'Order ' . $order->order_number . ': ' : '') . $text . ($extra ? ' ' . $extra : '')),
            'type'    => $withPayment ? 'payment_confirmed' : 'order_update',
            'url'     => route('orders.show', $order->id),
            'is_read' => false,
        ]);

        $orderId = $order->id;
        $photoPath = $event->photo_path;
        $note = $event->note;
        $kind = $withPayment ? 'payment' : $event->type;

        DB::afterCommit(function () use ($orderId, $stage, $note, $photoPath, $kind) {
            try {
                $o = Order::with('user')->find($orderId);
                if (!$o || !$o->user || !$o->user->email) {
                    return;
                }
                Mail::to($o->user->email)->send(new OrderStageMail($o, $stage, $note, $photoPath, $kind));
            } catch (\Throwable $e) {
                Log::warning("Order {$orderId}: the stage email could not be sent: " . $e->getMessage());
            }
        });
    }
}
