<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

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

        return view('payment.index', compact('categories', 'categoriesForJs'));
    }

    // Initialize payment
    public function initialize(Request $request)
    {
        $validated = $request->validate([
            'email'            => 'required|email',
            'subcategory_id'   => 'required|exists:subcategories,id',
            'category_id'      => 'nullable|exists:categories,id',
            'admission_number' => 'required|string|max:255',
            'quantity'         => 'required|integer|min:1',
        ]);

        // Derive category_id from subcategory if hidden input wasn't set by JS
        $subcategory = Subcategory::findOrFail($validated['subcategory_id']);
        $categoryId = $validated['category_id'] ?? $subcategory->category_id;
        $category   = Category::findOrFail($categoryId);

        $amount = $subcategory->price * $validated['quantity']; // in Naira

        // Create transaction (status pending)
        $transaction = Transaction::create([
            'category_id'      => $category->id,
            'subcategory_id'   => $subcategory->id,
            'category_name'    => $category->name,        // ✅ save category name
            'subcategory_name' => $subcategory->name,     // ✅ save subcategory name
            'admission_number' => $validated['admission_number'],
            'amount'           => $amount,
            'status'           => 'pending',
            'payment_method'   => 'paystack',
            'email'            => $validated['email'],
            'name'             => $request->name ?? null,
            'meta_data'        => ['quantity' => $validated['quantity']],
        ]);

        $paystack = [
            "amount" => $amount * 100, // convert to Kobo
            "email" => $validated['email'],
            "reference" => $transaction->id . '_' . Str::random(8),
            "callback_url" => route('payment.callback'),
            "metadata" => [
                "admission_number" => $validated['admission_number'],
                "transaction_id"   => $transaction->id,
                "quantity"         => $validated['quantity'],
            ],
        ];

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->post(config('services.paystack.payment_url') . '/transaction/initialize', $paystack);

        $resBody = $response->json();

        if ($resBody['status']) {
            return redirect($resBody['data']['authorization_url']);
        }

        return back()->with('error', 'Unable to initialize payment.');
    }

    // Verify payment
    public function callback(Request $request)
    {
        $reference = $request->reference;

        $response = Http::withToken(config('services.paystack.secret_key'))
            ->get(config('services.paystack.payment_url') . "/transaction/verify/{$reference}");

        $data = $response->json();

        if ($data['status'] && $data['data']['status'] === 'success') {
            // Extract payment channel (e.g. card, bank, ussd, mobile_money)
            $channel = $data['data']['channel'];

            Transaction::where('id', $data['data']['metadata']['transaction_id'])
                ->update([
                    'status' => 'success',
                    'payment_method' => $channel, // ✅ save actual channel
                ]);

            return redirect()->route('payment.index')->with('success', 'Payment successful!');
        }

        return redirect()->route('payment.index')->with('error', 'Payment failed!');
    }
}
