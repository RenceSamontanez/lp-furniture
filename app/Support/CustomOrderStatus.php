<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A refused order status change; the message is safe to show an admin. */
class OrderStatusException extends RuntimeException
{
}

/**
 * Status changes for CUSTOMIZED orders (design_agreement_id set).
 *
 * A forward-only map, not the free-form dropdown catalog orders keep:
 *
 *   pending -> confirmed -> in_production -> ready | out_for_delivery -> completed
 *
 * A later status can never go back. "cancelled" is allowed from any
 * open status, but only while nothing has been paid, unless the admin
 * gives a reason (the refund itself is handled outside the system).
 * Confirming by hand needs the upfront amount to be in the ledger.
 */
class CustomOrderStatus
{
    public const FLOW = [
        'pending'          => ['confirmed'],
        'confirmed'        => ['in_production'],
        'in_production'    => ['ready', 'out_for_delivery'],
        'ready'            => ['out_for_delivery', 'completed'],
        'out_for_delivery' => ['completed'],
        'completed'        => [],
        'cancelled'        => [],
    ];

    private const MESSAGES = [
        'confirmed'        => 'Your order has been confirmed and will begin production soon.',
        'in_production'    => 'Your order is now in production.',
        'ready'            => 'Your order is ready for pickup!',
        'out_for_delivery' => 'Your order is out for delivery!',
        'completed'        => 'Your order has been completed. Thank you for choosing LP Furniture!',
        'cancelled'        => 'Your order has been cancelled.',
    ];

    /** Forward targets the admin may pick right now (cancel is separate). */
    public static function forwardTargets(Order $order): array
    {
        return self::FLOW[$order->order_status] ?? [];
    }

    public static function canCancel(Order $order): bool
    {
        return !in_array($order->order_status, ['completed', 'cancelled'], true);
    }

    public function change(int $orderId, User $admin, string $to, ?string $reason = null): Order
    {
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($orderId, $admin, $to, $reason) {
            $completionOverride = null;
            $order = Order::lockForUpdate()->findOrFail($orderId);

            if ($order->design_agreement_id === null) {
                throw new OrderStatusException('This is not a customized order.');
            }

            $from = $order->order_status;

            if ($from === $to) {
                throw new OrderStatusException('This order is already ' . str_replace('_', ' ', $to) . '.');
            }

            if ($to === 'cancelled') {
                if (!self::canCancel($order)) {
                    throw new OrderStatusException('A ' . $from . ' order cannot be cancelled.');
                }

                $paid = round((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
                if ($paid > 0 && $reason === null) {
                    throw new OrderStatusException(
                        'Money has been received for this order (₱' . number_format($paid, 2)
                        . '). Enter a reason to cancel it; refunds are handled outside the system.'
                    );
                }
            } else {
                if (!in_array($to, self::FLOW[$from] ?? [], true)) {
                    throw new OrderStatusException(
                        'A customized order cannot go from ' . str_replace('_', ' ', $from) . ' to '
                        . str_replace('_', ' ', $to) . '. Statuses only move forward.'
                    );
                }

                if ($to === 'completed') {
                    $paidNow = round((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
                    $total = round((float) $order->total_amount, 2);

                    if ($paidNow + 0.001 < $total) {
                        if ($reason === null) {
                            throw new OrderStatusException(
                                'Only ₱' . number_format($paidNow, 2) . ' of ₱' . number_format($total, 2)
                                . ' has been received. Record the balance, or enter a reason to complete this order anyway.'
                            );
                        }
                        $completionOverride = $reason;
                    }
                }

                if ($to === 'confirmed') {
                    $needed = (float) ($order->payment?->amount ?? 0);
                    $paid = round((float) PaymentRecord::where('order_id', $order->id)->sum('amount'), 2);
                    if ($paid + 0.001 < $needed) {
                        throw new OrderStatusException(
                            'Record the upfront payment of ₱' . number_format($needed, 2) . ' before confirming this order.'
                        );
                    }
                }
            }

            $update = [
                'order_status'      => $to,
                'status_updated_by' => $admin->id,
                'status_updated_at' => now(),
            ];
            if (($to === 'cancelled' && $reason !== null) || $completionOverride !== null) {
                $what = $to === 'cancelled' ? 'cancelled' : 'completed with a balance outstanding';
                $line = '[' . now('Asia/Manila')->format('Y-m-d H:i') . ' ' . $what . ' by ' . trim($admin->first_name . ' ' . $admin->last_name) . '] ' . $reason;
                $update['notes'] = trim(($order->notes ? $order->notes . "\n" : '') . $line);
            }
            $order->forceFill($update)->save();

            $request = $order->design_request_id
                ? CustomDesignRequest::lockForUpdate()->find($order->design_request_id)
                : null;

            $pipelineFrom = $request?->pipeline_status;
            if ($request && $to === 'cancelled' && $request->pipeline_status === 'ordered') {
                $request->forceFill([
                    'pipeline_status' => 'cancelled',
                    'lock_version'    => (int) $request->lock_version + 1,
                ])->save();
            }

            if ($request) {
                CustomOrderEvent::create([
                    'request_id'  => $request->id,
                    'order_id'    => $order->id,
                    'type'        => 'order_status_changed',
                    'actor_type'  => CustomOrderEvent::ACTOR_ADMIN,
                    'actor_id'    => $admin->id,
                    'data'        => [
                        'from' => $from, 'to' => $to, 'reason' => $reason,
                        'completion_override_reason' => $completionOverride,
                        'pipeline_from' => $pipelineFrom, 'pipeline_to' => $request->pipeline_status,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            Notification::create([
                'user_id' => $order->user_id,
                'title'   => 'Order Update — ' . $order->order_number,
                'message' => self::MESSAGES[$to] ?? 'Your order was updated.',
                'type'    => 'order_update',
                'url'     => route('orders.show', $order->id),
                'is_read' => false,
            ]);

            return $order;
        });
    }
}
