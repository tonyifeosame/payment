<?php

namespace App\Http\Controllers;

use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
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

        // The one school with this name, case-insensitively. Two matches (legacy
        // duplicates) is treated as no match: never sign in to "the first one".
        $school = School::findUniqueByName($credentials['name']);
        if (! $school || ! $school->admin_password || ! Hash::check($credentials['password'], $school->admin_password)) {
            return back()->withInput()->with('error', 'Invalid school name or password.');
        }

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

        // Exactly one school with this email (case-insensitive); an ambiguous email
        // gets the same neutral message and no link.
        $school = School::findUniqueByEmail($request->email);

        if ($school) {
            $token = Password::createToken($school);
            $resetLink = url("/admin/reset-password/{$token}?email=".urlencode($request->email));

            Mail::to($request->email)->send(new SchoolPasswordResetMail($school, $resetLink));
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

        if (! $school) {
            return back()->withErrors(['email' => 'The provided email does not match our records.']);
        }

        $response = Password::broker()->tokenExists($school, $request->token);

        if (! $response) {
            return back()->withErrors(['email' => 'The password reset token is invalid.']);
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
