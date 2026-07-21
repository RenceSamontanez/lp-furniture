<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'payload',
        'processed',
    ];

    protected $casts = [
        'payload'    => 'array',
        'processed'  => 'boolean',
        'created_at' => 'datetime',
    ];

    // Scope — unprocessed events only
    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    // Mark as processed
    public function markProcessed(): void
    {
        $this->update(['processed' => true]);
    }
}