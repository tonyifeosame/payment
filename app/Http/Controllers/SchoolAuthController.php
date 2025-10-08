<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\School;

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
        return redirect()->route('school.categories.index', ['school' => $school->slug])
                         ->with('success', 'Logged in successfully.');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('school_admin_id');
        return redirect()->route('admin.login')->with('success', 'Logged out.');
    }
}
