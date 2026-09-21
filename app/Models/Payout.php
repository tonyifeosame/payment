<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payout obligation: money this platform owes a school for a settled payment.
 *
 * Payments and payouts are separate financial state machines. A payout may fail,
 * be retried, or be reversed without ever changing the payment's status — the
 * student paid either way.
 *
 * Local transfer state machine:
 *
 *   pending ──► initiating ──► processing ──► success ──► reversed
 *      │            │              │      └──► failed        (terminal)
 *      │            │              └─────────► reversed
 *      │            └── outcome unknown: NEVER re-send; resolve by lookup first
 *      └──────────────────────────────────────► needs_review (human required)
 *
 * Transitions are monotonic with exactly one legitimate "downgrade": success may
 * become reversed, because Paystack genuinely reverses transfers after reporting
 * them successful. Nothing else moves backwards — success can never become failed,
 * and reversed can never be resurrected.
 *
 * `initiating` is the ambiguous state: we asked Paystack to move money but do not
 * know whether it took. The only safe move from there is to look the transfer up
 * by our reference — never to issue a second transfer.
 *
 * `needs_review` means a human must decide: either the fee split could not be
 * trusted, or Paystack reported a transfer whose amount/currency did not match
 * what we owe. It is never transferable and never counted as paid.
 *
 * `success` is written ONLY when Paystack reports a definitive successful transfer
 * whose reference, amount and currency all match this payout. An HTTP 200 from the
 * initiate call means "accepted", not "the school has the money".
 */
class Payout extends Model
{
    /** Obligation recorded; nothing sent to Paystack yet. */
    public const PENDING = 'pending';

    /** Handed to Paystack; outcome not yet known. Ambiguous — resolve before retrying. */
    public const INITIATING = 'initiating';

    /** Paystack accepted the transfer but has not finalised it. */
    public const PROCESSING = 'processing';

    /** Paystack confirmed the money reached the recipient, and it matched what we owe. */
    public const SUCCESS = 'success';

    /** Paystack confirmed the transfer did not happen. Retryable by an operator. */
    public const FAILED = 'failed';

    /** Paystack confirmed the money came back. Terminal. */
    public const REVERSED = 'reversed';

    /** A human must resolve this before any money moves. Never transferable. */
    public const NEEDS_REVIEW = 'needs_review';

    /** States from which no further transfer may be initiated. */
    public const NON_INITIABLE = [
        self::INITIATING, self::PROCESSING, self::SUCCESS, self::REVERSED, self::NEEDS_REVIEW,
    ];

    /**
     * The only state changes this ledger permits.
     *
     * success => [reversed] is the single legitimate downgrade. reversed => [] means
     * a reversed payout is final and can never be resurrected.
     */
    public const ALLOWED_TRANSITIONS = [
        self::PENDING => [self::INITIATING, self::PROCESSING, self::SUCCESS, self::FAILED, self::REVERSED, self::NEEDS_REVIEW],
        self::INITIATING => [self::PROCESSING, self::SUCCESS, self::FAILED, self::REVERSED, self::NEEDS_REVIEW],
        self::PROCESSING => [self::SUCCESS, self::FAILED, self::REVERSED, self::NEEDS_REVIEW],
        self::SUCCESS => [self::REVERSED],
        self::FAILED => [self::PENDING, self::INITIATING, self::PROCESSING, self::SUCCESS, self::REVERSED, self::NEEDS_REVIEW],
        self::NEEDS_REVIEW => [self::PENDING, self::PROCESSING, self::SUCCESS, self::FAILED, self::REVERSED],
        self::REVERSED => [],
    ];

    protected $fillable = [
        'school_id',
        'transaction_id',
        'reference',
        'amount',
        'currency',
        'payout_date',
        'start_at',
        'end_at',
        'status',
        'attempts',
        'last_error',
        'initiated_at',
        'completed_at',
        'transfer_code',
        'transfer_id',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
        'payout_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /** Operator recovery actions taken on this payout (see PayoutRecoveryEvent). */
    public function recoveryEvents()
    {
        return $this->hasMany(PayoutRecoveryEvent::class)->orderBy('id');
    }

    /** No further state change is possible. */
    public function isTerminal(): bool
    {
        return (self::ALLOWED_TRANSITIONS[$this->status] ?? []) === [];
    }

    /** Is moving this payout to $target a legitimate transition? */
    public function canTransitionTo(string $target): bool
    {
        if ($target === $this->status) {
            return false; // no-op; never rewrite timestamps on a redelivery
        }

        return in_array($target, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Map a Paystack transfer status onto our own state machine.
     *
     * Deliberately conservative: only the three statuses we can act on decisively
     * are treated as terminal. Everything else (including Paystack's OTP flow and
     * any status added later) is held as `processing`, which never releases the
     * payout for another transfer.
     */
    public static function mapPaystackStatus(?string $paystackStatus): string
    {
        return match (strtolower((string) $paystackStatus)) {
            'success', 'successful' => self::SUCCESS,
            'failed', 'abandoned' => self::FAILED,
            'reversed' => self::REVERSED,
            default => self::PROCESSING,
        };
    }
}
