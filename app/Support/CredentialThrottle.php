<?php

namespace App\Support;

use App\Models\School;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Failure counters for the credential endpoints (L7).
 *
 * These used to be route throttles keyed on the client IP alone and counting
 * every request. That meant successful logins used up the budget, a school
 * office — or a whole mobile carrier NAT — shared one bucket across schools,
 * and anonymous requests to a school's settings forms counted against its
 * signed-in admin (the route throttle runs before EnsureSchoolAdmin).
 *
 * Each counter is now keyed on WHO the attempt is about, and counts only what
 * the finding is about:
 *
 *   admin login      typed school name + client IP; failed logins only, cleared
 *                    on success
 *   bank / password  the signed-in school's id; a wrong current_password only,
 *                    cleared on success — no IP involved
 *   reset request    the typed email; every request (the reply is neutral, so
 *                    there is no "failure" to tell apart)
 *
 * The limit is unchanged: 5 per 60 minutes. Names and emails are normalised the
 * way the School lookups compare them (trimmed, case-insensitive) and hashed, so
 * no identifier is stored in a cache key. Keys are built the same way whether or
 * not the school exists, so a counter can never reveal which names or emails are
 * registered. Counters live in the default cache store (the database in
 * production), shared by every instance.
 */
final class CredentialThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 3600;

    public static function loginKey(string $name, ?string $ip): string
    {
        return 'admin-login:'.sha1(self::normalize($name).'|'.$ip);
    }

    public static function bankChangeKey(School $school): string
    {
        return 'bank-change:school:'.$school->id;
    }

    public static function passwordChangeKey(School $school): string
    {
        return 'password-change:school:'.$school->id;
    }

    public static function resetRequestKey(string $email): string
    {
        return 'password-reset-request:'.sha1(self::normalize($email));
    }

    public static function tooManyAttempts(string $key): bool
    {
        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS);
    }

    public static function hit(string $key): void
    {
        RateLimiter::hit($key, self::DECAY_SECONDS);
    }

    public static function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    /** Seconds until the counter allows another attempt (at least 1). */
    public static function availableIn(string $key): int
    {
        return max(1, RateLimiter::availableIn($key));
    }

    /** The same 429 (with Retry-After) the route throttle used to produce. */
    public static function exception(string $key): ThrottleRequestsException
    {
        $retryAfter = self::availableIn($key);

        return new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => self::MAX_ATTEMPTS,
            'X-RateLimit-Remaining' => 0,
        ]);
    }

    /** "45 seconds", "a minute", "12 minutes". */
    public static function humanWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : $seconds.' seconds';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? 'a minute' : $minutes.' minutes';
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
