<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\School;

use Illuminate\Support\Facades\Password;
use App\Mail\SchoolLinksMail;
use Illuminate\Support\Facades\Mail;

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

        // Find school by name (case-insensitive)
        $school = School::whereRaw('LOWER(name) = LOWER(?)', [$credentials['name']])->first();
        if (!$school || !$school->admin_password || !Hash::check($credentials['password'], $school->admin_password)) {
            return back()->withInput()->with('error', 'Invalid school name or password.');
        }

        session(['school_admin_id' => $school->id]);
        return redirect()->route('school.categories.index', ['school' => $school])->with('success', 'Logged in successfully.');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('school_admin_id');
        return redirect()->route('admin.login')->with('success', 'Logged out.');
    }

    public function showLinkRequestForm()
    {
        return view('admin.password.request');
    }

    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $school = School::where('email', $request->email)->first();

        if ($school) {
            $token = Password::createToken($school);
            $resetLink = url("/admin/reset-password/{$token}?email=" . urlencode($request->email));

            Mail::to($request->email)->send(new SchoolLinksMail($school, $resetLink));
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

        $school = School::where('email', $request->email)->first();

        if (!$school) {
            return back()->withErrors(['email' => 'The provided email does not match our records.']);
        }

        $response = Password::broker()->tokenExists($school, $request->token);

        if (!$response) {
            return back()->withErrors(['email' => 'The password reset token is invalid.']);
        }

        $school->admin_password = Hash::make($request->password);
        $school->save();

        Password::broker()->deleteToken($school);

        return redirect()->route('admin.login')->with('status', 'Your password has been reset successfully.');
    }
}
