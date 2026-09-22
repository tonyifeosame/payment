<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audited school-admin action (M7). Immutable: written once, in the same
 * transaction as the change it records, never updated.
 *
 * `actor` is a ROLE, not a person — a school has one shared credential, so
 * nothing here can name a member of staff. `actor_session` is a SHA-256 of the
 * session id, which separates one signed-in session from another without
 * storing a live credential and without claiming to identify a human.
 *
 * Never holds passwords or hashes, Paystack secrets or recipient codes, full
 * account numbers, or student personal data.
 */
class SchoolAuditEvent extends Model
{
    /** Signed-in school admin acting through the web UI. */
    public const ACTOR_SCHOOL_ADMIN = 'school_admin';

    /** An operator at the console. */
    public const ACTOR_ARTISAN = 'artisan';

    /** The application itself, with no human behind the request. */
    public const ACTOR_SYSTEM = 'system';

    // ---- Tier 1: identity and money -------------------------------------

    /** School name and/or email changed: the login and password-reset identifiers. */
    public const ACTION_PROFILE_CHANGED = 'settings.profile_changed';

    /** The school's single admin password was changed from Settings. */
    public const ACTION_PASSWORD_CHANGED = 'settings.password_changed';

    /** The payout destination changed. */
    public const ACTION_BANK_CHANGED = 'settings.bank_changed';

    public const ACTION_FEE_CREATED = 'fee.created';

    public const ACTION_FEE_UPDATED = 'fee.updated';

    public const ACTION_FEE_DELETED = 'fee.deleted';

    /** The term new payments are attributed to changed. */
    public const ACTION_TERM_CHANGED = 'settings.current_term_changed';

    // ---- Tier 2: destructive --------------------------------------------

    public const ACTION_CATEGORY_DELETED = 'category.deleted';

    public const ACTION_CLASS_LEVEL_DELETED = 'class_level.deleted';

    /** Written once; there is no update path. */
    const UPDATED_AT = null;

    protected $fillable = [
        'school_id',
        'actor',
        'actor_session',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
