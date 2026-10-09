<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\BusinessTime;
use App\Support\RecordsSchoolAudit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cash school-fee payments recorded by the school, and their correction (void).
 *
 * A cash payment is money the school received directly from a parent. FEYRA did
 * not collect it, so it is stored as source = manual, payment_method = cash, with
 * no service fee, no Paystack reference and no email — and nothing here ever calls
 * Paystack, queues a receipt or records a payout obligation. It still settles the
 * student's school fee for the term exactly like an online payment: it writes the
 * same settled_obligation_key, whose unique index is what makes "one successful
 * school-fee payment per student, session and term" hold across both sources.
 *
 * Nothing about money or identity comes from the browser. The student is the one
 * bound to the route; the class, the term, the fee and its amount are re-derived
 * from the database on every step (quote()). The values the review page displayed
 * are only ever COMPARED with that derivation, so a fee edited or a student moved
 * between review and save is refused rather than recorded.
 */
class ManualPaymentService
{
    public function __construct(
        private AcademicPeriodService $periods,
        private RecordsSchoolAudit $audit,
    ) {}

    /**
     * What a cash payment for this student and term would be, or why there can't
     * be one.
     *
     * @return array{problem: ?string, student: Student, term: ?AcademicTerm, fee: ?Subcategory, amount: ?string, class_name: ?string, pending: ?Transaction}
     */
    public function quote(School $school, Student $student, ?AcademicTerm $term): array
    {
        $quote = ['problem' => null, 'student' => $student, 'term' => $term, 'fee' => null, 'amount' => null, 'class_name' => $student->class_name, 'pending' => null];
        $fail = fn (string $problem) => ['problem' => $problem] + $quote;

        if ((int) $student->school_id !== (int) $school->id) {
            abort(404);
        }
        if ($student->status !== Student::STATUS_ACTIVE) {
            return $fail('Cash payments can only be recorded for active students. '.$student->full_name.' is marked “'.(Student::STATUS_LABELS[$student->status] ?? $student->status).'”.');
        }
        if ($student->class_level_id === null) {
            return $fail('Assign '.$student->full_name.' to one of your classes before recording a payment, so the right school fee can be found.');
        }
        if ($term === null) {
            return $fail('Choose an academic year and term your school has set up.');
        }
        if ((int) $term->school_id !== (int) $school->id) {
            abort(404);
        }

        $term->loadMissing('session');
        $quote['pending'] = $this->pendingOnlineAttempt($school, $student, $term);

        if (! $this->periods->isOpenForCashPayment($school, $term)) {
            $current = $school->currentTerm()->with('session')->first();

            return $fail($term->label.' is not open for payment. Cash payments can only be recorded for your school’s current term'
                .($current ? ', which is '.$current->label.'.' : '. Set the current term on the Fees page first.'));
        }

        $fees = Subcategory::with('category')
            ->where('school_id', $school->id)
            ->where('is_tuition', true)
            ->applicableTo($student)
            ->where(fn ($q) => $q->where('academic_term_id', $term->id)->orWhereNull('academic_term_id'))
            ->orderBy('id')
            ->get();
        // A fee for this exact term wins over a main fee payable in any term.
        $termFees = $fees->filter(fn (Subcategory $f) => (int) $f->academic_term_id === (int) $term->id);
        $candidates = $termFees->isNotEmpty() ? $termFees : $fees;

        $className = $student->classLevel?->name ?? $student->class_name;
        if ($candidates->isEmpty()) {
            return $fail('There is no school fee for '.$className.' in '.$term->label.'. Add the school fees for this class and term on the Fees page first.');
        }
        if ($candidates->count() > 1) {
            return $fail('More than one school fee applies to '.$className.' in '.$term->label.' ('.$candidates->pluck('name')->implode(', ').'). Correct the fees on the Fees page first.');
        }

        $fee = $candidates->first();
        $quote['fee'] = $fee;
        $quote['class_name'] = $className;

        if ($fee->price === null || (float) $fee->price <= 0) {
            return $fail('The school fee “'.$fee->name.'” has no amount set. Set its amount on the Fees page first.');
        }
        $quote['amount'] = number_format((float) $fee->price, 2, '.', '');

        if (Transaction::paidObligation($student->id, $term->id)->exists()) {
            return $fail('School fees for '.$term->label.' have already been paid for '.$student->full_name.'.');
        }

        return $quote;
    }

