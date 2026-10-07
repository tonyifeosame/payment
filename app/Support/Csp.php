<?php

namespace App\Support;

/**
 * The per-request Content-Security-Policy nonce (M4).
 *
 * Held on the current request's attributes — not in a static or a shared view
 * variable — so under Octane, where one worker serves many requests, a nonce can
 * never leak from one response into the next. Views print it with @nonce on
 * every inline <script>; SecurityHeaders puts the same value in the header.
 */
final class Csp
{
    private const ATTRIBUTE = 'csp_nonce';

    public static function nonce(): string
    {
        $request = request();

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (! is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(18));
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }
}
