<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One name change made by a customer on My Account (see the migration for why it is kept). */
class UserNameChange extends Model
{
    protected $fillable = [
        'user_id', 'old_first_name', 'old_last_name', 'new_first_name', 'new_last_name', 'was_verified',
    ];

    protected $casts = ['was_verified' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oldName(): string
    {
        return trim($this->old_first_name . ' ' . $this->old_last_name);
    }

    public function newName(): string
    {
        return trim($this->new_first_name . ' ' . $this->new_last_name);
    }
}
