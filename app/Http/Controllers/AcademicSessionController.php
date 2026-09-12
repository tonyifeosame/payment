<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use App\Services\AcademicPeriodService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Academic sessions and terms, tenant-scoped.
 */
class AcademicSessionController extends Controller
{
    public function index(School $school)
    {
        $sessions = $school->academicSessions()->with('terms')->get();

        return view('sessions.index', [
            'school' => $school,
            'sessions' => $sessions,
            'currentTermId' => $school->current_academic_term_id,
        ]);
    }

    public function store(Request $request, School $school, AcademicPeriodService $periods)
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:20',
                function ($attribute, $value, $fail) {
                    if (! AcademicSession::isValidName((string) $value)) {
                        $fail('Enter the session as two consecutive years, e.g. 2026/2027.');
                    }
                },
                Rule::unique('academic_sessions', 'name')->where(fn ($q) => $q->where('school_id', $school->id)),
            ],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ], [
            'name.unique' => 'This session already exists for your school.',
        ]);

        $periods->createSession($school, $data['name'], $data['starts_on'] ?? null, $data['ends_on'] ?? null);

        return redirect()->route('school.sessions.index', ['school' => $school->slug])
            ->with('success', 'Session '.$data['name'].' created with First, Second and Third Term.');
    }

    /**
     * Make a term the school's current one. {academicTerm} is scope-bound to the school's
     * academicTerms relationship, and the service re-asserts ownership.
     */
    public function setCurrent(School $school, AcademicTerm $academicTerm, AcademicPeriodService $periods)
    {
        $periods->setCurrentTerm($school, $academicTerm);

        return redirect()->route('school.sessions.index', ['school' => $school->slug])
            ->with('success', $academicTerm->name.' is now the current term.');
    }
}
