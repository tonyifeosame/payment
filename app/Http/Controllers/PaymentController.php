<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\School;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\View;

class PaymentController extends Controller
{
    public function index()
    {
        $categories = Category::with('subcategories')->get();
        // Build a clean structure for JS (ids, names, prices only)
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
        return view('payment.index', compact('categories', 'categoriesForJs', 'markupPercent'));
    }

    /**
     * Tenant-aware payment page for a specific school.
     */
    public function indexSchool(School $school)
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

    // Initialize payment
    public function initialize(Request $request)
    {
        $validated = $request->validate([
            'email'            => 'required|email',
            'subcategory_id'   => 'required|exists:subcategories,id',
            'category_id'      => 'required|exists:categories,id',
            'quantity'         => 'required|integer|min:1',
        ]);

        // Load models
        $subcategory = Subcategory::findOrFail($validated['subcategory_id']);
        $category   = Category::findOrFail($validated['category_id']);

        // Ensure the selected subcategory belongs to the selected category
        if ((int) $subcategory->category_id !== (int) $category->id) {
            return back()->withInput()->withErrors([
                'subcategory_id' => 'Selected fee type does not belong to the chosen category.',
            ]);
        }

        // If the category is School Fees, enforce quantity = 1 (server-side safety)
        $catNameLower = strtolower($category->name);
        if (str_contains($catNameLower, 'school fee')) {
            $validated['quantity'] = 1;
        }

        $baseAmount = (float) $subcategory->price * (int) $validated['quantity']; // in Naira
        $markupPercent = (float) config('fees.markup_percent', 2.5);
        $markupAmount = round($baseAmount * ($markupPercent/100), 2);
        $amount = $baseAmount + $markupAmount;

        // Generate and persist a unique reference before insert
        $generatedRef = Str::uuid()->toString();

        // Create transaction (status pending)
        $transaction = Transaction::create([
            'category_id'      => $category->id,
            'subcategory_id'   => $subcategory->id,
            'category_name'    => $category->name,        // ✅ save category name
            'subcategory_name' => $subcategory->name,     // ✅ save subcategory name
            'reference'        => $generatedRef,
            'amount'           => $amount,
            'status'           => 'pending',
            'payment_method'   => 'paystack',
            'email'            => $validated['email'],
            'name'             => $request->name ?? null,
            'meta_data'        => [
                'quantity'       => (int) $validated['quantity'],
                'base_amount'    => $baseAmount,
                'markup_percent' => $markupPercent,
                'markup_amount'  => $markupAmount,
                'gross_amount'   => $amount,
            ],
        ]);

        $paystack = [
            "amount" => (int) round($amount * 100), // convert to Kobo
            "email" => $validated['email'],
            "reference" => $generatedRef,
            "callback_url" => route('payment.callback'),
            "metadata" => [
                "transaction_id"   => $transaction->id,
                "quantity"         => $validated['quantity'],
            ],
        ];

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->post(config('services.paystack.payment_url') . '/transaction/initialize', $paystack);

        $resBody = $response->json();

        if ($resBody['status']) {
            return redirect($resBody['data']['authorization_url']);
        }

        return back()->with('error', 'Unable to initialize payment.');
    }

