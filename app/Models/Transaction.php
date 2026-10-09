<?php

namespace App\Models;

use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    /** The only status that counts as money collected. */
    public const STATUS_SUCCESS = 'success';

    /**
     * A cash payment the school recorded and later withdrew. Never money: it leaves
     * every total, and no longer settles the school fee it was recorded for.
     */
    public const STATUS_VOIDED = 'voided';

    /** Every status a transaction can hold, for filters and validation. */
    public const STATUSES = ['success', 'pending', 'failed', 'mismatch', self::STATUS_VOIDED];

    /** Paid through FEYRA's Paystack checkout: a FEYRA collection. */
    public const SOURCE_PAYSTACK = 'paystack';

    /**
     * Cash the school received directly and recorded here. It settles the school fee
     * for the student, but FEYRA collected nothing: no service fee, no Paystack
     * charge, never a payout.
     */
    public const SOURCE_MANUAL = 'manual';

    public const METHOD_CASH = 'cash';

    /** How each recorded payment method reads after "Paid with". */
    private const METHOD_NAMES = [
        'cash' => 'Cash',
        'card' => 'Card',
        'bank' => 'Bank',
        'bank_transfer' => 'Bank Transfer',
        'ussd' => 'USSD',
        'mobile_money' => 'Mobile Money',
        'qr' => 'QR',
        'eft' => 'EFT',
        'apple_pay' => 'Apple Pay',
    ];

    protected $fillable = [
        'reference',
        'paystack_reference',
        'category_id',
        'subcategory_id',
        'category_name',
        'subcategory_name',
        'student_id',
        'student_name',
        'student_admission_number',
        'student_class',
        'academic_session_id',
        'academic_term_id',
        'session_name',
        'term_name',
        'email',
        'name',
        'amount',
        'fee_amount',
        'service_fee',
        'status',
        'paid_at',
        'payment_method',
        'meta_data',
        'school_id',
        'obligation_key',
        'source',
        'manual_receipt_number',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    /** Cash recorded by the school, as opposed to a payment collected through Paystack. */
    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    /**
     * "Paid with Card", "Paid with Bank Transfer", "Paid with Cash" … for a successful
     * payment; null for anything that is not (or is no longer) paid. Rows settled
     * before Paystack reported a channel only know they were paid online.
     */
    public function methodLabel(): ?string
    {
        if ($this->status !== self::STATUS_SUCCESS) {
            return null;
        }
        if ($this->isManual()) {
            return 'Paid with Cash';
        }

        $method = strtolower((string) $this->payment_method);
        if ($method === '' || $method === 'paystack') {
            return 'Paid online';
        }

        return 'Paid with '.(self::METHOD_NAMES[$method] ?? ucwords(str_replace('_', ' ', $method)));
    }

    /**
     * The figures a receipt must show, derived from one authoritative source.
     *
     * `amount` is what the payer was actually charged, so it is always the total.
     * `meta_data` only ever *explains* that total by splitting it into the fee and
     * the service charge; it never overrides it. Receipts previously printed
     * base_amount as the total, so a payer charged ₦51,250 received a document
     * claiming ₦50,000 and the service fee was invisible.
     *
     * Both the emailed and the on-screen receipt read from here so they cannot drift.
     *
     * @return array{
     *     quantity:int, unit_price:float, fee_subtotal:float,
     *     service_fee:float, total:float, has_service_fee:bool, has_breakdown:bool
     * }
     */
    public function receiptBreakdown(): array
    {
        $meta = $this->decodedMetaData();

        $total = round((float) ($this->amount ?? 0), 2);
        $quantity = max((int) ($meta['quantity'] ?? 1), 1);

        $hasBreakdown = array_key_exists('base_amount', $meta) && is_numeric($meta['base_amount']);

        if (! $hasBreakdown) {
            // Nothing to split by, so the whole charge is the fee.
            return [
                'quantity' => $quantity,
                'unit_price' => round($total / $quantity, 2),
                'fee_subtotal' => $total,
                'service_fee' => 0.0,
                'total' => $total,
                'has_service_fee' => false,
                'has_breakdown' => false,
            ];
        }

        $feeSubtotal = round((float) $meta['base_amount'], 2);

        // A recorded markup is preferred, but the total always wins: if the parts do
        // not reconcile (legacy or hand-edited rows), re-derive from the real charge.
        $serviceFee = array_key_exists('markup_amount', $meta) && is_numeric($meta['markup_amount'])
            ? round((float) $meta['markup_amount'], 2)
            : round($total - $feeSubtotal, 2);

        if (round($feeSubtotal + $serviceFee, 2) !== $total) {
            $serviceFee = round($total - $feeSubtotal, 2);
        }

        // Never present a negative service fee or a subtotal above the amount charged.
        if ($serviceFee < 0) {
            $serviceFee = 0.0;
            $feeSubtotal = $total;
        }

        return [
            'quantity' => $quantity,
            'unit_price' => round($feeSubtotal / $quantity, 2),
            'fee_subtotal' => $feeSubtotal,
            'service_fee' => $serviceFee,
            'total' => $total,
            'has_service_fee' => $serviceFee > 0,
            'has_breakdown' => true,
        ];
    }

    /**
     * meta_data may be an array, a JSON string, or — on rows written by the old
     * un-scoped TransactionController::store() — a doubly encoded JSON string.
     */
    public function decodedMetaData(): array
    {
        $value = $this->meta_data;

        for ($depth = 0; $depth < 3 && is_string($value); $depth++) {
            $decoded = json_decode($value, true);

            if ($decoded === null) {
                break;
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession()
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function academicTerm()
    {
        return $this->belongsTo(AcademicTerm::class);
    }

    /**
     * The payout obligation this settled payment created (Model A: at most one).
     */
    public function payout()
    {
        return $this->hasOne(Payout::class);
    }

    // -----------------------------------------------------------------------
    // Query scopes. Every management query starts from forSchool(); the filters
    // below only ever narrow that set, so a query parameter can never widen it.
    // -----------------------------------------------------------------------

    public function scopeForSchool(Builder $query, School|int $school): Builder
    {
        return $query->where('transactions.school_id', $school instanceof School ? $school->id : $school);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('transactions.status', self::STATUS_SUCCESS);
    }

    /**
     * Payments FEYRA collected through Paystack. Every collection, revenue and
     * service-fee total — and anything that could lead to a payout — must start
     * here: cash recorded by the school is the school's own money, never FEYRA's.
     * "Is this fee paid?" questions use successful() alone, across both sources.
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('transactions.source', self::SOURCE_PAYSTACK);
    }

    /** Cash payments the school recorded itself. */
    public function scopeManual(Builder $query): Builder
    {
        return $query->where('transactions.source', self::SOURCE_MANUAL);
    }

    /**
     * The identity of a main (tuition) school-fee obligation: one student, one
     * academic session, one term — "student:session:term". ANY main fee settles it,
     * so a student moved to another class mid-term (whose class has a different main
     * fee) cannot pay school fees for that term twice. Which main fee is due is still
     * decided by class-level assignment. Null when the payment is not a main fee, or
     * has no student or term to tie it to: the paid-once rule does not apply.
     */
    public static function obligationKeyFor(?Student $student, Subcategory $fee, ?AcademicTerm $term): ?string
    {
        if (! $fee->is_tuition || $student === null || $term === null) {
            return null;
        }

        return self::obligationKey($student->id, $term->academic_session_id, $term->id);
    }

    public static function obligationKey(int|string $studentId, int|string|null $sessionId, int|string $termId): string
    {
        return (int) $studentId.':'.(int) $sessionId.':'.(int) $termId;
    }

    /**
     * This student's successful main (tuition) school-fee payments, any term. A
     * payment counts when its fee is a main fee, or when it was settled as a
     * school-fee obligation (settled_obligation_key) — so editing the fee later
     * cannot un-pay it. Matched on the rows themselves, so payments made before the
     * key existed count too. Only `success` is paid: pending, failed and mismatch
     * rows never are.
     */
    public function scopeTuitionPaid(Builder $query, int $studentId): Builder
    {
        return $query->successful()
            ->where('transactions.student_id', $studentId)
            ->whereNotNull('transactions.academic_term_id')
            ->where(fn (Builder $q) => $q
                ->whereNotNull('transactions.settled_obligation_key')
                ->orWhereIn('transactions.subcategory_id', Subcategory::where('is_tuition', true)->select('id')));
    }

    /** This student's successful main school-fee payment(s) for this term (and so its session). */
    public function scopePaidObligation(Builder $query, int $studentId, int $termId): Builder
    {
        return $query->tuitionPaid($studentId)->where('transactions.academic_term_id', $termId);
    }

    /**
     * The moment a payment counts: when it settled, or — for rows that predate
     * paid_at — when it was created.
     */
    public static function paidAtExpression(): string
    {
        return 'COALESCE(transactions.paid_at, transactions.created_at)';
    }

    /**
     * The school's share of a payment, for SUM()ing in reports (L9).
     *
     * receiptBreakdown() is the authority on what a payment was worth to a
     * school, and for a row with no usable `base_amount` in its metadata it
     * answers "the whole charge" — there is nothing to split by, so nothing was
     * the platform's. Aggregate queries read `fee_amount` instead, which those
     * rows do not have: they predate the column and the backfill migration could
     * only populate the ones whose metadata let it. `COALESCE(fee_amount,
     * amount)` is the same fallback in SQL, so a total and the rows it totals
     * can no longer disagree.
     *
     * Deliberately NOT used for money movement: payouts derive every figure from
     * receiptBreakdown() itself and never read this column (see PayoutService).
     */
    public static function netAmountExpression(): string
    {
        return 'COALESCE(transactions.fee_amount, transactions.amount)';
    }

    /**
     * Apply the transaction-list filters. Shared by the list page and the CSV
     * export so the file always contains exactly what the screen showed.
     *
     * Ids for category/session/term are accepted as given: the surrounding query is
     * already restricted to one school, so an id from another school simply matches
     * nothing. Dates are inclusive calendar days.
     *
     * @param  array{q?:string|null, status?:string|null, category_id?:mixed, session_id?:mixed, term_id?:mixed, date_from?:string|null, date_to?:string|null}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $admission = Student::normalizeAdmissionNumber($q);
            $query->where(function (Builder $w) use ($like, $admission) {
                // whereLike: case-insensitive on PostgreSQL too (ILIKE).
                $w->whereLike('transactions.name', $like)
                    ->orWhereLike('transactions.email', $like)
                    ->orWhereLike('transactions.reference', $like)
                    ->orWhereLike('transactions.paystack_reference', $like)
                    ->orWhereLike('transactions.student_name', $like)
                    ->orWhereLike('transactions.student_admission_number', '%'.$admission.'%');
            });
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('transactions.status', $status);
        }

        // Payment method: paid online through FEYRA, or cash recorded by the school.
        $source = $filters['source'] ?? null;
        if ($source === 'online') {
            $query->online();
        } elseif ($source === 'cash') {
            $query->manual();
        }

        foreach (['category_id' => 'category_id', 'session_id' => 'academic_session_id', 'term_id' => 'academic_term_id'] as $filter => $column) {
            $value = $filters[$filter] ?? null;
            if ($value !== null && $value !== '' && ctype_digit((string) $value)) {
                $query->where('transactions.'.$column, (int) $value);
            }
        }

        // A date filter is a business day in the reporting zone, converted to
        // storage time for the comparison (M3, App\Support\BusinessTime). Parsed
        // as UTC these bounds were an hour late, so a payment the list dated to
        // one day was filtered as if it belonged to the next.
        $from = BusinessTime::startOfDay($filters['date_from'] ?? null);
        $to = BusinessTime::endOfDay($filters['date_to'] ?? null);
        if ($from) {
            $query->whereRaw(self::paidAtExpression().' >= ?', [$from]);
        }
        if ($to) {
            $query->whereRaw(self::paidAtExpression().' <= ?', [$to]);
        }

        return $query;
    }

    /** True when this payment has recorded money against a real student. */
    public function hasStudent(): bool
    {
        return $this->student_id !== null || $this->student_name !== null;
    }

    /**
     * H5: when a pending attempt was recorded as failed, and why — Paystack's status
     * (failed / reversed / abandoned / not_found) and its short gateway message.
     * Null for rows that never failed. Nothing here is secret or a raw payload.
     *
     * @return array{paystack_status: ?string, reason: ?string, gateway_response: ?string, observed_at: ?Carbon, source: ?string}|null
     */
    public function failure(): ?array
    {
        $failure = $this->decodedMetaData()['failure'] ?? null;
        if (! is_array($failure)) {
            return null;
        }

        return [
            'paystack_status' => $failure['paystack_status'] ?? null,
            'reason' => $failure['reason'] ?? null,
            'gateway_response' => $failure['gateway_response'] ?? null,
            'observed_at' => isset($failure['observed_at']) ? Carbon::parse($failure['observed_at']) : null,
            'source' => $failure['source'] ?? null,
        ];
    }
}
