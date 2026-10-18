<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A record that a refund was (or will be) given outside the system. It never moves money:
 * the real refund is done in the Stripe dashboard, GCash or in cash.
 */
class ServiceInvoiceRefundNote extends Model
{
    use HasFactory;

    public const METHODS = [
        'stripe' => 'Card (Stripe dashboard)',
        'gcash'  => 'GCash',
        'cash'   => 'Cash',
        'other'  => 'Other',
    ];

    protected $fillable = ['service_invoice_id', 'amount', 'method', 'note', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
