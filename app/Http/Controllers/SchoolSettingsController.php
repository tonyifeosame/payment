<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\SchoolLogo;
use App\Services\SchoolBankDetailsService;
use App\Support\SchoolSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * School profile, branding and payout account. Always the bound (and therefore
 * authenticated) school — there is no id in the form and none is accepted.
 */
class SchoolSettingsController extends Controller
{
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
                    if (School::whereSameIgnoringCase('name', $value, $school->id)->exists()) {
                        $fail('Another school is already registered with this name.');
                    }
                },
            ],
            // Email is the password-reset identifier: unique case-insensitively (H4).
            'email' => [
                'required', 'email', 'max:255',
                function ($attribute, $value, $fail) use ($school) {
                    if (School::whereSameIgnoringCase('email', $value, $school->id)->exists()) {
                        $fail('Another school is already registered with this email address.');
                    }
                },
            ],
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

        // The logo lives in school_logos (H3), one row per school, so it is the
        // same on every container and survives deploys. A change also bumps the
        // school's updated_at: that is what rotates the cache-busting `v` in
        // logoUrl(), which the row itself would not do since none of its own
        // columns change.
        $logoChanged = false;

        if ($request->boolean('remove_logo') && $school->hasLogo()) {
            $school->logo()->delete();
            $logoChanged = true;
        }

        if ($request->hasFile('logo')) {
            $school->logo()->updateOrCreate([], SchoolLogo::attributesFor($request->file('logo')));
            $logoChanged = true;
        }

        if ($logoChanged) {
            $school->forgetLogoState();
            $school->updated_at = $school->freshTimestamp();
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
     * Change the admin password from inside a logged-in session.
     *
     * The current password is re-checked (a hijacked session alone is not enough),
     * the new one follows the same rules as registration and the reset flow
     * (min 8, confirmed) and is stored with Hash::make. Errors go to their own bag
     * and NO input is flashed, so no password ever round-trips through the session
     * or back into the page. The session is kept: this is a change, not a reset.
     */
    public function updatePassword(Request $request, School $school)
    {
        $validator = Validator::make($request->only(['current_password', 'password', 'password_confirmation']), [
            'current_password' => [
                'required', 'string',
                function ($attribute, $value, $fail) use ($school) {
                    if (! $school->admin_password || ! Hash::check((string) $value, $school->admin_password)) {
                        $fail('The password you entered is incorrect.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'The new password and its confirmation do not match.',
        ]);

        if ($validator->fails()) {
            return redirect()->route('school.settings.edit', ['school' => $school->slug])
                ->withErrors($validator, 'password')
                ->withFragment('security');
        }

        $school->forceFill(['admin_password' => Hash::make($validator->validated()['password'])])->save();

        // This session stays signed in under the new password (new session id, new
        // fingerprint); every other session for this school is revoked on its next
        // request by the fingerprint check in EnsureSchoolAdmin (H6).
        SchoolSession::refresh($request, $school);

        return redirect()->route('school.settings.edit', ['school' => $school->slug])
            ->with('success', 'Your password has been changed.')
            ->withFragment('security');
    }

    /**
     * Stream the school's logo. Public: it appears on the parent-facing payment page
     * and the receipt. Only the logo row is ever read — it is keyed by the bound
     * school, never by anything from the request.
     *
     * Conditional requests: the ETag is a hash of the stored bytes and
     * Last-Modified the row's timestamp, so a browser (or mail client) that
     * already holds the image gets a 304 without the body being re-sent.
     */
    public function logo(Request $request, School $school)
    {
        $logo = $school->logo;

        if ($logo === null) {
            abort(404);
        }

        $response = response(null, 200, [
            'Content-Type' => $logo->mime,
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $logo->etag(),
            'Last-Modified' => $logo->updated_at->toRfc7231String(),
        ]);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($logo->bytes());
    }
}
