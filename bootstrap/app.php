<?php

use App\Support\BankLookupLimiter;
use App\Support\PaymentInitializeLimiter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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
    ->booted(function (): void {
        // Named rate limiters are registered here because this file is the
        // active bootstrap: bootstrap/providers.php points at a provider that is
        // not autoloadable (it sits outside app/), so nothing in it ever runs.
        //
        // payment-initialize: 10/min and 60/hour per client IP, shared by the
        // canonical and legacy checkout POSTs (see PaymentInitializeLimiter).
        // Counters live in the default cache store — the database in production
        // — so every web instance sees the same buckets.
        RateLimiter::for(PaymentInitializeLimiter::NAME, PaymentInitializeLimiter::limits(...));

        // bank-list / bank-resolve: the two public Paystack lookup helpers behind
        // the bank dropdown and the account-name preview. 20/min and 60/hour per
        // IP for the list; 10/min and 40/hour per IP for resolution, plus 60/hour
        // per signed-in school (see BankLookupLimiter). Two distinct limiter
        // names, so the buckets are separate: exhausting one leaves the other
        // open, and neither touches payment-initialize.
        RateLimiter::for(BankLookupLimiter::LIST_NAME, BankLookupLimiter::bankList(...));
        RateLimiter::for(BankLookupLimiter::RESOLVE_NAME, BankLookupLimiter::bankResolve(...));

        // M5: every job that exhausts its retries says so, once, in one shape.
        // Only InitiateSchoolPayout had a failed() hook, so a receipt mailable
        // that gave up left nothing behind but a `failed_jobs` row nobody reads.
        // This is the log side; `jobs:check` is what actually reaches a human.
        // Deliberately identification only — never the payload, which carries the
        // payer's email and the serialized transaction.
        Queue::failing(function (JobFailed $event): void {
            Log::critical('Queued job failed permanently', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'attempts' => $event->job->attempts(),
                'exception' => $event->exception->getMessage(),
            ]);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
