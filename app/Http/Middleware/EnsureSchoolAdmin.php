<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;

class EnsureSchoolAdmin
{
    /**
     * Establish the authenticated school for the request and prove that any
     * school bound to the route is that same school.
     *
     * Every protected route is now tenant-scoped, so this middleware is the single
     * place that answers "which school is acting?". Controllers must derive their
     * queries from the school it resolves, never from unscoped model lookups.
     */
    public function handle(Request $request, Closure $next)
    {
        $schoolId = session('school_admin_id');
        if (! $schoolId) {
            return redirect()->route('admin.login')->with('error', 'Please log in.');
        }

        // The session may reference a school that has since been deleted.
        $school = School::find($schoolId);
        if (! $school) {
            $request->session()->forget('school_admin_id');

            return redirect()->route('admin.login')->with('error', 'Please log in.');
        }

        // If the route binds a school, it must be the authenticated one.
        $routeSchool = $request->route('school');
        if ($routeSchool instanceof School && (int) $routeSchool->id !== (int) $school->id) {
            abort(404);
        }

        // Expose the authenticated school so controllers can scope from a trusted source.
        $request->attributes->set('school', $school);

        return $next($request);
    }
}
