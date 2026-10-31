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
        'attempts',
        'next_attempt_at',
        'last_attempt_at',
        'reminder_sent_at',
        'pay_link_sent_at',
        'grace_ends_at',
        'overdue_at',
        'gateway_reference',
        'charge_failed',
        'paid_via',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'due_date'   => 'date',
        'paid_at'    => 'datetime',
        'created_at' => 'datetime',
        'next_attempt_at'  => 'datetime',
        'last_attempt_at'  => 'datetime',
        'reminder_sent_at' => 'datetime',
        'pay_link_sent_at' => 'datetime',
        'grace_ends_at'    => 'datetime',
        'overdue_at'       => 'datetime',
        'charge_failed'    => 'boolean',
    ];

    /** Statuses that still owe money. */
    public const OPEN = ['pending', 'failed', 'link_sent', 'processing', 'overdue'];

    public const LABELS = [
        'pending'    => 'Pending',
        'failed'     => 'Failed, Will Retry',
        'link_sent'  => 'Pay Link Sent',
        'processing' => 'Processing',
        'overdue'    => 'Overdue',
        'paid'       => 'Paid',
    ];

    public function attemptsLog()
    {
        return $this->hasMany(InstallmentAttempt::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? ucfirst($this->status);
    }

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