    /**
     * Tenant-aware payment initialization for a specific school.
     */
    public function initializeSchool(Request $request, School $school)
    {
        $validated = $request->validate([
            'email'            => 'required|email',
            'subcategory_id'   => 'required|exists:subcategories,id',
            'category_id'      => 'required|exists:categories,id',
            'quantity'         => 'required|integer|min:1',
        ]);

        // Load models scoped to school
        $subcategory = Subcategory::where('school_id', $school->id)->findOrFail($validated['subcategory_id']);
        $category   = Category::where('school_id', $school->id)->findOrFail($validated['category_id']);

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
        $markupAmount = round($baseAmount * ($markupPercent/100), 2);
        $amount = $baseAmount + $markupAmount;

        // Generate and persist a unique reference before insert
        $generatedRef = Str::uuid()->toString();

        $transaction = Transaction::create([
            'school_id'        => $school->id,
            'category_id'      => $category->id,
            'subcategory_id'   => $subcategory->id,
            'category_name'    => $category->name,
            'subcategory_name' => $subcategory->name,
            'reference'        => $generatedRef,
            'amount'           => $amount,
            'status'           => 'pending',
            'payment_method'   => 'paystack',
            'email'            => $validated['email'],
            'name'             => $request->name ?? null,
            'meta_data'        => [
                'quantity'       => (int) $validated['quantity'],
                'base_amount'    => $baseAmount,
                'markup_percent' => $markupPercent,
                'markup_amount'  => $markupAmount,
                'gross_amount'   => $amount,
            ],
        ]);

        $paystack = [
            'amount' => (int) round($amount * 100),
            'email' => $validated['email'],
            'reference' => $generatedRef,
            'callback_url' => route('payment.callback'),
            'metadata' => [
                'transaction_id'   => $transaction->id,
                'quantity'         => $validated['quantity'],
                'school_id'        => $school->id,
                'school_slug'      => $school->slug,
                'school_name'      => $school->name,
            ],
        ];

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->post(config('services.paystack.payment_url') . '/transaction/initialize', $paystack);

        $resBody = $response->json();

        if (($resBody['status'] ?? false) && isset($resBody['data']['authorization_url'])) {
            return redirect($resBody['data']['authorization_url']);
        }

        return back()->with('error', 'Unable to initialize payment.');
    }

    // Verify payment
    public function callback(Request $request)
    {
        $reference = $request->reference;

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->retry(3, 200)
            ->connectTimeout(10)
            ->timeout(25)
            ->get(config('services.paystack.payment_url') . "/transaction/verify/{$reference}");

        $data = $response->json();

        if (($data['status'] ?? false) && ($data['data']['status'] ?? null) === 'success') {
            // Extract payment channel (e.g. card, bank, ussd, mobile_money)
            $channel = $data['data']['channel'];
            $paystackReference = $data['data']['reference'] ?? null;

            $txnId = $data['data']['metadata']['transaction_id'] ?? null;
            // Update transaction record with channel, references and success status
            $update = [
                'status' => 'success',
                'payment_method' => $channel ?: 'paystack',
                'paystack_reference' => $paystackReference,
            ];
            if (!empty($reference)) {
                $update['reference'] = $reference;
            }
            Transaction::where('id', $txnId)->update($update);

            // Store last successful transaction id in session for receipt link
            if ($txnId) {
                session(['last_transaction_id' => $txnId]);
            }

            // Send receipt email
            if ($txnId) {
                $transaction = Transaction::find($txnId);
                if ($transaction && $transaction->email) {
                    try {
                        \Mail::to($transaction->email)->send(new \App\Mail\PaymentReceiptMail($transaction));
                    } catch (\Throwable $e) {
                        // Log or ignore email errors
                    }
                }
            }

            // If school context present, redirect to the school's payment page
            $schoolSlug = $data['data']['metadata']['school_slug'] ?? null;
            if ($schoolSlug) {
                return redirect()->route('school.payment.index', ['school' => $schoolSlug])
                                 ->with('success', 'Payment successful! A receipt has been sent to your email. You can also download it here.');
            }
            return redirect()->route('payment.index')->with('success', 'Payment successful! A receipt has been sent to your email. You can also download it here.');
        }

        return redirect()->route('payment.index')->with('error', 'Payment failed!');
    }

    /**
     * Display a receipt page for a given transaction.
     */
    public function receipt(Transaction $transaction)
    {
        return view('payment.receipt', [
            'transaction' => $transaction,
        ]);
    }

    /**
     * Download the receipt as an attachment (HTML fallback).
     * If a PDF generator is installed, you can switch to PDF here.
     */
    public function downloadReceipt(Transaction $transaction)
    {
        $html = View::make('payment.receipt', ['transaction' => $transaction, 'download' => true])->render();

        $filename = 'receipt-'.($transaction->id).'.html';
        return Response::make($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
