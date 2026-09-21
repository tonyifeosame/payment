<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rung of a school's class ladder ("Primary 3", "JSS 1", "Basic 4" …).
 *
 * The school defines the list and its order; `position` alone decides where a
 * student goes next. Nothing here reads the name — "JSS 1" is not assumed to
 * precede "JSS 2" unless the school placed it there. The rung after the last
 * active one is graduation.
 */
class ClassLevel extends Model
{
    protected $fillable = ['school_id', 'name', 'position', 'is_active'];

    protected $casts = [
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        return $query->where('school_id', $school instanceof School ? $school->id : $school);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The level a student in this class normally moves to, given the school's full
     * ordered ladder: the next ACTIVE rung after this one, or null for graduation.
     *
     * @param  Collection<int, ClassLevel>  $ladder  every level of the same school, ordered
     */
    public function nextIn(Collection $ladder): ?ClassLevel
    {
        return $ladder->first(fn (ClassLevel $level) => $level->is_active
            && ($level->position > $this->position || ($level->position === $this->position && $level->id > $this->id)));
    }
}
