<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The record created at the moment a customer accepts a quote.
 * Insert-only (see ImmutableRecord): never edited, never deleted.
 * A change after acceptance is a NEW agreement that points back at
 * this one through amends_agreement_id.
 *
 * terms_snapshot holds everything that was agreed, including the
 * policy text the customer was shown, so this row stays meaningful
 * even if the quote, request or catalog data change later.
 * snapshot_sha256 is the hash of that snapshot in canonical form.
 */
class DesignAgreement extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $fillable = [
        'agreement_number',
        'request_id',
        'quote_id',
        'quote_version',
        'amends_agreement_id',
        'user_id',
        'finalized_by',
        'sent_at',
        'accepted_at',
        'accepted_at_local',
        'ip_address',
        'user_agent',
        'policy_version',
        'terms_snapshot',
        'snapshot_sha256',
        'total_amount',
        'upfront_amount',
    ];

    protected $casts = [
        'terms_snapshot' => 'array',
        'sent_at'        => 'datetime',
        'accepted_at'    => 'datetime',
        'created_at'     => 'datetime',
        'total_amount'   => 'decimal:2',
        'upfront_amount' => 'decimal:2',
    ];

    /* ── hashing ── */

    /**
     * Canonical SHA-256 of a terms snapshot: object keys are sorted
     * recursively (so key order — which a JSON column may not preserve
     * — never changes the hash), list order is kept, and slashes and
     * unicode are not escaped. Put money in the snapshot as strings
     * ("12000.00"), not floats, so it round-trips exactly.
     */
    public static function hashSnapshot(array $snapshot): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($snapshot),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /** Does the stored snapshot still hash to the stored digest? */
    public function snapshotIsIntact(): bool
    {
        return hash_equals((string) $this->snapshot_sha256, self::hashSnapshot((array) $this->terms_snapshot));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'canonicalize'], $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /* ── relationships ── */

    public function request()
    {
        return $this->belongsTo(CustomDesignRequest::class, 'request_id');
    }

    public function quote()
    {
        return $this->belongsTo(CustomQuote::class, 'quote_id');
    }

    /** The customer who accepted. */
    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The admin who finalized and sent the quote. */
    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function policy()
    {
        return $this->belongsTo(AgreementPolicy::class, 'policy_version', 'version');
    }

    /** The agreement this one amends, if any. */
    public function amends()
    {
        return $this->belongsTo(self::class, 'amends_agreement_id');
    }

    /** Amendments made to this agreement. */
    public function amendments()
    {
        return $this->hasMany(self::class, 'amends_agreement_id');
    }

    /** The order created from this agreement (at most one — unique in the DB). */
    public function order()
    {
        return $this->hasOne(Order::class, 'design_agreement_id');
    }
}
