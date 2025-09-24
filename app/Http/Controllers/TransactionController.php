<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Subcategory;

class TransactionController extends Controller
{
    public function index()
    {
        $transactions = Transaction::paginate(10);
        return view('transactions.index', compact('transactions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'admission_number' => 'required|string',
            'amount'           => 'required|numeric',
            'status'           => 'sometimes|string',
            'payment_method'   => 'nullable|string',
            'email'            => 'nullable|email',
            'name'             => 'nullable|string',
            'category_id'      => 'required|exists:categories,id',
            'subcategory_id'   => 'required|exists:subcategories,id',
            'meta_data'        => 'nullable|array',
            'reference'        => 'nullable|string',
        ]);

        // ✅ Default status
        $data['status'] = $data['status'] ?? 'pending';

        // ✅ Generate reference if not provided
        $data['reference'] = $data['reference'] ?? 'REF-' . uniqid();

        // ✅ Fetch category & subcategory names
        $category = Category::findOrFail($data['category_id']);
        $subcategory = Subcategory::findOrFail($data['subcategory_id']);

        $data['category_name'] = $category->name;
        $data['subcategory_name'] = $subcategory->name;

        // ✅ Encode meta_data to JSON if array
        if (isset($data['meta_data']) && is_array($data['meta_data'])) {
            $data['meta_data'] = json_encode($data['meta_data']);
        }

        // ✅ Save transaction
        $transaction = Transaction::create($data);

        // ✅ If request expects JSON
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'transaction' => $transaction
            ]);
        }

        return redirect()->route('transactions.index')
                         ->with('success', 'Transaction recorded successfully.');
    }
}
   