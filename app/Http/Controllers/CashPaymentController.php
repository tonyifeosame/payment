<?php

namespace App\Http\Controllers;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use App\Services\AcademicPeriodService;
use App\Services\ManualPaymentService;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Recording a cash school-fee payment from a student's profile (details → review →
 * record), and voiding one recorded in error.
 *
 * {student} and {transaction} are scope-bound through the school (another school's
 * id is a 404 before this runs); the explicit checks below are the backstop. The
 * request never names a student, a fee, a school or an amount: ManualPaymentService
 * derives all of them from the database on every step.
 */
class CashPaymentController extends Controller
{
    public function __construct(
        private ManualPaymentService $payments,
        private AcademicPeriodService $periods,
    ) {}

    /** Step 1: choose the term, see the school fee it resolves to, enter the details. */
    public function create(Request $request, School $school, Student $student)
    {
        $this->assertStudentOfSchool($school, $student);

        $current = $school->currentTerm()->with('session')->first();
        $year = (string) $request->query('academic_year', $current?->session?->name ?? '');
        $number = (string) $request->query('term', (string) ($current?->number ?? ''));
        $term = $this->periods->existingTerm($school, $year, $number);

        return view('students.cash-payment.create', [
            'school' => $school,
            'student' => $student->loadMissing('classLevel'),
            'years' => $school->academicSessions()->orderByDesc('name')->get(),
            'currentTerm' => $current,
            'year' => $year,
            'number' => $number,
            'quote' => $this->payments->quote($school, $student, $term),
            'today' => now(BusinessTime::zone())->format('Y-m-d'),
        ]);
    }

    /** Step 2: re-derive everything and show the summary to confirm. */
    public function review(Request $request, School $school, Student $student)
    {
        $this->assertStudentOfSchool($school, $student);

        $details = $this->validatedDetails($request);
        $term = $this->termOrFail($school, $details);
        $quote = $this->payments->quote($school, $student, $term);

        if ($quote['problem'] !== null) {
            return $this->backToDetails($school, $student, $details, ['payment' => $quote['problem']]);
        }

        return view('students.cash-payment.review', [
            'school' => $school,
            'student' => $student,
            'quote' => $quote,
            'details' => $details,
            'paidOn' => Carbon::createFromFormat('!Y-m-d', $details['paid_on']),
        ]);
    }

    /** Step 3: record it — re-derived and re-checked again, under locks. */
    public function store(Request $request, School $school, Student $student)
    {
        $this->assertStudentOfSchool($school, $student);

        $details = $this->validatedDetails($request, confirming: true);
        $term = $this->termOrFail($school, $details);

        try {
            $transaction = $this->payments->record($school, $student, $term, [
                'fee_id' => $details['expected_fee_id'],
                'amount' => $details['expected_amount'],
                'term_id' => $details['expected_term_id'],
                'class_level_id' => $details['expected_class_level_id'],
            ], $details, $request);
        } catch (ValidationException $e) {
            return $this->backToDetails($school, $student, $details, $e->errors());
        }

        return redirect()->route('school.students.show', ['school' => $school->slug, 'student' => $student->id])
            ->with('success', 'Cash payment of ₦'.number_format((float) $transaction->amount, 2).' recorded for '
                .$transaction->term_name.', '.$transaction->session_name.'. It shows below as “Paid with Cash”.');
    }

    /** Void form: only for a cash payment that still stands. */
    public function voidForm(School $school, Transaction $transaction)
    {
        $this->assertCashOfSchool($school, $transaction);

        if ($transaction->status !== Transaction::STATUS_SUCCESS) {
            return redirect()->route('school.transactions.show', ['school' => $school->slug, 'transaction' => $transaction->id])
                ->with('error', 'This cash payment has already been voided.');
        }

        return view('transactions.void', [
            'school' => $school,
            'transaction' => $transaction,
            'heldDuplicates' => $this->payments->heldOnlineDuplicates($transaction),
            'termIsCurrent' => (int) $school->current_academic_term_id === (int) $transaction->academic_term_id,
        ]);
    }

