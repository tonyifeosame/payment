<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy admin URLs (/s/{school}/…) → canonical (/admin/{school}/…).
 *
 * Registered on the legacy admin route group AFTER EnsureSchoolAdmin and after
 * the router's scoped model binding, so a guest is still sent to login, another
 * school's slug is still a 404 and a foreign record id is still a 404 — exactly
 * as before — and only then is a permitted request redirected. Only GET/HEAD are
 * redirected (301: these paths are retired for good); a write method on a legacy
 * URL keeps executing its unchanged action, whose own redirect already lands on
 * the canonical page, rather than being bounced through a method-changing redirect.
 *
 * Public legacy routes (/s/{school}/payment*, /s/{school}/logo) are not in this
 * group and are never redirected.
 */
class RedirectLegacyAdminUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        if (($request->isMethod('GET') || $request->isMethod('HEAD')) && str_starts_with($request->path(), 's/')) {
            $target = '/admin/'.substr($request->path(), 2);
            $query = $request->getQueryString();

            return redirect($target.($query !== null && $query !== '' ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
