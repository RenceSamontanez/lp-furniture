<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskAssignment extends Model
{
    protected $fillable = [
        'employee_id',
        'order_id',
        'assigned_by',
        'task_description',
        'priority',
        'due_date',
        'completed_at',
        'status',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    // Belongs to an employee
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Belongs to an order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Assigned by an admin user
    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // Check if done
    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    // Check if urgent
    public function isUrgent(): bool
    {
        return $this->priority === 'urgent';
    }
}