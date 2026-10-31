<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change to a customer's Installment Standing (table installment_standing_history). */
class StandingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'installment_standing_history';

    protected $fillable = ['user_id', 'installment_schedule_id', 'payment_id', 'scoring_key', 'points', 'score_after', 'reason', 'admin_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
