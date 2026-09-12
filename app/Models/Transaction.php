<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    /** The only status that counts as money collected. */
    public const STATUS_SUCCESS = 'success';

    /** Every status a transaction can hold, for filters and validation. */
    public const STATUSES = ['success', 'pending', 'failed', 'mismatch'];

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
    ];

    protected $casts = [
        'meta_data' => 'array',
        'paid_at' => 'datetime',
    ];

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
     * The moment a payment counts: when it settled, or — for rows that predate
     * paid_at — when it was created.
     */
    public static function paidAtExpression(): string
    {
        return 'COALESCE(transactions.paid_at, transactions.created_at)';
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
                $w->where('transactions.name', 'like', $like)
                    ->orWhere('transactions.email', 'like', $like)
                    ->orWhere('transactions.reference', 'like', $like)
                    ->orWhere('transactions.paystack_reference', 'like', $like)
                    ->orWhere('transactions.student_name', 'like', $like)
                    ->orWhere('transactions.student_admission_number', 'like', '%'.$admission.'%');
            });
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $query->where('transactions.status', $status);
        }

        foreach (['category_id' => 'category_id', 'session_id' => 'academic_session_id', 'term_id' => 'academic_term_id'] as $filter => $column) {
            $value = $filters[$filter] ?? null;
            if ($value !== null && $value !== '' && ctype_digit((string) $value)) {
                $query->where('transactions.'.$column, (int) $value);
            }
        }

        $from = self::parseDate($filters['date_from'] ?? null);
        $to = self::parseDate($filters['date_to'] ?? null);
        if ($from) {
            $query->whereRaw(self::paidAtExpression().' >= ?', [$from->startOfDay()]);
        }
        if ($to) {
            $query->whereRaw(self::paidAtExpression().' <= ?', [$to->endOfDay()]);
        }

        return $query;
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value)) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** True when this payment has recorded money against a real student. */
    public function hasStudent(): bool
    {
        return $this->student_id !== null || $this->student_name !== null;
    }
}
