<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
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

        $categoriesForJs = $categories->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => $c->name,
                'subcategories' => $c->subcategories->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'name' => $s->name,
                        'price' => (float) $s->price,
                    ];
                })->values(),
            ];
        })->values();

        $markupPercent = (float) config('fees.markup_percent', 2.5);

        return view('payment.index', compact('categories', 'categoriesForJs', 'school', 'markupPercent'));
    }

    /**
     * Tenant-aware payment initialization for a specific school.
     */
    public function initializeSchool(Request $request, School $school)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'subcategory_id' => 'required|exists:subcategories,id',
            'category_id' => 'required|exists:categories,id',
            'quantity' => 'required|integer|min:1',
        ]);

        // Load models scoped to school
        $subcategory = Subcategory::where('school_id', $school->id)->findOrFail($validated['subcategory_id']);
        $category = Category::where('school_id', $school->id)->findOrFail($validated['category_id']);

        // Ensure the selected subcategory belongs to the selected category
        if ((int) $subcategory->category_id !== (int) $category->id) {
            return back()->withInput()->withErrors([
                'subcategory_id' => 'Selected fee type does not belong to the chosen category.',
            ]);
        }

        // Enforce quantity for school fees
        $catNameLower = strtolower($category->name);
        if (str_contains($catNameLower, 'school fee')) {
            $validated['quantity'] = 1;
        }

        $baseAmount = (float) $subcategory->price * (int) $validated['quantity'];
        $markupPercent = (float) config('fees.markup_percent', 2.5);
        $markupAmount = round($baseAmount * ($markupPercent / 100), 2);
        $amount = $baseAmount + $markupAmount;

        // Generate and persist a unique reference before insert
        $generatedRef = Str::uuid()->toString();

        $transaction = Transaction::create([
            'school_id' => $school->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'category_name' => $category->name,
            'subcategory_name' => $subcategory->name,
            'reference' => $generatedRef,
            'amount' => $amount,
            'status' => 'pending',
            'payment_method' => 'paystack',
            'email' => $validated['email'],
            'name' => $request->name ?? null,
            'meta_data' => [
                'quantity' => (int) $validated['quantity'],
                'base_amount' => $baseAmount,
                'markup_percent' => $markupPercent,
                'markup_amount' => $markupAmount,
                'gross_amount' => $amount,
            ],
        ]);

        $paystack = [
            'amount' => (int) round($amount * 100),
            'email' => $validated['email'],
            'reference' => $generatedRef,
            'callback_url' => route('payment.callback'),
            'metadata' => [
                'transaction_id' => $transaction->id,
                'quantity' => $validated['quantity'],
                'school_id' => $school->id,
                'school_slug' => $school->slug,
                'school_name' => $school->name,
            ],
        ];

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->post(config('services.paystack.payment_url').'/transaction/initialize', $paystack);

        $resBody = $response->json();

        if (($resBody['status'] ?? false) && isset($resBody['data']['authorization_url'])) {
            return redirect($resBody['data']['authorization_url']);
        }

        return back()->with('error', 'Unable to initialize payment.');
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

        return view('payment.receipt', [
            'transaction' => $transaction,
            'downloadUrl' => $this->signedDownloadUrl($transaction),
        ]);
    }

    /**
     * Download the receipt as an attachment (HTML fallback).
     * If a PDF generator is installed, you can switch to PDF here.
     */
    public function downloadReceipt(Request $request, Transaction $transaction)
    {
        $this->authorizeReceipt($request, $transaction);

        $html = View::make('payment.receipt', [
            'transaction' => $transaction,
            'download' => true,
            'downloadUrl' => $this->signedDownloadUrl($transaction),
        ])->render();

        $filename = 'receipt-'.($transaction->id).'.html';

        return Response::make($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
