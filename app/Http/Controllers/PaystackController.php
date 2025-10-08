<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PaystackController extends Controller
{
    public function banks(Request $request)
    {
        $country = $request->query('country', 'nigeria');
        $resp = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->get(config('services.paystack.payment_url').'/bank', [
                'country' => $country,
            ]);
        if (!$resp->ok()) {
            return response()->json(['ok' => false, 'error' => 'Failed to fetch banks'], 502);
        }
        $data = $resp->json();
        return response()->json([
            'ok' => true,
            'banks' => $data['data'] ?? [],
        ]);
    }

    public function resolveAccount(Request $request)
    {
        $validated = $request->validate([
            'account_number' => 'required|string|min:10|max:12',
            'bank_code' => 'required|string',
        ]);

        $resp = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->get(config('services.paystack.payment_url').'/bank/resolve', [
                'account_number' => $validated['account_number'],
                'bank_code' => $validated['bank_code'],
            ]);

        $json = $resp->json();
        if (!($json['status'] ?? false)) {
            return response()->json(['ok' => false, 'error' => $json['message'] ?? 'Resolve failed'], 422);
        }

        return response()->json([
            'ok' => true,
            'account_name' => $json['data']['account_name'] ?? null,
            'account_number' => $json['data']['account_number'] ?? null,
        ]);
    }
}
