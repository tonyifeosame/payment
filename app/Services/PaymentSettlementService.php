<?php

namespace App\Services;

use App\Jobs\InitiateSchoolPayout;
use App\Mail\PaymentReceiptMail;
use App\Models\Payout;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Settles a Paystack payment against the local transaction it belongs to.
 *
 * This is the single implementation shared by the browser callback and the
 * webhook, so both paths apply identical rules:
 *
 *   G3  The amount, currency and status come from a server-side verify call.
 *       Nothing the browser or Paystack's metadata echo says is trusted.
 *   G4  The local transaction is resolved by the reference we generated,
 *       never by metadata, so a caller cannot point a payment at another
 *       school's transaction.
 *   G2  A reference settles at most once. Every write to the row — success
 *       *and* mismatch — happens under a row lock, against a freshly re-read
 *       row, so concurrent callback + webhook deliveries cannot both apply a
 *       change or repeat its side effects.
 *
 * Invariant: a transaction that has reached 'success' is terminal. No path in
 * this class may move it out of that state.
 */
class PaymentSettlementService
{
    /** The only currency this application charges in. */
    public const CURRENCY = 'NGN';

    // Outcomes.
    public const SETTLED = 'settled';                       // transitioned now; receipt queued
    public const ALREADY_SETTLED = 'already_settled';       // idempotent no-op
    public const NOT_FOUND = 'not_found';                   // no local transaction for this reference
    public const NOT_SUCCESSFUL = 'not_successful';         // Paystack says the charge did not succeed
    public const AMOUNT_MISMATCH = 'amount_mismatch';
    public const CURRENCY_MISMATCH = 'currency_mismatch';
    public const VERIFICATION_FAILED = 'verification_failed'; // transient: could not reach/parse Paystack
    public const SETTLEMENT_CONFLICT = 'settlement_conflict'; // durable DB conflict; needs a human, not a retry
    public const FAILED_RECORDED = 'failed_recorded';         // H5: Paystack's definitive failure written to the row
    public const ALREADY_FAILED = 'already_failed';           // H5: it was already recorded as failed; no-op

    /**
     * H5: Paystack statuses that are a definitive end for THIS attempt. `failed` is a
     * declined/errored charge; `reversed` means a charge was undone. `abandoned` is
     * NOT here: it means the customer has not (yet) completed the hosted checkout,
     * which they may still do, so it only becomes a failure once the pending
     * window has passed (see reconcilePendingAttempt).
     */
    public const DEFINITIVE_FAILURE_STATUSES = ['failed', 'reversed'];

    public function __construct(
        private PaystackService $paystack,
        private PayoutService $payouts,
    ) {}

    /**
     * @return array{outcome: string, transaction: ?Transaction, message: ?string}
     */
    public function settleByReference(?string $reference): array
    {
        $reference = is_string($reference) ? trim($reference) : '';
        if ($reference === '') {
            return $this->result(self::NOT_FOUND, null, 'No payment reference supplied.');
        }

        // G4: bind to the local record by OUR reference. Metadata is never consulted.
        $transaction = Transaction::where('reference', $reference)->first();
        if (! $transaction) {
            return $this->result(self::NOT_FOUND, null, 'Unknown payment reference.');
        }

        // G2 fast path: already settled, so do no work and fire no side effects.
        // This is only an optimisation — the authoritative check happens under the
        // row lock below, because this read can be stale by the time we write.
        if ($transaction->status === 'success') {
            return $this->result(self::ALREADY_SETTLED, $transaction);
        }

        // G3: ask Paystack. Done outside the row lock so network latency does not
        // hold a database lock open.
        $verification = $this->paystack->verifyTransaction($reference);

        return $this->applyVerification($transaction, $verification, expireAbandoned: false, source: 'verify');
    }

    /**
     * H5: the expiry command's path for a checkout that has stayed `pending` past the
     * configured window. Exactly the same verification and settlement as the
     * callback and webhook — a payment Paystack now reports successful is SETTLED
     * here, receipt and payout included — with two additional definitive outcomes
     * that only apply once the window has passed: an `abandoned` checkout, and a
     * reference Paystack has never seen, are recorded as failed. Anything Paystack
     * still calls open, and any answer we could not get, leaves the row pending.
     *
     * @return array{outcome: string, transaction: ?Transaction, message: ?string}
     */
    public function reconcilePendingAttempt(Transaction $transaction): array
    {
        $transaction->refresh();

        if ($transaction->status === 'success') {
            return $this->result(self::ALREADY_SETTLED, $transaction);
        }
        if ($transaction->status !== 'pending') {
            return $this->result($transaction->status === 'failed' ? self::ALREADY_FAILED : self::NOT_SUCCESSFUL, $transaction, 'transaction is '.$transaction->status);
        }

        $verification = $this->paystack->verifyTransaction((string) $transaction->reference);

        return $this->applyVerification($transaction, $verification, expireAbandoned: true, source: 'expiry');
    }

