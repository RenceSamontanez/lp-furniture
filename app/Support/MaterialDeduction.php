<?php

namespace App\Support;

use App\Models\MaterialUsage;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes an order's estimated materials off the inventory when the order is CONFIRMED, and puts exactly that back if it is cancelled.
 *
 *  - never when merely placed; one set of material_usage rows per order (orders.materials_deducted_at is the guard)
 *  - runs inside the caller's transaction as its own savepoint: if anything fails it is logged and the order change goes on
 *  - stock may go negative; nothing here can refuse an order
 */
final class MaterialDeduction
{
    public static function deduct(Order $order): void
    {
        try {
            DB::transaction(function () use ($order) {
                $locked = Order::lockForUpdate()->find($order->id);
                if (! $locked || $locked->materials_deducted_at !== null) {
                    return;
                }

                $plan = MaterialEstimator::planFor($locked);

                $notes = [];
                foreach ($plan['unmapped'] as $wood) {
                    $notes[] = 'unmapped_wood:' . $wood;
                }
                if ($plan['customer_wood']) {
                    $notes[] = 'customer_wood';
                }
                $locked->forceFill(['materials_deducted_at' => now(), 'materials_note' => $notes ? mb_substr(implode('|', $notes), 0, 250) : null])->save();

                foreach ($plan['lines'] as $line) {
                    MaterialStock::change($line['inventory']->id, -$line['quantity'], 'consumed', $locked);
                    MaterialUsage::create([
                        'order_id' => $locked->id, 'inventory_id' => $line['inventory']->id, 'quantity_used' => $line['quantity'],
                        'notes' => 'Estimated when the order was confirmed', 'used_at' => now(),
                    ]);
                }
                $order->materials_deducted_at = $locked->materials_deducted_at;
            });
        } catch (\Throwable $e) {
            Log::error('Materials: could not deduct for order ' . $order->id . ': ' . $e->getMessage());
        }
    }

    /** Puts back what was taken for this order; does nothing when nothing was taken or it was already put back. */
    public static function restore(Order $order): void
    {
        try {
            DB::transaction(function () use ($order) {
                Order::lockForUpdate()->find($order->id);
                $rows = MaterialUsage::where('order_id', $order->id)->whereNull('reversed_at')->lockForUpdate()->get();

                foreach ($rows as $row) {
                    MaterialStock::change((int) $row->inventory_id, (float) $row->quantity_used, 'restored', $order);
                    $row->forceFill(['reversed_at' => now()])->save();
                }
            });
        } catch (\Throwable $e) {
            Log::error('Materials: could not restore for order ' . $order->id . ': ' . $e->getMessage());
        }
    }
}
