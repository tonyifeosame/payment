<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subcategory extends Model
{
    protected $fillable = ['category_id', 'name', 'price', 'school_id', 'academic_term_id'];

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
