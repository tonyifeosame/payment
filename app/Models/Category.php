<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class Category extends Model
{
    /** system_key of the built-in category every main (tuition) fee belongs to. */
    public const SYSTEM_SCHOOL_FEES = 'school_fees';

    public const SCHOOL_FEES_NAME = 'School Fees';

    // system_key is deliberately not fillable: only schoolFeesFor() sets it.
    protected $fillable = ['name', 'school_id'];

    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /** Provided by FEYRA, not typed by the school: it cannot be renamed or deleted. */
    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * The comparison form of a category name: case, punctuation, spacing and plurals
     * ignored, so "School Fees", "school fee", "SCHOOL-FEES" and "SchoolFees" are one
     * name. Used to refuse near-duplicate categories.
     */
    public static function normalizeName(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name))), -1, PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(fn (string $w) => Str::singular($w), $words));
    }

    /** Is this name a spelling of the built-in "School Fees"? */
    public static function isSchoolFeesName(string $name): bool
    {
        return self::normalizeName($name) === self::normalizeName(self::SCHOOL_FEES_NAME);
    }

    /**
     * The school's built-in "School Fees" category, created on first use. A school
     * that typed its own "School Fees" (any spelling) before it was built in has
     * its oldest such category adopted instead of gaining a second one; see the
     * 2026_10_08 migration, which does the same for existing schools.
     */
    public static function schoolFeesFor(School $school): self
    {
        $existing = self::where('school_id', $school->id)->where('system_key', self::SYSTEM_SCHOOL_FEES)->first();
        if ($existing) {
            return $existing;
        }

        $legacy = self::where('school_id', $school->id)->whereNull('system_key')->orderBy('id')->get()
            ->first(fn (self $c) => self::isSchoolFeesName($c->name));

        try {
            if ($legacy) {
                $legacy->forceFill(['system_key' => self::SYSTEM_SCHOOL_FEES, 'name' => self::SCHOOL_FEES_NAME])->save();

                return $legacy;
            }

            $category = new self(['school_id' => $school->id, 'name' => self::SCHOOL_FEES_NAME]);
            $category->forceFill(['system_key' => self::SYSTEM_SCHOOL_FEES])->save();

            return $category;
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created it first (categories_school_system_key_unique).
            return self::where('school_id', $school->id)->where('system_key', self::SYSTEM_SCHOOL_FEES)->firstOrFail();
        }
    }
}
