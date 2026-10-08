<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use App\Services\AcademicPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What remains of academic-session management in the admin.
 *
 * Schools no longer create sessions by hand: a fee is created for an academic year
 * and term, and AcademicPeriodService::termFor() finds or creates the session and
 * its three terms behind it. The only admin choice left is which term is current,
 * made from the Fees page.
 */
class AcademicSessionController extends Controller
{
    /** The retired Sessions page: bookmarks land on Fees, where terms now live. */
    public function index(School $school)
    {
        return redirect()->route('school.subcategories.index', ['school' => $school->slug]);
    }

    /**
     * The Fees page's "current term" control: academic year + term, found or
     * created like a fee's. Only the pointer change is audited (setCurrentTerm).
     */
    public function updateCurrent(Request $request, School $school, AcademicPeriodService $periods)
    {
        $data = $request->validate([
            'current_academic_year' => [
                'required', 'string', 'max:20',
                function ($attribute, $value, $fail) {
                    if (! AcademicSession::isValidName(trim((string) $value))) {
                        $fail('Enter the academic year as two consecutive years, e.g. 2026/2027.');
                    }
                },
            ],
            'current_term' => ['required', 'integer', Rule::in(array_keys(AcademicTerm::NAMES))],
        ], [
            'current_term.required' => 'Choose the term.',
            'current_term.in' => 'Choose First, Second or Third Term.',
        ]);

        $term = DB::transaction(function () use ($school, $periods, $data) {
            $term = $periods->termFor($school, $data['current_academic_year'], (int) $data['current_term']);
            $periods->setCurrentTerm($school->refresh(), $term);

            return $term;
        });

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', $term->label.' is now the current term. The payment page opens on it.');
    }

    /**
     * Make a term the school's current one. {academicTerm} is scope-bound to the school's
     * academicTerms relationship, and the service re-asserts ownership.
     */
    public function setCurrent(School $school, AcademicTerm $academicTerm, AcademicPeriodService $periods)
    {
        $periods->setCurrentTerm($school, $academicTerm);

        return redirect()->route('school.subcategories.index', ['school' => $school->slug])
            ->with('success', $academicTerm->label.' is now the current term. The payment page opens on it.');
    }
}
