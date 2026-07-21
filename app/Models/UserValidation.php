<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserValidation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'id_photo_path',
        'selfie_photo_path',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'submitted_at',
    ];

    protected $casts = [
        'reviewed_at'  => 'datetime',
        'submitted_at' => 'datetime',
    ];

    // Belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Reviewed by an admin user
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Check if pending
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    // Check if approved
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}