<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Model;

/**
 * One itemised line of a service invoice. Lines can only be added, changed or removed while the
 * parent invoice is still a draft; after it is issued they are frozen.
 */
class ServiceInvoiceLine extends Model
{
    public const KINDS = [
        'service_cost'     => 'Service cost',
        'material_fee'     => 'Material fee',
        'other_fee'        => 'Other fee',
        'delivery_fee'     => 'Delivery fee',
        'home_service_fee' => 'Home service fee',
    ];

    protected $fillable = ['invoice_id', 'kind', 'description', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $status = ServiceInvoice::whereKey($line->invoice_id)->value('status');
            if ($status !== ServiceInvoice::DRAFT) {
                throw new ImmutableRecordException('The lines of a sent invoice cannot be changed. Cancel the invoice and send a new one instead.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'invoice_id');
    }
}
