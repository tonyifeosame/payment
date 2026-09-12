<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\School;
use App\Services\SchoolDashboardService;
use Illuminate\Http\Request;

/**
 * Read-only payout ledger for a school.
 *
 * Deliberately has no write actions: the payout state machine is driven only by
 * the settlement service, the worker job and Paystack's transfer webhooks. This
 * page shows the school what those did.
 */
class PayoutController extends Controller
{
    /** Human labels for the ledger. Keyed by Payout status constants. */
    public const STATUS_LABELS = [
        Payout::PENDING => 'Queued',
        Payout::INITIATING => 'Sending',
        Payout::PROCESSING => 'Processing at bank',
        Payout::SUCCESS => 'Paid',
        Payout::FAILED => 'Failed',
        Payout::REVERSED => 'Reversed',
        Payout::NEEDS_REVIEW => 'Under review',
    ];

    public function indexSchool(Request $request, School $school, SchoolDashboardService $dashboard)
    {
        $status = (string) $request->input('status', '');
        if (! array_key_exists($status, self::STATUS_LABELS)) {
            $status = '';
        }

        $payouts = Payout::where('school_id', $school->id)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->with('transaction')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('payouts.index', [
            'school' => $school,
            'payouts' => $payouts,
            'status' => $status,
            'labels' => self::STATUS_LABELS,
            'summary' => $dashboard->payoutSummary($school),
        ]);
    }
}
