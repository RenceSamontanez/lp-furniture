<?php

namespace App\Support;

use App\Models\Order;

/**
 * The stages of a catalog order and every word the admin and the customer read for them.
 * Pure data and wording: the rules live in OrderTracking.
 */
final class OrderStages
{
    public const DELIVERY = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'completed'];
    public const PICKUP   = ['pending', 'confirmed', 'in_production', 'ready', 'completed'];

    private const LABELS = [
        'pending'          => 'Pending',
        'confirmed'        => 'Confirmed',
        'in_production'    => 'In production',
        'ready'            => 'Ready',
        'out_for_delivery' => 'Out for delivery',
        'completed'        => 'Completed',
        'cancelled'        => 'Cancelled',
    ];

    private const BUTTONS = [
        'confirmed'        => 'Confirm order',
        'in_production'    => 'Start production',
        'ready'            => 'Mark as ready',
        'out_for_delivery' => 'Send out for delivery',
        'completed'        => 'Mark as completed',
    ];

    public static function isPickup(Order $order): bool
    {
        return $order->delivery_option === 'pickup';
    }

    /** @return list<string> */
    public static function for(Order $order): array
    {
        return self::isPickup($order) ? self::PICKUP : self::DELIVERY;
    }

    public static function label(string $key, bool $pickup = false): string
    {
        if ($key === 'ready' && $pickup) {
            return 'Ready for pickup';
        }

        return self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function labelFor(Order $order, ?string $key = null): string
    {
        return self::label($key ?? $order->order_status, self::isPickup($order));
    }

    public static function next(Order $order): ?string
    {
        $stages = self::for($order);
        $i = array_search($order->order_status, $stages, true);

        return ($i === false) ? null : ($stages[$i + 1] ?? null);
    }

    public static function previous(Order $order): ?string
    {
        $stages = self::for($order);
        $i = array_search($order->order_status, $stages, true);

        return ($i === false || $i === 0) ? null : $stages[$i - 1];
    }

    public static function buttonLabel(Order $order, string $key): string
    {
        if ($key === 'ready' && self::isPickup($order)) {
            return 'Mark ready for pickup';
        }

        return self::BUTTONS[$key] ?? 'Confirm this step';
    }

    /** The question in the confirmation dialog, word for word from the approved prototype. */
    public static function question(Order $order, string $key): string
    {
        $pickup = self::isPickup($order);

        return match ($key) {
            'in_production'    => 'Are the materials ready and are the craftsmen starting this piece?',
            'ready'            => $pickup ? 'Is the furniture finished, checked and ready to be collected?' : 'Is the furniture finished, checked and packed?',
            'out_for_delivery' => 'Has the furniture left the shop with the delivery team?',
            'completed'        => $pickup ? 'Has the customer collected the furniture?' : 'Has the customer received the furniture?',
            default            => 'Confirm this step?',
        };
    }

    /** Shop location for the pickup message: Contact Settings first, then config/shop.php (FooterData). */
    public static function shopPlace(): string
    {
        try {
            $address = trim((string) (FooterData::build()['visit']['address'] ?? ''));
        } catch (\Throwable) {
            $address = '';
        }

        return $address !== '' ? $address : 'San Juan, Batangas';
    }

    /** What the customer reads for a stage. */
    public static function customerCopy(Order $order, string $key): string
    {
        $pickup = self::isPickup($order);

        return match ($key) {
            'pending'          => 'We are waiting for your payment. Your order starts as soon as it is received.',
            'confirmed'        => 'We received your payment and confirmed your order. Our craftsmen will start building soon.',
            'in_production'    => 'Our craftsmen have started building your furniture piece.',
            'ready'            => $pickup
                ? 'Your furniture is finished and ready. You can pick it up at our shop in ' . self::shopPlace() . '.'
                : 'Your furniture is finished and checked. We are arranging its delivery.',
            'out_for_delivery' => 'Your furniture is on its way to your address.',
            'completed'        => $pickup
                ? 'Order complete. Thank you for collecting your furniture!'
                : 'Order complete. Thank you! We hope you enjoy your new furniture.',
            'cancelled'        => 'This order was cancelled. If you have questions, please contact us.',
            default            => '',
        };
    }
}
