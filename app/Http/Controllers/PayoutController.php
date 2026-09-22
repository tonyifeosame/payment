<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\School;
use App\Services\PaymentTimeline;
use App\Services\SchoolDashboardService;
use App\Support\BusinessTime;
use Illuminate\Http\Request;

/**
 * Read-only payout ledger for a school.
 *
 * Deliberately has no write actions: the payout state machine is driven only by
 * the settlement service, the worker job and Paystack's transfer webhooks. This
 * page shows the school what those did. Filters only ever narrow the school's
 * own rows; the provider payload, transfer identifiers and last_error are never
 * rendered — the views explain each state in plain language instead.
 */
class PayoutController extends Controller
{
    /** Human labels for the ledger. Keyed by Payout status constants. */
    public const STATUS_LABELS = [
        Payout::PENDING => 'Pending',
        Payout::INITIATING => 'Initiating',
        Payout::PROCESSING => 'Processing',
        Payout::SUCCESS => 'Paid',
        Payout::FAILED => 'Failed',
        Payout::NEEDS_REVIEW => 'Needs review',
        Payout::REVERSED => 'Reversed',
    ];

    /**
     * Two virtual filter values the summary cards link to. They map onto the same
     * groups SchoolDashboardService::payoutSummary() reports, nothing new.
     */
    public const STATUS_GROUPS = [
        'in_progress' => [Payout::PENDING, Payout::INITIATING, Payout::PROCESSING],
        'attention' => [Payout::FAILED, Payout::NEEDS_REVIEW],
    ];

    public function indexSchool(Request $request, School $school, SchoolDashboardService $dashboard)
    {
        $status = (string) $request->input('status', '');
        if (! array_key_exists($status, self::STATUS_LABELS) && ! array_key_exists($status, self::STATUS_GROUPS)) {
            $status = '';
        }
        $q = trim((string) $request->input('q', ''));
        // Business days in the reporting zone, converted to storage time (M3).
        $from = BusinessTime::startOfDay($request->input('date_from'));
        $to = BusinessTime::endOfDay($request->input('date_to'));

        $payouts = Payout::where('school_id', $school->id)
            ->when($status !== '' && isset(self::STATUS_GROUPS[$status]), fn ($query) => $query->whereIn('status', self::STATUS_GROUPS[$status]))
            ->when($status !== '' && ! isset(self::STATUS_GROUPS[$status]), fn ($query) => $query->where('status', $status))
            // Search: our payout reference, or the payment it belongs to (reference,
            // student, payer). The transaction subquery is scoped to the school too.
            ->when($q !== '', function ($query) use ($q, $school) {
                $like = '%'.$q.'%';
                $query->where(function ($w) use ($like, $school) {
                    $w->where('payouts.reference', 'like', $like)
                        ->orWhereHas('transaction', fn ($t) => $t->where('school_id', $school->id)->where(fn ($x) => $x
                            ->where('reference', 'like', $like)
                            ->orWhere('student_name', 'like', $like)
                            ->orWhere('student_admission_number', 'like', $like)
                            ->orWhere('name', 'like', $like)
                            ->orWhere('email', 'like', $like)));
                });
            })
            ->when($from, fn ($query) => $query->where('payouts.created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('payouts.created_at', '<=', $to))
            ->with('transaction')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('payouts.index', [
            'school' => $school,
            'payouts' => $payouts,
            'status' => $status,
            'q' => $q,
            // Echoed back into the filter form and chips, so they have to read as
            // the day the admin typed — the bounds above are the same instants in
            // storage time, which is the previous date for an early-morning start.
            'dateFrom' => BusinessTime::display($from)?->format('Y-m-d'),
            'dateTo' => BusinessTime::display($to)?->format('Y-m-d'),
            'labels' => self::STATUS_LABELS,
            'summary' => $dashboard->payoutSummary($school),
        ]);
    }

    /**
     * Read-only detail of one payout and the payment behind it. {payout} is
     * scope-bound through School::payouts(); the explicit check is the backstop.
     */
    public function showSchool(School $school, Payout $payout, PaymentTimeline $timeline)
    {
        if ((int) $payout->school_id !== (int) $school->id) {
            abort(404);
        }

        $payout->load('transaction.student');
        $transaction = $payout->transaction;
        if ($transaction) {
            $transaction->setRelation('payout', $payout);
        }

        return view('payouts.show', [
            'school' => $school,
            'payout' => $payout,
            'transaction' => $transaction,
            'breakdown' => $transaction?->receiptBreakdown(),
            'labels' => self::STATUS_LABELS,
            'state' => $timeline->payoutState($payout),
            'timeline' => $transaction ? $timeline->forTransaction($transaction) : [],
        ]);
    }
}
