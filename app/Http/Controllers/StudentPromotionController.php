<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\School;
use App\Services\AcademicPeriodService;
use App\Services\StudentPromotionService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bulk promotion, in three deliberate steps:
 *
 *   1. index  — see the academic year being promoted into and the per-class
 *               preview, and untick anyone who is repeating, leaving or otherwise
 *               not moving.
 *   2. review — a server-rendered confirmation summary of exactly what will change.
 *   3. store  — apply, in one transaction, after re-validating everything.
 *
 * The admin names an academic year, never a session: the years on offer come from
 * StudentPromotionService::allowedTargetYears and the year is created on apply.
 * The browser only ever sends that year, student ids and the class each was
 * reviewed in; the service re-resolves all of it against this school's live
 * roster and rejects anything else. Where each student goes is decided by the
 * school's class ladder alone.
 */
class StudentPromotionController extends Controller
{
    public function index(Request $request, School $school, StudentPromotionService $promotions, AcademicPeriodService $periods)
    {
        $years = $promotions->allowedTargetYears($school);
        $requested = (string) $request->query('to_year', '');
        $to = $promotions->targetSession($school, in_array($requested, $years, true) ? $requested : $promotions->defaultTargetYear($school));

        $preview = $promotions->preview($school, $to);
        $recent = $school->studentPromotions()->with(['fromSession', 'toSession'])->orderByDesc('id')->limit(5)->get();

        return view('students.promotion.index', [
            'school' => $school,
            'years' => $years,
            'currentYear' => $periods->currentYear($school),
            'currentTerm' => $school->currentTerm,
            'to' => $to,
            'preview' => $preview,
            'recent' => $recent,
            'hasLadder' => $school->classLevels()->exists(),
        ]);
    }

    /** Step 2: summarise the selection. Nothing is written here. */
    public function review(Request $request, School $school, StudentPromotionService $promotions, AcademicPeriodService $periods)
    {
        [$to, $expected] = $this->selection($request, $school, $promotions);

        try {
            $rows = $promotions->resolveSelection($school, $to, $expected);
        } catch (DomainException $e) {
            return $this->backToIndex($school, $to, $e->getMessage());
        }

        return view('students.promotion.review', [
            'school' => $school,
            'to' => $to,
            'currentYear' => $periods->currentYear($school),
            'rows' => $rows,
            'groups' => $this->groups($rows),
            'excluded' => $promotions->excludedCount($school, $to, $rows->count()),
        ]);
    }

    /** Step 3: apply. Same validation as review, then one transaction. */
    public function store(Request $request, School $school, StudentPromotionService $promotions)
    {
        [$to, $expected] = $this->selection($request, $school, $promotions);

        try {
            $rows = $promotions->resolveSelection($school, $to, $expected);
            $promotion = $promotions->apply($school, $to, $rows, $promotions->excludedCount($school, $to, $rows->count()));
        } catch (DomainException $e) {
            return $this->backToIndex($school, $to, $e->getMessage());
        }

        $parts = [];
        if ($promotion->promoted_count) {
            $parts[] = $promotion->promoted_count.' '.str('student')->plural($promotion->promoted_count).' promoted';
        }
        if ($promotion->graduated_count) {
            $parts[] = $promotion->graduated_count.' graduated';
        }

        return redirect()->route('school.students.index', ['school' => $school->slug])
            ->with('success', 'Promotion into '.$to->name.' applied: '.implode(', ', $parts).'.');
    }

    /**
     * @return array{0: AcademicSession, 1: array<int,int>}
     */
    private function selection(Request $request, School $school, StudentPromotionService $promotions): array
    {
        $data = $request->validate([
            'to_year' => ['required', 'string', Rule::in($promotions->allowedTargetYears($school))],
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['required', 'integer'],
            // Per-student "class as reviewed": student id => class level id.
            'from' => ['required', 'array'],
            'from.*' => ['required', 'integer'],
        ], [
            'students.required' => 'Select at least one student to promote.',
            'to_year.in' => 'Students can only be promoted into the current academic year or, in the last term, the next one. Review the promotion again.',
        ]);

        $to = $promotions->targetSession($school, $data['to_year']);

        $expected = [];
        foreach ($data['students'] as $id) {
            $id = (int) $id;
            $expected[$id] = (int) ($data['from'][$id] ?? 0);
        }

        return [$to, $expected];
    }

    private function groups($rows)
    {
        return $rows
            ->groupBy(fn (array $row) => $row['from']->id)
            ->map(fn ($group) => ['from' => $group->first()['from'], 'to' => $group->first()['to'], 'count' => $group->count()])
            ->sortBy(fn (array $g) => $g['from']->position)
            ->values();
    }

    private function backToIndex(School $school, AcademicSession $to, string $error)
    {
        return redirect()->route('school.students.promotion.index', ['school' => $school->slug, 'to_year' => $to->name])
            ->with('error', $error);
    }
}
