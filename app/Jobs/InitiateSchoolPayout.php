<?php

namespace App\Jobs;

use App\Models\Payout;
use App\Services\PaystackService;
use App\Services\PayoutService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Initiates the Paystack transfer for one payout obligation (Model A).
 *
 * Runs after the settling transaction has committed, so a slow or failing transfer
 * can never roll back or delay the student's payment. Scoped to a single payout, so
 * one school's failure cannot affect another's (E4b) — there is no loop to abort.
 *
 * Idempotent in three independent ways:
 *   1. it only acts on a payout it can atomically claim from `pending`;
 *   2. the transfer carries our durable reference, which Paystack treats as the
 *      idempotency key;
 *   3. an ambiguous outcome parks the payout in `initiating`, from which the only
 *      move is a status lookup — never a second transfer.
 */
class InitiateSchoolPayout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Deliberately low: retries here are about transient infrastructure, not about
     * re-sending money. The state machine decides whether anything may be sent.
     */
    public int $tries = 3;

    public array $backoff = [30, 120];

    /**
     * LOW-1: the lock is released after this many seconds even if a worker dies, so a
     * crashed job can never wedge a payout permanently.
     */
    public int $uniqueFor = 600;

    public function __construct(public int $payoutId)
    {
        // MEDIUM-2: never let a transfer run inside an uncommitted payment
        // transaction. `Queueable` already declares $afterCommit, so it is set
        // through the trait's own helper rather than redeclared.
        $this->afterCommit();
    }

    /**
     * Keeps two workers from processing the same payout simultaneously.
     *
     * This is a convenience, NOT the financial control: the guarantee that money
     * moves once comes from the database — the unique `payouts.reference` and the
     * atomic pending -> initiating claim in PayoutService::claimForTransfer().
     * Those hold even if the cache lock is unavailable or expires early.
     */
    public function uniqueId(): string
    {
        return 'payout:'.$this->payoutId;
    }

    public function handle(PayoutService $payouts, PaystackService $paystack): void
    {
        $payout = Payout::find($this->payoutId);

        if (! $payout) {
            return;
        }

        // Already finished, or already in flight — nothing to send.
        if (in_array($payout->status, Payout::NON_INITIABLE, true)) {
            if ($payout->status === Payout::INITIATING) {
                $this->resolveAmbiguous($payout, $payouts, $paystack);
            }

            return;
        }

        if ($payout->status === Payout::FAILED) {
            // A previous attempt definitively failed. Re-running this job does not
            // silently re-send; an operator must reset the payout to pending.
            return;
        }

        if (! $payouts->claimForTransfer($payout)) {
            return; // another worker won the claim
        }

        $school = $payout->school;

        if (! $school) {
            $payouts->releaseClaim($payout, 'School no longer exists');

            return;
        }

        $amountKobo = (int) round(((float) $payout->amount) * 100);

        if ($amountKobo <= 0) {
            $payouts->releaseClaim($payout, 'Payout amount is not positive');

            return;
        }

        // Last line of defence, immediately before money moves: the amount about to
        // be sent must still be within what the payment authorises (the same chain
        // release and retry are checked against). A breach releases the claim as a
        // definitive failure — no transfer exists — and is never auto-corrected.
        $problem = $payouts->amountAuthorityProblem($payout, (float) $payout->amount, 'payout amount');
        if ($problem !== null) {
            Log::critical('FINANCIAL INTEGRITY: refused to transfer a payout amount its payment does not authorise', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'school_id' => $payout->school_id,
                'payout_amount' => (float) $payout->amount,
                'problem' => $problem,
            ]);
            $payouts->releaseClaim($payout, 'Refused before transfer: '.$problem);

            return;
        }

        $result = $paystack->initiateTransfer(
            $school,
            $amountKobo,
            $payout->reference,
            'Payout for transaction '.($payout->transaction?->reference ?? $payout->reference)
        );

        match ($result['outcome']) {
            // Accepted. NOT success — Paystack has taken the instruction, not
            // necessarily delivered the money. The reported status decides.
            'accepted' => $payouts->applyPaystackStatus(
                $payout,
                $result['data']['status'] ?? null,
                $result['data'] ?? []
            ),

            // Definitively refused: no transfer exists, so the payout is retryable.
            'rejected' => $payouts->releaseClaim($payout, (string) ($result['message'] ?? 'Transfer rejected')),

            // Unknown: a transfer may exist. Park it; only a lookup may resolve this.
            default => $payouts->markAmbiguous($payout, (string) ($result['message'] ?? 'Unknown transfer outcome')),
        };
    }

    /**
     * Resolve a payout stuck in `initiating` by asking Paystack what happened to our
     * reference. The payout is only released for another attempt when Paystack
     * confirms no such transfer exists. The rule lives in
     * PayoutService::reconcileInitiating() so the operator lookup command and this
     * job can never disagree about it.
     */
    private function resolveAmbiguous(Payout $payout, PayoutService $payouts, PaystackService $paystack): void
    {
        $payouts->reconcileInitiating($payout, $paystack);
    }

    /**
     * A job that exhausts its retries must never leave the impression money moved.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('Payout job failed permanently', [
            'payout_id' => $this->payoutId,
            'error' => $e->getMessage(),
        ]);
    }
}
