<?php

namespace App\Services;

use App\Mail\SchoolBankDetailsChangedMail;
use App\Models\School;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Changing where a school's money goes.
 *
 * This is the most sensitive write in the application, so it is gated the same
 * way registration is, plus two more checks:
 *
 *   1. the admin re-enters the school password (a hijacked session alone is not
 *      enough);
 *   2. the new account is resolved server-side with Paystack and the returned
 *      account name is what we store — never a name typed into the form;
 *   3. the stored Paystack recipient code is cleared, so the next payout has to
 *      create a recipient for the NEW account. Nothing can keep paying the old one;
 *   4. the change is logged and the school's email is notified.
 *
 * Payouts already handed to Paystack (`initiating`/`processing`) are unaffected:
 * they carry the recipient they were sent with.
 */
class SchoolBankDetailsService
{
    public function __construct(private PaystackService $paystack) {}

    /**
     * @param  array{bank:string, bank_code:string, account_number:string, current_password:string}  $input
     */
    public function change(School $school, array $input): School
    {
        if (! $school->admin_password || ! Hash::check($input['current_password'], $school->admin_password)) {
            throw ValidationException::withMessages(['current_password' => 'The password you entered is incorrect.']);
        }

        $resolve = $this->paystack->resolveAccount($input['account_number'], $input['bank_code']);
        if (! ($resolve['ok'] ?? false) || empty($resolve['account_name'])) {
            throw ValidationException::withMessages([
                'account_number' => $resolve['message'] ?? 'Unable to verify account details.',
            ]);
        }

        $previous = [
            'bank' => $school->bank,
            'account_number' => $school->account_number,
            'account_name' => $school->account_name,
        ];

        $school->forceFill([
            'bank' => $input['bank'],
            'bank_code' => $input['bank_code'],
            'account_number' => $resolve['account_number'] ?? $input['account_number'],
            'account_name' => $resolve['account_name'],
            'paystack_recipient_code' => null,
        ])->save();

        Log::warning('School payout bank account changed', [
            'school_id' => $school->id,
            'previous_account_last4' => substr((string) $previous['account_number'], -4),
            'new_account_last4' => substr((string) $school->account_number, -4),
            'new_bank' => $school->bank,
        ]);

        if (! empty($school->email)) {
            try {
                Mail::to($school->email)->send(new SchoolBankDetailsChangedMail($school, $previous));
            } catch (\Throwable $e) {
                report($e); // the change itself must not fail because a notice could not be sent
            }
        }

        return $school;
    }
}
