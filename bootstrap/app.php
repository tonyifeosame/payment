<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Registered outside every middleware group: no session, no cookies,
            // no CSRF. See routes/webhooks.php.
            Route::group([], __DIR__.'/../routes/webhooks.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates TLS at its edge and forwards over plain HTTP, so the
        // X-Forwarded-* headers are the only truth about the original request.
        // Without trusting them Laravel sees scheme=http and the proxy's own IP,
        // which breaks signed URL validation (a link signed as https is checked as
        // http) and collapses per-IP rate limiting into one global bucket.
        // '*' is correct here: Render's edge is the only thing that can reach the
        // container, so there is no untrusted hop to spoof these headers.
        $middleware->trustProxies(at: '*');

        $middleware->append(\App\Http\Middleware\ForceHttps::class);

        // The webhook is registered outside the web group (see withRouting above),
        // so CSRF validation never runs for it. This entry is kept deliberately as a
        // guard: if the route is ever moved back into routes/web.php it stays exempt,
        // because a server-to-server POST has no CSRF token to present. Its actual
        // access control is the HMAC signature check in PaystackWebhookController.
        $middleware->validateCsrfTokens(except: [
            'paystack/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
