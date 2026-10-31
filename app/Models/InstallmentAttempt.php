<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['installment_schedule_id', 'kind', 'result', 'message', 'gateway_reference', 'simulated'];

    protected $casts = ['simulated' => 'boolean', 'created_at' => 'datetime'];

    public function installment()
    {
        return $this->belongsTo(InstallmentSchedule::class, 'installment_schedule_id');
    }
}
