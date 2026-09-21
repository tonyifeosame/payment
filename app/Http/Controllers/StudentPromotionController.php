<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Student;
use App\Services\StudentPromotionService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bulk promotion, in three deliberate steps:
 *
 *   1. index  — pick the session being promoted INTO, see the per-class preview and
 *               untick anyone who is repeating, leaving or otherwise not moving.
 *   2. review — a server-rendered confirmation summary of exactly what will change.
 *   3. store  — apply, in one transaction, after re-validating everything.
 *
 * The browser only ever sends student ids and the class each was reviewed in;
 * the service re-resolves both against this school's live roster and rejects
 * anything else. Where each student goes is decided by the school's ladder alone.
 */
class StudentPromotionController extends Controller
{
    public function index(Request $request, School $school, StudentPromotionService $promotions)
    {
        $sessions = $school->academicSessions()->get();
        $current = $school->currentTerm?->session;
        $to = $this->targetSession($request, $school, $sessions);

        $preview = $to ? $promotions->preview($school, $to) : null;
        $recent = $school->studentPromotions()->with(['fromSession', 'toSession'])->orderByDesc('id')->limit(5)->get();

        return view('students.promotion.index', [
            'school' => $school,
            'sessions' => $sessions,
            'current' => $current,
            'to' => $to,
            'preview' => $preview,
            'recent' => $recent,
            'hasLadder' => $school->classLevels()->exists(),
        ]);
    }

    /** Step 2: summarise the selection. Nothing is written here. */
    public function review(Request $request, School $school, StudentPromotionService $promotions)
    {
        [$to, $expected] = $this->selection($request, $school);

        try {
            $rows = $promotions->resolveSelection($school, $to, $expected);
        } catch (DomainException $e) {
            return $this->backToIndex($school, $to, $e->getMessage());
        }

        return view('students.promotion.review', [
            'school' => $school,
            'to' => $to,
            'current' => $school->currentTerm?->session,
            'rows' => $rows,
            'groups' => $this->groups($rows),
            'excluded' => $promotions->excludedCount($school, $to, $rows->count()),
        ]);
    }

    /** Step 3: apply. Same validation as review, then one transaction. */
    public function store(Request $request, School $school, StudentPromotionService $promotions)
    {
        [$to, $expected] = $this->selection($request, $school);

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
    private function selection(Request $request, School $school): array
    {
        $data = $request->validate([
            'to_session_id' => ['required', 'integer', Rule::exists('academic_sessions', 'id')->where(fn ($q) => $q->where('school_id', $school->id))],
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['required', 'integer'],
            // Per-student "class as reviewed": student id => class level id.
            'from' => ['required', 'array'],
            'from.*' => ['required', 'integer'],
        ], [
            'students.required' => 'Select at least one student to promote.',
            'to_session_id.exists' => 'Choose one of your own academic sessions.',
        ]);

        $to = AcademicSession::where('school_id', $school->id)->findOrFail((int) $data['to_session_id']);

        $expected = [];
        foreach ($data['students'] as $id) {
            $id = (int) $id;
            $expected[$id] = (int) ($data['from'][$id] ?? 0);
        }

        return [$to, $expected];
    }

    private function targetSession(Request $request, School $school, $sessions): ?AcademicSession
    {
        $requested = $request->input('to_session_id');
        if (ctype_digit((string) $requested)) {
            return $sessions->firstWhere('id', (int) $requested);
        }

        // Default: the session after the current one (sessions are named YYYY/YYYY and
        // listed newest first), if the school has created it.
        $currentName = $school->currentTerm?->session?->name;
        if ($currentName) {
            return $sessions->filter(fn ($s) => $s->name > $currentName)->sortBy('name')->first();
        }

        return null;
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
        return redirect()->route('school.students.promotion.index', ['school' => $school->slug, 'to_session_id' => $to->id])
            ->with('error', $error);
    }
}
