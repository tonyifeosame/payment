<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Forwarded-Proto') == 'http') {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
