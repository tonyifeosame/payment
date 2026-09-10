<?php

namespace App\Console\Commands;

use App\Models\Payout;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Delivers a correctly-signed Paystack webhook to this application, locally.
 *
 * Paystack cannot reach http://localhost, so demonstrating the webhook leg
 * normally means standing up a public tunnel. This command removes that
 * dependency for a local demo: it builds the same JSON body Paystack sends,
 * signs it with HMAC-SHA512 over the raw body using the configured secret key —
 * exactly what PaystackWebhookController verifies — and POSTs it to the running
 * dev server.
 *
 * What it does and does not prove:
 *
 *   transfer.*      Fully real. handleTransferEvent() applies the reported status
 *                   straight to the payout state machine and calls no Paystack
 *                   API, so this genuinely completes the payout leg offline.
 *
 *   charge.success  Transport and signature only. Settlement re-verifies the
 *                   reference against the real Paystack API before it will move
 *                   any money, so this settles a transaction only if it was
 *                   actually paid in test mode. That is the point — a forged
 *                   webhook cannot invent a payment.
 *
 * This is a CLI command, not a route. It adds no HTTP surface, and it refuses to
 * run in production regardless.
 */
class DemoWebhook extends Command
{
    protected $signature = 'demo:webhook
        {event=charge.success : Paystack event name, e.g. charge.success or transfer.success}
        {reference? : Transaction reference (charge.*) or payout reference (transfer.*); defaults to the most recent}
        {--url= : Override the target URL (default: APP_URL + /paystack/webhook)}';

    protected $description = '[local demo] Send a signed Paystack webhook to this app without a public tunnel';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('demo:webhook is refused in production. Real webhooks come from Paystack.');

            return self::FAILURE;
        }

        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '') {
            $this->error('PAYSTACK_SECRET_KEY is not set, so the signature cannot be computed.');
            $this->line('Set your Paystack TEST secret key (sk_test_...) in .env first.');

            return self::FAILURE;
        }

        if (str_starts_with($secret, 'sk_live_')) {
            $this->error('Refusing to run: PAYSTACK_SECRET_KEY is a LIVE key. This command is for test mode only.');

            return self::FAILURE;
        }

        $event = (string) $this->argument('event');
        $reference = $this->argument('reference');

        $payload = str_starts_with($event, 'transfer.')
            ? $this->transferPayload($event, $reference)
            : $this->chargePayload($event, $reference);

        if ($payload === null) {
            return self::FAILURE;
        }

        // Signed over the exact bytes we send, which is what the controller checks.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $body, $secret);

        $url = (string) ($this->option('url') ?: rtrim((string) config('app.url'), '/').'/paystack/webhook');

        $this->line("POST {$url}");
        $this->line("  event:     {$event}");
        $this->line('  reference: '.($payload['data']['reference'] ?? '-'));

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-paystack-signature' => $signature,
            ])->withBody($body, 'application/json')->post($url);
        } catch (\Throwable $e) {
            $this->error('Could not reach the app: '.$e->getMessage());
            $this->line('Is `php artisan serve` running, and does APP_URL match its address?');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('HTTP '.$response->status().'  '.$response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function chargePayload(string $event, ?string $reference): ?array
    {
        $transaction = $reference
            ? Transaction::where('reference', $reference)->first()
            : Transaction::latest('id')->first();

        if (! $transaction) {
            $this->error($reference
                ? "No transaction with reference [{$reference}]."
                : 'No transactions exist yet. Start a payment on the public page first.');

            return null;
        }

        return [
            'event' => $event,
            'data' => [
                'reference' => $transaction->reference,
                'status' => 'success',
                'amount' => (int) round(((float) $transaction->amount) * 100),
                'currency' => 'NGN',
            ],
        ];
    }

    private function transferPayload(string $event, ?string $reference): ?array
    {
        $payout = $reference
            ? Payout::where('reference', $reference)->first()
            : Payout::latest('id')->first();

        if (! $payout) {
            $this->error($reference
                ? "No payout with reference [{$reference}]."
                : 'No payouts exist yet. Settle a payment first.');

            return null;
        }

        return [
            'event' => $event,
            'data' => [
                'reference' => $payout->reference,
                // e.g. transfer.success -> success, transfer.failed -> failed
                'status' => substr($event, strlen('transfer.')),
                'amount' => (int) round(((float) $payout->amount) * 100),
                'currency' => $payout->currency ?: 'NGN',
                'transfer_code' => $payout->transfer_code,
            ],
        ];
    }
}
