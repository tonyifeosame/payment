<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\StudentPromotionEntry;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bulk promotion of a school's roster into a new academic year.
 *
 * Where a student goes is decided ONLY by the school's ordered class ladder
 * (ClassLevel::nextIn): the next active rung, or graduation after the last one.
 * Nothing is inferred from class names.
 *
 * The admin never manages sessions: they promote "into 2027/2028", and the
 * session row behind that year is created when the promotion is applied
 * (AcademicPeriodService::termFor). Until then the target is an unsaved model.
 *
 * Safety properties:
 *  - every read and write is scoped to the acting school; student ids from the
 *    browser are re-resolved inside that scope and anything else is rejected;
 *  - apply() runs in one database transaction — a failure leaves nothing changed;
 *  - a student is promoted at most once per academic year, and never "backwards":
 *    a student with an entry into the target year OR ANY LATER YEAR is not
 *    eligible. apply() re-checks it, and the unique index on
 *    student_promotion_entries enforces the same-year case even against two
 *    concurrent requests;
 *  - only the current year, or — in the last term — the next one, can be a
 *    target (allowedTargetYears), so a promotion run twice in the same year can
 *    never move anyone a second rung.
 */
class StudentPromotionService
{
    public function __construct(private AcademicPeriodService $periods) {}

    /**
     * The academic years the school may promote into right now: the current year
     * (for a school that promotes when the new year starts) and, in the last term
     * of the year or when no term is set, the next year (for a school that
     * promotes at the end of the year). Never further ahead.
     *
     * @return array<int, string>
     */
    public function allowedTargetYears(School $school): array
    {
        $current = $this->periods->currentYear($school);
        $term = $school->currentTerm;

        $years = [$current];
        if ($term === null || (int) $term->number === max(array_keys(AcademicTerm::NAMES))) {
            $years[] = AcademicPeriodService::nextYear($current);
        }

        return $years;
    }

    /** The year the promotion page offers first: the latest allowed one. */
    public function defaultTargetYear(School $school): string
    {
        $years = $this->allowedTargetYears($school);

        return end($years);
    }

    /** The school's session for $year, or an unsaved one apply() will create. */
    public function targetSession(School $school, string $year): AcademicSession
    {
        return AcademicSession::where('school_id', $school->id)->where('name', $year)->first()
            ?? new AcademicSession(['school_id' => $school->id, 'name' => $year]);
    }

    /**
     * Everything the review screen needs for a target session.
     *
     * @return array{
     *   ladder: Collection<int, ClassLevel>,
     *   rows: Collection<int, array{student: Student, from: ClassLevel, to: ClassLevel|null}>,
     *   groups: Collection<string, array{from: ClassLevel, to: ClassLevel|null, count: int}>,
     *   unassigned: int, already: int
     * }
     */
    public function preview(School $school, AcademicSession $to): array
    {
        $this->assertOwnSession($school, $to);

        $ladder = $school->classLevels()->get();

        $students = Student::forSchool($school)
            ->active()
            ->whereNotNull('class_level_id')
            ->with('classLevel')
            ->rosterOrder()
            ->get();

        $alreadyIds = $this->alreadyPromotedIds($school, $to, $students->pluck('id')->all());

        $rows = $students
            ->reject(fn (Student $s) => isset($alreadyIds[$s->id]) || ! $s->classLevel)
            ->map(fn (Student $s) => ['student' => $s, 'from' => $s->classLevel, 'to' => $s->classLevel->nextIn($ladder)])
            ->values();

        $groups = $rows
            ->groupBy(fn (array $row) => $row['from']->id)
            ->map(fn (Collection $group) => ['from' => $group->first()['from'], 'to' => $group->first()['to'], 'count' => $group->count()])
            ->sortBy(fn (array $g) => $g['from']->position)
            ->values();

        return [
            'ladder' => $ladder,
            'rows' => $rows,
            'groups' => $groups,
            'unassigned' => Student::forSchool($school)->active()->whereNull('class_level_id')->count(),
            'already' => count($alreadyIds),
        ];
    }

