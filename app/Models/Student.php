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
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUS_LEFT = 'left';

    /** Every status a student can hold, with the label the admin sees. */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_GRADUATED => 'Graduated',
        self::STATUS_LEFT => 'Left the school',
    ];

    protected $fillable = [
        'school_id',
        'full_name',
        'admission_number',
        // class_name is the display snapshot (payment page, receipts, CSV read it);
        // class_level_id is the school-configured level it is written from.
        'class_name',
        'class_level_id',
        'status',
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

    /**
     * The admission number as shown on the PUBLIC payment page: only the last
     * few characters, with separators kept for shape (masked characters become "*").
     * Enough for a parent to tell two same-named students apart, not enough to
     * harvest a roster. The full number is never sent to an unauthenticated page.
     */
    public function maskedAdmissionNumber(int $visible = 3): string
    {
        $number = (string) $this->admission_number;
        $length = mb_strlen($number);
        if ($length === 0) {
            return '';
        }
        // Short numbers still hide something: never reveal more than half.
        $visible = min($visible, max(1, intdiv($length, 2)));
        $hideUntil = $length - $visible;

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $ch = mb_substr($number, $i, 1);
            $out .= ($i < $hideUntil && ctype_alnum($ch)) ? '*' : $ch;
        }

        return $out;
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

    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function promotionEntries(): HasMany
    {
        return $this->hasMany(StudentPromotionEntry::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        return $query->where('school_id', $school instanceof School ? $school->id : $school);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Students a parent may pay for: the active roster. Graduated students and
     * students who left keep their record and every historical payment, but the
     * public payment page neither lists them nor accepts their id.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->active();
    }

    /**
     * Roster order: by the school's class ladder, then legacy class text (students
     * not yet mapped to a level sort after mapped ones), then name.
     */
    public function scopeRosterOrder(Builder $query): Builder
    {
        return $query
            // Explicit, because SQLite sorts NULL first and PostgreSQL sorts it last.
            ->orderByRaw('CASE WHEN students.class_level_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy(ClassLevel::select('position')->whereColumn('class_levels.id', 'students.class_level_id'))
            ->orderBy('class_name')
            ->orderBy('full_name');
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

    /**
     * The public payment page's student lookup (L8): the ACTIVE student of this
     * school whose complete admission number AND full name both match what the
     * parent typed, or null.
     *
     * Nothing is searched. The admission number is compared whole after the usual
     * normalisation (trimmed, upper-cased), so a fragment, a LIKE wildcard or a
     * name alone finds nothing; the name is then compared in full, ignoring case
     * and runs of whitespace. A parent who knows both learns who they are paying
     * for; anyone else learns nothing — every failure is the same null, whether
     * the number, the name or the school was wrong, or the input was malformed.
     */
    public static function findForPublicPayment(School $school, mixed $fullName, mixed $admissionNumber): ?self
    {
        if (! is_string($fullName) || ! is_string($admissionNumber)
            || mb_strlen($fullName) > 255 || mb_strlen($admissionNumber) > 50) {
            return null;
        }

        $number = self::normalizeAdmissionNumber($admissionNumber);
        $name = self::normalizeNameForLookup($fullName);
        if ($number === '' || $name === '') {
            return null;
        }

        $student = self::forSchool($school)->payable()->where('admission_number', $number)->first();

        return $student && self::normalizeNameForLookup($student->full_name) === $name ? $student : null;
    }

    /** A full name as compared by the public lookup: trimmed, single-spaced, lower-cased. */
    public static function normalizeNameForLookup(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
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
