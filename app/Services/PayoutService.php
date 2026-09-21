<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PayoutRecoveryEvent;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
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
     * An `initiating` payout older than this is considered stale: Paystack answers
     * a transfer request in seconds, so after ten minutes silence means the
     * outcome was lost and a status lookup is the only safe next step.
     */
    public const STALE_INITIATING_MINUTES = 10;

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

    // -----------------------------------------------------------------------
    // Operator recovery (B1). These are the ONLY ways a payout leaves `failed`,
    // `needs_review` or a stale `initiating` without Paystack driving it. Each
    // one re-reads the row under the same lock the rest of the ledger uses,
    // applies a transition the state machine already permits, and writes an
    // immutable PayoutRecoveryEvent in the same database transaction. None of
    // them talk to Paystack except reconcileInitiating(), which only ever LOOKS a
    // transfer up — a re-send is exclusively the queued job's business, and only
    // from `pending` through claimForTransfer().
    // -----------------------------------------------------------------------

    /**
     * Reset a definitively failed payout so the queued job may try the transfer
     * again. Only `failed` qualifies: everything else is either still in flight
     * (never re-send), finished, or needs a human's amount first.
     *
     * The reference is kept — it is the idempotency key Paystack knows this
     * obligation by — and `attempts` keeps counting, so the retry is visible in
     * the row's own history as well as in the recovery event.
     *
     * @return array{ok: bool, previous: string, status: string, message: string, event: ?PayoutRecoveryEvent}
     */
    public function retryFailed(Payout $payout, string $source = PayoutRecoveryEvent::SOURCE_ARTISAN, ?string $note = null): array
    {
        return DB::transaction(function () use ($payout, $source, $note) {
            /** @var Payout|null $locked */
            $locked = Payout::whereKey($payout->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->recoveryResult(false, $payout->status, $payout->status, 'payout no longer exists');
            }

            if ($locked->status !== Payout::FAILED || ! $locked->canTransitionTo(Payout::PENDING)) {
                $event = $this->recordRecoveryEvent($locked, PayoutRecoveryEvent::ACTION_RETRY, $locked->status, null, $source, null, $note, 'rejected: payout is '.$locked->status);

                return $this->recoveryResult(false, $locked->status, $locked->status, $this->rejection($locked), $event);
            }

            // The job will send `payouts.amount` as it stands. Re-prove it against the
            // payment before letting it back into the queue: a tampered or mis-set
            // amount is refused here, audited, and never silently corrected.
            $problem = $this->amountAuthorityProblem($locked, (float) $locked->amount, 'payout amount');
            if ($problem !== null) {
                Log::critical('Refused to retry a payout whose amount exceeds what its payment authorises', [
                    'payout_id' => $locked->id,
                    'reference' => $locked->reference,
                    'school_id' => $locked->school_id,
                    'payout_amount' => (float) $locked->amount,
                    'problem' => $problem,
                ]);

                $event = $this->recordRecoveryEvent($locked, PayoutRecoveryEvent::ACTION_RETRY, $locked->status, null, $source, (float) $locked->amount, $note, 'rejected: '.$problem);

                return $this->recoveryResult(false, $locked->status, $locked->status, $problem, $event);
            }

            $previous = $locked->status;
            $reason = $note ?? $locked->last_error;

            // Only what the state machine needs to accept the row as a fresh
            // obligation: `completed_at` marked the failure as final and is cleared;
            // `last_error` stays so the reason for the failure is still on the row.
            $locked->forceFill(['status' => Payout::PENDING, 'completed_at' => null])->save();

            $event = $this->recordRecoveryEvent($locked, PayoutRecoveryEvent::ACTION_RETRY, $previous, Payout::PENDING, $source, null, $reason, 'reset');

            Log::warning('Operator reset a failed payout for retry', [
                'payout_id' => $locked->id,
                'reference' => $locked->reference,
                'school_id' => $locked->school_id,
                'attempts_so_far' => $locked->attempts,
                'source' => $source,
            ]);

            return $this->recoveryResult(true, $previous, Payout::PENDING, 'reset from failed to pending', $event);
        });
    }

    /**
     * Release a payout parked in `needs_review` with an operator-supplied amount.
     *
     * A reviewed payout carries no trustworthy amount (or a mismatching one), so
     * nothing here derives one: the operator states it explicitly, it must be a
     * positive naira amount to the ledger's two-decimal precision, and it can never
     * exceed what the parent was actually charged for the payment it belongs to.
     * The original reason the payout was parked is preserved on the row
     * (`last_error`) and copied onto the recovery event.
     *
     * @return array{ok: bool, previous: string, status: string, message: string, event: ?PayoutRecoveryEvent}
     */
    public function releaseForTransfer(Payout $payout, float $amount, string $source = PayoutRecoveryEvent::SOURCE_ARTISAN, ?string $note = null): array
    {
        return DB::transaction(function () use ($payout, $amount, $source, $note) {
            /** @var Payout|null $locked */
            $locked = Payout::whereKey($payout->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return $this->recoveryResult(false, $payout->status, $payout->status, 'payout no longer exists');
            }

            if ($locked->status !== Payout::NEEDS_REVIEW || ! $locked->canTransitionTo(Payout::PENDING)) {
                $event = $this->recordRecoveryEvent($locked, PayoutRecoveryEvent::ACTION_RELEASE, $locked->status, null, $source, $amount, $note, 'rejected: payout is '.$locked->status);

                return $this->recoveryResult(false, $locked->status, $locked->status, $this->rejection($locked), $event);
            }

            $problem = $this->releaseAmountProblem($locked, $amount);
            if ($problem !== null) {
                $event = $this->recordRecoveryEvent($locked, PayoutRecoveryEvent::ACTION_RELEASE, $locked->status, null, $source, $amount, $note, 'rejected: '.$problem);

                return $this->recoveryResult(false, $locked->status, $locked->status, $problem, $event);
            }

            $previous = $locked->status;
            $originalReason = $locked->last_error;

            $locked->forceFill([
                'amount' => round($amount, 2),
                'status' => Payout::PENDING,
                'completed_at' => null,
            ])->save();

            $event = $this->recordRecoveryEvent(
                $locked,
                PayoutRecoveryEvent::ACTION_RELEASE,
                $previous,
                Payout::PENDING,
                $source,
                round($amount, 2),
                trim(($note ? $note.' | ' : '').'parked because: '.($originalReason ?? 'unknown')),
                'released'
            );

            Log::warning('OPERATOR OVERRIDE: payout released from review with an explicit amount', [
                'payout_id' => $locked->id,
                'reference' => $locked->reference,
                'school_id' => $locked->school_id,
                'transaction_id' => $locked->transaction_id,
                'amount' => round($amount, 2),
                'parked_because' => $originalReason,
                'source' => $source,
            ]);

            return $this->recoveryResult(true, $previous, Payout::PENDING, 'released from needs_review to pending with amount NGN '.number_format($amount, 2), $event);
        });
    }

    /**
     * Resolve an `initiating` payout by asking Paystack what became of our
     * reference. Shared by the queued job (its second run) and the operator lookup
     * command, so both follow exactly one rule: a transfer Paystack knows about is
     * applied through applyPaystackStatus(); a definitive 404 releases the claim
     * to `failed` (retryable); anything else leaves the payout parked. Nothing
     * here can create a transfer.
     *
     * @return array{outcome: string, previous: string, status: string, message: ?string}
     *                                                                                    outcome: 'resolved' | 'released' | 'unresolved' | 'not_initiating'
     */
    public function reconcileInitiating(Payout $payout, PaystackService $paystack): array
    {
        $payout->refresh();
        $previous = $payout->status;

        if ($previous !== Payout::INITIATING) {
            return ['outcome' => 'not_initiating', 'previous' => $previous, 'status' => $previous, 'message' => 'payout is '.$previous];
        }

        $lookup = $paystack->fetchTransfer($payout->reference);

        switch ($lookup['outcome']) {
            case 'found':
                $status = $this->applyPaystackStatus($payout, $lookup['status'], $lookup['data'] ?? []);

                return ['outcome' => 'resolved', 'previous' => $previous, 'status' => $status, 'message' => 'Paystack reports the transfer as '.($lookup['status'] ?? 'unknown')];

            case 'absent':
                $this->releaseClaim($payout, 'Paystack has no transfer for this reference: '.($lookup['message'] ?? 'not found'));

                return ['outcome' => 'released', 'previous' => $previous, 'status' => $payout->status, 'message' => 'Paystack has no transfer for this reference'];

            default:
                Log::critical('Payout status still unresolved after lookup', [
                    'payout_id' => $payout->id,
                    'reference' => $payout->reference,
                    'message' => $lookup['message'] ?? null,
                ]);

                return ['outcome' => 'unresolved', 'previous' => $previous, 'status' => $payout->refresh()->status, 'message' => $lookup['message'] ?? 'Paystack could not be reached'];
        }
    }

    /**
     * `initiating` payouts that have been silent for longer than the stale
     * threshold — the only ones the operator lookup touches in batch mode.
     */
    public function staleInitiating(): Builder
    {
        $threshold = now()->subMinutes(self::STALE_INITIATING_MINUTES);

        return Payout::query()
            ->where('status', Payout::INITIATING)
            ->where(fn (Builder $q) => $q
                ->where('initiated_at', '<=', $threshold)
                ->orWhere(fn (Builder $w) => $w->whereNull('initiated_at')->where('updated_at', '<=', $threshold)))
            ->orderBy('id');
    }

    /**
     * Write the immutable audit row for an operator action. Called inside the
     * transaction that performs the action, so the two commit or roll back together.
     */
    public function recordRecoveryEvent(
        Payout $payout,
        string $action,
        string $previousStatus,
        ?string $newStatus,
        string $source,
        ?float $amount,
        ?string $reason,
        string $result
    ): PayoutRecoveryEvent {
        return PayoutRecoveryEvent::create([
            'payout_id' => $payout->id,
            'school_id' => $payout->school_id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'source' => $source,
            'amount' => $amount !== null ? round($amount, 2) : null,
            'reason' => $reason !== null ? Str::limit($reason, 2000, '') : null,
            'result' => Str::limit($result, 255, ''),
            'created_at' => now(),
        ]);
    }

    /** Why a release amount is not acceptable, or null when it is. */
    private function releaseAmountProblem(Payout $payout, float $amount): ?string
    {
        if (! is_finite($amount) || $amount <= 0) {
            return 'amount must be greater than zero';
        }

        if (round($amount, 2) !== $amount) {
            return 'amount must have at most two decimal places';
        }

        return $this->amountAuthorityProblem($payout, $amount, 'amount');
    }

    /**
     * The most this payout may ever transfer, and where that ceiling comes from.
     *
     * There is exactly one money chain: the payment's `receiptBreakdown()`
     * (meta_data.base_amount explaining transactions.amount) and payoutAmountFor()
     * on top of it. A trustworthy breakdown gives the school share as the ceiling;
     * a legacy payment with no trustworthy split can only be bounded by the gross
     * the parent paid — the same fallback settlement itself parks for review.
     * Never transactions.fee_amount, never anything on the payout row.
     *
     * @return array{ceiling: float, basis: 'school_share'|'gross_legacy', transaction: Transaction}|null
     *                                                                                                    null when the payout has no transaction behind it
     */
    public function amountAuthorityFor(Payout $payout): ?array
    {
        $transaction = $payout->transaction_id ? Transaction::whereKey($payout->transaction_id)->first() : null;

        if (! $transaction) {
            return null;
        }

        if ($this->hasTrustworthyBreakdown($transaction)) {
            return ['ceiling' => $this->payoutAmountFor($transaction), 'basis' => 'school_share', 'transaction' => $transaction];
        }

        return ['ceiling' => round((float) $transaction->amount, 2), 'basis' => 'gross_legacy', 'transaction' => $transaction];
    }

    /**
     * Why $amount may not be transferred for this payout, or null when it may.
     *
     * Shared by the release command (the operator's figure), the retry command
     * (`payouts.amount` as it stands) and the job's last check before the transfer,
     * so no path can move more than the payment authorises. It never changes the
     * amount: an approved release is sent exactly as approved or not at all.
     */
    public function amountAuthorityProblem(Payout $payout, float $amount, string $subject = 'amount'): ?string
    {
        $authority = $this->amountAuthorityFor($payout);

        if ($authority === null) {
            return $subject.' cannot be authorised: this payout has no transaction (no payment) behind it';
        }

        $amount = round($amount, 2);

        if ($authority['basis'] === 'school_share') {
            if ($amount > $authority['ceiling']) {
                return sprintf(
                    '%s NGN %s exceeds the NGN %s school share recorded for this payment (the parent paid NGN %s including the service fee)',
                    $subject,
                    number_format($amount, 2),
                    number_format($authority['ceiling'], 2),
                    number_format((float) $authority['transaction']->amount, 2)
                );
            }

            return null;
        }

        if ($amount > $authority['ceiling']) {
            return sprintf(
                '%s NGN %s exceeds the NGN %s charged for this legacy payment, which has no trustworthy fee breakdown',
                $subject,
                number_format($amount, 2),
                number_format($authority['ceiling'], 2)
            );
        }

        return null;
    }

    private function rejection(Payout $payout): string
    {
        return match ($payout->status) {
            Payout::PENDING => 'payout is already pending',
            Payout::INITIATING => 'payout is initiating (outcome unknown — run payouts:lookup first)',
            Payout::PROCESSING => 'payout is already processing',
            Payout::SUCCESS => 'payout is already paid',
            Payout::REVERSED => 'payout was reversed and is final',
            Payout::NEEDS_REVIEW => 'payout needs review (use payouts:release with an explicit amount)',
            Payout::FAILED => 'payout is failed (use payouts:retry)',
            default => 'payout is '.$payout->status,
        };
    }

    /** @return array{ok: bool, previous: string, status: string, message: string, event: ?PayoutRecoveryEvent} */
    private function recoveryResult(bool $ok, string $previous, string $status, string $message, ?PayoutRecoveryEvent $event = null): array
    {
        return ['ok' => $ok, 'previous' => $previous, 'status' => $status, 'message' => $message, 'event' => $event];
    }

    /**
     * Our durable transfer reference, and Paystack's idempotency key for the transfer.
     */
    private function generateReference(): string
    {
        return 'PO-'.Str::uuid()->toString();
    }
}