    public function void(Request $request, School $school, Transaction $transaction)
    {
        $this->assertCashOfSchool($school, $transaction);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_void' => ['accepted'],
        ], [
            'reason.required' => 'Give the reason for voiding this cash payment.',
            'reason.min' => 'Give the reason for voiding this cash payment in a few words.',
            'confirm_void.accepted' => 'Tick the box to confirm you want to void this cash payment.',
        ]);

        $voided = $this->payments->void($school, $transaction, $data['reason'], $request);

        $message = 'Cash payment voided. It is kept on record, no longer counts as paid, and '
            .$voided->term_name.', '.$voided->session_name.' can be paid again.';

        return $voided->student_id
            ? redirect()->route('school.students.show', ['school' => $school->slug, 'student' => $voided->student_id])->with('success', $message)
            : redirect()->route('school.transactions.show', ['school' => $school->slug, 'transaction' => $voided->id])->with('success', $message);
    }

    private function assertStudentOfSchool(School $school, Student $student): void
    {
        if ((int) $student->school_id !== (int) $school->id) {
            abort(404);
        }
    }

    /** Only the school's own cash payments can be voided; a Paystack payment 404s. */
    private function assertCashOfSchool(School $school, Transaction $transaction): void
    {
        if ((int) $transaction->school_id !== (int) $school->id || ! $transaction->isManual()) {
            abort(404);
        }
    }

    /**
     * The payment details the admin entered. `expected_*` (confirming only) are what
     * the review page showed — compared with a fresh derivation, never used.
     */
    private function validatedDetails(Request $request, bool $confirming = false): array
    {
        $rules = [
            'academic_year' => ['required', 'string', 'max:20'],
            'term' => ['required', 'integer', 'in:'.implode(',', array_keys(AcademicTerm::NAMES))],
            'paid_on' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! ManualPaymentService::isAcceptablePaymentDate(is_string($value) ? $value : null)) {
                    $fail('Enter the date the cash was paid. It cannot be in the future.');
                }
            }],
            'receipt_number' => ['nullable', 'string', 'max:100', 'not_regex:/\p{Cc}/u'],
            'received_by' => ['required', 'string', 'max:100', 'not_regex:/\p{Cc}/u'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
        if ($confirming) {
            $rules += [
                'confirm_received' => ['accepted'],
                'expected_fee_id' => ['required', 'integer'],
                'expected_amount' => ['required', 'string', 'max:20'],
                'expected_term_id' => ['required', 'integer'],
                // Null when the student had no class: the quote refuses that anyway.
                'expected_class_level_id' => ['nullable', 'integer'],
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'received_by.required' => 'Enter the name of the person who received the cash.',
            'paid_on.required' => 'Enter the date the cash was paid.',
            'confirm_received.accepted' => 'Tick the box to confirm the school has received this amount in cash, in full.',
            'term.in' => 'Choose First, Second or Third Term.',
        ]);

        if ($validator->fails()) {
            // Back to the details page explicitly: "back" from the review step would
            // be a POST-only URL.
            throw new ValidationException($validator, $this->detailsRedirect($request->route('school'), $request->route('student'), $request->all())
                ->withErrors($validator)->withInput($request->except(['expected_fee_id', 'expected_amount', 'expected_term_id', 'expected_class_level_id'])));
        }

        $data = $validator->validated();
        $data['received_by'] = trim(preg_replace('/\s+/u', ' ', $data['received_by']));
        if ($data['received_by'] === '') {
            throw new ValidationException($validator, $this->detailsRedirect($request->route('school'), $request->route('student'), $request->all())
                ->withErrors(['received_by' => 'Enter the name of the person who received the cash.'])->withInput());
        }

        return $data;
    }

    private function termOrFail(School $school, array $details): AcademicTerm
    {
        $term = $this->periods->existingTerm($school, $details['academic_year'], $details['term']);
        if (! $term) {
            throw new ValidationException(Validator::make([], []), $this->detailsRedirect($school, request()->route('student'), $details)
                ->withErrors(['payment' => 'Choose an academic year and term your school has set up.'])->withInput());
        }

        return $term;
    }

    private function backToDetails(School $school, Student $student, array $details, array $errors)
    {
        return $this->detailsRedirect($school, $student, $details)
            ->withErrors($errors)
            ->withInput(collect($details)->only(['paid_on', 'receipt_number', 'received_by', 'notes'])->all());
    }

    private function detailsRedirect(School $school, Student $student, array $details)
    {
        return redirect()->route('school.students.cash-payment.create', [
            'school' => $school->slug,
            'student' => $student->id,
            'academic_year' => is_string($details['academic_year'] ?? null) ? $details['academic_year'] : null,
            'term' => is_scalar($details['term'] ?? null) ? $details['term'] : null,
        ]);
    }
}
