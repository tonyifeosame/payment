<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subcategory extends Model
{
    protected $fillable = ['category_id', 'name', 'price', 'school_id', 'academic_term_id', 'allows_quantity', 'is_tuition'];

    /** A new fee is a single charge until the school allows multiple units (L1). */
    protected $attributes = ['allows_quantity' => false, 'is_tuition' => false];

    protected $casts = ['allows_quantity' => 'boolean', 'is_tuition' => 'boolean'];

    /**
     * The class levels this fee is assigned to (fee_assignments). None means the
     * fee applies to every student, as every fee did before assignment existed.
     */
    public function classLevels(): BelongsToMany
    {
        return $this->belongsToMany(ClassLevel::class, 'fee_assignments')
            ->withPivot('school_id')
            ->withTimestamps();
    }

    /**
     * May this fee be paid for the given student? An unassigned fee may, for anyone
     * (or no one, on a school without a roster). An assigned fee only for a student
     * of this fee's school whose class level is one it is assigned to — a student
     * not yet mapped to a level, or no student at all, cannot pay it.
     */
    public function isPayableForStudent(?Student $student): bool
    {
        if (! $this->classLevels()->exists()) {
            // A main (tuition) fee is a class's fee by definition: unassigned, it
            // belongs to no class and is payable by no one, so a parent can never
            // choose another class's tuition through an unassigned one. Ordinary
            // unassigned fees (uniform, books) stay payable by everyone, as before.
            return ! $this->is_tuition;
        }

        return $student !== null
            && $student->class_level_id !== null
            && (int) $student->school_id === (int) $this->school_id
            && $this->classLevels()
                ->where('class_levels.school_id', $this->school_id)
                ->whereKey($student->class_level_id)
                ->exists();
    }

    /** Fees the student may pay: unassigned ones, and those assigned to the student's class level. */
    public function scopeApplicableTo(Builder $query, ?Student $student): Builder
    {
        return $query->where(function (Builder $q) use ($student) {
            // Unassigned ordinary fees; an unassigned main fee applies to no one
            // (see isPayableForStudent).
            $q->where(fn (Builder $open) => $open->whereDoesntHave('classLevels')->where('subcategories.is_tuition', false));
            if ($student?->class_level_id !== null) {
                $q->orWhereHas('classLevels', fn (Builder $levels) => $levels
                    ->where('class_levels.school_id', $student->school_id)
                    ->whereKey($student->class_level_id));
            }
        });
    }

    /**
     * The term this fee is charged for, or null for a general fee (uniform, books)
     * that is payable in any term.
     */
    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /** May this fee be paid for the given term? General fees may; term fees only for their own term. */
    public function isPayableForTerm(?AcademicTerm $term): bool
    {
        if ($this->academic_term_id === null) {
            return true;
        }

        return $term !== null && (int) $term->id === (int) $this->academic_term_id;
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
