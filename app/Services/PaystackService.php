<?php

namespace App\Services;

use App\Models\School;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    /**
     * Client for read-only lookups (bank list, account resolution).
     *
     * Only a connection failure or a 5xx is retried, and the client never throws
     * on a failed response. The default retry() re-throws once its attempts are
     * exhausted — and it counts any 4xx as an attempt — so Paystack's own
     * "Could not resolve account name" 422 was retried three times and then
     * surfaced as a generic "Failed to verify account." 500. The callers below
     * inspect the response themselves and keep Paystack's message.
     */
    protected function lookupClient()
    {
        return Http::withToken($this->secret)
            ->retry(3, 200, function (\Throwable $e) {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError());
            }, throw: false)
            ->connectTimeout(10)
            ->timeout(30);
    }

    /**
     * Client for money-moving requests.
     *
     * Deliberately NOT retrying: an automatic retry of POST /transfer can create a
     * second transfer when the first one succeeded but the response was lost. Any
     * re-attempt must go through the payout state machine, which looks the transfer
     * up by reference first.
     */
    protected function transferClient()
    {
        return Http::withToken($this->secret)
            ->connectTimeout(10)
            ->timeout(30);
    }

    /**
     * Banks Paystack can pay out to, for the registration / bank-change forms.
     *
     * @return array{ok:bool, banks?:array, message?:string, reason?:string}
     *                                                                       reason (when !ok): 'config' | 'rejected' | 'unavailable'
     */
    public function listBanks(string $country = 'nigeria'): array
    {
        if (empty($this->secret)) {
            return ['ok' => false, 'reason' => 'config', 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->lookupClient()->get($this->baseUrl.'/bank', ['country' => $country]);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'reason' => 'unavailable', 'message' => 'Could not reach the bank directory.'];
        }

        $json = $resp->json();

        if ($resp->successful() && is_array($json) && ($json['status'] ?? false) && is_array($json['data'] ?? null)) {
            return ['ok' => true, 'banks' => $json['data']];
        }

        return $this->lookupFailure($resp->status(), $json, 'Failed to fetch banks');
    }

    /**
     * The integration's Paystack balance(s) — the funds transfers are paid from.
     *
     * Read-only (GET /balance), for the `paystack:check` readiness command. Uses
     * the same non-throwing lookup client and failure classification as the bank
     * directory, and never returns anything but amounts and currencies.
     *
     * @return array{ok:bool, balances?:array<int, array{currency:string, balance:float}>, message?:string, reason?:string}
     *                                                                                                                      reason (when !ok): 'config' | 'rejected' | 'unavailable'
     */
    public function fetchBalance(): array
    {
        if (empty($this->secret)) {
            return ['ok' => false, 'reason' => 'config', 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->lookupClient()->get($this->baseUrl.'/balance');
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'reason' => 'unavailable', 'message' => 'Could not reach Paystack.'];
        }

        $json = $resp->json();

        if ($resp->successful() && is_array($json) && ($json['status'] ?? false) && is_array($json['data'] ?? null)) {
            $balances = [];
            foreach ($json['data'] as $row) {
                if (is_array($row) && isset($row['balance'])) {
                    // Paystack reports balances in minor units (kobo).
                    $balances[] = ['currency' => strtoupper((string) ($row['currency'] ?? 'NGN')), 'balance' => round(((int) $row['balance']) / 100, 2)];
                }
            }

            return ['ok' => true, 'balances' => $balances];
        }

        return $this->lookupFailure($resp->status(), $json, 'Failed to fetch balance');
    }

    /**
     * Resolve an account number to the name the bank holds for it.
     *
     * Every caller that stores bank details (registration, bank-details change)
     * uses the name returned here and never one typed into a form.
     *
     * @return array{ok:bool, account_name?:?string, account_number?:?string, message?:string, reason?:string}
     *                                                                                                         reason (when !ok): 'config' | 'rejected' | 'unavailable'
     */
    public function resolveAccount(string $accountNumber, string $bankCode): array
    {
        if (empty($this->secret)) {
            return ['ok' => false, 'reason' => 'config', 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->lookupClient()->get($this->baseUrl.'/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $message = $e instanceof ConnectionException ? 'Connection to verification service timed out.' : 'Failed to verify account.';

            return ['ok' => false, 'reason' => 'unavailable', 'message' => $message];
        }

        $json = $resp->json();

        if ($resp->successful() && is_array($json) && ($json['status'] ?? false) && ! empty($json['data']['account_name'])) {
            return [
                'ok' => true,
                'account_name' => $json['data']['account_name'],
                'account_number' => $json['data']['account_number'] ?? null,
            ];
        }

        return $this->lookupFailure($resp->status(), $json, 'Resolve failed');
    }

    /**
     * Classify a non-successful lookup response.
     *
     * A 4xx with a decodable body is Paystack's definitive answer and its message
     * is passed on (the account really does not resolve; the key really is
     * invalid). A 5xx or an unreadable body is Paystack being unavailable, and the
     * caller should say so rather than blame the account.
     */
    private function lookupFailure(int $status, mixed $json, string $fallback): array
    {
        $message = is_array($json) ? ($json['message'] ?? null) : null;

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'reason' => 'config', 'message' => $message ?? 'Paystack rejected the API key'];
        }

        if ($status >= 400 && $status < 500 && $message !== null) {
            return ['ok' => false, 'reason' => 'rejected', 'message' => $message];
        }

        if ($status >= 200 && $status < 300 && is_array($json)) {
            // 200 with status:false — Paystack occasionally answers this way.
            return ['ok' => false, 'reason' => 'rejected', 'message' => $message ?? $fallback];
        }

        return ['ok' => false, 'reason' => 'unavailable', 'message' => 'Verification service is temporarily unavailable. Please try again.'];
    }

    /**
     * Server-side verification of a charge, by the reference we generated.
     *
     * This is the only trustworthy source of a payment's status, amount and
     * currency — never the browser's query string or Paystack's metadata echo.
     * Returns a normalised shape so callers do not have to dig through the
     * raw payload, and so the callback and the webhook agree on the result.
     */
    public function verifyTransaction(string $reference): array
    {
        if (empty($this->secret)) {
            return ['ok' => false, 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->client()->get($this->baseUrl.'/transaction/verify/'.rawurlencode($reference));
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not reach the payment verification service.'];
        }

        $json = $resp->json();

        if (! is_array($json) || ! ($json['status'] ?? false) || ! isset($json['data'])) {
            return ['ok' => false, 'message' => is_array($json) ? ($json['message'] ?? 'Verification failed') : 'Verification failed'];
        }

        $data = $json['data'];

        return [
            'ok' => true,
            'status' => $data['status'] ?? null,          // 'success' | 'failed' | 'abandoned' | ...
            'amount' => isset($data['amount']) ? (int) $data['amount'] : null, // minor units (kobo)
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'reference' => $data['reference'] ?? null,
            'raw' => $data,
        ];
    }

    public function ensureRecipientForSchool(School $school): ?string
    {
        if ($school->paystack_recipient_code) {
            return $school->paystack_recipient_code;
        }
        if (! $school->bank_code || ! $school->account_number || ! $school->account_name) {
            return null; // cannot create recipient without bank details
        }
        $payload = [
            'type' => 'nuban',
            'name' => $school->account_name,
            'account_number' => $school->account_number,
            'bank_code' => $school->bank_code,
            'currency' => 'NGN',
        ];
        $resp = $this->client()->post($this->baseUrl.'/transferrecipient', $payload);
        $json = $resp->json();
        if (! ($json['status'] ?? false)) {
            return null;
        }
        $code = $json['data']['recipient_code'] ?? null;
        if ($code) {
            $school->paystack_recipient_code = $code;
            $school->save();
        }

        return $code;
    }

    /**
     * Initiate a transfer to a school, keyed by OUR reference.
     *
     * The reference is the idempotency key: re-sending the same one cannot create a
     * second transfer at Paystack. The outcome is deliberately NOT flattened to a
     * boolean — callers must distinguish "definitely not sent" from "unknown".
     *
     * @return array{outcome:string, message:?string, data:?array, response:?array}
     *                                                                              outcome: 'accepted' | 'rejected' | 'unknown'
     */
    public function initiateTransfer(School $school, int $amountKobo, string $reference, string $reason = ''): array
    {
        if (empty($this->secret)) {
            return ['outcome' => 'rejected', 'message' => 'Paystack secret key is not configured', 'data' => null, 'response' => null];
        }

        $recipient = $school->paystack_recipient_code ?: $this->ensureRecipientForSchool($school);
        if (! $recipient) {
            return ['outcome' => 'rejected', 'message' => 'Recipient not available', 'data' => null, 'response' => null];
        }

        $payload = [
            'source' => 'balance',
            'amount' => $amountKobo,
            'recipient' => $recipient,
            'reason' => $reason,
            'reference' => $reference,
        ];

        try {
            $resp = $this->transferClient()->post($this->baseUrl.'/transfer', $payload);
        } catch (\Throwable $e) {
            report($e);

            // The request may or may not have reached Paystack. This is the one
            // case where we must never assume anything.
            return ['outcome' => 'unknown', 'message' => 'Transfer request did not complete', 'data' => null, 'response' => null];
        }

        $json = $resp->json();

        if (! is_array($json)) {
            return ['outcome' => 'unknown', 'message' => 'Unreadable response from Paystack', 'data' => null, 'response' => null];
        }

        if (! ($json['status'] ?? false)) {
            // A 4xx with a decodable body is a definitive refusal; a 5xx is not.
            $outcome = $resp->serverError() ? 'unknown' : 'rejected';

            return ['outcome' => $outcome, 'message' => $json['message'] ?? 'Transfer failed', 'data' => null, 'response' => $json];
        }

        return ['outcome' => 'accepted', 'message' => $json['message'] ?? null, 'data' => $json['data'] ?? [], 'response' => $json];
    }

    /**
     * Look a transfer up by the reference we issued.
     *
     * Used to resolve the ambiguous case before any re-attempt, so we never create a
     * second transfer for money that may already be on its way.
     *
     * @return array{outcome:string, status:?string, data:?array, message:?string}
     *                                                                             outcome: 'found' | 'absent' | 'unknown'
     */
    public function fetchTransfer(string $reference): array
    {
        if (empty($this->secret)) {
            return ['outcome' => 'unknown', 'status' => null, 'data' => null, 'message' => 'Paystack secret key is not configured'];
        }

        try {
            $resp = $this->transferClient()->get($this->baseUrl.'/transfer/verify/'.rawurlencode($reference));
        } catch (\Throwable $e) {
            report($e);

            return ['outcome' => 'unknown', 'status' => null, 'data' => null, 'message' => 'Could not reach Paystack'];
        }

        $json = $resp->json();

        if (is_array($json) && ($json['status'] ?? false) && isset($json['data'])) {
            return [
                'outcome' => 'found',
                'status' => $json['data']['status'] ?? null,
                'data' => $json['data'],
                'message' => null,
            ];
        }

        // Only a definitive 404 proves no such transfer exists. Anything else is
        // unknown, and unknown must never release the payout for a re-send.
        if ($resp->status() === 404) {
            return ['outcome' => 'absent', 'status' => null, 'data' => null, 'message' => is_array($json) ? ($json['message'] ?? null) : null];
        }

        return ['outcome' => 'unknown', 'status' => null, 'data' => null, 'message' => is_array($json) ? ($json['message'] ?? null) : null];
    }
}