    /**
     * Resolve a browser-supplied selection against the live roster.
     *
     * Returns only students that are this school's, active, still in the class the
     * admin reviewed them in (expected[id] => class_level_id) and not yet promoted
     * into $to. Throws when the selection contains anything else, so a crafted or
     * stale request is rejected wholesale rather than partially applied.
     *
     * @param  array<int, int>  $expectedFrom  student id => class level id as reviewed
     * @return Collection<int, array{student: Student, from: ClassLevel, to: ClassLevel|null}>
     */
    public function resolveSelection(School $school, AcademicSession $to, array $expectedFrom): Collection
    {
        $this->assertOwnSession($school, $to);

        $ids = array_map('intval', array_keys($expectedFrom));
        if ($ids === []) {
            throw new DomainException('Select at least one student to promote.');
        }

        $ladder = $school->classLevels()->get();

        $students = Student::forSchool($school)
            ->whereIn('id', $ids)
            ->with('classLevel')
            ->get()
            ->keyBy('id');

        if ($students->count() !== count($ids)) {
            throw new DomainException('Some selected students do not belong to this school or no longer exist.');
        }

        $already = $this->alreadyPromotedIds($school, $to, $ids);
        if ($already !== []) {
            throw new DomainException(count($already).' of the selected students have already been promoted into '.$to->name.' or a later year. Review the promotion again.');
        }

        return collect($ids)->map(function (int $id) use ($students, $expectedFrom, $ladder) {
            /** @var Student $student */
            $student = $students[$id];
            if ($student->status !== Student::STATUS_ACTIVE || ! $student->classLevel) {
                throw new DomainException($student->full_name.' is not an active student with a class and cannot be promoted.');
            }
            if ((int) $expectedFrom[$id] !== (int) $student->class_level_id) {
                throw new DomainException('The roster changed since you reviewed it ('.$student->full_name.' is no longer in '.$student->class_name.'). Review the promotion again.');
            }

            return ['student' => $student, 'from' => $student->classLevel, 'to' => $student->classLevel->nextIn($ladder)];
        });
    }

    /**
     * Promote the resolved selection in one transaction and record the run.
     *
     * @param  Collection<int, array{student: Student, from: ClassLevel, to: ClassLevel|null}>  $rows
     */
    public function apply(School $school, AcademicSession $to, Collection $rows, int $excludedCount = 0): StudentPromotion
    {
        $this->assertOwnSession($school, $to);

        return DB::transaction(function () use ($school, $to, $rows, $excludedCount) {
            if (! $to->exists) {
                // First use of this academic year: create it and its three terms.
                $to = $this->periods->termFor($school, $to->name, 1)->session;
            }

            $promotion = $school->studentPromotions()->create([
                'from_academic_session_id' => $school->currentTerm?->academic_session_id,
                'to_academic_session_id' => $to->id,
                'performed_by' => 'school_admin',
                'excluded_count' => $excludedCount,
            ]);

            $promoted = 0;
            $graduated = 0;

            foreach ($rows as $row) {
                /** @var Student $student */
                $student = $row['student'];
                /** @var ClassLevel $from */
                $from = $row['from'];
                /** @var ClassLevel|null $next */
                $next = $row['to'];

                // Scoped UPDATE: the WHERE re-asserts school, status and current class,
                // so a row that changed underneath us is not touched — and the count
                // check below then aborts the whole batch.
                $changed = Student::forSchool($school)
                    ->whereKey($student->id)
                    ->active()
                    ->where('class_level_id', $from->id)
                    ->update($next
                        ? ['class_level_id' => $next->id, 'class_name' => $next->name, 'updated_at' => now()]
                        : ['status' => Student::STATUS_GRADUATED, 'updated_at' => now()]);

                if ($changed !== 1) {
                    throw new DomainException('The roster changed while promoting ('.$student->full_name.'). Nothing was changed; review the promotion again.');
                }

                $promotion->entries()->create([
                    'school_id' => $school->id,
                    'student_id' => $student->id,
                    'to_academic_session_id' => $to->id,
                    'from_class_level_id' => $from->id,
                    'to_class_level_id' => $next?->id,
                    'from_class_name' => $from->name,
                    'to_class_name' => $next?->name,
                    'action' => $next ? StudentPromotionEntry::ACTION_PROMOTED : StudentPromotionEntry::ACTION_GRADUATED,
                ]);

                $next ? $promoted++ : $graduated++;
            }

            $promotion->forceFill(['promoted_count' => $promoted, 'graduated_count' => $graduated])->save();

            return $promotion;
        });
    }

    /**
     * How many eligible students the admin left out of a selection of $selected —
     * computed here rather than trusted from the browser.
     */
    public function excludedCount(School $school, AcademicSession $to, int $selected): int
    {
        $eligible = Student::forSchool($school)->active()->whereNotNull('class_level_id')
            ->whereDoesntHave('promotionEntries', fn ($q) => $q->whereIn('to_academic_session_id', $this->sessionsFrom($school, $to)))
            ->count();

        return max(0, $eligible - $selected);
    }

    /** @return array<int, true> student ids already promoted into $to or a later year */
    private function alreadyPromotedIds(School $school, AcademicSession $to, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return StudentPromotionEntry::where('school_id', $school->id)
            ->whereIn('to_academic_session_id', $this->sessionsFrom($school, $to))
            ->whereIn('student_id', $ids)
            ->pluck('student_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * The school's sessions for $to's year and every later one. Session names are
     * "YYYY/YYYY", so string order is year order.
     */
    private function sessionsFrom(School $school, AcademicSession $to)
    {
        return AcademicSession::where('school_id', $school->id)->where('name', '>=', $to->name)->select('id');
    }

    private function assertOwnSession(School $school, AcademicSession $to): void
    {
        if ((int) $to->school_id !== (int) $school->id) {
            abort(404);
        }
    }
}
