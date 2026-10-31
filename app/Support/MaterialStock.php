<?php

namespace App\Support;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of inventory quantities for the Materials feature. Every change locks the row, recomputes
 * inventory_status in ONE place (statusFor) and leaves a movement line behind. Stock may go negative on purpose:
 * customers are never blocked when materials run short, the admin sees "Short by X" and restocks.
 */
final class MaterialStock
{
    public const IN = 'in_stock';
    public const LOW = 'low_stock';
    public const OUT = 'out_of_stock';

    /** quantity <= 0 is out_of_stock (a negative one is shown as "Short" but keeps the enum); <= threshold is low. */
    public static function statusFor(float|string|null $quantity, float|string|null $threshold): string
    {
        $q = (float) $quantity;
        if ($q <= 0) {
            return self::OUT;
        }

        return $q <= (float) $threshold ? self::LOW : self::IN;
    }

    /** What the status chip says: "Short" below zero, "Out Of Stock" at zero, then Low Stock / In Stock. */
    public static function chipLabel(Inventory $m): string
    {
        $q = (float) $m->material_quantity;
        if ($q < 0) {
            return 'Short';
        }

        return ['in_stock' => 'In Stock', 'low_stock' => 'Low Stock', 'out_of_stock' => 'Out Of Stock'][self::statusFor($q, $m->low_stock_threshold)];
    }

    /** The CSS tone of the chip: in | low | short | out */
    public static function chipTone(Inventory $m): string
    {
        $q = (float) $m->material_quantity;

        return $q < 0 ? 'short' : ($q == 0 ? 'out' : (self::statusFor($q, $m->low_stock_threshold) === self::LOW ? 'low' : 'in'));
    }

    public static function number(float|string $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.');
    }

    /** "412 sqft", "0 sqft" or "Short by 40 sqft". */
    public static function remainingText(Inventory $m): string
    {
        $q = (float) $m->material_quantity;

        return $q < 0 ? 'Short by ' . self::number(-$q) . ' ' . $m->unit : self::number($q) . ' ' . $m->unit;
    }

    /**
     * Add (positive) or take away (negative) an amount. Caller may or may not hold a transaction; the row is locked here.
     * Returns the movement, or null when the material no longer exists.
     */
    public static function change(int $inventoryId, float $delta, string $type, ?Order $order = null, ?User $user = null, ?string $note = null): ?InventoryMovement
    {
        return DB::transaction(function () use ($inventoryId, $delta, $type, $order, $user, $note) {
            $row = Inventory::lockForUpdate()->find($inventoryId);
            if (! $row) {
                return null;
            }

            $after = round((float) $row->material_quantity + round($delta, 2), 2);
            $row->material_quantity = $after;
            $row->inventory_status = self::statusFor($after, $row->low_stock_threshold);
            $row->save();

            return InventoryMovement::create([
                'inventory_id' => $row->id, 'material_name' => $row->material_name, 'type' => $type,
                'quantity_change' => round($delta, 2), 'quantity_after' => $after,
                'order_id' => $order?->id, 'user_id' => $user?->id, 'note' => $note,
            ]);
        });
    }

    /** Recount: set the quantity to what a physical count found; the movement carries the difference. */
    public static function correct(int $inventoryId, float $newQuantity, ?User $user = null): ?InventoryMovement
    {
        return DB::transaction(function () use ($inventoryId, $newQuantity, $user) {
            $row = Inventory::lockForUpdate()->find($inventoryId);
            if (! $row) {
                return null;
            }
            $delta = round($newQuantity - (float) $row->material_quantity, 2);
            if ($delta == 0.0) {
                return null;
            }

            return self::change($row->id, $delta, 'corrected', null, $user, 'Count corrected to ' . self::number($newQuantity));
        });
    }

    /** Create a material and write its 'created' movement with the starting quantity. */
    public static function create(array $attributes, ?User $user = null): Inventory
    {
        return DB::transaction(function () use ($attributes, $user) {
            $qty = round((float) ($attributes['material_quantity'] ?? 0), 2);
            $row = Inventory::create([
                'material_name' => $attributes['material_name'], 'material_quantity' => $qty, 'unit' => $attributes['unit'],
                'low_stock_threshold' => $attributes['low_stock_threshold'],
                'inventory_status' => self::statusFor($qty, $attributes['low_stock_threshold']),
            ]);
            InventoryMovement::create([
                'inventory_id' => $row->id, 'material_name' => $row->material_name, 'type' => 'created',
                'quantity_change' => $qty, 'quantity_after' => $qty, 'user_id' => $user?->id, 'note' => 'Material added',
            ]);

            return $row;
        });
    }

    /** Net use per material over the last $days days (consumed minus restored), keyed by inventory id. @return array<int, float> */
    public static function usedSince(int $days = 30): array
    {
        return InventoryMovement::query()
            ->whereIn('type', ['consumed', 'restored'])->whereNotNull('inventory_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('inventory_id, -SUM(quantity_change) as used')->groupBy('inventory_id')
            ->pluck('used', 'inventory_id')->map(fn ($v) => (float) $v)->all();
    }

    /** Days of stock left at the 30-day pace, or null when nothing was used (no pace) or the stock is out. */
    public static function daysLeft(Inventory $m, float $used30): ?float
    {
        if ((float) $m->material_quantity <= 0 || $used30 <= 0) {
            return null;
        }

        return (float) $m->material_quantity / ($used30 / 30);
    }

    /** "About 12 days", "About 1 day", "Less than a day", "Out", or "No recent use". */
    public static function lastsAbout(Inventory $m, float $used30): string
    {
        if ((float) $m->material_quantity <= 0) {
            return 'Out';
        }
        $d = self::daysLeft($m, $used30);
        if ($d === null) {
            return 'No recent use';
        }

        return $d < 1 ? 'Less than a day' : ($d < 2 ? 'About 1 day' : 'About ' . round($d) . ' days');
    }
}
