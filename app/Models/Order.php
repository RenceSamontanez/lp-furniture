<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'design_request_id',
        'order_number',
        'total_amount',
        'delivery_option',
        'delivery_address',
        'delivery_snapshot',
        'delivery_fee',
        'customization_note',
        'production_status',
        'order_status',
        'status_updated_by',
        'status_updated_at',
        'notes',
    ];

    protected $casts = [
        'total_amount'      => 'decimal:2',
        'delivery_fee'      => 'decimal:2',
        'status_updated_at' => 'datetime',
        'amount_paid'       => 'decimal:2',
        'needs_review'      => 'boolean',
        'delivery_snapshot' => 'array',
        'reviewed_at'       => 'datetime',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Admin who last updated order_status
    public function statusUpdatedBy()
    {
        return $this->belongsTo(User::class, 'status_updated_by');
    }

    // Has many order items
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Has one payment
    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    // Has one delivery record
    public function delivery()
    {
        return $this->hasOne(Delivery::class);
    }

    // Has many material usage records
    public function materialUsage()
    {
        return $this->hasMany(MaterialUsage::class);
    }

    // Has many task assignments
    public function tasks()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    // Has one feedback
    public function feedback()
    {
        return $this->hasOne(Feedback::class);
    }

    // Order history (placed, payments, stages, updates, corrections, cancel)
    public function events()
    {
        return $this->hasMany(OrderStatusEvent::class)->orderByDesc('id');
    }

    // Has many installment schedules
    public function installments()
    {
        return $this->hasMany(InstallmentSchedule::class);
    }

    // Belongs to a custom design request
    public function designRequest()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'design_request_id');
    }

    // Generate unique order number
    public static function generateOrderNumber(): string
    {
        return 'LP-' . date('Y') . '-' . str_pad(
            static::count() + 1, 5, '0', STR_PAD_LEFT
        );
    }

    // Check if order is pending
    public function isPending(): bool
    {
        return $this->order_status === 'pending';
    }

    // Check if order is completed
    public function isCompleted(): bool
    {
        return $this->order_status === 'completed';
    }

    /* ── custom order flow ── */

    // The agreement this order was created from (null for catalog orders).
    public function agreement()
    {
        return $this->belongsTo(DesignAgreement::class, 'design_agreement_id');
    }

    public function paymentRecords()
    {
        return $this->hasMany(PaymentRecord::class);
    }

    public function isCustomized(): bool
    {
        return $this->design_agreement_id !== null;
    }

    /**
     * A customized order nobody has started paying for, past its
     * agreement's payment window. Shown to admins; the order is never
     * cancelled automatically.
     */
    public function isOverdueUnpaid(): bool
    {
        if ($this->design_agreement_id === null || $this->order_status !== 'pending') {
            return false;
        }

        $due = $this->designRequest?->payment_due_at;

        return $due !== null
            && $due->isPast()
            && ($this->payment?->payment_status ?? 'pending') === 'pending'
            && (float) $this->amount_paid <= 0;
    }
}