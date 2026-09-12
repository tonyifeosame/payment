<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Services\SchoolBankDetailsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * School profile, branding and payout account. Always the bound (and therefore
 * authenticated) school — there is no id in the form and none is accepted.
 */
class SchoolSettingsController extends Controller
{
    /** Where logos live on the private disk. Served back through school.logo. */
    private const LOGO_DIR = 'school-logos';

    public function edit(School $school)
    {
        return view('settings.edit', ['school' => $school]);
    }

    public function update(Request $request, School $school)
    {
        $data = $request->validate([
            // Login is by school name, so a duplicate would make one school
            // unreachable. Case-insensitive to match SchoolAuthController::login.
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($school) {
                    $taken = School::whereRaw('LOWER(name) = LOWER(?)', [$value])
                        ->whereKeyNot($school->id)
                        ->exists();
                    if ($taken) {
                        $fail('Another school is already registered with this name.');
                    }
                },
            ],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $school->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'receipt_footer' => $data['receipt_footer'] ?? null,
        ]);

        if ($request->boolean('remove_logo') && $school->logo_path) {
            Storage::disk('local')->delete($school->logo_path);
            $school->logo_path = null;
        }

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            // One file per school, named by id rather than by the uploaded name.
            $path = self::LOGO_DIR.'/'.$school->id.'.'.strtolower($file->extension());
            if ($school->logo_path && $school->logo_path !== $path) {
                Storage::disk('local')->delete($school->logo_path);
            }
            Storage::disk('local')->putFileAs(self::LOGO_DIR, $file, basename($path));
            $school->logo_path = $path;
        }

        $school->save();

        return redirect()->route('school.settings.edit', ['school' => $school->slug])
            ->with('success', 'School settings saved.');
    }

    public function updateBank(Request $request, School $school, SchoolBankDetailsService $bankDetails)
    {
        $data = $request->validate([
            'bank' => ['required', 'string', 'max:100'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
            'current_password' => ['required', 'string'],
        ], [
            'account_number.regex' => 'Enter the 10-digit NUBAN account number.',
        ]);

        $bankDetails->change($school, $data);

        return redirect()->route('school.settings.edit', ['school' => $school->slug])
            ->with('success', 'Payout account updated to '.$school->account_name.'. A confirmation has been emailed to '.$school->email.'.');
    }

    /**
     * Stream the school's logo. Public: it appears on the parent-facing payment page
     * and the receipt. Only the logo file is ever read — the path comes from the
     * school row, never from the request.
     */
    public function logo(School $school)
    {
        if (! $school->hasLogo()) {
            abort(404);
        }

        return Storage::disk('local')->response($school->logo_path, null, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
