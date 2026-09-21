<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bulk promotion run: the audit record of a school moving its roster into a
 * new academic session. The per-student detail lives in the entries.
 */
class StudentPromotion extends Model
{
    protected $fillable = [
        'school_id', 'from_academic_session_id', 'to_academic_session_id',
        'performed_by', 'promoted_count', 'graduated_count', 'excluded_count',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_academic_session_id');
    }

    public function toSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'to_academic_session_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(StudentPromotionEntry::class);
    }
}
