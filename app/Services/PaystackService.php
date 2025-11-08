<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Http;

class PaystackService
{
    protected string $baseUrl;
    protected string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.paystack.payment_url', 'https://api.paystack.co'), '/');
        $this->secret = (string) config('services.paystack.secret_key');
    }

    protected function client()
    {
        return Http::withToken($this->secret)
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(30);
    }

    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        if (empty($this->secret)) {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->client()->get($this->baseUrl . '/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            $json = $resp->json();

            if (!($json['status'] ?? false)) {
                return ['ok' => false, 'message' => $json['message'] ?? 'Resolve failed'];
            }

            return [
                'ok' => true,
                'account_name' => $json['data']['account_name'] ?? null,
                'account_number' => $json['data']['account_number'] ?? null,
            ];
        } catch (\Exception $e) {
            report($e); // Log the exception
            $errorMessage = $e instanceof \Illuminate\Http\Client\ConnectionException ? 'Connection to verification service timed out.' : 'Failed to verify account.';
            return ['ok' => false, 'message' => $errorMessage];
        }
    }

    public function ensureRecipientForSchool(School $school): ?string
    {
        if ($school->paystack_recipient_code) {
            return $school->paystack_recipient_code;
        }
        if (!$school->bank_code || !$school->account_number || !$school->account_name) {
            return null; // cannot create recipient without bank details
        }
        $payload = [
            'type' => 'nuban',
            'name' => $school->account_name,
            'account_number' => $school->account_number,
            'bank_code' => $school->bank_code,
            'currency' => 'NGN',
        ];
        $resp = $this->client()->post($this->baseUrl . '/transferrecipient', $payload);
        $json = $resp->json();
        if (!($json['status'] ?? false)) {
            return null;
        }
        $code = $json['data']['recipient_code'] ?? null;
        if ($code) {
            $school->paystack_recipient_code = $code;
            $school->save();
        }
        return $code;
    }

    public function initiateTransferToSchool(School $school, int $amountKobo, string $reason = ''): array
    {
        $recipient = $school->paystack_recipient_code ?: $this->ensureRecipientForSchool($school);
        if (!$recipient) {
            return ['ok' => false, 'message' => 'Recipient not available'];
        }
        $payload = [
            'source' => 'balance',
            'amount' => $amountKobo,
            'recipient' => $recipient,
            'reason' => $reason,
        ];
        $resp = $this->client()->post($this->baseUrl . '/transfer', $payload);
        $json = $resp->json();
        if (!($json['status'] ?? false)) {
            return ['ok' => false, 'message' => $json['message'] ?? 'Transfer failed', 'response' => $json];
        }
        return ['ok' => true, 'response' => $json];
    }
}
