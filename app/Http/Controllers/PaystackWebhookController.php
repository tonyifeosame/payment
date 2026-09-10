<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Services\PaymentSettlementService;
use App\Services\PayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paystack webhook receiver (G1).
 *
 * The browser callback is best-effort — the payer can close the tab, lose
 * connectivity, or never be redirected at all. This endpoint is the reliable,
 * server-to-server path and is the one that should be treated as authoritative.
 *
 * It is unauthenticated by necessity and CSRF-exempt (see bootstrap/app.php),
 * so the HMAC signature is the sole access control and is checked before the
 * body is looked at in any way.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentSettlementService $settlement, PayoutService $payouts)
    {
        $secret = (string) config('services.paystack.secret_key');
        if ($secret === '') {
            Log::error('Paystack webhook received but no secret key is configured.');

            return response()->json(['status' => 'misconfigured'], 500);
        }

        // Signature is computed over the exact raw body, before any parsing.
        $rawBody = $request->getContent();
        $provided = (string) $request->header('x-paystack-signature', '');
        $expected = hash_hmac('sha512', $rawBody, $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            Log::warning('Rejected Paystack webhook with an invalid signature.', [
                'ip' => $request->ip(),
                'has_signature' => $provided !== '',
            ]);

            return response()->json(['status' => 'invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $event = $payload['event'] ?? null;

        // Transfer events move the PAYOUT state machine. They never touch a payment:
        // a school transfer failing does not un-charge the student.
        if (is_string($event) && str_starts_with($event, 'transfer.')) {
            return $this->handleTransferEvent($event, $payload, $payouts);
        }

        // Only charge success settles a payment. Everything else is acknowledged
        // so Paystack stops retrying, but deliberately does nothing.
        if ($event !== 'charge.success') {
            return response()->json(['status' => 'ignored', 'event' => $event], 200);
        }

        // G4: resolve strictly by reference; metadata in the payload is not consulted.
        $reference = $payload['data']['reference'] ?? null;

        $result = $settlement->settleByReference(is_string($reference) ? $reference : null);

        return match ($result['outcome']) {
            // Transient: ask Paystack to redeliver.
            PaymentSettlementService::VERIFICATION_FAILED => response()->json(['status' => 'retry'], 500),

            // A durable database conflict. Redelivering would hit the same wall, so
            // acknowledge and let the critical log drive manual reconciliation
            // instead of having Paystack retry indefinitely (M5).
            PaymentSettlementService::SETTLEMENT_CONFLICT => response()->json([
                'status' => PaymentSettlementService::SETTLEMENT_CONFLICT,
            ], 200),

            // Everything else is a final answer. Acknowledge so retries stop.
            default => response()->json(['status' => $result['outcome']], 200),
        };
    }

    /**
     * Apply a transfer.* event to the payout it belongs to.
     *
     * Resolved by OUR reference (the idempotency key we sent on initiation), never by
     * anything else in the payload. Idempotent: PayoutService refuses to move a payout
     * out of a terminal state, so a redelivery changes nothing.
     */
    private function handleTransferEvent(string $event, array $payload, PayoutService $payouts)
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            Log::warning('Transfer webhook carried no reference', ['event' => $event]);

            return response()->json(['status' => 'ignored', 'event' => $event], 200);
        }

        $payout = Payout::where('reference', $reference)->first();

        if (! $payout) {
            Log::warning('Transfer webhook for an unknown payout reference', [
                'event' => $event,
                'reference' => $reference,
            ]);

            return response()->json(['status' => 'not_found'], 200);
        }

        // Prefer the status Paystack reports; fall back to the event name so an
        // event without a status field still moves the payout correctly.
        $status = $data['status'] ?? substr($event, strlen('transfer.'));

        $applied = $payouts->applyPaystackStatus($payout, $status, $data);

        return response()->json(['status' => 'applied', 'payout_status' => $applied], 200);
    }
}
