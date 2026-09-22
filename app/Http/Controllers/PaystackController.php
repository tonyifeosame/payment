<?php

namespace App\Http\Controllers;

use App\Services\PaystackService;
use App\Support\SchoolSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Browser-facing helpers behind the registration and bank-change forms.
 *
 * These only ever *look things up*; what the browser shows is a convenience.
 * The name that gets stored is resolved again server-side by whichever action
 * saves bank details (RegistrationController, SchoolBankDetailsService).
 *
 * The bank directory is public: it is the list of banks Paystack can pay out to
 * and there is nothing in it to enumerate. Account resolution is public too —
 * registration has no session to authenticate — but it discloses the resolved
 * account-holder name only to a signed-in admin; see resolveAccount().
 */
class PaystackController extends Controller
{
    public function __construct(private PaystackService $paystack) {}

    public function banks(Request $request): JsonResponse
    {
        $country = (string) $request->query('country', 'nigeria');

        $result = $this->paystack->listBanks($country);

        if (! $result['ok']) {
            Log::error('Bank list lookup failed', ['reason' => $result['reason'], 'message' => $result['message']]);

            return $this->failure($result);
        }

        return response()->json(['ok' => true, 'banks' => $result['banks']]);
    }

    public function resolveAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_number' => 'required|string|min:10|max:12',
            'bank_code' => 'required|string',
        ]);

        $result = $this->paystack->resolveAccount($validated['account_number'], $validated['bank_code']);

        if (! $result['ok']) {
            // A rejected account is the expected outcome for a typo, not an error.
            if ($result['reason'] !== 'rejected') {
                Log::error('Account resolution failed', ['reason' => $result['reason'], 'message' => $result['message']]);
            }

            return $this->failure($result);
        }

        // M2: the resolved NAME is disclosed only to a signed-in school admin.
        //
        // Unauthenticated, this endpoint was a public
        // (account_number, bank_code) -> account-holder name oracle: one known
        // number against the ~25 Nigerian bank codes reveals which bank holds it
        // and whose name is on it, comfortably inside one IP's hourly throttle
        // budget. Registration cannot be authenticated — the school does not
        // exist yet — so the access could not be restricted; the disclosure
        // could. An anonymous caller now learns only that the number resolves,
        // which is what the registration form actually needs (the registrant
        // knows their own account name).
        //
        // Nothing about integrity changes: both writers re-resolve server-side
        // and store Paystack's answer, never the browser's
        // (RegistrationController, SchoolBankDetailsService).
        if (! $this->isSignedInAdmin($request)) {
            return response()->json(['ok' => true, 'verified' => true]);
        }

        return response()->json([
            'ok' => true,
            'account_name' => $result['account_name'],
            'account_number' => $result['account_number'],
        ]);
    }

    /**
     * Is this request from a school admin whose session is still valid?
     *
     * SchoolSession::school() — not the raw session id — so a session revoked by
     * a password change or reset (H6), or one whose school has been deleted, is
     * treated as anonymous and gets the same undisclosed answer.
     */
    private function isSignedInAdmin(Request $request): bool
    {
        return $request->hasSession() && SchoolSession::school($request) !== null;
    }

    /**
     * 'config'      -> 500: our side is misconfigured (no key / key refused).
     * 'rejected'    -> 422: Paystack answered definitively; pass its message on.
     * 'unavailable' -> 503: Paystack unreachable or erroring; worth retrying later.
     */
    private function failure(array $result): JsonResponse
    {
        $status = match ($result['reason']) {
            'config' => 500,
            'rejected' => 422,
            default => 503,
        };

        return response()->json(['ok' => false, 'error' => $result['message']], $status);
    }
}
