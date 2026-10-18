<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A customer's (or the shop's, on their behalf) request to cancel an Other Services ticket, with a refund
 * when money was paid. Created and changed only through App\Support\ServiceRefundRequestService.
 * The GCash number is encrypted at rest and must never be written to logs, notes or notifications.
 */
class ServiceRefundRequest extends Model
{
    public const NEW            = 'new';
    public const NEEDS_INFO     = 'needs_info';
    public const APPROVED       = 'approved';
    public const REFUNDED       = 'refunded';
    public const REFUND_PROBLEM = 'refund_problem';
    public const DECLINED       = 'declined';
    public const CLOSED         = 'closed';

    public const STATUSES = [
        self::NEW            => 'New',
        self::NEEDS_INFO     => 'Needs more information',
        self::APPROVED       => 'Approved',
        self::REFUNDED       => 'Refunded',
        self::REFUND_PROBLEM => 'Refund problem',
        self::DECLINED       => 'Declined',
        self::CLOSED         => 'Closed',
    ];

    /** A request in one of these puts its invoice on hold. */
    public const OPEN = [self::NEW, self::NEEDS_INFO, self::APPROVED, self::REFUND_PROBLEM];

    public const SOURCES = ['form' => 'Request form', 'luma' => 'Luma', 'admin' => 'Entered by the shop'];

    public const REASONS = [
        'change_of_mind'       => 'I changed my mind',
        'taking_too_long'      => 'It is taking too long',
        'wrong_details'        => 'I gave the wrong details',
        'found_other_provider' => 'I found another provider',
        'other'                => 'Something else',
    ];

    public const METHODS = ['gcash' => 'GCash', 'cash' => 'Cash'];

    /** Refunds are returned within this many days after approval. */
    public const REFUND_DAYS = 7;

    protected $fillable = [
        'reference', 'service_inquiry_id', 'service_invoice_id', 'user_id', 'source', 'reason_code', 'reason_text',
        'contact_phone', 'contact_email', 'refund_method', 'gcash_number', 'gcash_account_name', 'amount_paid_snapshot',
        'work_state_snapshot', 'status', 'open_slot', 'decided_by', 'decided_at', 'decision_note', 'approved_amount',
        'approved_as_exception', 'exception_reason', 'refund_due_at',
    ];

    protected $hidden = ['gcash_number'];

    protected $casts = [
        'gcash_number'          => 'encrypted',
        'amount_paid_snapshot'  => 'decimal:2',
        'approved_amount'       => 'decimal:2',
        'approved_as_exception' => 'boolean',
        'decided_at'            => 'datetime',
        'refund_due_at'         => 'datetime',
    ];

    public function inquiry()
    {
        return $this->belongsTo(ServiceInquiry::class, 'service_inquiry_id');
    }

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function attempts()
    {
        return $this->hasMany(ServiceRefundAttempt::class, 'service_refund_request_id')->orderBy('id');
    }

    public function messages()
    {
        return $this->hasMany(ServiceRefundMessage::class, 'service_refund_request_id')->orderBy('id');
    }

    /** The admin menu badge: new requests, refunds that did not go through, and approved refunds past their due date. */
    public static function attentionCount(): int
    {
        return (int) static::query()
            ->where(function ($q) {
                $q->whereIn('status', [self::NEW, self::REFUND_PROBLEM])
                  ->orWhere(fn ($q2) => $q2->where('status', self::APPROVED)->where('refund_due_at', '<', now()));
            })->count();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code] ?? (string) $this->reason_code;
    }

    public function methodLabel(): ?string
    {
        return self::METHODS[$this->refund_method] ?? null;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::APPROVED, self::REFUND_PROBLEM], true)
            && $this->refund_due_at && $this->refund_due_at->isPast();
    }

    /** 0917 *** 1234, or null. For lists; the full number is shown only on the admin detail page. */
    public function maskedGcash(): ?string
    {
        $n = (string) $this->gcash_number;
        $d = preg_replace('/\D+/', '', $n);
        if (strlen($d) < 10) {
            return $n === '' ? null : '*** ***';
        }
        $d = str_starts_with($d, '63') ? '0' . substr($d, 2) : $d;

        return substr($d, 0, 4) . ' *** ' . substr($d, -4);
    }
}
