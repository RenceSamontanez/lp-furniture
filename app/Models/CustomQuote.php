<?php

namespace App\Models;

use App\Support\InstallmentScheduler;
use Illuminate\Database\Eloquent\Model;

/**
 * A quote for a custom order request — a draft until sent, then a
 * numbered version. Editing a sent quote creates version N+1 and marks
 * N superseded.
 *
 * "One open quote per request" is NOT a database constraint (no
 * generated column). Whoever creates or sends a quote must do it inside
 * a transaction that first takes lockForUpdate() on the request row and
 * then checks findOpenForRequest(); the only DB-level uniqueness is
 * (request_id, version).
 */
class CustomQuote extends Model
{
    public const STATUS_DRAFT      = 'draft';
    public const STATUS_SENT       = 'sent';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_ACCEPTED   = 'accepted';
    public const STATUS_DECLINED   = 'declined';
    public const STATUS_EXPIRED    = 'expired';

    /** A sent quote can be accepted for this many days. */
    public const VALID_DAYS = 7;

    /** Price ceiling the admin form allows. */
    public const MAX_PRICE = 999999;

    /** Delivery fee pre-filled on the agreement form; the admin sets the real one. */
    public const DEFAULT_DELIVERY_FEE = 500;

    /** Hours after acceptance the customer still has to START paying (D2: later of quote expiry or this). */
    public const PAYMENT_GRACE_HOURS = 24;

    protected $fillable = [
        'request_id',
        'version',
        'status',
        'final_description',
        'width_cm',
        'depth_cm',
        'height_cm',
        'material',
        'finish',
        'modifications',
        'price',
        'delivery_option',
        'delivery_fee',
        'delivery_address',
        'production_days',
        'payment_terms',
        'deposit_amount',
        'installment_duration_months',
        'installment_frequency_days',
        'call_notes',
        'previous_price',
        'created_by',
        'sent_by',
        'sent_at',
        'expires_at',
        'is_amendment',
        'amends_agreement_id',
        'customer_decline_reason',
    ];

    protected $casts = [
        'version'                     => 'integer',
        'width_cm'                    => 'decimal:2',
        'depth_cm'                    => 'decimal:2',
        'height_cm'                   => 'decimal:2',
        'price'                       => 'decimal:2',
        'delivery_fee'                => 'decimal:2',
        'deposit_amount'              => 'decimal:2',
        'previous_price'              => 'decimal:2',
        'production_days'             => 'integer',
        'installment_duration_months' => 'integer',
        'installment_frequency_days'  => 'integer',
        'is_amendment'                => 'boolean',
        'sent_at'                     => 'datetime',
        'expires_at'                  => 'datetime',
    ];

    /* ── money helpers ── */

    /** What the customer pays in total: price plus delivery fee. */
    public function totalAmount(): float
    {
        return round((float) $this->price + (float) $this->delivery_fee, 2);
    }

    /** Smallest allowed deposit for this quote (shares the scheduler's single constant). */
    public function minimumDeposit(): float
    {
        return InstallmentScheduler::minimumDeposit($this->totalAmount());
    }

    /** What must be paid up front: everything, or the deposit. */
    public function upfrontAmount(): float
    {
        return $this->payment_terms === 'deposit'
            ? round((float) $this->deposit_amount, 2)
            : $this->totalAmount();
    }

    /* ── open-quote lookup ── */

    /**
     * The draft or sent quote still in play for a request, if any.
     * Call inside a transaction that already holds lockForUpdate() on
     * the request row; pass $lock = true to also lock the quote row.
     */
    public static function findOpenForRequest(int $requestId, bool $lock = false): ?self
    {
        $query = static::query()
            ->where('request_id', $requestId)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SENT])
            ->orderByDesc('version');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /* ── relationships ── */

    public function request()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'request_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** The agreement created when this quote was accepted. */
    public function agreement()
    {
        return $this->hasOne(DesignAgreement::class, 'quote_id');
    }

    /** For an amendment quote: the agreement it amends. */
    public function amendsAgreement()
    {
        return $this->belongsTo(DesignAgreement::class, 'amends_agreement_id');
    }
}
