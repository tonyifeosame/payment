<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Owns the payout side of the ledger: what a school is owed, and the transfer
 * state machine that moves it.
 *
 * Payments and payouts are separate state machines. Nothing here may ever change a
 * transaction's status, and a failed transfer never invalidates a settled payment.
 */
class PayoutService
{
    public const CURRENCY = 'NGN';

    /**
     * The school's share of a settled payment.
     *
     * `transactions.amount` is the gross charged to the payer; `meta_data.base_amount`
     * is the school's own price. The difference is the platform's service fee and
     * must NOT be transferred to the school. Clamped so a payout can never exceed
     * what was actually collected.
     */
    public function payoutAmountFor(Transaction $transaction): float
    {
        $breakdown = $transaction->receiptBreakdown();

        return min($breakdown['fee_subtotal'], $breakdown['total']);
    }

    /**
     * Can this transaction's school share be trusted?
     *
     * Without a recorded base_amount the school's fee and the platform's markup are
     * indistinguishable. Paying the gross would hand the platform's fee to the
     * school; guessing a split would be worse. Such a payment gets a reviewable,
     * non-transferable obligation instead (HIGH-3).
     */
    public function hasTrustworthyBreakdown(Transaction $transaction): bool
    {
        return $transaction->receiptBreakdown()['has_breakdown'] === true;
    }

    /**
     * Record the obligation created by a settled payment.
     *
     * Idempotent: `payouts.transaction_id` is unique, so a replayed settlement, a
     * concurrent callback + webhook, or a backfill re-run all converge on one row.
     * Returns null when there is nothing to owe.
     */
    public function recordObligationFor(Transaction $transaction): ?Payout
    {
        if ($transaction->status !== 'success' || ! $transaction->school_id) {
            return null;
        }

        $existing = Payout::where('transaction_id', $transaction->id)->first();
        if ($existing) {
            return $existing;
        }

        // HIGH-3: an untrustworthy split becomes a reviewable obligation carrying no
        // amount, so it is durable and discoverable but can never move money.
        if (! $this->hasTrustworthyBreakdown($transaction)) {
            Log::critical('Payout needs manual review: the school share cannot be separated from the platform fee', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'school_id' => $transaction->school_id,
                'amount_charged' => (float) $transaction->amount,
            ]);

            return $this->createPayout($transaction, [
                'amount' => 0,
                'status' => Payout::NEEDS_REVIEW,
                'last_error' => 'No base_amount recorded: the school share cannot be separated from the platform fee. Resolve the amount manually before releasing this payout.',
            ]);
        }

        $amount = $this->payoutAmountFor($transaction);

        if ($amount <= 0) {
            Log::warning('Skipping payout obligation with a non-positive amount', [
                'transaction_id' => $transaction->id,
                'amount' => $amount,
            ]);

            return null;
        }

