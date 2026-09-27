<?php

namespace App\Support;

use App\Models\School;
use App\Models\SchoolRememberToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * "Remember me" for school admins, layered on SchoolSession (H6).
 *
 * Ticking "Remember me" at login stores `selector:verifier` in its own cookie
 * (config auth.school_remember.cookie), separate from the session cookie. Both
 * halves are random: the selector finds the row in school_remember_tokens, and
 * the verifier is checked against the SHA-256 hash stored there, in constant
 * time. The cookie carries no school id and nothing derived from the password.
 *
 * When a request reaches an admin page with no signed-in session,
 * SchoolSession::resolve() calls restore(). A token that is known, unrevoked,
 * unexpired, matches its verifier, belongs to an existing school and was issued
 * under that school's CURRENT password fingerprint signs the browser in exactly
 * like a login: SchoolSession::login() gives it a fresh session id and the usual
 * school_admin_id + school_admin_fp context. The token is then rotated — used
 * once, replaced by a new one with the same expiry — so a copied cookie is only
 * good until the real browser next uses it. Any failed check clears the cookie.
 *
 * Revocation mirrors the session rules: logout revokes this browser's token only;
 * a password change or reset revokes every token for the school (and the stored
 * fingerprint would refuse them anyway). Tokens expire after a fixed lifetime from
 * the original login; rotation does not extend it.
 */
final class SchoolRemember
{
    /** Cookie value: 32 hex selector, a colon, 64 hex verifier. */
    private const FORMAT = '/^([0-9a-f]{32}):([0-9a-f]{64})$/';

    public static function cookieName(): string
    {
        return (string) config('auth.school_remember.cookie', 'school_remember');
    }

    /** Remember this browser for $school (login with "Remember me" ticked). */
    public static function issue(Request $request, School $school): void
    {
        // One credential per browser: a cookie this browser already holds is
        // replaced, not kept alongside the new one.
        self::revokeBrowserToken($request);

        // Rows that can never be used again are not worth keeping.
        SchoolRememberToken::where('school_id', $school->id)
            ->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now()))
            ->delete();

        $days = max(1, (int) config('auth.school_remember.lifetime_days', 30));
        self::issueToken($school, now()->addDays($days));
    }

    /**
     * Sign the browser in from its remember cookie, or return null. Only called
     * when the session is not already signed in.
     */
    public static function restore(Request $request): ?School
    {
        if (! $request->cookies->has(self::cookieName())) {
            return null;
        }

        // Null when the cookie could not be decrypted (EncryptCookies), so a
        // tampered value fails the format check like any other malformed one.
        $parsed = self::parse($request->cookie(self::cookieName()));
        $token = $parsed ? SchoolRememberToken::where('selector', $parsed[0])->first() : null;

        if (! $token) {
            return self::reject($request, null, 'unknown or malformed');
        }
        if (! hash_equals($token->token_hash, self::hash($parsed[1]))) {
            return self::reject($request, $token, 'verifier mismatch');
        }
        if ($token->revoked_at !== null) {
            return self::reject($request, null, 'revoked');
        }
        if ($token->expires_at->isPast()) {
            return self::reject($request, $token, 'expired');
        }

        $school = $token->school;
        if (! $school) {
            return self::reject($request, $token, 'school missing');
        }
        if (! hash_equals($token->password_fingerprint, SchoolSession::fingerprint($school))) {
            return self::reject($request, $token, 'password changed');
        }

        // Claim the token atomically: of two requests racing with the same
        // cookie, exactly one restores the session and rotates it.
        $claimed = SchoolRememberToken::whereKey($token->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'last_used_at' => now()]);
        if ($claimed !== 1) {
            return self::reject($request, null, 'already used');
        }

        SchoolSession::login($request, $school);
        self::issueToken($school, $token->expires_at);

        Log::info('School admin session restored from remember token', ['school_id' => $school->id]);

        return $school;
    }

    /** Logout, or login without "Remember me": this browser's credential only. */
    public static function forgetBrowser(Request $request): void
    {
        if (! $request->cookies->has(self::cookieName())) {
            return;
        }

        self::revokeBrowserToken($request);
        self::clearCookie();
    }

    /** Password change or reset: every remembered browser of the school. */
    public static function revokeAllFor(School $school): void
    {
        SchoolRememberToken::where('school_id', $school->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private static function issueToken(School $school, \DateTimeInterface $expiresAt): void
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        SchoolRememberToken::create([
            'school_id' => $school->id,
            'selector' => $selector,
            'token_hash' => self::hash($verifier),
            'password_fingerprint' => SchoolSession::fingerprint($school),
            'expires_at' => $expiresAt,
        ]);

        // Same path, domain, Secure and SameSite as the session cookie; always HttpOnly.
        Cookie::queue(new SymfonyCookie(
            self::cookieName(),
            $selector.':'.$verifier,
            $expiresAt,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            config('session.same_site', 'lax'),
        ));
    }

    /** Revoke the row this browser's cookie names, if its verifier checks out. */
    private static function revokeBrowserToken(Request $request): void
    {
        $parsed = self::parse($request->cookie(self::cookieName()));
        if (! $parsed) {
            return;
        }

        $token = SchoolRememberToken::where('selector', $parsed[0])->whereNull('revoked_at')->first();
        if ($token && hash_equals($token->token_hash, self::hash($parsed[1]))) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
    }

    /** Refuse the cookie: clear it and, when the row is known, revoke that row too. */
    private static function reject(Request $request, ?SchoolRememberToken $token, string $reason): null
    {
        if ($token && $token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
        }
        self::clearCookie();

        Log::info('School remember token refused: '.$reason, ['school_id' => $token?->school_id]);

        return null;
    }

    private static function clearCookie(): void
    {
        Cookie::queue(Cookie::forget(self::cookieName(), config('session.path', '/'), config('session.domain')));
    }

    /** @return array{0: string, 1: string}|null [selector, verifier] */
    private static function parse(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match(self::FORMAT, $value, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    private static function hash(string $verifier): string
    {
        return hash('sha256', $verifier);
    }
}
