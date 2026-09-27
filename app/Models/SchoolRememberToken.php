<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered browser for a school admin. Holds only a hash of the secret;
 * issuing, checking and revoking live in App\Support\SchoolRemember.
 */
class SchoolRememberToken extends Model
{
    protected $fillable = [
        'school_id',
        'selector',
        'token_hash',
        'password_fingerprint',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash', 'password_fingerprint'];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