        return $this->createPayout($transaction, [
            'amount' => $amount,
            'status' => Payout::PENDING,
        ]);
    }

    /**
     * Insert the payout row, tolerating a lost race on the unique index.
     *
     * MEDIUM-1: the INSERT runs inside its own nested transaction (a SAVEPOINT) and
     * the exception is allowed to escape that closure, so Laravel rolls back to the
     * savepoint. On PostgreSQL that leaves the surrounding transaction usable — a
     * QueryException caught *inside* a transaction would otherwise poison it and
     * make every later statement fail.
     */
    private function createPayout(Transaction $transaction, array $attributes): ?Payout
    {
        try {
            return DB::transaction(fn () => Payout::create(array_merge([
                'school_id' => $transaction->school_id,
                'transaction_id' => $transaction->id,
                'reference' => $this->generateReference(),
                'currency' => self::CURRENCY,
            ], $attributes)));
        } catch (QueryException $e) {
            $existing = Payout::where('transaction_id', $transaction->id)->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Claim a payout for transfer, moving pending -> initiating atomically.
     *
     * This conditional update is the duplicate-transfer guard: only one worker can
     * win it, so only one can reach the Paystack API for a given payout. Returns
     * false when the payout is already claimed, in flight, or finished.
     */
    public function claimForTransfer(Payout $payout): bool
    {
        $claimed = Payout::whereKey($payout->getKey())
            ->where('status', Payout::PENDING)
            ->update([
                'status' => Payout::INITIATING,
                'attempts' => DB::raw('attempts + 1'),
                'initiated_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed) {
            $payout->refresh();
        }

        return $claimed > 0;
    }

    /**
     * Release a claim when we know for certain no transfer was created.
     * Only ever called for a definitive rejection — never for an unknown outcome.
     */
    public function releaseClaim(Payout $payout, string $reason): void
    {
        Payout::whereKey($payout->getKey())
            ->where('status', Payout::INITIATING)
            ->update([
                'status' => Payout::FAILED,
                'last_error' => Str::limit($reason, 1000),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $payout->refresh();
    }

    /**
     * Leave a payout in the ambiguous state: we asked Paystack to move money and do
     * not know whether it did. Never re-sends; the next attempt must look it up.
     */
    public function markAmbiguous(Payout $payout, string $reason): void
    {
        Log::critical('Payout transfer outcome is unknown — will not re-send without a status lookup', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'school_id' => $payout->school_id,
            'reason' => $reason,
        ]);

        Payout::whereKey($payout->getKey())
            ->whereIn('status', [Payout::PENDING, Payout::INITIATING])
            ->update([
                'status' => Payout::INITIATING,
                'last_error' => Str::limit($reason, 1000),
                'updated_at' => now(),
            ]);

        $payout->refresh();
    }

    /**
     * Apply a status reported by Paystack (initiate response, lookup, or webhook).
     *
     * Idempotent and transition-checked: every change is validated against
     * Payout::ALLOWED_TRANSITIONS, so a redelivery is a no-op, a late
     * `transfer.failed` can never undo a success, and a reversed payout can never be
     * resurrected — while the one legitimate downgrade (success -> reversed) is
     * applied.
     *
     * HIGH-2: `success` is additionally gated on the transfer actually matching this
     * payout — same reference, same amount in kobo, currency NGN. A mismatch is a
     * financial-integrity event: it is logged critical and parked in `needs_review`,
     * never recorded as paid.
     */
    public function applyPaystackStatus(Payout $payout, ?string $paystackStatus, array $data = []): string
    {
        $target = Payout::mapPaystackStatus($paystackStatus);

        try {
            return DB::transaction(function () use ($payout, $target, $data, $paystackStatus) {
                /** @var Payout|null $locked */
                $locked = Payout::whereKey($payout->getKey())->lockForUpdate()->first();

                if (! $locked) {
                    return Payout::FAILED;
                }

                if ($target === Payout::SUCCESS) {
                    $problem = $this->transferMismatch($locked, $data);

                    if ($problem !== null) {
                        return $this->parkForReview($locked, $problem, $data);
                    }
                }

                if (! $locked->canTransitionTo($target)) {
                    return $locked->status; // redelivery, or an illegitimate downgrade
                }

                $attributes = ['status' => $target];

                // MEDIUM-1: pre-check instead of catching a constraint violation, so
                // no failed statement can poison this transaction on PostgreSQL.
                $transferCode = $data['transfer_code'] ?? null;
                if ($transferCode !== null && ! $this->transferCodeTaken($locked, (string) $transferCode)) {
                    $attributes['transfer_code'] = $transferCode;
                }

                if (isset($data['id'])) {
                    $attributes['transfer_id'] = (string) $data['id'];
                }
                if ($data !== []) {
                    $attributes['response'] = $data;
                }
                if (in_array($target, [Payout::SUCCESS, Payout::FAILED, Payout::REVERSED], true)) {
                    $attributes['completed_at'] = now();
                }
                if ($target === Payout::FAILED) {
                    $attributes['last_error'] = Str::limit(
                        'Paystack reported transfer status: '.($paystackStatus ?? 'unknown'), 1000
                    );
                }

                $locked->forceFill($attributes)->save();

                return $target;
            });
        } catch (QueryException $e) {
            // Caught outside the transaction so PostgreSQL has already rolled it back.
            report($e);

            Log::critical('Could not record a payout transfer status', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'reported_status' => $paystackStatus,
            ]);

            return $payout->fresh()?->status ?? Payout::PENDING;
        }
    }

    /**
     * HIGH-2: does the transfer Paystack is reporting actually correspond to what we
     * owe? Returns null when it matches, or a description of the discrepancy.
     */
    private function transferMismatch(Payout $payout, array $data): ?string
    {
        $reference = $data['reference'] ?? null;
        if ($reference !== null && (string) $reference !== (string) $payout->reference) {
            return sprintf('reference mismatch (expected %s, got %s)', $payout->reference, $reference);
        }

        $expectedMinorUnits = (int) round(((float) $payout->amount) * 100);
        $reportedMinorUnits = array_key_exists('amount', $data) ? (int) $data['amount'] : null;

        if ($reportedMinorUnits === null) {
            return 'Paystack reported a successful transfer without an amount';
        }

        if ($reportedMinorUnits !== $expectedMinorUnits) {
            return sprintf('amount mismatch (expected %d kobo, got %d kobo)', $expectedMinorUnits, $reportedMinorUnits);
        }

        $currency = $data['currency'] ?? self::CURRENCY;
        if (strtoupper((string) $currency) !== self::CURRENCY) {
            return sprintf('currency mismatch (expected %s, got %s)', self::CURRENCY, $currency);
        }

        return null;
    }

    /**
     * Park a payout for a human. Never counted as paid, never transferable.
     */
    private function parkForReview(Payout $payout, string $problem, array $data): string
    {
        Log::critical('FINANCIAL INTEGRITY: Paystack reported a successful transfer that does not match the payout', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'school_id' => $payout->school_id,
            'expected_amount' => (float) $payout->amount,
            'problem' => $problem,
        ]);

        if (! $payout->canTransitionTo(Payout::NEEDS_REVIEW)) {
            return $payout->status;
        }

        $payout->forceFill([
            'status' => Payout::NEEDS_REVIEW,
            'last_error' => Str::limit('Transfer did not match this payout: '.$problem, 1000),
            'response' => $data !== [] ? $data : $payout->response,
        ])->save();

        return Payout::NEEDS_REVIEW;
    }

    /** Is this transfer_code already claimed by a different payout? */
    private function transferCodeTaken(Payout $payout, string $transferCode): bool
    {
        return Payout::where('transfer_code', $transferCode)
            ->whereKeyNot($payout->getKey())
            ->exists();
    }

    /**
     * Our durable transfer reference, and Paystack's idempotency key for the transfer.
     */
    private function generateReference(): string
    {
        return 'PO-'.Str::uuid()->toString();
    }
}
