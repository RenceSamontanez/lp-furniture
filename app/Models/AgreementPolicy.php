<?php

namespace App\Models;

use App\Models\Concerns\ImmutableRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * One version of the refund / cancellation policy text. Insert-only:
 * a wording change is a NEW version, because agreements record the
 * version (and a snapshot of the text) the customer actually saw.
 */
class AgreementPolicy extends Model
{
    use ImmutableRecord;

    public const UPDATED_AT = null;

    protected $fillable = [
        'version',
        'title',
        'body',
    ];

    protected $casts = [
        'version'    => 'integer',
        'created_at' => 'datetime',
    ];

    /** The policy version new quotes should show. */
    public static function current(): ?self
    {
        return static::query()->orderByDesc('version')->first();
    }

    public function agreements()
    {
        return $this->hasMany(DesignAgreement::class, 'policy_version', 'version');
    }
}