    /**
     * Record a cash payment. $expected is what the review page showed (fee id,
     * amount, term id, class level id); it is compared, never used.
     *
     * @param  array{fee_id:mixed, amount:mixed, term_id:mixed, class_level_id:mixed}  $expected
     * @param  array{paid_on:string, receipt_number:?string, received_by:string, notes:?string}  $details
     *
     * @throws ValidationException
     */
    public function record(School $school, Student $student, AcademicTerm $term, array $expected, array $details, ?Request $request = null): Transaction
    {
        $paidAt = $this->paidAt($details['paid_on']);
        $receiptNumber = $this->normalizeReceiptNumber($details['receipt_number'] ?? null);
        $receiptKey = $receiptNumber !== null ? $school->id.':'.mb_strtoupper($receiptNumber) : null;

        try {
            return DB::transaction(function () use ($school, $student, $term, $expected, $details, $paidAt, $receiptNumber, $receiptKey, $request) {
                // Same lock order as void(): school, then student. The school row is
                // re-read so the current term (the payment window) is the committed one.
                $school = School::whereKey($school->id)->lockForUpdate()->firstOrFail();
                $student = Student::forSchool($school)->whereKey($student->id)->lockForUpdate()->firstOrFail();
                $term = AcademicTerm::where('school_id', $school->id)->with('session')->findOrFail($term->id);

                $quote = $this->quote($school, $student, $term);
                if ($quote['problem'] !== null) {
                    throw ValidationException::withMessages(['payment' => $quote['problem']]);
                }

                /** @var Subcategory $fee */
                $fee = $quote['fee'];
                if ((int) ($expected['fee_id'] ?? 0) !== (int) $fee->id
                    || (string) ($expected['amount'] ?? '') !== $quote['amount']
                    || (int) ($expected['term_id'] ?? 0) !== (int) $term->id
                    || (int) ($expected['class_level_id'] ?? 0) !== (int) $student->class_level_id) {
                    throw ValidationException::withMessages(['payment' => 'These payment details changed after you reviewed them (the fee, its amount or the student’s class). Nothing was recorded — please review the payment again.']);
                }

                if ($receiptKey !== null && Transaction::where('active_receipt_key', $receiptKey)->exists()) {
                    throw ValidationException::withMessages(['receipt_number' => 'Receipt number '.$receiptNumber.' is already used by another cash payment at your school.']);
                }

                $amount = round((float) $quote['amount'], 2);
                $obligationKey = Transaction::obligationKey($student->id, $term->academic_session_id, $term->id);

                $transaction = new Transaction;
                $transaction->forceFill([
                    'school_id' => $school->id,
                    'source' => Transaction::SOURCE_MANUAL,
                    'payment_method' => Transaction::METHOD_CASH,
                    // Our own reference, never sent to Paystack.
                    'reference' => 'CASH-'.Str::ulid(),
                    'paystack_reference' => null,
                    'category_id' => $fee->category_id,
                    'subcategory_id' => $fee->id,
                    'category_name' => $fee->category?->name,
                    'subcategory_name' => $fee->name,
                    'student_id' => $student->id,
                    'student_name' => $student->full_name,
                    'student_admission_number' => $student->admission_number,
                    'student_class' => $student->class_name,
                    'academic_session_id' => $term->academic_session_id,
                    'academic_term_id' => $term->id,
                    'session_name' => $term->session?->name,
                    'term_name' => $term->name,
                    // The school's price, in full. No platform service fee on cash.
                    'amount' => $amount,
                    'fee_amount' => $amount,
                    'service_fee' => 0,
                    'status' => Transaction::STATUS_SUCCESS,
                    'paid_at' => $paidAt,
                    'email' => null,
                    'name' => null,
                    'obligation_key' => $obligationKey,
                    // Unique: the database refuses a second successful school-fee payment
                    // for this student and term, whichever source got there first.
                    'settled_obligation_key' => $obligationKey,
                    'manual_receipt_number' => $receiptNumber,
                    'active_receipt_key' => $receiptKey,
                    'received_by' => $details['received_by'],
                    'notes' => $this->clean($details['notes'] ?? null),
                    'meta_data' => [
                        'quantity' => 1,
                        'base_amount' => $amount,
                        'markup_percent' => 0,
                        'markup_amount' => 0,
                        'gross_amount' => $amount,
                        'manual' => ['paid_on' => $details['paid_on'], 'recorded_at' => now()->toIso8601String()],
                    ],
                ])->save();

                $this->audit->record($school, SchoolAuditEvent::ACTION_CASH_PAYMENT_RECORDED, 'transaction', $transaction->id, [
                    'amount' => ['from' => null, 'to' => number_format($amount, 2, '.', '')],
                    'fee' => ['from' => null, 'to' => $fee->name],
                    'term' => ['from' => null, 'to' => $term->label],
                    'paid_on' => ['from' => null, 'to' => $details['paid_on']],
                    'receipt_number' => ['from' => null, 'to' => $receiptNumber],
                    'received_by' => ['from' => null, 'to' => $details['received_by']],
                ], request: $request);

                return $transaction;
            });
        } catch (QueryException $e) {
            // The transaction rolled back (and its audit row with it). A unique index
            // refused the insert: decide which one, outside the failed transaction.
            $obligationKey = Transaction::obligationKey($student->id, $term->academic_session_id, $term->id);
            if (Transaction::where('settled_obligation_key', $obligationKey)->exists()
                || Transaction::paidObligation($student->id, $term->id)->exists()) {
                throw ValidationException::withMessages(['payment' => 'School fees for '.$term->label.' have just been paid for '.$student->full_name.'. Nothing was recorded.']);
            }
            if ($receiptKey !== null && Transaction::where('active_receipt_key', $receiptKey)->exists()) {
                throw ValidationException::withMessages(['receipt_number' => 'Receipt number '.$receiptNumber.' is already used by another cash payment at your school.']);
            }

            throw $e;
        }
    }

