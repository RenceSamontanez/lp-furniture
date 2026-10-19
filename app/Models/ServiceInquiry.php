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
        'cancelled'   => 'Cancelled',
    ];

    /** "Where should the work be done?" (Stage 21i). Null on older tickets and on tickets entered at the desk. */
    public const WORK_LOCATIONS = [
        'home'   => 'At my home',
        'shop'   => 'At your shop',
        'unsure' => 'Not sure',
    ];

    /** Statuses a ticket can still be worked in. */
    public const OPEN = ['new', 'contacting', 'unreachable', 'called'];

    protected $fillable = [
        'user_id', 'reference_number', 'service_type', 'furniture_type', 'concern',
        'name', 'phone', 'email', 'preferred_call_window', 'preferred_call_date',
        'status', 'admin_notes', 'attempts', 'last_contacted_at', 'closed_reason', 'created_by_admin_id',
        'service_started_at', 'terms_accepted_at', 'work_location', 'reference_photos',
    ];

    protected $casts = [
        'preferred_call_date' => 'date',
        'last_contacted_at'   => 'datetime',
        'service_started_at'  => 'datetime',
        'terms_accepted_at'   => 'datetime',
        'attempts'            => 'integer',
        'reference_photos'    => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function invoices()
    {
        return $this->hasMany(ServiceInvoice::class, 'service_inquiry_id')->orderByDesc('id');
    }

    public function refundRequests()
    {
        return $this->hasMany(ServiceRefundRequest::class, 'service_inquiry_id')->orderByDesc('id');
    }

    /** The request that currently holds this ticket's invoice, if any. */
    public function openRefundRequest(): ?ServiceRefundRequest
    {
        return $this->refundRequests()->open()->first();
    }

    /** Cancelled by the shop or through a cancellation request. (A ticket merely "closed" was finished or settled by phone.) */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
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

    public function workLocationLabel(): ?string
    {
        return self::WORK_LOCATIONS[$this->work_location] ?? null;
    }

    /** True when the problem is described: a written description of at least ServiceInquiryDesk::MIN_DETAIL characters, or a photo. */
    public function hasEnoughDetail(): bool
    {
        return \App\Support\ServiceInquiryDesk::describedEnough((string) $this->concern, !empty($this->reference_photos));
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
