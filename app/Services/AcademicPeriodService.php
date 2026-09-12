<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use Illuminate\Support\Facades\DB;

/**
 * Creates and selects the periods a school collects fees for.
 */
class AcademicPeriodService
{
    /**
     * Create a session and its three terms in one transaction. The first session a
     * school creates also becomes its current term (First Term), so the dashboard
     * and payment page have a context without a second click.
     */
    public function createSession(School $school, string $name, ?string $startsOn = null, ?string $endsOn = null): AcademicSession
    {
        return DB::transaction(function () use ($school, $name, $startsOn, $endsOn) {
            $session = $school->academicSessions()->create([
                'name' => trim($name),
                'starts_on' => $startsOn ?: null,
                'ends_on' => $endsOn ?: null,
            ]);

            foreach (AcademicTerm::NAMES as $number => $termName) {
                $session->terms()->create([
                    'school_id' => $school->id,
                    'number' => $number,
                    'name' => $termName,
                ]);
            }

            if ($school->current_academic_term_id === null) {
                $school->forceFill([
                    'current_academic_term_id' => $session->terms()->where('number', 1)->value('id'),
                ])->save();
            }

            return $session->load('terms');
        });
    }

    /**
     * Point the school at a term it owns. The ownership check is the whole point:
     * a term id from the request is never written without proving it is this
     * school's, so a school can never "select" another school's term.
     */
    public function setCurrentTerm(School $school, AcademicTerm $term): void
    {
        if ((int) $term->school_id !== (int) $school->id) {
            abort(404);
        }

        $school->forceFill(['current_academic_term_id' => $term->id])->save();
    }

    /**
     * All of a school's terms, newest session first, with sessions loaded — the
     * list every term dropdown (fees, payment page, filters) is built from.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AcademicTerm>
     */
    public function termsForSchool(School $school)
    {
        return AcademicTerm::query()
            ->where('academic_terms.school_id', $school->id)
            ->join('academic_sessions', 'academic_sessions.id', '=', 'academic_terms.academic_session_id')
            ->orderByDesc('academic_sessions.name')
            ->orderBy('academic_terms.number')
            ->select('academic_terms.*')
            ->with('session')
            ->get();
    }
}
