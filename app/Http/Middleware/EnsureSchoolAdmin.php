<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Support\SchoolSession;
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
        // No session, a deleted school, or a session that predates the school's
        // current password (H6) all mean "not signed in".
        $school = SchoolSession::school($request);
        if (! $school) {
            return redirect()->route('admin.login')->with('error', $request->session()->get('error', 'Please log in.'));
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
