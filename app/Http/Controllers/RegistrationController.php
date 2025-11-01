<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\School;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\SchoolLinksMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

class RegistrationController extends Controller
{
    public function create()
    {
        return view('registration.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|max:255',
            'account_number'  => 'required|string|min:10|max:12',
            'bank'            => 'required|string|max:100',
            'bank_code'       => 'required|string',
            'account_name'    => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
            'admin_password'  => 'required|string|min:8|confirmed',
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
        $resolve = Http::withToken(config('services.paystack.secret_key'))
            ->get(config('services.paystack.payment_url').'/bank/resolve', [
                'account_number' => $data['account_number'],
                'bank_code' => $data['bank_code'],
            ])->json();

        if (!($resolve['status'] ?? false)) {
            return back()->withInput()->withErrors(['account_number' => $resolve['message'] ?? 'Unable to verify account details']);
        }

        $data['account_name'] = $resolve['data']['account_name'] ?? null;

        // Hash password before save
        $data['admin_password'] = Hash::make($data['admin_password']);

        $school = School::create($data);

        // Automatically log in the new school admin
        session(['school_admin_id' => $school->id]);

        // Build tenant-aware links and email them to the school admin
        $links = [
            'payment'       => route('school.payment.index', ['school' => $school->slug]),
            'categories'    => route('school.categories.index', ['school' => $school->slug]),
            'subcategories' => route('school.subcategories.index', ['school' => $school->slug]),
            'transactions'  => route('school.transactions.index', ['school' => $school->slug]),
        ];

        if (!empty($school->email)) {
            try {
                Mail::to($school->email)->send(new SchoolLinksMail($school, $links));
            } catch (\Throwable $e) {
                // Log and surface a friendly message
                report($e);
                session()->flash('error', 'Email delivery failed: '.$e->getMessage());
            }
        }

        return redirect()
            ->route('school.categories.index', ['school' => $school->slug])
            ->with('success', 'School registered and you are now logged in. Get started by adding your first category.');
    }
}
