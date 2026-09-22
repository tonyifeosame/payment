<?php

namespace App\Support;

use App\Http\Controllers\PaymentController;
use App\Models\School;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `payment-initialize` rate limiter behind both public checkout endpoints
 * (POST /pay/{school}/initialize and the legacy POST /s/{school}/payment/initialize).
 *
 * Every submit creates a pending transaction and calls Paystack, so an
 * unauthenticated form is an easy way to fill the transactions table and burn
 * through Paystack's own limits. The bucket is keyed by client IP only — never
 * by school, student, email, reference or session — so alternating schools or
 * dropping cookies does not buy a fresh allowance; and it is ONE named limiter,
 * so switching between the canonical and legacy URL does not either. The
 * throttle middleware runs before the controller, so a throttled request never
 * reaches createPendingTransaction() or the Paystack API.
 *
 * Registered in bootstrap/app.php; applied as `throttle:payment-initialize`.
 */
final class PaymentInitializeLimiter
{
    public const NAME = 'payment-initialize';

    public const PER_MINUTE = 10;

    public const PER_HOUR = 60;

    /**
     * The limits for one request: a short burst window and an hourly ceiling.
     *
     * The two limits need distinct keys — the throttle middleware namespaces a
     * key by limiter name only, so two limits keyed by the bare IP would share
     * one counter (each request counted twice against the first window).
     *
     * @return array<int, Limit>
     */
    public static function limits(Request $request): array
    {
        $ip = (string) $request->ip();

        return [
            Limit::perMinute(self::PER_MINUTE)->by('minute:'.$ip)->response(self::response(...)),
            Limit::perHour(self::PER_HOUR)->by('hour:'.$ip)->response(self::response(...)),
        ];
    }

    /**
     * The 429 shown to a throttled parent.
     *
     * A redirect cannot carry a 429, so the payment form itself is re-rendered
     * as the 429 body — the same page `back()->withInput()` would have landed
     * on, with the parent's choices still filled in and the reason on top — and
     * the throttle headers (Retry-After, X-RateLimit-*) are attached to it.
     *
     * @param  array<string, mixed>  $headers  the throttle headers, incl. Retry-After
     */
    public static function response(Request $request, array $headers): Response
    {
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));
        $message = 'Too many payment attempts from your connection. Please wait '
            .self::humanWait($retryAfter).' and try again. No payment was started.';

        // The throttle runs before route-model binding, so {school} is normally
        // still the slug; accept a bound model too in case the order ever changes.
        $school = $request->route('school');
        if (! $school instanceof School) {
            $school = is_string($school) ? School::where('slug', $school)->first() : null;
        }

        if (! $school) {
            return response($message, 429, $headers);
        }

        // old() and session('error') are what the form reads, so the input and the
        // message go through the session — for this render only. Left flashed,
        // they would be consumed by whichever request came next (often the page's
        // own logo fetch), so they are removed again once the page is built.
        $session = $request->session();
        $session->flashInput($request->except('_token'));
        $session->flash('error', $message);

        try {
            $html = app(PaymentController::class)->indexSchool($school)->render();
        } finally {
            $session->forget(['_old_input', 'error']);
        }

        return response($html, 429, $headers);
    }

    private static function humanWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : $seconds.' seconds';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? 'a minute' : $minutes.' minutes';
    }
}
