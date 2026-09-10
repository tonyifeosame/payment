<?php

namespace App\Http\Controllers;

use App\Models\School;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * Tenant-aware transaction listing.
     *
     * Every query here starts from the acting school's id. The previous un-scoped
     * index()/store()/destroy() methods were removed: index() listed every school's
     * payer names, emails and amounts to any logged-in admin, and store() allowed an
     * admin to insert an arbitrary 'success' transaction with no school attribution.
     */
    public function indexSchool(Request $request, School $school)
    {
        $q = trim((string) $request->input('q', ''));

        $transactions = Transaction::where('school_id', $school->id)
            ->where('status', 'success')
            ->when($q !== '', function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%");
            })
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('transactions.index', [
            'transactions' => $transactions,
            'q' => $q,
            'school' => $school,
        ]);
    }
}
