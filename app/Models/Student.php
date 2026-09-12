<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student on a school's roster.
 *
 * The admission number is the handle a parent quotes on the public payment page;
 * it is unique within a school (students_school_admission_unique) and is stored
 * normalised (trimmed, upper-cased) so "abc/001" and "ABC/001 " resolve to one
 * row. Every lookup MUST start from a school_id — see forSchool() and
 * findByAdmissionNumber().
 */
class Student extends Model
{
    protected $fillable = [
        'school_id',
        'full_name',
        'admission_number',
        'class_name',
        'academic_session_id',
        'guardian_name',
        'guardian_phone',
        'guardian_email',
    ];

    /** Canonical form of an admission number as typed by a human. */
    public static function normalizeAdmissionNumber(?string $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    public function setAdmissionNumberAttribute(?string $value): void
    {
        $this->attributes['admission_number'] = self::normalizeAdmissionNumber($value);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        return $query->where('school_id', $school instanceof School ? $school->id : $school);
    }

    /**
     * Resolve a student by admission number within ONE school. Returns null rather
     * than throwing so callers can produce a validation error instead of a 404.
     */
    public static function findByAdmissionNumber(School $school, ?string $admissionNumber): ?self
    {
        $normalized = self::normalizeAdmissionNumber($admissionNumber);

        if ($normalized === '') {
            return null;
        }

        return self::forSchool($school)->where('admission_number', $normalized)->first();
    }

    /** Search by name, admission number or class, always within the given query's school. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $like = '%'.$term.'%';
            $q->where('full_name', 'like', $like)
                ->orWhere('admission_number', 'like', '%'.self::normalizeAdmissionNumber($term).'%')
                ->orWhere('class_name', 'like', $like);
        });
    }
}
