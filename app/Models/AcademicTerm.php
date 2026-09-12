<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the three terms of an academic session.
 *
 * Fees may be tied to a term (subcategories.academic_term_id) and every payment
 * records the term it was made for. The school's "current term" is a pointer on the
 * school row, chosen by the admin — never inferred from the calendar.
 */
class AcademicTerm extends Model
{
    /** The only terms a session has, keyed by number. */
    public const NAMES = [
        1 => 'First Term',
        2 => 'Second Term',
        3 => 'Third Term',
    ];

    protected $fillable = ['school_id', 'academic_session_id', 'number', 'name', 'starts_on', 'ends_on'];

    protected $casts = [
        'number' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** "First Term, 2026/2027" — for dropdowns and receipts. */
    public function getLabelAttribute(): string
    {
        $session = $this->relationLoaded('session') ? $this->session : $this->session()->first();

        return $this->name.($session ? ', '.$session->name : '');
    }
}
