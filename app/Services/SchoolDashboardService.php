<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the school dashboard, every one of them a SUM/COUNT over that
 * school's own transactions and payouts. Nothing is estimated or derived from
 * anything but settled rows.
 *
 * Two figures are shown for every collection total:
 *   gross — what parents were charged (transactions.amount)
 *   net   — the school's share (transactions.fee_amount), i.e. what is paid out
 */
class SchoolDashboardService
{
    /**
     * @return array{
     *   term: ?AcademicTerm,
     *   today: array{gross: float, net: float, count: int},
     *   week: array{gross: float, net: float, count: int},
     *   term_totals: array{gross: float, net: float, count: int},
     *   all_time: array{gross: float, net: float, count: int},
     *   status_counts: array<string, int>,
     *   recent: \Illuminate\Support\Collection<int, Transaction>,
     *   by_category: \Illuminate\Support\Collection<int, object>,
     *   payouts: array{by_status: array<string, array{amount: float, count: int}>, recent: \Illuminate\Support\Collection<int, Payout>}
     * }
     */
    public function build(School $school, ?AcademicTerm $term): array
    {
        $now = Carbon::now();
        $paidAt = Transaction::paidAtExpression();

        $successful = fn () => Transaction::forSchool($school)->successful();

        $today = $this->totals(
            $successful()->whereRaw("$paidAt >= ?", [$now->copy()->startOfDay()])
        );
        $week = $this->totals(
            $successful()->whereRaw("$paidAt >= ?", [$now->copy()->startOfWeek()])
        );
        $allTime = $this->totals($successful());
        $termTotals = $term
            ? $this->totals($successful()->where('academic_term_id', $term->id))
            : ['gross' => 0.0, 'net' => 0.0, 'count' => 0];

        $statusCounts = Transaction::forSchool($school)
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();

        $recent = $successful()
            ->with(['student'])
            ->orderByDesc(DB::raw($paidAt))
            ->limit(10)
            ->get();

        // Grouped by the name snapshotted at payment time, so a renamed or deleted
        // category still reports under the name the parent actually paid for.
        $byCategory = $successful()
            ->when($term, fn ($q) => $q->where('academic_term_id', $term->id))
            ->select(
                DB::raw("COALESCE(category_name, 'Uncategorised') as category"),
                DB::raw('SUM(amount) as gross'),
                DB::raw('SUM(COALESCE(fee_amount, 0)) as net'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy(DB::raw("COALESCE(category_name, 'Uncategorised')"))
            ->orderByDesc(DB::raw('SUM(amount)'))
            ->get();

        return [
            'term' => $term,
            'today' => $today,
            'week' => $week,
            'term_totals' => $termTotals,
            'all_time' => $allTime,
            'status_counts' => $statusCounts,
            'recent' => $recent,
            'by_category' => $byCategory,
            'payouts' => $this->payoutSummary($school),
        ];
    }

    /** @return array{gross: float, net: float, count: int} */
    private function totals($query): array
    {
        $row = $query->selectRaw('COALESCE(SUM(amount), 0) as gross, COALESCE(SUM(fee_amount), 0) as net, COUNT(*) as count')->first();

        return [
            'gross' => round((float) ($row->gross ?? 0), 2),
            'net' => round((float) ($row->net ?? 0), 2),
            'count' => (int) ($row->count ?? 0),
        ];
    }

    /**
     * Payout ledger totals by state. `success` is the only state that means the
     * school has the money; needs_review and failed are "attention" states.
     */
    public function payoutSummary(School $school): array
    {
        $rows = Payout::where('school_id', $school->id)
            ->select('status', DB::raw('COALESCE(SUM(amount), 0) as amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row->status] = ['amount' => round((float) $row->amount, 2), 'count' => (int) $row->count];
        }

        $sum = function (array $statuses) use ($byStatus): array {
            $amount = 0.0;
            $count = 0;
            foreach ($statuses as $s) {
                $amount += $byStatus[$s]['amount'] ?? 0;
                $count += $byStatus[$s]['count'] ?? 0;
            }

            return ['amount' => round($amount, 2), 'count' => $count];
        };

        return [
            'by_status' => $byStatus,
            'paid' => $sum([Payout::SUCCESS]),
            'in_progress' => $sum([Payout::PENDING, Payout::INITIATING, Payout::PROCESSING]),
            'attention' => $sum([Payout::FAILED, Payout::NEEDS_REVIEW]),
            'reversed' => $sum([Payout::REVERSED]),
            'recent' => Payout::where('school_id', $school->id)
                ->with('transaction')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
        ];
    }
}
