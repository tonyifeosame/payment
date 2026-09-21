<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one promotion run did to one student: previous class, new class (null when
 * graduated) and the action. The unique (student_id, to_academic_session_id)
 * index means a student can only ever be promoted into a given session once.
 */
class StudentPromotionEntry extends Model
{
    public const ACTION_PROMOTED = 'promoted';

    public const ACTION_GRADUATED = 'graduated';

    protected $fillable = [
        'student_promotion_id', 'school_id', 'student_id', 'to_academic_session_id',
        'from_class_level_id', 'to_class_level_id', 'from_class_name', 'to_class_name', 'action',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(StudentPromotion::class, 'student_promotion_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
