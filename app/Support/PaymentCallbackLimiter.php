<?php

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * The `payment-callback` limiter behind GET /payment/callback (L3).
 *
 * Paystack returns each payer here once, and a payer may refresh. But the route
 * is public and every hit on a still-pending reference makes a synchronous
 * verify call on the integration's secret key and holds a PHP worker for it, so
 * one client replaying its own reference could burn Paystack's limits and tie up
 * the (single) web worker. Twenty a minute and 120 an hour per address is far
 * above real use. Settlement is idempotent either way; this only bounds the cost.
 */
final class PaymentCallbackLimiter
{
    public const NAME = 'payment-callback';

    public const PER_MINUTE = 20;

    public const PER_HOUR = 120;

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
