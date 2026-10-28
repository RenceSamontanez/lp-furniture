<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;

/**
 * Puts a cancelled catalog order's items back into stock, once. Shared by the admin cancel and orders:release-abandoned.
 * The caller holds the transaction. The order row is re-read under a lock and stamped (stock_restored_at), so a second
 * call for the same order, from either path, adds nothing.
 */
class StockRestorer
{
    /** @return int units returned to stock (0 when nothing was done) */
    public static function restore(Order $order): int
    {
        $locked = Order::lockForUpdate()->find($order->id);

        if (!$locked || $locked->design_agreement_id !== null || $locked->stock_restored_at !== null) {
            return 0;
        }

        $units = 0;
        foreach ($locked->items()->get() as $item) {
            Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
            if ($item->product_id) {
                $units += (int) $item->quantity;
            }
        }

        $locked->forceFill(['stock_restored_at' => now()])->save();
        $order->stock_restored_at = $locked->stock_restored_at;

        return $units;
    }
}
