<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One payment attempt for one installment of a service invoice. Only a verified gateway
 * confirmation, or an admin recording cash, turns a row to "paid".
 */
class ServiceInvoicePayment extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const PAID    = 'paid';
    public const FAILED  = 'failed';
    public const EXPIRED = 'expired';

    public const STRIPE   = 'stripe';
    public const PAYMONGO = 'paymongo';
    public const CASH     = 'cash';

    public const METHODS = [
        self::STRIPE   => 'Card (online)',
        self::PAYMONGO => 'GCash (online)',
        self::CASH     => 'Cash (in person)',
    ];

    protected $fillable = [
        'service_invoice_id', 'installment', 'amount', 'method', 'status', 'provider_reference', 'checkout_url',
        'expires_at', 'paid_at', 'note', 'recorded_by', 'paid_slot',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'installment' => 'integer',
        'expires_at'  => 'datetime',
        'paid_at'     => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? (string) $this->method;
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
