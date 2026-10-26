<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserValidation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'attempt_number',
        'id_photo_path',
        'selfie_photo_path',
        'status',
        'rejection_reason',
        'checks_confirmed',
        'reviewed_by',
        'reviewed_at',
        'follow_up_requested_at',
        'follow_up_requested_by',
        'submitted_at',
    ];

    protected $casts = [
        'reviewed_at'            => 'datetime',
        'submitted_at'           => 'datetime',
        'follow_up_requested_at' => 'datetime',
        'checks_confirmed'       => 'array',
        'attempt_number'         => 'integer',
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

    public function followUpBy()
    {
        return $this->belongsTo(User::class, 'follow_up_requested_by');
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

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /** A decided submission (approved or rejected) is final. */
    public function isDecided(): bool
    {
        return $this->status !== 'pending';
    }

    /** Earlier attempts of the same customer, oldest first. */
    public function earlierAttempts()
    {
        return static::where('user_id', $this->user_id)
            ->where('attempt_number', '<', $this->attempt_number)
            ->orderBy('attempt_number')
            ->get();
    }

    public function isLatestAttempt(): bool
    {
        return !static::where('user_id', $this->user_id)
            ->where('attempt_number', '>', $this->attempt_number)
            ->exists();
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%' . addcslashes($term, '\\%_') . '%';

        return $q->whereHas('user', function (Builder $u) use ($like) {
            $u->where('email', 'like', $like)
              ->orWhere('first_name', 'like', $like)
              ->orWhere('last_name', 'like', $like)
              ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
        });
    }
}