    /**
     * Apply one verification answer to one transaction. Shared by every path so the
     * callback, the webhook and the expiry job can never disagree about what an
     * answer means. Only `success` settles; only a definitive failure fails; every
     * ambiguous answer changes nothing.
     */
    private function applyVerification(Transaction $transaction, array $verification, bool $expireAbandoned, string $source): array
    {
        if (! ($verification['ok'] ?? false)) {
            // A reference Paystack has never seen (initialize never reached it) can
            // only be a failure once we would otherwise expire it; until then a
            // retry of the same checkout might still initialise it.
            if (($verification['not_found'] ?? false) && $expireAbandoned) {
                return $this->recordFailure($transaction, 'not_found', 'Paystack has no transaction with this reference', $verification['message'] ?? null, $source);
            }

            Log::warning('Paystack verification failed', [
                'reference' => $transaction->reference,
                'message' => $verification['message'] ?? null,
            ]);

            return $this->result(self::VERIFICATION_FAILED, $transaction, $verification['message'] ?? null);
        }

        $status = strtolower((string) ($verification['status'] ?? ''));

        if ($status !== 'success') {
            if (in_array($status, self::DEFINITIVE_FAILURE_STATUSES, true)) {
                return $this->recordFailure($transaction, $status, 'Paystack reported the charge as '.$status, $verification['gateway_response'] ?? null, $source);
            }

            if ($status === 'abandoned' && $expireAbandoned) {
                return $this->recordFailure($transaction, $status, 'Checkout was never completed (abandoned) within the pending window', $verification['gateway_response'] ?? null, $source);
            }

            // pending / ongoing / queued / processing / abandoned-but-within-window /
            // anything new: not proof of anything. Leave it.
            return $this->result(self::NOT_SUCCESSFUL, $transaction, $status !== '' ? 'Paystack reports the transaction as '.$status : null);
        }

        $expectedMinorUnits = (int) round(((float) $transaction->amount) * 100);

        if ($verification['amount'] !== $expectedMinorUnits) {
            return $this->recordMismatch($transaction, self::AMOUNT_MISMATCH, [
                'expected_minor_units' => $expectedMinorUnits,
                'paystack_minor_units' => $verification['amount'],
            ], 'Paid amount does not match the expected amount.');
        }

        if (strtoupper((string) $verification['currency']) !== self::CURRENCY) {
            return $this->recordMismatch($transaction, self::CURRENCY_MISMATCH, [
                'expected_currency' => self::CURRENCY,
                'paystack_currency' => $verification['currency'],
            ], 'Paid currency does not match the expected currency.');
        }

        return $this->markSuccessful($transaction, $verification);
    }

    /**
     * Transition to success exactly once, under a row lock.
     */
    private function markSuccessful(Transaction $transaction, array $verification): array
    {
        try {
            [$outcome, $row] = DB::transaction(function () use ($transaction, $verification) {
                $locked = $this->lockRow($transaction);

                if (! $locked) {
                    return [self::NOT_FOUND, null];
                }

                if ($locked->status === 'success') {
                    return [self::ALREADY_SETTLED, $locked];
                }

                $attributes = [
                    'status' => 'success',
                    'paid_at' => now(),
                    'payment_method' => $verification['channel'] ?: 'paystack',
                ];

                // M5: `paystack_reference` carries a unique index. Claim it only if no
                // other row already holds it, so a data anomaly cannot turn a payment
                // Paystack has confirmed into an unhandled constraint violation.
                $claimed = $this->claimPaystackReference($locked, $verification['reference'] ?: null);
                if ($claimed['conflict']) {
                    $attributes['meta_data'] = $this->mergeMeta($locked, 'paystack_reference_conflict', [
                        'paystack_reference' => $claimed['reference'],
                        'observed_at' => now()->toIso8601String(),
                    ]);
                } elseif ($claimed['reference'] !== null) {
                    $attributes['paystack_reference'] = $claimed['reference'];
                }

                $locked->forceFill($attributes)->save();

                // M4: queue the receipt inside the settling transaction so it commits
                // atomically with the payment (the database queue driver writes the job
                // row in this same transaction). Wrapped in a savepoint so a queue
                // failure can never roll back a confirmed payment.
                $this->queueReceipt($locked);

                // E5 (Model A): the school is owed money the moment the payment is
                // confirmed. The obligation is recorded here so it commits atomically
                // with the payment; the transfer itself happens in a queued job, well
                // away from this transaction and this HTTP request.
                $this->schedulePayout($locked);

                return [self::SETTLED, $locked];
            });
        } catch (QueryException $e) {
            // M5 safety net: the transaction rolled back. Decide definitively rather
            // than letting Paystack retry the same failing delivery forever.
            report($e);

            $fresh = Transaction::whereKey($transaction->getKey())->first();

            if ($fresh && $fresh->status === 'success') {
                return $this->result(self::ALREADY_SETTLED, $fresh);
            }

            Log::critical('Payment settlement failed with a database conflict', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
            ]);

            return $this->result(
                self::SETTLEMENT_CONFLICT,
                $fresh ?? $transaction,
                'Settlement could not be recorded. This payment needs manual reconciliation.'
            );
        }

