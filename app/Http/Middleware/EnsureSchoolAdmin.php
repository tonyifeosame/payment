<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\School;

class EnsureSchoolAdmin
{
    public function handle(Request $request, Closure $next)
    {
        // Expect a logged-in school id in session
        $schoolId = session('school_admin_id');
        if (!$schoolId) {
            return redirect()->route('admin.login')->with('error', 'Please log in.');
        }

        // If route has a bound school, ensure it matches logged in admin
        /** @var School|null $routeSchool */
        $routeSchool = $request->route('school');
        if ($routeSchool instanceof School) {
            if ((int)$routeSchool->id !== (int)$schoolId) {
                return redirect()->route('admin.login')->with('error', 'Unauthorized for this school.');
            }
        }

        return $next($request);
    }
}
