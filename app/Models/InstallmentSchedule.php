<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentSchedule extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'order_id',
        'user_id',
        'installment_no',
        'amount_due',
        'due_date',
        'paid_at',
        'stripe_payment_intent_id',
        'status',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'due_date'   => 'date',
        'paid_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    // Belongs to a payment
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

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

    // Check if overdue
    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    // Check if paid
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}