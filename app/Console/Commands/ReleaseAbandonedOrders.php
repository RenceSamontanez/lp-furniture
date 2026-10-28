<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReleaseAbandonedOrders extends Command
{
    protected $signature = 'orders:release-abandoned {--minutes=60 : How old an unpaid Stripe/GCash order must be before it is auto-cancelled}';

    protected $description = 'Auto-cancel Stripe or GCash orders left unpaid too long and restore their reserved stock. Cash orders are never touched by this — those are legitimately confirmed later by admin, in person, not tied to an online payment attempt.';

    public function handle()
    {
        $minutes = (int) $this->option('minutes');
        $cutoff  = now()->subMinutes($minutes);

        // Stripe and GCash (via PayMongo) both confirm instantly or
        // not at all — a still-pending payment past the cutoff means
        // the attempt was abandoned, not "still in progress." Cash
        // is excluded: it's confirmed manually by admin in person
        // and can legitimately sit pending for a long time without
        // being abandoned.
        $abandoned = Order::whereHas('payment', function ($q) {
                $q->whereIn('payment_method', ['stripe', 'gcash'])
                  ->where('payment_status', 'pending');
            })
            ->where('order_status', 'pending')
            // Customized orders follow their agreement's payment window
            // (custom-orders:expire) and are never auto-cancelled here.
            ->whereNull('design_agreement_id')
            ->where('created_at', '<', $cutoff)
            ->with('items')
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned Stripe/GCash orders found.');
            return;
        }

        foreach ($abandoned as $order) {
            DB::transaction(function () use ($order) {

                // Restore stock for every item on this order (once per order)
                \App\Support\StockRestorer::restore($order);

                $order->update(['order_status' => 'cancelled']);
                app(\App\Support\OrderTracking::class)->systemCancel($order, 'Payment was not completed in time.');
                $order->payment()->update(['payment_status' => 'failed']);

                $expired = \App\Support\NotificationTemplates::render('payment_expired', ['order_number' => $order->order_number]);
                Notification::create([
                    'user_id' => $order->user_id,
                    'title'   => $expired['title'],
                    'message' => $expired['message'],
                    'type'    => 'order_update',
                    'url'     => route('orders.show', $order->id),
                ]);
            });

            $this->line("Cancelled and restored stock for order {$order->order_number}");
        }

        $this->info("Released " . $abandoned->count() . " abandoned order(s).");
    }
}