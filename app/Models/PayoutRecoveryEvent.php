<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator action on a payout (B1 recovery tooling). Immutable: written once,
 * in the same transaction as the state change it records, never updated.
 *
 * Never holds provider payloads, account numbers or credentials.
 */
class PayoutRecoveryEvent extends Model
{
    public const ACTION_RETRY = 'retry';

    public const ACTION_RELEASE = 'release';

    public const ACTION_LOOKUP = 'lookup';

    /** Who performed the action. The artisan commands are the only source today. */
    public const SOURCE_ARTISAN = 'artisan';

    const UPDATED_AT = null;

    protected $fillable = [
        'payout_id',
        'school_id',
        'action',
        'previous_status',
        'new_status',
        'source',
        'amount',
        'reason',
        'result',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
