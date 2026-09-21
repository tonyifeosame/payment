<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a parent's checkout form into a pending transaction.
 *
 * Everything the browser sends is treated as a claim to be verified against the
 * bound school, never as fact:
 *
 *   - category / fee ids must belong to this school, and to each other;
 *   - the term must belong to this school, and the fee must be payable in it;
 *   - the student id is looked up WITHIN this school, so a hidden field pointing
 *     at another school's student (or at nothing) fails closed, and the name,
 *     admission number and class stored on the transaction come from that row —
 *     never from anything the browser typed;
 *   - the amount is computed from the fee's stored price and the configured
 *     markup. `client_total` and any other browser-side figure are ignored.
 *
 * An id that does not belong to this school is a 404, matching every other
 * tenant-scoped lookup in the application (fail closed, reveal nothing). A genuine
 * user mistake — no student picked, a fee not payable in the chosen term — is a
 * ValidationException so the payer sees a field error.
 */
class PaymentCheckoutService
{
    /**
     * @param  array{email:string, name?:string|null, category_id:int|string, subcategory_id:int|string, quantity:int|string, student_id?:int|string|null, academic_term_id?:int|string|null, academic_session_id?:int|string|null}  $input
     */
    public function createPendingTransaction(School $school, array $input): Transaction
    {
        $category = Category::where('school_id', $school->id)->find($input['category_id']);
        $subcategory = Subcategory::where('school_id', $school->id)->find($input['subcategory_id']);

        if (! $category || ! $subcategory) {
            abort(404);
        }
        if ((int) $subcategory->category_id !== (int) $category->id) {
            throw ValidationException::withMessages(['subcategory_id' => 'Selected fee type does not belong to the chosen category.']);
        }

        $term = $this->resolveTerm($school, $input);
        if (! $subcategory->isPayableForTerm($term)) {
            throw ValidationException::withMessages(['subcategory_id' => 'The selected fee is not payable for the selected term.']);
        }

        $student = $this->resolveStudent($school, $input);

        // Enforce quantity for school fees
        $quantity = (int) $input['quantity'];
        if (str_contains(strtolower($category->name), 'school fee')) {
            $quantity = 1;
        }

        $baseAmount = round((float) $subcategory->price * $quantity, 2);
        $markupPercent = (float) config('fees.markup_percent', 2.5);
        $markupAmount = round($baseAmount * ($markupPercent / 100), 2);
        $amount = round($baseAmount + $markupAmount, 2);

        return Transaction::create([
            'school_id' => $school->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'category_name' => $category->name,
            'subcategory_name' => $subcategory->name,
            'student_id' => $student?->id,
            'student_name' => $student?->full_name,
            'student_admission_number' => $student?->admission_number,
            'student_class' => $student?->class_name,
            'academic_session_id' => $term?->academic_session_id,
            'academic_term_id' => $term?->id,
            'session_name' => $term?->session?->name,
            'term_name' => $term?->name,
            'reference' => Str::uuid()->toString(),
            'amount' => $amount,
            'fee_amount' => $baseAmount,
            'service_fee' => $markupAmount,
            'status' => 'pending',
            'payment_method' => 'paystack',
            'email' => $input['email'],
            'name' => $input['name'] ?? null,
            'meta_data' => [
                'quantity' => $quantity,
                'base_amount' => $baseAmount,
                'markup_percent' => $markupPercent,
                'markup_amount' => $markupAmount,
                'gross_amount' => $amount,
            ],
        ]);
    }

    /**
     * The term the parent is paying for. Required once the school has set up any
     * session; schools with no sessions keep the pre-period behaviour.
     */
    private function resolveTerm(School $school, array $input): ?AcademicTerm
    {
        $termId = $input['academic_term_id'] ?? null;
        $hasTerms = $school->academicTerms()->exists();

        if ($termId === null || $termId === '') {
            if ($hasTerms) {
                throw ValidationException::withMessages(['academic_term_id' => 'Please select the term you are paying for.']);
            }

            return null;
        }

        $term = AcademicTerm::with('session')
            ->where('school_id', $school->id)
            ->find($termId);

        if (! $term) {
            abort(404);
        }

        $sessionId = $input['academic_session_id'] ?? null;
        if ($sessionId !== null && $sessionId !== '' && (int) $sessionId !== (int) $term->academic_session_id) {
            throw ValidationException::withMessages(['academic_term_id' => 'The selected term does not belong to the selected session.']);
        }

        return $term;
    }

    /**
     * The student the payment is for, by id WITHIN this school.
     *
     * Required once the school has a roster; optional (null) before that. The id
     * arrives from the page's autocomplete, but it is only a claim: an id that is
     * not one of this school's ACTIVE students is a 404 (fail closed), exactly like
     * a foreign fee or term id — a graduated student or one who has left cannot be
     * paid for, however the id was obtained. Nothing else the browser sends about
     * the student — `student_name`, `admission_number`, `class` — is read at all.
     */
    private function resolveStudent(School $school, array $input): ?Student
    {
        $studentId = $input['student_id'] ?? null;
        $requires = $school->requiresStudentOnPayment();

        if ($studentId === null || $studentId === '') {
            if ($requires) {
                throw ValidationException::withMessages(['student_id' => 'Please search for and select the student you are paying for.']);
            }

            return null;
        }

        $student = Student::forSchool($school)->payable()->find((int) $studentId);

        if (! $student) {
            abort(404);
        }

        return $student;
    }
}
