<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackController extends Controller
{
    private function validatePaystackConfig()
    {
        $secretKey = config('services.paystack.secret_key');
        $paymentUrl = config('services.paystack.payment_url');

        if (empty($secretKey)) {
            return ['error' => 'Paystack secret key is not configured'];
        }

        if (empty($paymentUrl)) {
            return ['error' => 'Paystack payment URL is not configured'];
        }

        return null;
    }

    public function banks(Request $request)
    {
        $validationError = $this->validatePaystackConfig();
        if ($validationError) {
            return response()->json(['ok' => false, 'error' => $validationError['error']], 500);
        }

        $country = $request->query('country', 'nigeria');

        try {
            $resp = Http::withToken(config('services.paystack.secret_key'))
                ->retry(3, 200)
                ->connectTimeout(10)
                ->timeout(25)
                ->get(config('services.paystack.payment_url').'/bank', [
                    'country' => $country,
                ]);

            if (! $resp->ok()) {
                Log::error('Paystack banks API error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);

                return response()->json(['ok' => false, 'error' => 'Failed to fetch banks'], 502);
            }

            $data = $resp->json();

            return response()->json([
                'ok' => true,
                'banks' => $data['data'] ?? [],
            ]);
        } catch (\Exception $e) {
            Log::error('Exception fetching banks', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Failed to fetch banks'], 500);
        }
    }

    public function resolveAccount(Request $request)
    {
        $validationError = $this->validatePaystackConfig();
        if ($validationError) {
            return response()->json(['ok' => false, 'error' => $validationError['error']], 500);
        }

        $validated = $request->validate([
            'account_number' => 'required|string|min:10|max:12',
            'bank_code' => 'required|string',
        ]);

        try {
            $resp = Http::withToken(config('services.paystack.secret_key'))
                ->retry(3, 200)
                ->connectTimeout(10)
                ->timeout(40) // Increased timeout
                ->get(config('services.paystack.payment_url').'/bank/resolve', [
                    'account_number' => $validated['account_number'],
                    'bank_code' => $validated['bank_code'],
                ]);

            if (! $resp->ok()) {
                Log::error('Paystack resolve account error', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                $error = $resp->json('message') ?? 'Could not connect to verification service.';

                return response()->json(['ok' => false, 'error' => $error], $resp->status());
            }

            $json = $resp->json();
            if (! ($json['status'] ?? false)) {
                return response()->json(['ok' => false, 'error' => $json['message'] ?? 'Resolve failed'], 422);
            }

            return response()->json([
                'ok' => true,
                'account_name' => $json['data']['account_name'] ?? null,
                'account_number' => $json['data']['account_number'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Exception resolving account', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $errorMessage = $e instanceof \Illuminate\Http\Client\ConnectionException ? 'Connection to verification service timed out.' : 'Failed to verify account.';

            return response()->json(['ok' => false, 'error' => $errorMessage], 500);
        }
    }
}
