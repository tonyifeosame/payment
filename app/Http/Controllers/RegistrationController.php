<?php

namespace App\Http\Controllers;

use App\Mail\SchoolLinksMail;
use App\Models\School;
use App\Services\PaystackService;
use App\Support\SchoolSession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; // This seems unused, but I'll leave it.
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RegistrationController extends Controller
{
    public function create()
    {
        return view('registration.create');
    }

    public function store(Request $request, PaystackService $paystack)
    {
        $data = $request->validate([
            // Name is the login identifier and email the password-reset identifier:
            // both unique case-insensitively (H4). The database enforces the same
            // rule (unique indexes on LOWER(name) / LOWER(email)); the catch below
            // turns a lost race into the same validation message.
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    if (School::whereSameIgnoringCase('name', $value)->exists()) {
                        $fail('A school with this name is already registered. Choose a distinct name — it is your login name.');
                    }
                },
            ],
            'email' => [
                'required', 'email', 'max:255',
                function ($attribute, $value, $fail) {
                    if (School::whereSameIgnoringCase('email', $value)->exists()) {
                        $fail('A school is already registered with this email address.');
                    }
                },
            ],
            'account_number' => 'required|string|min:10|max:12',
            'bank' => 'required|string|max:100',
            'bank_code' => 'required|string',
            'account_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'admin_password' => 'required|string|min:8|confirmed',
        ]);

        // Generate unique slug from name
        $base = Str::slug($data['name']);
        $slug = $base;
        $i = 1;
        while (School::where('slug', $slug)->exists()) {
            $slug = $base.'-'.($i++);
        }
        $data['slug'] = $slug;

        // Resolve account name server-side to ensure integrity
        $resolve = $paystack->resolveAccount($data['account_number'], $data['bank_code']);
        if (! $resolve['ok']) {
            return back()->withInput()->withErrors(['account_number' => $resolve['message'] ?? 'Unable to verify account details.']);
        }

        $data['account_name'] = $resolve['account_name'] ?? null;

        // Hash password before save
        $data['admin_password'] = Hash::make($data['admin_password']);

        try {
            $school = School::create($data);
        } catch (UniqueConstraintViolationException) {
            // Two registrations raced past validation; the database kept one.
            return back()->withInput($request->except(['admin_password', 'admin_password_confirmation']))
                ->withErrors(['name' => 'A school with this name or email address was registered a moment ago. Choose a distinct name and email.']);
        }

        // Automatically log in the new school admin (fresh session id).
        SchoolSession::login($request, $school);

        // Build tenant-aware links and email them to the school admin
        $links = [
            'dashboard' => route('school.dashboard', ['school' => $school->slug]),
            'payment' => route('public.payment', ['school' => $school->slug]),
            'categories' => route('school.categories.index', ['school' => $school->slug]),
            'subcategories' => route('school.subcategories.index', ['school' => $school->slug]),
            'transactions' => route('school.transactions.index', ['school' => $school->slug]),
        ];

        if (! empty($school->email)) {
            try {
                Mail::to($school->email)->send(new SchoolLinksMail($school, $links));
            } catch (\Throwable $e) {
                // L4: the exception is reported, never shown. It used to be flashed
                // verbatim, putting SMTP host, port and provider errors on an
                // unauthenticated registrant's screen. The school is registered and
                // signed in either way, so this is a notice, not a failure — and the
                // links are on the dashboard they are about to land on. Same
                // handling as the password-reset send (SchoolAuthController).
                report($e);
                session()->flash('error', 'Your account is ready, but we could not email your links just now. You can find them on your dashboard.');
            }
        }

        return redirect()
            ->route('school.dashboard', ['school' => $school->slug])
            ->with('success', 'School registered and you are now logged in. Start by creating your academic session, then your fees and students.');
    }
}
