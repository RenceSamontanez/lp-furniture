<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseAbandonedOrders extends Command
{
    protected $signature = 'orders:release-abandoned {--minutes=60 : How old an unpaid Stripe order must be before it is auto-cancelled}';

    protected $description = 'Auto-cancel Stripe orders left unpaid too long and restore their reserved stock. Cash/GCash orders are never touched by this — they legitimately take longer to confirm.';

    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        $cutoff  = now()->subMinutes($minutes);

        // Only Stripe orders — cash and GCash are confirmed
        // manually by admin and can legitimately sit pending
        // for a long time without being "abandoned."
        $abandoned = Order::whereHas('payment', function ($q) {
                $q->where('payment_method', 'stripe')
                  ->where('payment_status', 'pending');
            })
            ->where('order_status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->with('items')
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned Stripe orders found.');
            return;
        }

        foreach ($abandoned as $order) {
            DB::transaction(function () use ($order) {

                // Restore stock for every item on this order
                foreach ($order->items as $item) {
                    Product::where('id', $item->product_id)
                        ->increment('stock_quantity', $item->quantity);
                }

                $order->update(['order_status' => 'cancelled']);
                $order->payment()->update(['payment_status' => 'failed']);

                Notification::create([
                    'user_id' => $order->user_id,
                    'title'   => 'Order Cancelled — Payment Not Completed',
                    'message' => "Your order {$order->order_number} was cancelled because payment was not completed in time. You're welcome to place a new order anytime.",
                    'type'    => 'order_update',
                    'url'     => route('orders.show', $order->id),
                ]);
            });

            $this->line("Cancelled and restored stock for order {$order->order_number}");
        }

        $this->info("Released " . $abandoned->count() . " abandoned order(s).");
    }
}