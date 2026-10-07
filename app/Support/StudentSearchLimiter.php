<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * The `student-search` limiter behind the public student lookup on both payment
 * URLs (L1).
 *
 * A lookup returns a student only for an exact full name + admission number
 * pair, but at the old 60 a minute (86,400 a day) one address could walk an
 * admission-number pattern for a known name and confirm a child's enrolment and
 * class. A parent presses "Find student" a handful of times — this allows ten a
 * minute and sixty an hour per address, one bucket for both URLs. The payment
 * page already shows its own message for a 429.
 */
final class StudentSearchLimiter
{
    public const NAME = 'student-search';

    public const PER_MINUTE = 10;

    public const PER_HOUR = 60;

    /** @return array<int, Limit> */
    public static function limits(Request $request): array
    {
        $ip = (string) $request->ip();

        return [
            Limit::perMinute(self::PER_MINUTE)->by('minute:'.$ip),
            Limit::perHour(self::PER_HOUR)->by('hour:'.$ip),
        ];
    }
}
