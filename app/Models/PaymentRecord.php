<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the payment ledger: money that was actually received
 * (gateway confirmation, or an admin recording cash / a balance).
 * orders.amount_paid is the sum of an order's entries.
 */
class PaymentRecord extends Model
{
    public const UPDATED_AT = null;

    public const METHODS = ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo'];

    protected $fillable = [
        'order_id',
        'payment_id',
        'installment_schedule_id',
        'amount',
        'method',
        'gateway_reference',
        'paid_on',
        'recorded_by',
        'note',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'paid_on'    => 'date',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function installment()
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }

    /** The admin who recorded it; null when the system did. */
    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
