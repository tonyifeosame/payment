<?php

namespace App\Support;

/**
 * The application's own origin, from configuration only (H1).
 *
 * Links that leave the request — above all the password-reset link, which
 * carries a live token — must never be built from the incoming Host or
 * X-Forwarded-Host header: a caller who controls either would receive the
 * token on their own domain. APP_URL is the one trusted source.
 *
 * In production the origin is always https, matching ForceHttps and the
 * secure session cookie, even if APP_URL was entered as http://.
 */
final class AppUrl
{
    /** Scheme and host (and port, if any) from APP_URL, without a trailing slash. */
    public static function root(): string
    {
        $root = rtrim((string) config('app.url'), '/');

        if (app()->environment('production') && str_starts_with($root, 'http://')) {
            $root = 'https://'.substr($root, strlen('http://'));
        }

        return $root;
    }

    /** An absolute URL on the trusted origin for a root-relative path ("/admin/..."). */
    public static function to(string $path): string
    {
        return self::root().'/'.ltrim($path, '/');
    }
}
