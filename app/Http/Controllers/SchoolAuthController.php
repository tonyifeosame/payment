<?php

namespace App\Http\Controllers;

use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use App\Support\CredentialThrottle;
use App\Support\SchoolSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class SchoolAuthController extends Controller
{
    public function showLogin()
    {
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
        ]);

        // L7: failed logins only, per typed school name + client IP (5 per 60
        // minutes). A successful login never counts and clears the counter, and
        // other schools on the same connection have their own. Checked before the
        // password, so a locked-out attempt is refused whether it is right or wrong.
        $throttleKey = CredentialThrottle::loginKey($credentials['name'], $request->ip());
        if (CredentialThrottle::tooManyAttempts($throttleKey)) {
            return redirect()->route('admin.login')
                ->withInput($request->only('name'))
                ->with('error', 'Too many failed sign-in attempts. Please wait '
                    .CredentialThrottle::humanWait(CredentialThrottle::availableIn($throttleKey)).' and try again.');
        }

        // The one school with this name, case-insensitively. Two matches (legacy
        // duplicates) is treated as no match: never sign in to "the first one".
        $school = School::findUniqueByName($credentials['name']);
        if (! $school || ! $school->admin_password || ! Hash::check($credentials['password'], $school->admin_password)) {
            CredentialThrottle::hit($throttleKey);

            return back()->withInput()->with('error', 'Invalid school name or password.');
        }

        CredentialThrottle::clear($throttleKey);

        // Fresh session id (fixation protection), then the school context.
        SchoolSession::login($request, $school);

        return redirect()->route('school.dashboard', ['school' => $school])->with('success', 'Logged in successfully.');
    }

    public function logout(Request $request)
    {
        SchoolSession::logout($request);

        return redirect()->route('admin.login')->with('success', 'Logged out.');
    }

    /**
     * Where the installed "FEYRA Admin" app opens: the signed-in school's dashboard,
     * otherwise the login page. Uses the same session key as EnsureSchoolAdmin and
     * the same deleted-school fallback; nothing about authentication changes.
     */
    public function app(Request $request)
    {
        $school = SchoolSession::school($request);

        if (! $school) {
            return redirect()->route('admin.login');
        }

        return redirect()->route('school.dashboard', ['school' => $school]);
    }

    /**
     * The admin web app manifest. Served by a route (not a static file) so the
     * content type is right on every web server and the URLs follow the app URL.
     * Scope is strictly /admin/ — login, the /admin entry point and every
     * /admin/{school}/… page — so no public page (/, /pay/…, receipts) can ever be
     * inside the installed app.
     */
    public function manifest()
    {
        return response()->json([
            'id' => '/admin',
            'name' => 'FEYRA Admin',
            'short_name' => 'FEYRA Admin',
            'description' => "Manage your school's payments, students, fees and payouts.",
            'lang' => 'en',
            'dir' => 'ltr',
            // start_url must itself be inside the scope (a plain path-prefix match), or
            // Chrome drops the scope and falls back to "/" — which would let public pages
            // into the installed app. /admin/ resolves to the same entry point as /admin.
            'start_url' => '/admin/',
            'scope' => '/admin/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#F7F7F8',
            'theme_color' => '#FFFFFF',
            'icons' => [
                ['src' => '/icons/admin-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/admin-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/admin-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_SLASHES);
    }

    public function showLinkRequestForm()
    {
        return view('admin.password.request');
    }

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // L7: 5 requests per 60 minutes per email address (normalised, hashed),
        // whether or not a school has it, and from any IP — so at most five
        // emails an hour reach any inbox, without depending on the client IP.
        // Every request counts: the reply is neutral, so there is no failure to
        // tell apart. A refused request is a 429, as before; it reveals nothing
        // because the counter exists for every address.
        $throttleKey = CredentialThrottle::resetRequestKey($request->email);
        if (CredentialThrottle::tooManyAttempts($throttleKey)) {
            throw CredentialThrottle::exception($throttleKey);
        }
        CredentialThrottle::hit($throttleKey);

        // Exactly one school with this email (case-insensitive); an ambiguous email
        // gets the same neutral message and no link.
        $school = School::findUniqueByEmail($request->email);

        // Password::createToken() deletes the school's existing token before it
        // inserts the new one, so every request here invalidates the link the
        // previous one emailed. Unguarded, a caller who knows a school's address
        // can keep an admin from ever completing a reset. The broker's own
        // throttle window (config/auth.php: passwords.users.throttle) is the
        // check PasswordBroker::sendResetLink() applies for exactly this reason;
        // createToken() bypasses it, so it is applied here instead. Inside the
        // window the live token and the link already in the admin's inbox stand.
        if ($school && ! Password::broker()->getRepository()->recentlyCreatedToken($school)) {
            $token = Password::createToken($school);
            $resetLink = url("/admin/reset-password/{$token}?email=".urlencode($request->email));

            try {
                Mail::to($request->email)->send(new SchoolPasswordResetMail($school, $resetLink));
            } catch (\Throwable $e) {
                // A mail outage must not become a 500 on the recovery flow, nor an
                // oracle: the caller gets the same neutral answer either way.
                report($e);
            }
        }

        return back()->with('status', 'If your email is in our system, you will receive a password reset link.');
    }

    public function showResetForm(Request $request, $token)
    {
        return view('admin.password.reset', ['token' => $token, 'email' => $request->email]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        $school = School::findUniqueByEmail($request->email);

        // One message for "no such school" and for "bad token". Two distinct ones
        // made this endpoint an unauthenticated oracle for which addresses are
        // registered schools — answerable with any junk token.
        $invalid = fn () => back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);

        if (! $school) {
            return $invalid();
        }

        if (! Password::broker()->tokenExists($school, $request->token)) {
            return $invalid();
        }

        $school->admin_password = Hash::make($request->password);
        $school->save();

        Password::broker()->deleteToken($school);

        // Every session authenticated under the old password — including one in
        // this browser — now fails the fingerprint check; start this one clean.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'Your password has been reset successfully.');
    }
}