    /**
     * Withdraw a cash payment recorded in error. The row is kept with every original
     * value; it becomes `voided`, stops counting anywhere, and releases the school-fee
     * obligation and receipt number it held so the fee can be paid (or recorded) again.
     *
     * Only cash rows can be voided — a Paystack payment is money FEYRA holds and is
     * never touched here. Voiding moves no money and creates no payout.
     *
     * @throws ValidationException
     */
    public function void(School $school, Transaction $transaction, string $reason, ?Request $request = null): Transaction
    {
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');

        return DB::transaction(function () use ($school, $transaction, $reason, $request) {
            // Same lock order as record(): school, student, then the payment itself.
            $school = School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            if ($transaction->student_id) {
                Student::forSchool($school)->whereKey($transaction->student_id)->lockForUpdate()->first();
            }
            $locked = Transaction::forSchool($school)->whereKey($transaction->id)->lockForUpdate()->first();

            if (! $locked) {
                abort(404);
            }
            if (! $locked->isManual()) {
                throw ValidationException::withMessages(['reason' => 'Only cash payments recorded by your school can be voided.']);
            }
            if ($locked->status !== Transaction::STATUS_SUCCESS) {
                throw ValidationException::withMessages(['reason' => 'This cash payment has already been voided.']);
            }

            $locked->forceFill([
                'status' => Transaction::STATUS_VOIDED,
                'voided_at' => now(),
                'void_reason' => $reason,
                // Release the obligation and the receipt number. obligation_key is kept
                // so the record still says which school fee it was for.
                'settled_obligation_key' => null,
                'active_receipt_key' => null,
            ])->save();

            $this->audit->record($school, SchoolAuditEvent::ACTION_CASH_PAYMENT_VOIDED, 'transaction', $locked->id, [
                'status' => ['from' => Transaction::STATUS_SUCCESS, 'to' => Transaction::STATUS_VOIDED],
                'reason' => ['from' => null, 'to' => $reason],
            ], request: $request);

            return $locked;
        });
    }

    /**
     * An online checkout for this student's school fee in this term that Paystack
     * has not settled yet. Recording cash is still allowed; if that checkout later
     * completes, settlement finds the fee paid and holds it for a refund.
     */
    public function pendingOnlineAttempt(School $school, Student $student, AcademicTerm $term): ?Transaction
    {
        return Transaction::forSchool($school)->online()
            ->where('transactions.status', 'pending')
            ->where('transactions.student_id', $student->id)
            ->where('transactions.academic_term_id', $term->id)
            ->where(fn ($q) => $q->whereNotNull('transactions.obligation_key')
                ->orWhereIn('transactions.subcategory_id', Subcategory::where('is_tuition', true)->select('id')))
            ->latest('id')
            ->first();
    }

    /**
     * Online payments of this cash payment's school fee that were confirmed after it
     * and are held for a refund. Voiding does not convert them: a person decides.
     */
    public function heldOnlineDuplicates(Transaction $cash)
    {
        if (! $cash->student_id || ! $cash->academic_term_id) {
            return collect();
        }

        return Transaction::forSchool($cash->school_id)->online()
            ->where('transactions.status', 'mismatch')
            ->where('transactions.student_id', $cash->student_id)
            ->where('transactions.academic_term_id', $cash->academic_term_id)
            ->get()
            ->filter(fn (Transaction $t) => ($t->decodedMetaData()['verification_error']['kind'] ?? null) === PaymentSettlementService::DUPLICATE_OBLIGATION)
            ->values();
    }

    /** True when a Y-m-d date is a real day, not after today in the school's timezone. */
    public static function isAcceptablePaymentDate(?string $date): bool
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $day = Carbon::createFromFormat('!Y-m-d', $date, BusinessTime::zone());
        if (! $day || $day->format('Y-m-d') !== $date) {
            return false;
        }

        return $date <= Carbon::now(BusinessTime::zone())->format('Y-m-d');
    }

    /**
     * When the cash was paid, in storage time: now, if it was paid today; otherwise
     * midday of that day in the school's timezone, so day-based reports file it on
     * the day the school entered.
     */
    private function paidAt(string $date): Carbon
    {
        $today = Carbon::now(BusinessTime::zone())->format('Y-m-d');
        if ($date === $today) {
            return now();
        }

        return Carbon::createFromFormat('!Y-m-d', $date, BusinessTime::zone())
            ->setTime(12, 0)
            ->setTimezone(BusinessTime::storageZone());
    }

    private function normalizeReceiptNumber(?string $value): ?string
    {
        return $this->clean($value);
    }

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }
}
