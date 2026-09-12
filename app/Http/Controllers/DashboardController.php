<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Services\AcademicPeriodService;
use App\Services\SchoolDashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, School $school, SchoolDashboardService $dashboard, AcademicPeriodService $periods)
    {
        $terms = $periods->termsForSchool($school);

        // Term context: an explicit ?term= that belongs to this school wins, then
        // the admin-selected current term, then none (all-time figures).
        $term = null;
        $requested = $request->query('term');
        if ($requested !== null && $requested !== '' && ctype_digit((string) $requested)) {
            $term = $terms->firstWhere('id', (int) $requested);
        } elseif ($school->current_academic_term_id) {
            $term = $terms->firstWhere('id', (int) $school->current_academic_term_id);
        }

        $stats = $dashboard->build($school, $term instanceof AcademicTerm ? $term : null);

        return view('dashboard.index', [
            'school' => $school,
            'terms' => $terms,
            'stats' => $stats,
            'selectedTerm' => $term,
        ]);
    }
}
