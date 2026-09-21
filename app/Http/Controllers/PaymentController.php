<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use App\Services\AcademicPeriodService;
use App\Services\PaymentCheckoutService;
use App\Services\PaymentSettlementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class PaymentController extends Controller
{
    /** Suggestions per search: enough to disambiguate, not enough to list a roster. */
    public const STUDENT_SEARCH_LIMIT = 10;

    /**
     * Admin entry point for the payment page.
     *
     * Previously this listed every school's categories to any logged-in admin and
     * rendered a form posting to the un-scoped initialize endpoint. It now renders
     * the acting school's own page, so the data and the form target are both scoped.
     */
    public function index()
    {
        $school = School::find(session('school_admin_id'));

        if (! $school) {
            return redirect()->route('admin.login')->with('error', 'Please log in.');
        }

        return $this->renderPaymentPage($school);
    }

    /**
     * Tenant-aware public payment page for a specific school.
     */
    public function indexSchool(School $school)
    {
        return $this->renderPaymentPage($school);
    }

    /**
     * Render the payment page for exactly one school's fee structure.
     */
    private function renderPaymentPage(School $school)
    {
        $categories = Category::with('subcategories')
            ->where('school_id', $school->id)
            ->get();

        // Only ids, names, prices and the fee's term reach the browser. The term id
        // lets the page hide fees that are not payable in the chosen term; the
        // server re-checks the same rule on submit.
        $categoriesForJs = $categories->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'name' => $s->name,
                        'price' => (float) $s->price,
                        'term_id' => $s->academic_term_id,
                    ];
                })->values(),
            ];
        })->values();

        $terms = app(AcademicPeriodService::class)->termsForSchool($school);
        $sessionsForJs = $terms->groupBy('academic_session_id')->map(function ($group) {
            $session = $group->first()->session;

            return [
                'id' => $session->id,
                'name' => $session->name,
                'terms' => $group->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ];
        })->values();

        $markupPercent = (float) config('fees.markup_percent', 2.5);
        $requiresStudent = $school->requiresStudentOnPayment();
        $currentTerm = $school->currentTerm;

        // After a failed submit, re-select the student the parent had picked — but
        // only if that id really is one of this school's active students.
        $oldStudent = null;
        if ($requiresStudent && is_numeric(old('student_id'))) {
            $s = Student::forSchool($school)->payable()->find((int) old('student_id'));
            if ($s) {
                $oldStudent = ['id' => $s->id, 'full_name' => $s->full_name, 'class_name' => $s->class_name, 'admission_number_masked' => $s->maskedAdmissionNumber()];
            }
        }

        return view('payment.index', compact(
            'categories', 'categoriesForJs', 'school', 'markupPercent',
            'sessionsForJs', 'requiresStudent', 'currentTerm', 'oldStudent'
        ));
    }

    /**
     * Public, throttled autocomplete behind the payment page's "Student" field.
     *
     * A convenience for the browser only: it lists ACTIVE students WITHIN the bound
     * school (name first, admission number second) and returns just what a parent needs to
     * pick the right child — name, class, a MASKED admission number, and the id
     * the form will send back. The id is then re-checked against the same school
     * on submit by PaymentCheckoutService, so nothing here is trusted later. The
     * full admission number and guardian details never leave the server here.
     */
    public function studentSearch(Request $request, School $school)
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
        ]);

        $students = Student::forSchool($school)
            ->payable()
            ->publicSearch($validated['q'])
            ->limit(self::STUDENT_SEARCH_LIMIT)
            ->get(['id', 'full_name', 'class_name', 'admission_number']);

        return response()->json([
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'class_name' => $s->class_name,
                'admission_number_masked' => $s->maskedAdmissionNumber(),
            ])->values(),
        ]);
    }

    /**
     * Tenant-aware payment initialization for a specific school.
     *
     * Validation here is shape-only. Ownership (school, category, fee, term,
     * student) and the amount are decided by PaymentCheckoutService from trusted
     * rows; nothing about money or identity is taken from the request.
     */
    public function initializeSchool(Request $request, School $school, PaymentCheckoutService $checkout)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name' => 'nullable|string|max:255',
            'subcategory_id' => 'required|integer',
            'category_id' => 'required|integer',
            'quantity' => 'required|integer|min:1|max:100',
            'student_id' => 'nullable|integer',
            'academic_session_id' => 'nullable|integer',
            'academic_term_id' => 'nullable|integer',
        ]);

        $transaction = $checkout->createPendingTransaction($school, $validated);
        $amount = (float) $transaction->amount;
        $generatedRef = $transaction->reference;

        $paystack = [
            'amount' => (int) round($amount * 100),
            'email' => $validated['email'],
            'reference' => $generatedRef,
            'callback_url' => route('payment.callback'),
            'metadata' => [
                'transaction_id' => $transaction->id,
                'quantity' => $transaction->decodedMetaData()['quantity'] ?? 1,
                'school_id' => $school->id,
                'school_slug' => $school->slug,
                'school_name' => $school->name,
                'admission_number' => $transaction->student_admission_number,
                'term' => $transaction->term_name ? $transaction->term_name.' '.$transaction->session_name : null,
            ],
        ];

        // throw: false is what makes the graceful return below reachable. Without it
        // retry() re-throws once the attempts are exhausted, so every Paystack-side
        // failure — a bad key, a rate limit, an outage — reached the payer as a 500
        // instead of the message this method is clearly written to return.
        // The try/catch covers the other half: retry's flag only suppresses a failed
        // *response*, while a DNS or TCP failure still raises ConnectionException.
        try {
            $response = Http::withToken(config('services.paystack.secret_key'))
                ->retry(3, 200, throw: false)
                ->connectTimeout(10)
                ->timeout(25)
                ->post(config('services.paystack.payment_url').'/transaction/initialize', $paystack);

            $resBody = $response->json();
        } catch (\Throwable $e) {
            report($e);

            $resBody = null;
        }

        if (($resBody['status'] ?? false) && isset($resBody['data']['authorization_url'])) {
            return redirect($resBody['data']['authorization_url']);
        }

        // Keep what the parent filled in (student, term, fee, email…) so a retry is
        // one click. Nothing secret is in this form; _token is regenerated anyway.
        return back()->withInput($request->except('_token'))->with('error', 'Unable to initialize payment.');
    }

    /**
     * Browser return path from Paystack.
     *
     * This is a convenience redirect, not the source of truth — the webhook is
     * (see PaystackWebhookController). It performs the same server-side
     * verification and the same once-only settlement, then sends the payer
     * somewhere sensible.
     *
     * Nothing in the query string or in Paystack's metadata is trusted: the
     * reference is used only to look up our own row, and the school we redirect
     * to comes from that row, never from metadata.
     */
    public function callback(Request $request, PaymentSettlementService $settlement)
    {
        $result = $settlement->settleByReference($request->query('reference'));
        $transaction = $result['transaction'];

        $successMessage = 'Payment successful! A receipt has been sent to your email. You can also download it here.';

        switch ($result['outcome']) {
            case PaymentSettlementService::SETTLED:
                // Only a real transition grants this browser the receipt link, so a
                // replayed callback cannot be used to open someone else's receipt.
                session(['last_transaction_id' => $transaction->id]);

                return $this->backToPaymentPage($transaction)->with('success', $successMessage);

            case PaymentSettlementService::ALREADY_SETTLED:
                return $this->backToPaymentPage($transaction)->with('success', $successMessage);

            case PaymentSettlementService::AMOUNT_MISMATCH:
            case PaymentSettlementService::CURRENCY_MISMATCH:
                return $this->backToPaymentPage($transaction)->with(
                    'error',
                    'We could not confirm this payment. Please contact the school with your payment reference before paying again.'
                );

            case PaymentSettlementService::VERIFICATION_FAILED:
                return $this->backToPaymentPage($transaction)->with(
                    'error',
                    'We could not reach the payment provider to confirm this payment. If you were charged, it will be confirmed automatically shortly.'
                );

            default:
                return $this->backToPaymentPage($transaction)->with('error', 'Payment failed!');
        }
    }

    /**
     * Send the payer back to the school page the transaction actually belongs to.
     * Derived from our own record so metadata cannot redirect across tenants.
     */
    private function backToPaymentPage(?Transaction $transaction)
    {
        $slug = $transaction?->school?->slug;

        if ($slug) {
            // Deliberately the legacy /s/{school}/payment URL, not /pay/{school}. Both
            // serve the same page and both stay registered (the URL migration only
            // moved link generation to /pay/, without redirecting public routes), so
            // the post-payment leg of the checkout flow stays byte-for-byte the same.
            // Switch this to public.payment only as a separate public-payment change.
            return redirect()->route('school.payment.index', ['school' => $slug]);
        }

        return redirect()->route('payment.index');
    }

    /**
     * Authorize access to a receipt, or 404.
     *
     * Transaction ids are sequential, so the bare /payment/receipt/{id} route used to
     * let anyone enumerate every payer's name, email, amount and reference. Access is
     * now granted only through one of three non-enumerable proofs:
     *
     *   1. A valid URL signature — for links we hand out (emails, download buttons).
     *   2. The paying browser session — the payer who just completed this transaction.
     *   3. The owning school's admin session — scoped to that school's own records.
     *
     * We abort with 404 rather than 403 so the endpoint does not confirm whether a
     * given transaction id exists.
     */
    private function authorizeReceipt(Request $request, Transaction $transaction): void
    {
        // 1. Signed link.
        if ($request->hasValidSignature()) {
            return;
        }

        // 2. The payer who just completed this transaction in this session.
        $lastTransactionId = session('last_transaction_id');
        if ($lastTransactionId !== null && (int) $lastTransactionId === (int) $transaction->id) {
            return;
        }

        // 3. The admin of the school that owns the transaction.
        $schoolId = session('school_admin_id');
        if ($schoolId && $transaction->school_id !== null
            && (int) $transaction->school_id === (int) $schoolId) {
            return;
        }

        abort(404);
    }

    /**
     * Build a signed, non-expiring download link for a receipt.
     * Non-expiring because a receipt is a permanent record the payer may revisit.
     */
    private function signedDownloadUrl(Transaction $transaction): string
    {
        return URL::signedRoute('payment.receipt.download', ['transaction' => $transaction->id]);
    }

    /**
     * Display a receipt page for a given transaction.
     */
    public function receipt(Request $request, Transaction $transaction)
    {
        $this->authorizeReceipt($request, $transaction);

        $transaction->loadMissing('school', 'payout');

        return view('payment.receipt', [
            'transaction' => $transaction,
            'downloadUrl' => $this->signedDownloadUrl($transaction),
        ]);
    }

    /**
     * Download the receipt as a branded PDF.
     *
     * Same authorization as the on-screen receipt. The PDF template is a separate,
     * plain-CSS view (Dompdf cannot run the Tailwind CDN) and embeds the school's
     * logo as a data URI so rendering never makes an HTTP request.
     */
    public function downloadReceipt(Request $request, Transaction $transaction)
    {
        $this->authorizeReceipt($request, $transaction);

        $transaction->loadMissing('school', 'payout');

        $pdf = Pdf::loadView('payment.receipt_pdf', [
            'transaction' => $transaction,
            'school' => $transaction->school,
            'logoDataUri' => $transaction->school?->logoDataUri(),
        ])->setPaper('a4');

        $filename = 'receipt-'.($transaction->reference ?: $transaction->id).'.pdf';

        return $pdf->download($filename);
    }
}
