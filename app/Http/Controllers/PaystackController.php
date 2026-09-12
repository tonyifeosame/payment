<?php

namespace App\Http\Controllers;

use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Browser-facing helpers behind the registration and bank-change forms.
 *
 * These only ever *look things up*; the name the browser shows is a convenience.
 * The name that gets stored is resolved again server-side by whichever action
 * saves bank details (RegistrationController, SchoolBankDetailsService).
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

        return response()->json([
            'ok' => true,
            'account_name' => $result['account_name'],
            'account_number' => $result['account_number'],
        ]);
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
