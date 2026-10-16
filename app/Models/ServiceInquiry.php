<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An "Other Services" inquiry ticket: a concern and contact details the team follows up by
 * phone. It never becomes an order.
 */
class ServiceInquiry extends Model
{
    public const SERVICE_TYPES = [
        'labor_only_own_wood' => 'Labor only (I bring my own wood)',
        'repair'              => 'Repair or restoration',
        'other'               => 'Something else',
    ];

    public const STATUSES = [
        'new'         => 'New',
        'contacting'  => 'Contacting',
        'unreachable' => 'Unreachable',
        'called'      => 'Called',
        'closed'      => 'Closed',
    ];

    /** Statuses a ticket can still be worked in. */
    public const OPEN = ['new', 'contacting', 'unreachable', 'called'];

    protected $fillable = [
        'user_id', 'reference_number', 'service_type', 'furniture_type', 'concern',
        'name', 'phone', 'email', 'preferred_call_window', 'preferred_call_date',
        'status', 'admin_notes', 'attempts', 'last_contacted_at', 'closed_reason', 'created_by_admin_id',
    ];

    protected $casts = [
        'preferred_call_date' => 'date',
        'last_contacted_at'   => 'datetime',
        'attempts'            => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function serviceLabel(): string
    {
        return self::SERVICE_TYPES[$this->service_type] ?? (string) $this->service_type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function callWindowLabel(): ?string
    {
        return CustomDesignRequest::CALL_WINDOWS[$this->preferred_call_window] ?? null;
    }

    /** "Morning, 8 AM to 12 PM, on Mon, Nov 02, 2026" or null when nothing was chosen. */
    public function callTimeSummary(): ?string
    {
        $window = $this->callWindowLabel();
        $date   = $this->preferred_call_date?->format('D, M d, Y');

        return match (true) {
            $window && $date => "{$window}, on {$date}",
            (bool) $window   => $window,
            (bool) $date     => "On {$date}",
            default          => null,
        };
    }
}
