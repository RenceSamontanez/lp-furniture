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
        'installment_duration_months',
        'installment_frequency_days',
        'stripe_payment_intent_id',
        'stripe_client_secret',
        'stripe_charge_id',
        'stripe_customer_id',
        'gcash_reference_number',
        'paymongo_payment_intent_id',
        'paymongo_payment_method_id',
        'receipt_image_path',
        'payment_status',
        'verified_by',
        'verified_at',
        'qr_hash',
        'qr_is_active',
        'paid_at',
        'paymongo_customer_id',
        'installment_card_method_id',
        'card_brand',
        'card_last4',
        'card_simulated',
        'installments_started_at',
        'terms_version',
        'terms_accepted_at',
        'auto_charge_consent_at',
    ];

    protected $casts = [
        'installments_started_at' => 'datetime',
        'terms_accepted_at'       => 'datetime',
        'auto_charge_consent_at'  => 'datetime',
        'card_simulated' => 'boolean',
        'amount'       => 'decimal:2',
        'qr_is_active' => 'boolean',
        'paid_at'      => 'datetime',
        'verified_at'  => 'datetime',
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

    // Admin who approved/rejected this payment
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
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