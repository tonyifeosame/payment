<?php

namespace App\Support;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The school-admin session, in one place (H6).
 *
 * A signed-in school is identified by two session values:
 *
 *   school_admin_id  which school is acting;
 *   school_admin_fp  a fingerprint (SHA-256) of that school's password hash at the
 *                    moment the session was authenticated.
 *
 * The fingerprint is what makes password changes and resets revoke every OTHER
 * session for the school: on each request the stored fingerprint is compared
 * with the school's current password hash, so a session created before the
 * password changed no longer authenticates. This is the same mechanism as
 * Laravel's AuthenticateSession middleware (`password_hash_{guard}`), applied to
 * this application's session-keyed tenancy — no custom session store, no scan of
 * other sessions' payloads. The session that performed the change is refreshed
 * with the new fingerprint, so the admin stays signed in.
 *
 * Login regenerates the session id (fixation protection); logout invalidates the
 * whole session and rotates the CSRF token. Neither the password hash nor any
 * secret is ever stored in the session — only the fingerprint.
 */
final class SchoolSession
{
    public const ID = 'school_admin_id';

    public const FINGERPRINT = 'school_admin_fp';

    /** Fingerprint of the school's current password hash. Never the hash itself. */
    public static function fingerprint(School $school): string
    {
        return hash('sha256', (string) $school->admin_password);
    }

    /** The session values a freshly authenticated admin session carries. */
    public static function payloadFor(School $school): array
    {
        return [self::ID => $school->id, self::FINGERPRINT => self::fingerprint($school)];
    }

    /**
     * Successful login / registration: a new session id (the old one is destroyed,
     * as Laravel's own SessionGuard does), then the school context.
     */
    public static function login(Request $request, School $school): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::payloadFor($school));
    }

    /** The current session changed the password: keep it signed in under the new hash. */
    public static function refresh(Request $request, School $school): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::payloadFor($school));
    }

    /** Logout: the session is gone, and so is its CSRF token. */
    public static function logout(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /** Drop the school context without ending the session (a stale or invalid one). */
    public static function forget(Request $request): void
    {
        $request->session()->forget([self::ID, self::FINGERPRINT]);
    }

    /**
     * The school this session is authenticated as, or null.
     *
     * Null — and the context is cleared — when there is no school id, the school no
     * longer exists, the session predates the fingerprint, or the school's password
     * has changed since the session authenticated. Callers that redirect may read
     * the flashed `error` for the reason to show.
     */
    public static function school(Request $request): ?School
    {
        $id = $request->session()->get(self::ID);
        if (! $id) {
            return null;
        }

        $school = School::find($id);
        if (! $school) {
            self::forget($request);

            return null;
        }

        $expected = self::fingerprint($school);
        $stored = (string) $request->session()->get(self::FINGERPRINT, '');

        if ($stored === '' || ! hash_equals($expected, $stored)) {
            self::forget($request);
            $request->session()->flash('error', 'Your password was changed. Please log in again.');
            Log::info('School admin session revoked: password changed since it authenticated', ['school_id' => $school->id]);

            return null;
        }

        return $school;
    }
}
