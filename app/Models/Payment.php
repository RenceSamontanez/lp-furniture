<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'amount',
        'payment_method',
        'payment_type',
        'stripe_payment_intent_id',
        'stripe_client_secret',
        'stripe_charge_id',
        'stripe_customer_id',
        'gcash_reference_number',
        'receipt_image_path',
        'payment_status',
        'qr_hash',
        'qr_is_active',
        'paid_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'qr_is_active' => 'boolean',
        'paid_at'      => 'datetime',
    ];

    // Belongs to an order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Has many installment schedules
    public function installments()
    {
        return $this->hasMany(InstallmentSchedule::class);
    }

    // Check if paid
    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    // Check if pending
    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    // Check if via Stripe
    public function isStripe(): bool
    {
        return $this->payment_method === 'stripe';
    }

    // Check if via GCash
    public function isGcash(): bool
    {
        return $this->payment_method === 'gcash';
    }
}