        return $this->result($outcome, $row);
    }

    /**
     * Record a verification discrepancy without ever marking the payment successful —
     * and, critically, without ever un-marking one that already is.
     *
     * H1: this used to write from a stale model with no lock, so a mismatch computed
     * before a concurrent delivery settled the payment could downgrade a confirmed
     * 'success' back to 'mismatch' (which also removed it from payouts). The write now
     * happens under the same lock as settlement, against a freshly re-read row.
     */
    private function recordMismatch(Transaction $transaction, string $kind, array $details, string $message): array
    {
        [$outcome, $row] = DB::transaction(function () use ($transaction, $kind, $details) {
            $locked = $this->lockRow($transaction);

            if (! $locked) {
                return [self::NOT_FOUND, null];
            }

            // Success is terminal. A stale mismatch is discarded, never applied.
            if ($locked->status === 'success') {
                Log::warning('Discarded a stale verification mismatch for an already-settled payment', array_merge($details, [
                    'kind' => $kind,
                    'transaction_id' => $locked->id,
                    'reference' => $locked->reference,
                ]));

                return [self::ALREADY_SETTLED, $locked];
            }

            Log::critical('Paystack payment verification mismatch', array_merge($details, [
                'kind' => $kind,
                'transaction_id' => $locked->id,
                'reference' => $locked->reference,
                'school_id' => $locked->school_id,
            ]));

            $locked->forceFill([
                'status' => 'mismatch',
                // M2: merged onto the locked row's own metadata, decoded first, so
                // existing fields such as base_amount survive.
                'meta_data' => $this->mergeMeta($locked, 'verification_error', array_merge($details, [
                    'kind' => $kind,
                    'observed_at' => now()->toIso8601String(),
                ])),
            ])->save();

            return [$kind, $locked];
        });

        return $this->result($outcome, $row, $outcome === self::ALREADY_SETTLED ? null : $message);
    }

    /**
     * H5: record a definitive failure for a pending attempt, under the same row lock
     * as settlement. Success is terminal (a stale failure can never downgrade it),
     * `mismatch` is a state for a human and is left alone, and an already-failed row
     * is a no-op — so a webhook, callback and two expiry workers can all report the
     * same failure and the row is written once. The reference, amount and currency
     * stay exactly as they were; what is added is the status and a short,
     * non-secret record of why (Paystack's status and gateway message).
     */
    private function recordFailure(Transaction $transaction, string $paystackStatus, string $reason, ?string $gatewayResponse, string $source): array
    {
        [$outcome, $row] = DB::transaction(function () use ($transaction, $paystackStatus, $reason, $gatewayResponse, $source) {
            $locked = $this->lockRow($transaction);

            if (! $locked) {
                return [self::NOT_FOUND, null];
            }
            if ($locked->status === 'success') {
                Log::warning('Discarded a stale failure report for an already-settled payment', [
                    'transaction_id' => $locked->id, 'reference' => $locked->reference, 'paystack_status' => $paystackStatus,
                ]);

                return [self::ALREADY_SETTLED, $locked];
            }
            if ($locked->status === 'failed') {
                return [self::ALREADY_FAILED, $locked];
            }
            if ($locked->status !== 'pending') {
                return [self::NOT_SUCCESSFUL, $locked]; // mismatch: a human decides
            }

            $locked->forceFill([
                'status' => 'failed',
                'meta_data' => $this->mergeMeta($locked, 'failure', [
                    'paystack_status' => $paystackStatus,
                    'reason' => $reason,
                    'gateway_response' => $gatewayResponse !== null ? mb_substr($gatewayResponse, 0, 200) : null,
                    'observed_at' => now()->toIso8601String(),
                    'source' => $source,
                ]),
            ])->save();

            Log::info('Payment attempt recorded as failed', [
                'transaction_id' => $locked->id, 'reference' => $locked->reference, 'school_id' => $locked->school_id,
                'paystack_status' => $paystackStatus, 'source' => $source,
            ]);

            return [self::FAILED_RECORDED, $locked];
        });

        return $this->result($outcome, $row, $outcome === self::FAILED_RECORDED ? $reason : null);
    }

    /**
     * Re-read the row inside the current transaction, holding a write lock on it.
     *
     * On PostgreSQL and MySQL this emits `select ... for update`, so a competing
     * delivery blocks here and then observes our committed state. SQLite emits no
     * lock clause, but serialises writers itself; the status re-check performed by
     * every caller is what actually guarantees correctness on either engine.
     */
    private function lockRow(Transaction $transaction): ?Transaction
    {
        return Transaction::whereKey($transaction->getKey())->lockForUpdate()->first();
    }

    /**
     * Decide whether we may store Paystack's reference on this row.
     *
     * @return array{reference: ?string, conflict: bool}
     */
    private function claimPaystackReference(Transaction $locked, ?string $paystackReference): array
    {
        if ($paystackReference === null || $paystackReference === $locked->paystack_reference) {
            return ['reference' => null, 'conflict' => false];
        }

        $takenByAnotherRow = Transaction::where('paystack_reference', $paystackReference)
            ->whereKeyNot($locked->getKey())
            ->exists();

        if ($takenByAnotherRow) {
            Log::critical('Paystack reference already claimed by another transaction', [
                'paystack_reference' => $paystackReference,
                'transaction_id' => $locked->id,
                'reference' => $locked->reference,
            ]);

            return ['reference' => $paystackReference, 'conflict' => true];
        }

        return ['reference' => $paystackReference, 'conflict' => false];
    }

    /**
     * Merge a key into a transaction's metadata without losing what is already there.
     */
    private function mergeMeta(Transaction $transaction, string $key, array $value): array
    {
        $meta = $this->decodeMeta($transaction->meta_data);
        $meta[$key] = $value;

        return $meta;
    }

    /**
     * M2: metadata may arrive as an array, as a JSON string, or — on rows written by
     * the old un-scoped TransactionController::store() — as a *doubly* encoded JSON
     * string. Decode down to the array so a merge never silently discards it.
     */
    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $value = $raw;

        for ($depth = 0; $depth < 3 && is_string($value); $depth++) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {
                break;
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Receipt delivery is a side effect of settlement, so it is queued exactly once —
     * only on a real transition, never on a replay or retry.
     *
     * The nested DB::transaction creates a savepoint: if pushing the job fails, only
     * that savepoint rolls back and the settlement it belongs to still commits.
     */
    private function queueReceipt(Transaction $transaction): void
    {
        if (! $transaction->email) {
            return;
        }

        try {
            DB::transaction(function () use ($transaction) {
                Mail::to($transaction->email)->queue(new PaymentReceiptMail($transaction));
            });
        } catch (\Throwable $e) {
            // A confirmed payment must never be lost because a receipt could not be
            // queued, but the failure must be visible.
            report($e);
        }
    }

    /**
     * Record the payout obligation, and queue the transfer for after the commit.
     *
     * MEDIUM-3: the obligation is written inside the settling transaction, so it is
     * durable the instant the payment is — its existence does not depend on the queue
     * being reachable. If dispatch then fails, a `pending` payout remains and
     * `payouts:run` will find and queue it.
     *
     * MEDIUM-2: dispatch is deferred with DB::afterCommit(), so no Paystack transfer
     * can execute while the payment transaction is still open. That matters most on
     * the `sync` driver, where dispatch runs the job inline: without this, a transfer
     * would move money inside a transaction that might still roll back.
     *
     * A payout problem must never fail a payment the student actually made, so every
     * step here is contained.
     */
    private function schedulePayout(Transaction $transaction): void
    {
        try {
            $payout = $this->payouts->recordObligationFor($transaction);
        } catch (\Throwable $e) {
            report($e);

            Log::error('Could not record a payout obligation for a settled payment', [
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
            ]);

            return;
        }

        // Only a freshly created, transferable obligation needs dispatching. A replay
        // finds the existing row, and a needs_review row must never move money.
        if (! $payout || ! $payout->wasRecentlyCreated || $payout->status !== Payout::PENDING) {
            return;
        }

        $payoutId = $payout->id;

        DB::afterCommit(function () use ($payoutId, $transaction) {
            try {
                InitiateSchoolPayout::dispatch($payoutId);
            } catch (\Throwable $e) {
                // The obligation is already committed and reconcilable; losing the
                // dispatch costs us promptness, not the payout.
                report($e);

                Log::error('Payout obligation recorded but could not be queued; payouts:run will retry it', [
                    'payout_id' => $payoutId,
                    'transaction_id' => $transaction->id,
                ]);
            }
        });
    }

    private function result(string $outcome, ?Transaction $transaction, ?string $message = null): array
    {
        return ['outcome' => $outcome, 'transaction' => $transaction, 'message' => $message];
    }
}
