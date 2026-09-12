<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academic year for one school, e.g. "2026/2027". Always owns exactly three
 * terms (see AcademicTerm::NAMES), created alongside it.
 */
class AcademicSession extends Model
{
    /** A session name is two consecutive years: 2026/2027. */
    public const NAME_PATTERN = '/^(\d{4})\/(\d{4})$/';

    protected $fillable = ['school_id', 'name', 'starts_on', 'ends_on'];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function terms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class)->orderBy('number');
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Is "2026/2027" well-formed: two four-digit years, the second one year on?
     */
    public static function isValidName(string $name): bool
    {
        if (! preg_match(self::NAME_PATTERN, $name, $m)) {
            return false;
        }

        return (int) $m[2] === (int) $m[1] + 1;
    }
}
