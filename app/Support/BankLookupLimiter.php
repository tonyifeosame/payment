<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `bank-list` and `bank-resolve` rate limiters behind the two public Paystack
 * lookup helpers (GET /api/banks and GET /api/resolve-account).
 *
 * Both endpoints are unauthenticated and every request that reaches the
 * controller is one outbound call on the integration's single secret key — the
 * same key that initialises payments and moves payouts. Left open they are the
 * cheapest way to burn Paystack's own limits, and each request pins a PHP worker
 * for the length of a synchronous lookup (up to connect 10s + 30s, retried on a
 * 5xx), so an outage turns a flood into an app-wide outage.
 *
 * Two SEPARATE named limiters, never one shared bucket: the list is fetched once
 * per form load while a resolve fires on every corrected digit, so they have
 * different legitimate rates. The throttle middleware namespaces a bucket by
 * limiter name (md5(name.key)), so `bank-list` and `bank-resolve` cannot mix.
 *
 * Keying:
 *   - client IP for both, on every request. It is the only identity the
 *     registration form has — those visitors have no session by definition.
 *   - additionally, for resolution only, the signed-in school. The schema has one
 *     admin password per school, so "several admins" means one credential shared
 *     across sessions and addresses; the school bucket counts them as the one
 *     actor they are, which a per-IP limit alone cannot. It is applied ON TOP of
 *     the IP limits, never instead of them: registration is open, so a school id
 *     on its own would be a bucket anyone could mint.
 *
 * Registered in bootstrap/app.php; applied as `throttle:bank-list` and
 * `throttle:bank-resolve`. Counters live in the default cache store — the
 * database in production — so every web instance sees the same buckets.
 */
final class BankLookupLimiter
{
    public const LIST_NAME = 'bank-list';

    public const RESOLVE_NAME = 'bank-resolve';

    public const LIST_PER_MINUTE = 20;

    public const LIST_PER_HOUR = 60;

    public const RESOLVE_PER_MINUTE = 10;

    public const RESOLVE_PER_HOUR = 40;

    /** Per signed-in school, across however many sessions and addresses it uses. */
    public const RESOLVE_PER_SCHOOL_PER_HOUR = 60;

    /**
     * GET /api/banks — 20/minute and 60/hour per client IP.
     *
     * One fetch per form load, and the registration form refetches after every
     * validation bounce-back, so the minute window has to absorb a reload loop.
     *
     * @return array<int, Limit>
     */
    public static function bankList(Request $request): array
    {
        $ip = (string) $request->ip();

        return [
            Limit::perMinute(self::LIST_PER_MINUTE)->by('minute:'.$ip)->response(self::listResponse(...)),
            Limit::perHour(self::LIST_PER_HOUR)->by('hour:'.$ip)->response(self::listResponse(...)),
        ];
    }

    /**
     * GET /api/resolve-account — 10/minute and 40/hour per client IP, plus
     * 60/hour per school when a school-admin session is present.
     *
     * Neither bank form debounces: once the account number reaches ten digits
     * every further keystroke fires a lookup, so a typo-correct-retype cycle is
     * several calls. The minute window is sized for that burst.
     *
     * The two IP limits need distinct keys — the throttle middleware namespaces a
     * key by limiter name only, so two limits keyed by the bare IP would share
     * one counter and each request would be counted twice against the first
     * window.
     *
     * @return array<int, Limit>
     */
    public static function bankResolve(Request $request): array
    {
        $ip = (string) $request->ip();

        $limits = [
            Limit::perMinute(self::RESOLVE_PER_MINUTE)->by('minute:'.$ip)->response(self::resolveResponse(...)),
            Limit::perHour(self::RESOLVE_PER_HOUR)->by('hour:'.$ip)->response(self::resolveResponse(...)),
        ];

        if ($school = self::schoolKey($request)) {
            $limits[] = Limit::perHour(self::RESOLVE_PER_SCHOOL_PER_HOUR)
                ->by('school-hour:'.$school)
                ->response(self::resolveResponse(...));
        }

        return $limits;
    }

    /**
     * The school this request is signed in as, for bucketing only.
     *
     * Deliberately the raw session id rather than SchoolSession::school(): this is
     * a counter key, not an authorization decision, and reading it costs no query.
     * Taking the unvalidated id is also the tighter choice — a session whose
     * fingerprint no longer matches still counts against its school instead of
     * falling back to an IP-only allowance.
     */
    private static function schoolKey(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(SchoolSession::ID);

        return $id ? (string) $id : null;
    }

    /**
     * The 429 for a throttled bank-list request.
     *
     * @param  array<string, mixed>  $headers  the throttle headers, incl. Retry-After
     */
    public static function listResponse(Request $request, array $headers): Response
    {
        return self::json('Too many bank-list requests from your connection. Please wait '
            .self::humanWait($headers).' and try again.', $headers);
    }

    /**
     * The 429 for a throttled account-resolution request.
     *
     * @param  array<string, mixed>  $headers  the throttle headers, incl. Retry-After
     */
    public static function resolveResponse(Request $request, array $headers): Response
    {
        return self::json('Too many account verification attempts. Please wait '
            .self::humanWait($headers).' and try again.', $headers);
    }

    /**
     * Both forms fetch these endpoints and render `data.error` on failure — the
     * same shape PaystackController::failure() returns — so a throttled lookup
     * shows its reason in the bank dropdown and under the account field without
     * any change to the views.
     *
     * @param  array<string, mixed>  $headers
     */
    private static function json(string $message, array $headers): Response
    {
        return response()->json(['ok' => false, 'error' => $message], 429, $headers);
    }

    /** @param  array<string, mixed>  $headers */
    private static function humanWait(array $headers): string
    {
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : $seconds.' seconds';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? 'a minute' : $minutes.' minutes';
    }
}
