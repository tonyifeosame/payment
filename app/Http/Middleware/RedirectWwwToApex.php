<?php

namespace App\Http\Middleware;

use App\Support\AppUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * www.feyra.site -> https://feyra.site, same path and query.
 *
 * Render redirects www to the apex itself once both are custom domains on the
 * web service (docs/production/paystack-runbook.md §1a). This is the fallback
 * for when that redirect is not in place: without it a www request is served
 * as a second copy of the site, with a session cookie scoped to www that the
 * apex never sees.
 *
 * Deliberately narrow. Only the exact host "www." + APP_URL's host is
 * redirected: the onrender.com host, Render's health check and anything else
 * pass through untouched. The Host header is the request's own (Render keeps
 * it; X-Forwarded-Host is not trusted, see bootstrap/app.php), and the target
 * is always APP_URL, never the request's host. GET and HEAD get a 301; any
 * other method a 308, which keeps the method and body.
 */
class RedirectWwwToApex
{
    public function handle(Request $request, Closure $next): Response
    {
        $apex = parse_url(AppUrl::root(), PHP_URL_HOST);

        if (is_string($apex) && $apex !== '' && ! str_starts_with($apex, 'www.')
            && strcasecmp($request->getHost(), 'www.'.$apex) === 0) {
            return redirect()->away(
                AppUrl::root().$request->getRequestUri(),
                $request->isMethod('GET') || $request->isMethod('HEAD') ? 301 : 308,
            );
        }

        return $next($request);
    }
}
