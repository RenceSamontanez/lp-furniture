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
        'delivery_fee',
        'customization_note',
        'production_status',
        'order_status',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
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
}