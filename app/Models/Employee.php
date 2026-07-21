<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'full_name',
        'role',
        'type',
        'phone',
        'status',
        'notes',
    ];

    // Has many task assignments
    public function tasks()
    {
        return $this->hasMany(TaskAssignment::class);
    }

    // Active tasks only
    public function activeTasks()
    {
        return $this->hasMany(TaskAssignment::class)
                    ->whereIn('status', ['pending', 'in_progress']);
    }

    // Check if available
    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    // Check if construction worker
    public function isConstruction(): bool
    {
        return $this->role === 'construction';
    }

    // Check if barnis worker
    public function isBarnis(): bool
    {
        return $this->role === 'barnis';
    }
}