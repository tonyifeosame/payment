<?php

namespace App\Http\Controllers;

use App\Mail\SchoolIdentityChangedMail;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolLogo;
use App\Services\SchoolBankDetailsService;
use App\Support\CredentialThrottle;
use App\Support\RecordsSchoolAudit;
use App\Support\SchoolLogoImage;
use App\Support\SchoolRemember;
use App\Support\SchoolSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

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

    public function update(Request $request, School $school, RecordsSchoolAudit $audit)
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
            // Content-based type and size first; then, from the header alone and
            // before any pixel is decoded, the dimension limit and animated WebP.
            'logo' => [
                'bail', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
                function ($attribute, $value, $fail) {
                    if (($problem = SchoolLogoImage::problem((string) $value->get())) !== null) {
                        $fail($problem);
                    }
                },
            ],
            'remove_logo' => ['nullable', 'boolean'],
            'identity_password' => ['nullable', 'string'],
        ]);

        // M1: the name is the login identifier and the email the password-reset
        // identifier, so changing either is a takeover step: a stolen session could
        // point resets at its own inbox and lock the school out. Either change needs
        // the current password, counted against the same per-school limit as the
        // password form. Phone, address, footer and logo do not.
        $identityBefore = ['name' => $school->name, 'email' => $school->email];
        // Compared the way login and reset look schools up (trimmed, case-insensitive):
        // a change of letter case alone does not change who can sign in or reset.
        $same = fn (?string $a, ?string $b) => mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
        $changesIdentity = ! $same($data['name'], $school->name) || ! $same($data['email'], $school->email);

        if ($changesIdentity) {
            $throttleKey = CredentialThrottle::passwordChangeKey($school);
            if (CredentialThrottle::tooManyAttempts($throttleKey)) {
                throw CredentialThrottle::exception($throttleKey);
            }

            $given = (string) ($data['identity_password'] ?? '');
            if ($given === '' || ! $school->admin_password || ! Hash::check($given, $school->admin_password)) {
                if ($given !== '') {
                    CredentialThrottle::hit($throttleKey);
                }

                throw ValidationException::withMessages([
                    'identity_password' => $given === ''
                        ? 'Enter your current password to change the school name or email.'
                        : 'The password you entered is incorrect.',
                ]);
            }

            CredentialThrottle::clear($throttleKey);
        }

        // The logo is normalised (SchoolLogoImage) before anything is written, so
        // an image that cannot be processed fails validation instead of leaving a
        // half-applied update.
        $logoAttributes = null;
        if ($request->hasFile('logo')) {
            try {
                $logoAttributes = SchoolLogo::attributesFor($request->file('logo'));
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['logo' => $e->getMessage()]);
            }
        }

        // M7 audits the identity fields only (captured above): the name is the login
        // identifier and the email is the password-reset identifier, so a change to
        // either moves how this school is reached. Phone, address and receipt
        // footer are presentation and are deliberately not recorded.
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

        if ($logoAttributes !== null) {
            $school->logo()->updateOrCreate([], $logoAttributes);
            $logoChanged = true;
        }

        if ($logoChanged) {
            $school->forgetLogoState();
            $school->updated_at = $school->freshTimestamp();
        }

        // Only the save and its audit row are transactional (M7). The logo writes
        // above keep exactly the persistence behaviour they had — making them
        // atomic with the profile would be a change to logo handling, which this
        // finding has no business making.
        $identityChanges = $audit->diff($identityBefore, ['name' => $school->name, 'email' => $school->email], ['name', 'email']);

        DB::transaction(function () use ($school, $identityChanges, $audit, $request) {
            $school->save();

            if ($identityChanges !== []) {
                $audit->record($school, SchoolAuditEvent::ACTION_PROFILE_CHANGED, 'school', $school->id, $identityChanges, request: $request);
            }
        });

        if ($changesIdentity) {
            // M1: a reset link already sent to the old address must not outlive the
            // change; tokens are keyed by email, so remove the old address's row.
            if (! $same($identityBefore['email'], $school->email)) {
                DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                    ->where('email', $identityBefore['email'])
                    ->delete();
            }

            // ...and the address that WAS on file hears about it, so an unwanted
            // change is noticed even when the new address belongs to someone else.
            if (! empty($identityBefore['email'])) {
                try {
                    Mail::to($identityBefore['email'])->send(new SchoolIdentityChangedMail($school, $identityBefore));
                } catch (\Throwable $e) {
                    report($e); // the change is saved and audited either way
                }
            }
        }

        return redirect()->route('school.settings.edit', ['school' => $school->slug])
            ->with('success', 'School settings saved.');
    }

    public function updateBank(Request $request, School $school, SchoolBankDetailsService $bankDetails)
    {
        // L7: wrong current passwords only, per signed-in school (5 per 60
        // minutes). Validation errors and a failed Paystack account lookup do not
        // count; anonymous requests never get here (EnsureSchoolAdmin), and other
        // schools on the same connection have their own counter.
        $throttleKey = CredentialThrottle::bankChangeKey($school);
        if (CredentialThrottle::tooManyAttempts($throttleKey)) {
            throw CredentialThrottle::exception($throttleKey);
        }

        $data = $request->validate([
            'bank' => ['required', 'string', 'max:100'],
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/'],
            'current_password' => ['required', 'string'],
        ], [
            'account_number.regex' => 'Enter the 10-digit NUBAN account number.',
        ]);

        try {
            $bankDetails->change($school, $data);
        } catch (ValidationException $e) {
            if (array_key_exists('current_password', $e->errors())) {
                CredentialThrottle::hit($throttleKey);
            }

            throw $e;
        }

        CredentialThrottle::clear($throttleKey);

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
    public function updatePassword(Request $request, School $school, RecordsSchoolAudit $audit)
    {
        // L7: same rule as the bank form, its own counter — wrong current
        // passwords only, per signed-in school, cleared on success.
        $throttleKey = CredentialThrottle::passwordChangeKey($school);
        if (CredentialThrottle::tooManyAttempts($throttleKey)) {
            throw CredentialThrottle::exception($throttleKey);
        }

        $validator = Validator::make($request->only(['current_password', 'password', 'password_confirmation']), [
            'current_password' => [
                'required', 'string',
                function ($attribute, $value, $fail) use ($school) {
                    if (! $school->admin_password || ! Hash::check((string) $value, $school->admin_password)) {
                        $fail('The password you entered is incorrect.');
                    }
                },
            ],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()], // M5
        ], [
            'password.confirmed' => 'The new password and its confirmation do not match.',
        ]);

        if ($validator->fails()) {
            if ($validator->errors()->has('current_password')) {
                CredentialThrottle::hit($throttleKey);
            }

            return redirect()->route('school.settings.edit', ['school' => $school->slug])
                ->withErrors($validator, 'password')
                ->withFragment('security');
        }

        // The new hash and its audit row commit together (M7). The event records
        // THAT the password changed and nothing about it — no hash, no old or new
        // value, not even a length. `changes` is null for exactly that reason.
        DB::transaction(function () use ($school, $validator, $audit, $request) {
            $school->forceFill(['admin_password' => Hash::make($validator->validated()['password'])])->save();

            // Every remembered browser of the school, including this one, is revoked
            // with the password it was issued under.
            SchoolRemember::revokeAllFor($school);

            $audit->record($school, SchoolAuditEvent::ACTION_PASSWORD_CHANGED, 'school', $school->id, null, request: $request);
        });

        // This session stays signed in under the new password (new session id, new
        // fingerprint); every other session for this school is revoked on its next
        // request by the fingerprint check in EnsureSchoolAdmin (H6).
        SchoolSession::refresh($request, $school);
        SchoolRemember::forgetBrowser($request);
        CredentialThrottle::clear($throttleKey);

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
            // Served only as the stored image type, never sniffed as anything else.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($logo->bytes());
    }
}
